#!/usr/bin/env python3
"""Generate the SmartPRS 3 blueprint's cross-part registers from the parts themselves.

Appendix A (§A.6 residual risk 2) found that the three hand-maintained central
registers -- Part XXVIII's table catalogue, Part XXII's permission catalogue and
Part XXXIV's decision register -- were each materially incomplete, and that the
cause was hand maintenance of a register that must stay in step with thirty-nine
other files. This script is the implementation of that recommendation: the
registers are derived from the corpus, not written.

Standard library only. Run:

    python3 tools/generate-registers.py [--docs docs] [--out docs/registers]

Emits REGISTER-TABLES.md, REGISTER-PERMISSIONS.md, REGISTER-DECISIONS.md and
REGISTER-REQUIREMENTS.md, and prints a summary to stdout.

Extraction is pattern-based over prose written by forty authors. Where a
construct cannot be parsed it is counted as UNKNOWN and listed, never dropped.
"""

from __future__ import annotations

import argparse
import collections
import os
import re
import sys
from datetime import date

SCRIPT = "tools/generate-registers.py"

# --------------------------------------------------------------------------
# Corpus
# --------------------------------------------------------------------------

# Filename -> part token. The token is what requirement, decision and
# permission identifiers minted by that document are prefixed with.
FILE_PART = re.compile(r"^\d\d-(?:PART-([IVXL]+)|APPENDIX-([A-Z]))-")

# Part XXXI mints decisions as D-31-nn (Appendix A R-12: four conventions
# coexist). Normalise the arabic form onto the roman part token so the two
# spellings land on one part in every register.
DECISION_PART_ALIAS = {"31": "XXXI"}

ROMAN_ORDER = [
    "SPINE", "INDEX",
    "I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX", "X", "XI", "XII",
    "XIII", "XIV", "XV", "XVI", "XVII", "XVIII", "XIX", "XX", "XXI", "XXII",
    "XXIII", "XXIV", "XXV", "XXVI", "XXVII", "XXVIII", "XXIX", "XXX", "XXXI",
    "XXXII", "XXXIII", "XXXIV", "XXXV", "A", "B", "C",
]
ORDER_INDEX = {p: i for i, p in enumerate(ROMAN_ORDER)}


def part_sort_key(part: str):
    return (ORDER_INDEX.get(part, 999), part)


class Doc:
    """One markdown file, split into lines once."""

    def __init__(self, path: str):
        self.path = path
        self.name = os.path.basename(path)
        with open(path, encoding="utf-8") as fh:
            self.text = fh.read()
        self.lines = self.text.split("\n")
        m = FILE_PART.match(self.name)
        if m:
            self.part = m.group(1) or m.group(2)
        elif self.name == "SPINE.md":
            self.part = "SPINE"
        elif self.name.startswith("00-INDEX"):
            self.part = "INDEX"
        else:
            self.part = self.name

    def label(self) -> str:
        if self.part in ("SPINE", "INDEX"):
            return self.part.title()
        if self.part in ("A", "B", "C"):
            return f"Appendix {self.part}"
        return f"Part {self.part}"


def load_corpus(docs_dir: str):
    paths = sorted(
        os.path.join(docs_dir, f)
        for f in os.listdir(docs_dir)
        if f.endswith(".md")
    )
    return [Doc(p) for p in paths]


# --------------------------------------------------------------------------
# Shared markdown helpers
# --------------------------------------------------------------------------

HEADING = re.compile(r"^(#{1,6})\s+(.*)$")
SEPARATOR = re.compile(r"^\|[\s:|-]+\|?\s*$")
BACKTICKED = re.compile(r"`([^`]+)`")
TABLE_NAME = re.compile(r"^[a-z][a-z0-9_]{2,}$")
# Column names may be as short as `id`, table names may not.
COLUMN_NAME = re.compile(r"^[a-z][a-z0-9_]{1,}$")
FENCE = re.compile(r"^\s*(```|~~~)")


def split_row(line: str):
    """Cells of a markdown table row, outer pipes stripped."""
    s = line.strip()
    if s.startswith("|"):
        s = s[1:]
    if s.endswith("|"):
        s = s[:-1]
    return [c.strip() for c in s.split("|")]


def iter_tables(doc: Doc):
    """Yield (header_cells, [(lineno, row_cells)]) for every markdown table.

    Fenced code blocks are skipped -- several parts draw ASCII tables inside
    ```mermaid and ```yaml fences that are not markdown tables.

    A long table interrupted by a blank line or a note keeps its header: several
    parts (Part XXXIV's register most of all) break a 300-row table into visual
    blocks without repeating the header row.
    """
    in_fence = False
    header = None
    rows = []
    i = 0
    lines = doc.lines

    def flush():
        nonlocal header, rows
        if header is not None and rows:
            out = (header, rows)
            rows = []
            return out
        rows = []
        return None

    while i < len(lines):
        line = lines[i]
        if FENCE.match(line):
            in_fence = not in_fence
            i += 1
            continue
        if in_fence:
            i += 1
            continue
        if HEADING.match(line) or line.strip() in ("---", "***", "___"):
            got = flush()
            if got:
                yield got
            header = None
            i += 1
            continue
        if not line.lstrip().startswith("|"):
            i += 1
            continue
        if i + 1 < len(lines) and SEPARATOR.match(lines[i + 1]):
            got = flush()
            if got:
                yield got
            header = split_row(line)
            i += 2
            continue
        if SEPARATOR.match(line):
            i += 1
            continue
        if header is not None:
            rows.append((i + 1, split_row(line)))
        i += 1
    got = flush()
    if got:
        yield got


def section_spans(doc: Doc):
    """Yield (lineno, level, heading_text, body_lines) for every heading."""
    heads = []
    in_fence = False
    for idx, line in enumerate(doc.lines):
        if FENCE.match(line):
            in_fence = not in_fence
            continue
        if in_fence:
            continue
        m = HEADING.match(line)
        if m:
            heads.append((idx, len(m.group(1)), m.group(2).strip()))
    for k, (idx, level, text) in enumerate(heads):
        end = heads[k + 1][0] if k + 1 < len(heads) else len(doc.lines)
        yield idx + 1, level, text, doc.lines[idx + 1:end]


# --------------------------------------------------------------------------
# 1. Tables
# --------------------------------------------------------------------------

# A heading names a table specification only if its body actually specifies one.
# Section 123 grade (spine S20) means columns, keys and a policy line; the four
# authoring styles in the corpus all leave at least one of these marks.
SPEC_SIGNALS = (
    re.compile(r"^\|\s*`?Columns?`?\s*\|", re.I),
    re.compile(r"\*\*Keys?\.?\*\*"),
    re.compile(r"\*\*Policy\.?\*\*"),
    re.compile(r"\*Purpose[:*]"),
    re.compile(r"^\s*PK\s"),
    re.compile(r"\bUQ\s*\("),
    re.compile(r"\bPK\s*`"),
    # Part XIII / Part XI style: a backticked comma-separated column block
    # closed by a Unique / Audit / PII / Hist line.
    re.compile(r"^\s*Uniques?\s+`?\("),
    re.compile(r"\bUnique\s+`\("),
    re.compile(r"^\s*(Audit|PII|Hist|Tenant scope|Retention|SD)\s*:", re.I),
    re.compile(r"^`?id,\s*(uuid,\s*)?tenant_id\b"),
)

# A backticked block that is a comma-separated column list rather than one name.
COLUMN_BLOCK = re.compile(r"^[a-z_][a-z0-9_]*[,\s]")

# Derivable-register style tables: a markdown table whose first column is the
# table name (Part XXVIII §XXVIII.6, Part XVII §XVII.14.4, Part XXI, Part XXVII).
TABLE_COLUMN_HEADER = re.compile(r"^`?Tables?`?$", re.I)

DERIVABLE = re.compile(r"\bderivable\b", re.I)
EXTENDS = re.compile(r"\bextend(s|ing)?\b", re.I)
CLUSTER_HEAD = re.compile(r"^(C\d{1,2})\s*[—–-]\s*(.+?)\s*(?:\((\d+)\s+tables?\))?$")


class TableDef:
    __slots__ = ("name", "part", "doc", "line", "cluster", "derivable",
                 "extends", "columns", "source", "heading")

    def __init__(self, name, part, doc, line, cluster, derivable, extends,
                 columns, source, heading):
        self.name = name
        self.part = part
        self.doc = doc
        self.line = line
        self.cluster = cluster
        self.derivable = derivable
        self.extends = extends
        self.columns = columns          # frozenset of column names, may be empty
        self.source = source            # 'heading' | 'register-row'
        self.heading = heading


def heading_table_names(text: str):
    """Table names named in a specification heading.

    Handles every shape in the corpus:
        `tenants` - A1, **platform-owned**
        `rosters` - A1 . `roster_days` - A3
        `cfg_evaluation_forms` + `cfg_evaluation_form_versions` + ...
        `onboarding_journeys` - A1 (+ `journey_tasks`, `journey_submissions` A3, derivable)
        XXIII.10.1 `audit_entries`
        C.14.1 `cfg_overtime_policies`

    A parenthetical that is not a `(+ ...)` child list is an aside naming some
    *other* table ("parent `cfg_holiday_calendars`") or an artefact key
    ("payload of the `policy` artefact) and must not be read as a specification.
    """
    text = re.sub(r"\((?!\+)[^)]*\)", " ", text)
    names = [n for n in BACKTICKED.findall(text) if TABLE_NAME.match(n)]
    # A bold span inside a heading is a gloss, not a subject: "`cfg_overtime_
    # multipliers` - **the target of `multiplier_ref`**" specifies one table.
    if len(names) > 1:
        glossed = {n for span in re.findall(r"\*\*(.+?)\*\*", text)
                   for n in BACKTICKED.findall(span) if TABLE_NAME.match(n)}
        kept = [n for n in names if n not in glossed]
        if kept:
            names = kept
    return names


def body_columns(body):
    """Column names declared under a table specification.

    Two authoring styles: a | Column | Type | ... markdown table, or an inline
    comma-separated list of backticked column names before a **Keys.** line.
    """
    cols = set()
    in_col_table = False
    for line in body:
        s = line.strip()
        if s.startswith("|"):
            cells = split_row(s)
            if SEPARATOR.match(s):
                continue
            if cells and re.fullmatch(r"`?(Column|Field)s?`?", cells[0], re.I):
                in_col_table = True
                continue
            if in_col_table and cells:
                for n in BACKTICKED.findall(cells[0]):
                    if COLUMN_NAME.match(n):
                        cols.add(n)
                continue
            in_col_table = False
            continue
        in_col_table = False
        if re.match(r"^\*\*(Keys?|Policy)", s):
            break
        if s.startswith("`") or re.match(r"^[a-z_]", s):
            # inline column list, e.g. `candidate_id` (FK RESTRICT), `offer_no`, ...
            for chunk in BACKTICKED.findall(s):
                if "," in chunk and COLUMN_BLOCK.match(chunk):
                    # one backticked block holding the whole column list
                    for item in chunk.split(","):
                        tok = item.strip().split()[0] if item.strip() else ""
                        if COLUMN_NAME.match(tok):
                            cols.add(tok)
                elif COLUMN_NAME.match(chunk):
                    cols.add(chunk)
            # Deliberately no fallback to unbackticked prose: a sentence about the
            # table would contribute "and", "all" and "exactly" as column names.
    return frozenset(cols)


def extract_tables(corpus):
    defs = []
    unknown = []
    for doc in corpus:
        cluster = None
        for line, level, heading, body in section_spans(doc):
            m = CLUSTER_HEAD.match(heading)
            if m:
                cluster = m.group(1)
            elif level <= 2:
                cluster = None

            names = heading_table_names(heading)
            if names:
                joined = "\n".join(body[:80])
                if any(p.search(l) for l in body[:80] for p in SPEC_SIGNALS):
                    cols = body_columns(body)
                    # `foo` - A1 (+ `bar`, `baz` A3, derivable): only the names
                    # inside the parenthetical are derivable, not `foo`.
                    aside = re.search(r"\(\+([^)]*)\)", heading)
                    if aside and not heading_table_names(aside.group(1)):
                        aside = None
                    if aside and DERIVABLE.search(aside.group(1)):
                        derivable_names = set(heading_table_names(aside.group(1)))
                    elif DERIVABLE.search(heading):
                        derivable_names = set(names)
                    else:
                        derivable_names = set()
                    # The column list under a "`foo` (+ `bar` A3)" heading belongs
                    # to `foo`. Under a "`foo` . `bar`" heading the two column
                    # lists are interleaved and cannot be attributed, so neither
                    # gets one rather than both getting a false union.
                    owner = names[0] if (len(names) == 1 or aside) else None
                    for n in names:
                        defs.append(TableDef(
                            name=n, part=doc.part, doc=doc.name, line=line,
                            cluster=cluster,
                            derivable=n in derivable_names,
                            extends=bool(EXTENDS.search(heading)) or
                                    bool(EXTENDS.search(joined[:400])),
                            columns=cols if n == owner else frozenset(),
                            source="heading", heading=heading))
                elif len(names) == 1 and re.search(r"\btable\b", joined[:300], re.I):
                    unknown.append((doc.part, doc.name, line, heading))

        # Bold-paragraph specifications: **`mig_installs`** - one row per ...
        # followed by a column table. Part XXIX's whole tooling schema and a
        # handful of tables in Parts XV, XX and XXVI are written this way.
        for idx, line in enumerate(doc.lines):
            m = re.match(r"^\*\*`([a-z][a-z0-9_]{2,})`\*\*\s*(?:[—–-]|$)", line)
            if not m:
                continue
            window = doc.lines[idx + 1: idx + 40]
            if not any(p.search(l) for l in window for p in SPEC_SIGNALS):
                continue
            defs.append(TableDef(
                name=m.group(1), part=doc.part, doc=doc.name, line=idx + 1,
                cluster=None, derivable=False,
                extends=bool(EXTENDS.search(line)),
                columns=body_columns(window), source="bold-paragraph",
                heading=line.strip()))

        # Register-style tables: first column holds the table name(s).
        for header, rows in iter_tables(doc):
            if not header or not TABLE_COLUMN_HEADER.match(header[0].strip()):
                continue
            ctx_idx = next((i for i, h in enumerate(header)
                            if h.strip().lower() in ("ctx", "context", "cluster")), None)
            for lineno, cells in rows:
                if not cells:
                    continue
                names = [n for n in BACKTICKED.findall(cells[0])
                         if TABLE_NAME.match(n)]
                if not names:
                    if cells[0] and not cells[0].startswith("**"):
                        unknown.append((doc.part, doc.name, lineno,
                                        f"register row: {cells[0][:70]}"))
                    continue
                row_cluster = None
                if ctx_idx is not None and ctx_idx < len(cells):
                    cm = re.match(r"^(C\d{1,2})", cells[ctx_idx].strip())
                    if cm:
                        row_cluster = cm.group(1)
                # Only a Columns / Natural key column holds column names; a
                # Parent or Ctx column holds another table's name.
                col_idxs = [i for i, h in enumerate(header)
                            if i > 0 and re.search(r"column|key", h, re.I)]
                cols = frozenset(
                    n for i in col_idxs if i < len(cells)
                    for n in BACKTICKED.findall(cells[i]) if COLUMN_NAME.match(n)
                ) if len(names) == 1 else frozenset()
                for n in names:
                    defs.append(TableDef(
                        name=n, part=doc.part, doc=doc.name, line=lineno,
                        cluster=row_cluster,
                        derivable=True if row_cluster else
                                  bool(DERIVABLE.search(" ".join(cells))),
                        extends=False, columns=cols,
                        source="register-row", heading=header[0]))
    return defs, unknown


# --------------------------------------------------------------------------
# 2. Permissions
# --------------------------------------------------------------------------

PERM_CODE = re.compile(r"`([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+)`")
PERM_WORD = re.compile(r"permission|holding\b|MUST hold|may hold", re.I)
PERM_HEADING = re.compile(r"permission", re.I)
CATALOGUE_SECTION = re.compile(r"^XXII\.2\.2\b")
CATALOGUE_END = re.compile(r"^XXII\.2\.[3-9]\b")


def extract_catalogue(corpus):
    """Part XXII §XXII.2.2 -- the codes the platform currently seeds."""
    doc = next((d for d in corpus if d.part == "XXII"), None)
    if doc is None:
        return set(), {}
    codes, groups = set(), {}
    inside = False
    group = None
    in_fence = False
    for line in doc.lines:
        if FENCE.match(line):
            in_fence = not in_fence
            continue
        if in_fence:
            continue
        m = HEADING.match(line)
        if m:
            text = m.group(2).strip()
            if CATALOGUE_SECTION.match(text):
                inside = True
                continue
            if inside and CATALOGUE_END.match(text):
                break
            if inside and len(m.group(1)) >= 4:
                group = text
            continue
        if inside and line.lstrip().startswith("|"):
            cells = split_row(line)
            if not cells or SEPARATOR.match(line):
                continue
            found = PERM_CODE.findall(cells[0])
            if len(found) == 1:
                codes.add(found[0])
                groups[found[0]] = group or "(ungrouped)"
    return codes, groups


# `verb.resource` is the grammar placeholder of R-XXII-010, not a code.
PERM_LITERALS = {"verb.resource", "module.resource.verb"}

# A code can be named in order to say it is *not* a permission. R-XXII-013 names
# `payroll.run.approve.hyderabad` as the string the catalogue validator must
# reject; read as a declaration, that sentence puts in the register the one code
# the blueprint says must never exist.
#
# The markers below describe the *code itself* as invalid. Two near-misses are
# deliberately excluded. "MUST NOT" alone: "a user MUST NOT hold `x.y`" declares
# `x.y`. "Part XXII does not define `x.y`" (Part XXIV §XXIV.2.5's note on
# superseded spellings): correct about the code, but for three of the six codes
# in that sentence it is the only prose occurrence in the corpus, so keying on it
# deletes `platform.cross_tenant` and `integration.webhook.manage` from the
# register — a real loss to remove a real artefact. Narrow beats tidy here.
NEGATIVE_EXAMPLE = re.compile(
    r"counter-?example"
    r"|schema error"
    r"|rejected by the (?:catalogue )?validator"
    r"|validator MUST reject"
    r"|is not a (?:valid )?permission"
    r"|is invalid",
    re.I,
)

# A sentence ends at . ! or ? followed by whitespace and the start of the next --
# a capital, a backtick or markdown emphasis. `§XXII.2.2` and `payroll.run` have
# no space after the dot, so neither splits.
SENTENCE_SPLIT = re.compile(r"(?<=[.!?])\s+(?=[A-Z*`_>])")


def paragraph_map(body):
    """For each line of `body`, the text of the blank-line-delimited block it is in.

    A sentence routinely wraps across two or three lines, so a line alone is not
    enough context to tell a declaration from a counter-example.
    """
    out = [""] * len(body)
    start = 0
    for i in range(len(body) + 1):
        if i == len(body) or not body[i].strip():
            if i > start:
                text = " ".join(l.strip() for l in body[start:i])
                for j in range(start, i):
                    out[j] = text
            start = i + 1
    return out


def sentence_of(paragraph: str, code: str) -> str:
    """The sentence of `paragraph` that contains `code`, or the whole paragraph."""
    quoted = "`" + code + "`"
    for s in SENTENCE_SPLIT.split(paragraph):
        if quoted in s:
            return s
    return paragraph


def extract_permissions(corpus, catalogue, table_names=frozenset()):
    """Every permission code used anywhere, with the parts that use it.

    Shape alone cannot separate a permission from an event code or an SPX path
    (`payroll.run.created` and `attendance.day.view` are the same shape), so
    extraction is context-scoped: a permission column of a markdown table, a
    section whose heading is about permissions, or a sentence that says
    'permission' or 'holding'.
    """
    uses = collections.defaultdict(set)
    evidence = collections.defaultdict(set)
    excluded = collections.defaultdict(set)

    def keep(code: str) -> bool:
        if code in catalogue:
            return True
        if code in PERM_LITERALS:
            return False
        # `status_transitions.required_permission` is a column reference, not a
        # permission: its first segment is a table the schema declares.
        return code.split(".")[0] not in table_names

    for doc in corpus:
        # (a) permission columns of tables
        for header, rows in iter_tables(doc):
            idxs = [i for i, h in enumerate(header)
                    if re.search(r"permission", h, re.I)]
            for _, cells in rows:
                for i in idxs:
                    if i < len(cells):
                        for c in PERM_CODE.findall(cells[i]):
                            if not keep(c):
                                continue
                            uses[c].add(doc.part)
                            evidence[c].add("table")
        # (b) permission-titled sections and permission-mentioning prose
        for _, _, heading, body in section_spans(doc):
            in_perm_section = bool(PERM_HEADING.search(heading))
            paras = paragraph_map(body)
            in_fence = False
            for i, line in enumerate(body):
                if FENCE.match(line):
                    in_fence = not in_fence
                    continue
                if in_fence or line.lstrip().startswith("|"):
                    continue
                if in_perm_section:
                    kind = "section"
                elif PERM_WORD.search(line):
                    kind = "prose"
                else:
                    continue
                for c in PERM_CODE.findall(line):
                    if not keep(c):
                        continue
                    # A sentence may name a code in order to say it is not one.
                    sentence = sentence_of(paras[i], c)
                    if NEGATIVE_EXAMPLE.search(sentence):
                        excluded[c].add((doc.part, " ".join(sentence.split())))
                        continue
                    uses[c].add(doc.part)
                    evidence[c].add(kind)
    for c in catalogue:
        uses[c].add("XXII")
        evidence[c].add("catalogue")
    return uses, evidence, excluded


# --------------------------------------------------------------------------
# 3. Decisions
# --------------------------------------------------------------------------

DECISION_ID = re.compile(r"\b((?:D|PO)-(?:[IVXL]+|[A-C]|\d{1,2})-\d{1,3})\b")
SPINE_DECISION_ID = re.compile(r"\bD-(\d{2})\b(?!-)")

# Definition sites, most specific first.
DEF_INLINE = re.compile(
    r"\*\*[^*]*DECISION REQUIRED\s*[—–-]\s*(?P<id>(?:D|PO)-[A-Z0-9]{1,6}-\d{1,3})"
    r"\s*[:—–-]\s*(?P<q>[^*]+)")
DEF_HEADING = re.compile(
    r"^(?P<id>(?:D|PO)-[A-Z0-9]{1,6}-\d{1,3})\s*[·:—–-]+\s*(?P<q>.+)$")
DEF_BOLD = re.compile(
    r"^\*\*(?P<id>(?:D|PO)-[A-Z0-9]{1,6}-\d{1,3})\*\*\s*[·:—–-]*\s*(?P<q>.+)$")


def decision_part(ident: str) -> str:
    token = ident.split("-")[1]
    return DECISION_PART_ALIAS.get(token, token)


def clean_question(text: str) -> str:
    text = text.strip()
    text = re.sub(r"^[*_`\s—–:·-]+", "", text)
    text = re.sub(r"\*\*|\*|`", "", text)
    text = re.sub(r"\s+", " ", text).strip(" .*")
    return text[:160]


def extract_decisions(corpus):
    seen = collections.defaultdict(set)       # id -> parts it appears in
    question = {}
    define_site = {}

    for doc in corpus:
        for idx, line in enumerate(doc.lines, start=1):
            for m in DECISION_ID.finditer(line):
                seen[m.group(1)].add(doc.part)
            if doc.part == "SPINE":
                for m in SPINE_DECISION_ID.finditer(line):
                    seen["D-" + m.group(1)].add("SPINE")

        # "**PRODUCT OWNER DECISION REQUIRED - D-B-01: <question>.**" often wraps
        # across two or three source lines, so match it on the unwrapped text.
        flat = re.sub(r"[ \t]*\n[ \t]*", " ", doc.text)
        for m in DEF_INLINE.finditer(flat):
            if decision_part(m.group("id")) == doc.part:
                question.setdefault(m.group("id"), clean_question(m.group("q")))
                define_site.setdefault(m.group("id"), (doc.name, 0))

        # Definition sites within the part that mints the id.
        for idx, line in enumerate(doc.lines, start=1):
            s = line.strip()
            hm = HEADING.match(line)
            if hm:
                m = DEF_HEADING.match(hm.group(2).strip())
                if m and decision_part(m.group("id")) == doc.part:
                    question.setdefault(m.group("id"), clean_question(m.group("q")))
                    define_site.setdefault(m.group("id"), (doc.name, idx))
                continue
            m = DEF_BOLD.match(s)
            if m and decision_part(m.group("id")) == doc.part:
                question.setdefault(m.group("id"), clean_question(m.group("q")))
                define_site.setdefault(m.group("id"), (doc.name, idx))

        # Table-row definitions: | **D-XXI-04** | **question** | ...
        for header, rows in iter_tables(doc):
            for lineno, cells in rows:
                if not cells:
                    continue
                m = re.fullmatch(
                    r"\**`?((?:D|PO)-[A-Z0-9]{1,6}-\d{1,3})`?\**", cells[0].strip())
                if not m:
                    continue
                ident = m.group(1)
                if decision_part(ident) != doc.part:
                    continue
                if len(cells) > 1 and cells[1].strip():
                    question.setdefault(ident, clean_question(cells[1]))
                define_site.setdefault(ident, (doc.name, lineno))

    # Spine decisions
    spine = next((d for d in corpus if d.part == "SPINE"), None)
    if spine:
        for m in re.finditer(
                r"DECISION REQUIRED\s*[—–-]\s*(D-\d{2})\s*[:—–-]\s*([^.*\n]+)",
                spine.text):
            question.setdefault(m.group(1), clean_question(m.group(2)))
            define_site.setdefault(m.group(1), ("SPINE.md", 0))
            seen[m.group(1)].add("SPINE")

    return seen, question, define_site


def extract_xxxiv_register(corpus):
    """The rows Part XXXIV §XXXIV.6 currently carries: id -> (tier, question)."""
    doc = next((d for d in corpus if d.part == "XXXIV"), None)
    if doc is None:
        return {}
    rows = {}
    for header, body in iter_tables(doc):
        if not header or not re.fullmatch(r"ID", header[0].strip(), re.I):
            continue
        tier_idx = next((i for i, h in enumerate(header)
                         if h.strip().lower() == "tier"), None)
        for _, cells in body:
            m = re.fullmatch(r"\**`?((?:D|PO)-[A-Z0-9]{1,6}-\d{1,3}|D-\d{2})`?\**",
                             cells[0].strip())
            if not m:
                continue
            tier = ""
            if tier_idx is not None and tier_idx < len(cells):
                tier = re.sub(r"[*\s]", "", cells[tier_idx])
            q = clean_question(cells[2]) if len(cells) > 2 else ""
            rows[m.group(1)] = (tier or "—", q)
    return rows


# --------------------------------------------------------------------------
# 4. Requirements
# --------------------------------------------------------------------------

REQ_ID = re.compile(r"\b(R-[A-Z]{1,7}-\d{1,4})\b")
REQ_DEF_BOLD = re.compile(
    r"^\s*(?:[-*+]\s+|\|\s*)?\*\*(R-[A-Z]{1,7}-\d{1,4})\*\*")
REQ_DEF_PLAIN = re.compile(r"^\s*(R-[A-Z]{1,7}-\d{1,4})[\s.·—–:]")


def req_part(ident: str) -> str:
    return ident.split("-")[1]


def extract_requirements(corpus):
    """A requirement is defined where its id opens a line, heading or table cell
    inside the part that owns the prefix. Everywhere else it is a citation.

    Two definition styles exist and a part uses one of them, never both:

      prose  -- **R-XXII-010** the requirement, or ### R-I-001 - the requirement
      table  -- | R-XXXII-001 | the requirement |

    A part that states its requirements in prose ALSO restates them in a
    requirements-index table at the end of the part. Counting both styles in such
    a part double-counts every requirement it has, which is why the table style is
    only consulted for parts that define nothing in prose.
    """
    prose = collections.defaultdict(list)         # id -> [(doc, line)]
    tabular = collections.defaultdict(list)
    citations = collections.defaultdict(set)      # id -> parts
    prose_parts = set()

    for doc in corpus:
        in_fence = False
        for idx, line in enumerate(doc.lines, start=1):
            if FENCE.match(line):
                in_fence = not in_fence
                continue
            for m in REQ_ID.finditer(line):
                citations[m.group(1)].add(doc.part)
            if in_fence:
                continue
            hm = HEADING.match(line)
            if hm:
                m = REQ_DEF_PLAIN.match(" " + hm.group(2))
            else:
                m = REQ_DEF_BOLD.match(line)
                if m and line.lstrip().startswith("|"):
                    m = None            # a bolded id inside a table row
            if m and req_part(m.group(1)) == doc.part:
                prose[m.group(1)].append((doc.name, idx))
                prose_parts.add(doc.part)

        for header, rows in iter_tables(doc):
            for lineno, cells in rows:
                if not cells:
                    continue
                m = re.fullmatch(r"\**`?(R-[A-Z]{1,7}-\d{1,4})`?\**",
                                 cells[0].strip())
                if m and req_part(m.group(1)) == doc.part:
                    tabular[m.group(1)].append((doc.name, lineno))

    definitions = dict(prose)
    for ident, sites in tabular.items():
        if req_part(ident) not in prose_parts:
            definitions.setdefault(ident, []).extend(sites)
    return definitions, citations


# --------------------------------------------------------------------------
# Emission
# --------------------------------------------------------------------------

def header(title: str, purpose: str) -> str:
    return (
        f"# {title}\n\n"
        f"> **MACHINE-GENERATED — DO NOT HAND-EDIT.**\n"
        f"> Generated by `{SCRIPT}` from the markdown sources in `docs/`.\n"
        f"> Any hand edit is lost on the next run. To change a row, change the\n"
        f"> part that specifies it and re-run the script.\n"
        f"> Generated: {date.today().isoformat()}\n\n"
        f"{purpose}\n\n---\n\n"
    )


def write(path: str, body: str):
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(body)


def emit_tables(out, defs, unknown):
    by_name = collections.defaultdict(list)
    for d in defs:
        by_name[d.name].append(d)

    conflicts = []
    for name, ds in by_name.items():
        parts = {d.part for d in ds}
        if len(parts) < 2:
            continue
        colsets = {d.part: d.columns for d in ds if d.columns}
        if len(colsets) >= 2 and len(set(colsets.values())) > 1:
            conflicts.append((name, sorted(parts, key=part_sort_key), colsets,
                              any(d.extends for d in ds)))

    lines = [header(
        "REGISTER — TABLES",
        "Every table specified anywhere in the blueprint, its owning part, its\n"
        "cluster where a part states one, and a divergence flag where the same\n"
        "table name is specified in two parts with different column sets.\n\n"
        "**This register is the index. The owning part is the specification.**\n"
        "Where a row and a part disagree, the part wins and this file is stale\n"
        "until the script is re-run.\n\n"
        "Part XXIX names SmartPRS 2's legacy tables in prose but specifies none of\n"
        "them; the eleven `mig_` rows it contributes are the migration tooling's own\n"
        "SmartPRS 3 schema (§XXIX.6.8) and are counted here like any other.")]

    legacy = []
    live = sorted(by_name)

    lines.append("## 1. Counts\n")
    lines.append("| Measure | Count |\n|---|---:|")
    lines.append(f"| Distinct table names | **{len(live)}** |")
    lines.append(f"| Specification sites (a name may be specified in several parts) | {len(defs)} |")
    lines.append(f"| Names specified in more than one part | "
                 f"{sum(1 for n in live if len({d.part for d in by_name[n]}) > 1)} |")
    lines.append(f"| Names flagged: two parts, different columns | **{len(conflicts)}** |")
    lines.append(f"| Headings that look like a table spec but could not be parsed (UNKNOWN) | {len(unknown)} |")
    lines.append("")

    per_part = collections.Counter()
    for n in live:
        for p in {d.part for d in by_name[n]}:
            per_part[p] += 1
    lines.append("### Distinct table names by part\n")
    lines.append("| Part | Tables named here |\n|---|---:|")
    for p in sorted(per_part, key=part_sort_key):
        lines.append(f"| {p} | {per_part[p]} |")
    lines.append("")

    core = collections.Counter()
    derv = collections.Counter()
    for n in live:
        tagged = [d for d in by_name[n] if d.cluster]
        if not tagged:
            continue
        cl = tagged[0].cluster
        (derv if all(d.derivable for d in tagged) else core)[cl] += 1
    clusters = core + derv
    if clusters:
        lines.append("### Part XXVIII clusters, as actually specified\n")
        lines.append("Counted from the specifications themselves. `Core` is a table "
                     "specified in full under a cluster heading; `Derivable` is one "
                     "carried in §XXVIII.6's derivable register under that cluster tag.\n")
        lines.append("| Cluster | Core | Derivable | Total |\n|---|---:|---:|---:|")
        for c in sorted(clusters, key=lambda x: int(x[1:])):
            lines.append(f"| {c} | {core[c]} | {derv[c]} | {core[c] + derv[c]} |")
        lines.append(f"| **Total** | **{sum(core.values())}** | "
                     f"**{sum(derv.values())}** | **{sum(clusters.values())}** |")
        lines.append("")

    lines.append("---\n\n## 2. Divergences — same name, different columns\n")
    if not conflicts:
        lines.append("None found.\n")
    else:
        lines.append("`extension` marks a definition that declares itself an extension of "
                     "another part's table (Appendix A found this to be the usual case).\n")
        lines.append("| Table | Parts | Extension declared | Columns unique to each part |\n|---|---|---|---|")
        for name, parts, colsets, is_ext in sorted(conflicts):
            shared = set.intersection(*[set(v) for v in colsets.values()])
            detail = "; ".join(
                f"{p}: " + (", ".join(f"`{c}`" for c in sorted(set(cs) - shared)[:8]) or "—")
                for p, cs in sorted(colsets.items(), key=lambda kv: part_sort_key(kv[0])))
            lines.append(f"| `{name}` | {', '.join(parts)} | "
                         f"{'yes' if is_ext else '**no**'} | {detail} |")
        lines.append("")

    lines.append("---\n\n## 3. The register\n")
    lines.append("| Table | Owning part(s) | Cluster | Derivable | Sites |\n|---|---|---|---|---|")
    for n in live:
        ds = by_name[n]
        parts = sorted({d.part for d in ds}, key=part_sort_key)
        cl = next((d.cluster for d in ds if d.cluster), "—")
        der = "yes" if all(d.derivable for d in ds) else (
            "partly" if any(d.derivable for d in ds) else "no")
        sites = "; ".join(f"{d.doc}:{d.line}" for d in ds[:4])
        lines.append(f"| `{n}` | {', '.join(parts)} | {cl} | {der} | {sites} |")
    lines.append("")

    if legacy:
        lines.append("---\n\n## 4. Part XXIX — SmartPRS 2 legacy schema (not SmartPRS 3)\n")
        lines.append("| Table | Sites |\n|---|---|")
        for n in legacy:
            lines.append(f"| `{n}` | " +
                         "; ".join(f"{d.doc}:{d.line}" for d in by_name[n][:3]) + " |")
        lines.append("")

    lines.append("---\n\n## 5. UNKNOWN — could not be parsed\n")
    if not unknown:
        lines.append("None.\n")
    else:
        lines.append("Each of these looks like a table specification but does not carry the "
                     "column table, `**Keys.**`, `**Policy.**` or `*Purpose:*` marks the "
                     "extractor recognises. **Counted as unknown, not dropped.**\n")
        lines.append("| Part | File:line | Heading or row |\n|---|---|---|")
        for part, doc, line, text in unknown:
            safe = text[:110].replace("|", r"\|")
            lines.append(f"| {part} | {doc}:{line} | {safe} |")
        lines.append("")

    write(out, "\n".join(lines))
    return {"live": len(live), "legacy": len(legacy), "sites": len(defs),
            "conflicts": len(conflicts), "unknown": len(unknown),
            "clusters": clusters, "core": core, "derivable": derv,
            "conflict_rows": conflicts, "by_name": by_name,
            "per_part": per_part}


def emit_permissions(out, uses, evidence, catalogue, groups, excluded=None):
    used = set(uses)
    missing = sorted(used - catalogue)
    unused = sorted(catalogue - {c for c, p in uses.items() if p - {"XXII"}})

    lines = [header(
        "REGISTER — PERMISSIONS",
        "Every `verb.resource` permission code used anywhere in the blueprint, the\n"
        "parts that use it, and whether Part XXII §XXII.2.2's catalogue contains it.\n\n"
        "A code marked **NOT CATALOGUED** fails the build under R-XXIV-022 (a route\n"
        "must bind to a catalogued permission) until Part XXII carries it.\n\n"
        "*Extraction note.* Shape alone cannot tell a permission from an event code\n"
        "or an SPX path — `payroll.run.created` and `attendance.day.view` are the\n"
        "same shape. Codes are therefore taken only from a permission column of a\n"
        "table, a section headed about permissions, or a sentence naming a\n"
        "permission. The `Evidence` column records which.")]

    lines.append("## 1. Counts\n")
    lines.append("| Measure | Count |\n|---|---:|")
    lines.append(f"| Codes in Part XXII §XXII.2.2's catalogue | {len(catalogue)} |")
    lines.append(f"| Distinct codes used across the corpus | **{len(used)}** |")
    lines.append(f"| Used but **not catalogued** | **{len(missing)}** |")
    lines.append(f"| Catalogued but used by no other part | {len(unused)} |")
    lines.append("")

    mod = collections.Counter(c.split(".")[0] for c in missing)
    lines.append("### Uncatalogued codes by module\n")
    lines.append("| Module | Missing codes |\n|---|---:|")
    for m in sorted(mod):
        lines.append(f"| `{m}.*` | {mod[m]} |")
    lines.append(f"| **Total** | **{len(missing)}** |")
    lines.append("")

    lines.append("---\n\n## 2. Used but not catalogued\n")
    lines.append("| Permission | Used by | Evidence |\n|---|---|---|")
    for c in missing:
        lines.append(f"| `{c}` | {', '.join(sorted(uses[c], key=part_sort_key))} | "
                     f"{', '.join(sorted(evidence[c]))} |")
    lines.append("")

    lines.append("---\n\n## 3. The full register\n")
    lines.append("| Permission | Catalogued | Catalogue group | Used by |\n|---|---|---|---|")
    for c in sorted(used):
        lines.append(f"| `{c}` | {'yes' if c in catalogue else '**NO**'} | "
                     f"{groups.get(c, '—')} | "
                     f"{', '.join(sorted(uses[c], key=part_sort_key))} |")
    lines.append("")

    if unused:
        lines.append("---\n\n## 4. Catalogued, used by no part outside Part XXII\n")
        lines.append("Not a defect — a catalogue may lead the modules — but it is the "
                     "set no route currently binds.\n")
        lines.append("| Permission | Group |\n|---|---|")
        for c in unused:
            lines.append(f"| `{c}` | {groups.get(c, '—')} |")
        lines.append("")

    if excluded:
        lines.append("---\n\n## 5. Excluded — named as a counter-example, not declared\n")
        lines.append(
            "A sentence can name a code in order to say it is **not** a permission "
            "— R-XXII-013 names `payroll.run.approve.hyderabad` as the string the "
            "catalogue validator must reject. Read as a declaration, such a "
            "sentence puts in this register the very code the corpus says must "
            "never exist. These occurrences were skipped. The exclusion is listed "
            "rather than silent so a wrong skip is visible; **still used** means "
            "the code is declared elsewhere and remains in the register above.\n")
        lines.append("| Code | Part | Still used | Sentence that excluded it |\n|---|---|---|---|")
        for c in sorted(excluded):
            for part, sentence in sorted(excluded[c]):
                if len(sentence) > 300:
                    sentence = sentence[:297] + "…"
                sentence = sentence.replace("|", r"\|")
                lines.append(f"| `{c}` | {part} | "
                             f"{'yes' if c in uses else '**no**'} | "
                             f"{sentence} |")
        lines.append("")

    write(out, "\n".join(lines))
    return {"catalogue": len(catalogue), "used": len(used),
            "missing": missing, "unused": len(unused), "by_module": mod}


def emit_decisions(out, seen, question, define_site, xxxiv):
    ids = sorted(seen, key=lambda i: (part_sort_key(decision_part(i) if "-" in i[2:] else "SPINE"), i))
    orphans = [i for i in ids if i not in xxxiv]
    # UNKNOWN = no one-line question could be recovered from either the part that
    # raises the decision or Part XXXIV's register.
    undefined = [i for i in ids
                 if not question.get(i) and not xxxiv.get(i, ("", ""))[1]]

    lines = [header(
        "REGISTER — DECISIONS",
        "Every Product Owner decision identifier raised anywhere in the blueprint,\n"
        "the part that raises it, its one-line question, and the tier Part XXXIV\n"
        "assigns it. A row marked **NOT IN XXXIV** reaches no queue.\n\n"
        "Four identifier conventions coexist (Appendix A R-12): `D-nn` (spine),\n"
        "`D-<ROMAN>-nn`, `D-<arabic>-nn` (Part XXXI only) and `PO-<ROMAN>-nn`\n"
        "(Parts XIX and XXIII). `D-31-nn` is normalised onto Part XXXI here.")]

    lines.append("## 1. Counts\n")
    lines.append("| Measure | Count |\n|---|---:|")
    lines.append(f"| Distinct decision identifiers in the corpus | **{len(ids)}** |")
    lines.append(f"| Rows in Part XXXIV §XXXIV.6's register | {len(xxxiv)} |")
    lines.append(f"| Raised but **absent from Part XXXIV** | **{len(orphans)}** |")
    lines.append(f"| No definition site parsed — question UNKNOWN | {len(undefined)} |")
    lines.append("")

    per = collections.Counter(decision_part(i) if i.count("-") == 2 else "SPINE" for i in ids)
    lines.append("### By part\n")
    lines.append("| Part | Decisions | In XXXIV | Missing |\n|---|---:|---:|---:|")
    for p in sorted(per, key=part_sort_key):
        pids = [i for i in ids if (decision_part(i) if i.count("-") == 2 else "SPINE") == p]
        inreg = sum(1 for i in pids if i in xxxiv)
        lines.append(f"| {p} | {len(pids)} | {inreg} | "
                     f"{'**' + str(len(pids) - inreg) + '**' if len(pids) - inreg else '0'} |")
    lines.append("")

    if orphans:
        lines.append("---\n\n## 2. Raised but not registered in Part XXXIV\n")
        lines.append("| ID | Part | Question | Raised in |\n|---|---|---|---|")
        for i in orphans:
            p = decision_part(i) if i.count("-") == 2 else "SPINE"
            site = define_site.get(i, ("—", 0))[0]
            lines.append(f"| `{i}` | {p} | {question.get(i, '**UNKNOWN — not parsed**')} | {site} |")
        lines.append("")

    lines.append("---\n\n## 3. The full register\n")
    lines.append("| ID | Part | Question (one line) | Tier (Part XXXIV) | In XXXIV |\n|---|---|---|---|---|")
    for i in ids:
        p = decision_part(i) if i.count("-") == 2 else "SPINE"
        tier = xxxiv.get(i, ("—", ""))[0]
        q = question.get(i) or (xxxiv.get(i, ("", ""))[1]) or "**UNKNOWN — not parsed**"
        lines.append(f"| `{i}` | {p} | {q} | {tier} | {'yes' if i in xxxiv else '**NO**'} |")
    lines.append("")

    if undefined:
        lines.append("---\n\n## 4. UNKNOWN — identifier used, definition site not parsed\n")
        lines.append("No one-line question could be recovered for these identifiers from "
                     "either the part that raises them or Part XXXIV's register. "
                     "**Counted as unknown, not dropped.**\n")
        lines.append("| ID | Appears in |\n|---|---|")
        for i in undefined:
            lines.append(f"| `{i}` | {', '.join(sorted(seen[i], key=part_sort_key))} |")
        lines.append("")

    write(out, "\n".join(lines))
    return {"total": len(ids), "xxxiv": len(xxxiv), "orphans": orphans,
            "undefined": undefined, "per_part": per}


def emit_requirements(out, definitions, citations):
    per_part = collections.defaultdict(list)
    for i in definitions:
        per_part[req_part(i)].append(i)
    dups = {i: sites for i, sites in definitions.items() if len(sites) > 1}
    undefined = sorted(i for i in citations if i not in definitions)

    lines = [header(
        "REGISTER — REQUIREMENTS",
        "Every requirement identifier, by the part that owns its prefix, with counts,\n"
        "gaps, and any identifier defined more than once inside its owning part.\n\n"
        "A *definition* is the identifier opening a line, a heading or a table's first\n"
        "cell **inside the part that owns the prefix**. The same identifier elsewhere\n"
        "is a citation. An identifier cited but never defined in its owning part is a\n"
        "dangling reference and is listed as UNKNOWN.")]

    total = len(definitions)
    lines.append("## 1. Counts\n")
    lines.append("| Measure | Count |\n|---|---:|")
    lines.append(f"| Distinct requirement identifiers defined | **{total}** |")
    lines.append(f"| Definition sites (a duplicate counts twice) | "
                 f"{sum(len(v) for v in definitions.values())} |")
    lines.append(f"| Identifiers defined twice or more in their own part | **{len(dups)}** |")
    lines.append(f"| Cited but never defined (UNKNOWN / dangling) | {len(undefined)} |")
    lines.append("")

    lines.append("---\n\n## 2. By part\n")
    lines.append("| Prefix | Defined | Range | Gaps in range | Duplicates |\n|---|---:|---|---:|---:|")
    for p in sorted(per_part, key=part_sort_key):
        nums = sorted(int(i.rsplit("-", 1)[1]) for i in per_part[p])
        gaps = (nums[-1] - nums[0] + 1) - len(set(nums))
        d = sum(1 for i in per_part[p] if i in dups)
        lines.append(f"| `R-{p}-` | {len(per_part[p])} | {nums[0]}–{nums[-1]} | {gaps} | "
                     f"{'**' + str(d) + '**' if d else '0'} |")
    lines.append(f"| **Total** | **{total}** | | | **{len(dups)}** |")
    lines.append("")

    lines.append("---\n\n## 3. Duplicate identifiers within a part\n")
    if not dups:
        lines.append("None.\n")
    else:
        lines.append("Each of these identifiers opens two or more definitions in its own "
                     "part. Appendix A R-07 established that some are genuine "
                     "double-definitions and some are a bolded restatement; the script "
                     "reports the sites and does not judge.\n")
        lines.append("| ID | Sites |\n|---|---|")
        for i in sorted(dups, key=lambda x: (part_sort_key(req_part(x)), x)):
            lines.append(f"| `{i}` | " +
                         "; ".join(f"{d}:{l}" for d, l in dups[i]) + " |")
        lines.append("")

    lines.append("---\n\n## 4. The register\n")
    for p in sorted(per_part, key=part_sort_key):
        ids = sorted(per_part[p], key=lambda x: int(x.rsplit("-", 1)[1]))
        lines.append(f"**`R-{p}-`** — {len(ids)} identifiers\n")
        lines.append("> " + " · ".join(f"`{i}`" for i in ids) + "\n")

    lines.append("---\n\n## 5. UNKNOWN — cited but never defined in the owning part\n")
    if not undefined:
        lines.append("None.\n")
    else:
        lines.append("| ID | Cited in |\n|---|---|")
        for i in undefined:
            lines.append(f"| `{i}` | {', '.join(sorted(citations[i], key=part_sort_key))} |")
        lines.append("")

    write(out, "\n".join(lines))
    return {"total": total, "dups": dups, "undefined": undefined,
            "per_part": {p: len(v) for p, v in per_part.items()}}


# --------------------------------------------------------------------------

# --------------------------------------------------------------------------
# Self-check
# --------------------------------------------------------------------------

SELFTEST_DOC = """# PART XL - SELFTEST

## XL.1 Tables

##### `alpha_rows` - A1, **platform-owned**
| Column | Type | N | Notes |
|---|---|---|---|
| `id` | BIGINT | N | PK |
| `alpha_code` | VARCHAR(40) | N | |
**Keys.** PK `id`.

##### `beta_rows` - A1 (+ `beta_lines`, `beta_notes` A3, derivable)
`id, tenant_id, beta_code varchar(40), created_at`.
Unique `(tenant_id, beta_code)`. Audit: full. PII: none.

##### `gamma_rows` - A3 (parent `delta_rows`, a config payload)
*Purpose:* the child. PK `id`.

### XL.1.9 `epsilon_rows` - **the target of `epsilon_ref`**
| Column | Type |
|---|---|
| `id` | BIGINT |

#### `employee.*`
Not a table - an SPX namespace, and no specification follows.

**`zeta_rows`** - the bold-paragraph style.

| Column | Type |
|---|---|
| `id` | BIGINT |

### XL.1.10 Derivable register

| Table | Ctx | Archetype |
|---|---|---|
| `eta_rows`, `theta_rows` | C1 | A4 |

## XL.2 Permissions

| Route | Permission |
|---|---|
| POST /a | `alpha.thing.create` |

The `beta.thing.update` permission gates the update.

A permission MUST NOT encode a record set. `gamma.thing.delete.mumbai` is a
schema error, rejected by the catalogue validator, and is not a permission.

| Event | Payload |
|---|---|
| `alpha.thing.created` | not a permission |

## XL.3 Requirements

**R-XL-001** The first requirement.
**R-XL-002** The second requirement.
**R-XL-001** A duplicated identifier.

## XL.4 Decisions

**PRODUCT OWNER DECISION REQUIRED - D-XL-01: does the wrapped question
survive the line break?**

| ID | Question |
|---|---|
| **D-XL-02** | The tabular decision |
"""


def selftest() -> int:
    """One runnable check over a synthetic part exercising every parse shape."""
    import tempfile

    with tempfile.TemporaryDirectory() as tmp:
        path = os.path.join(tmp, "40-PART-XL-selftest.md")
        with open(path, "w", encoding="utf-8") as fh:
            fh.write(SELFTEST_DOC)
        corpus = load_corpus(tmp)
        assert corpus[0].part == "XL", corpus[0].part

        defs, unknown = extract_tables(corpus)
        names = {d.name for d in defs}
        expected = {"alpha_rows", "beta_rows", "beta_lines", "beta_notes",
                    "gamma_rows", "epsilon_rows", "zeta_rows",
                    "eta_rows", "theta_rows"}
        assert names == expected, sorted(names ^ expected)
        derivable = {d.name for d in defs if d.derivable}
        assert derivable == {"beta_lines", "beta_notes", "eta_rows", "theta_rows"}, derivable
        assert {d.cluster for d in defs if d.name == "eta_rows"} == {"C1"}
        cols = next(d.columns for d in defs if d.name == "alpha_rows")
        assert cols == frozenset({"id", "alpha_code"}), cols
        cols = next(d.columns for d in defs if d.name == "beta_rows")
        assert "beta_code" in cols and "tenant_id" in cols, cols

        uses, _, excl = extract_permissions(corpus, set(), frozenset(names))
        assert set(uses) == {"alpha.thing.create", "beta.thing.update"}, sorted(uses)
        # The counter-example is named, not declared -- and the marker sits on the
        # line after the code, so a line-at-a-time reader would miss it.
        assert set(excl) == {"gamma.thing.delete.mumbai"}, sorted(excl)

        rdefs, _ = extract_requirements(corpus)
        assert set(rdefs) == {"R-XL-001", "R-XL-002"}, sorted(rdefs)
        assert len(rdefs["R-XL-001"]) == 2, rdefs["R-XL-001"]

        seen, question, _ = extract_decisions(corpus)
        assert set(seen) == {"D-XL-01", "D-XL-02"}, sorted(seen)
        assert question["D-XL-01"].endswith("line break?"), question["D-XL-01"]
        assert question["D-XL-02"] == "The tabular decision", question["D-XL-02"]

    print("selftest: OK")
    return 0


def main() -> int:
    here = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--docs", default=os.path.join(here, "docs"))
    ap.add_argument("--out", default=os.path.join(here, "docs", "registers"))
    ap.add_argument("--selftest", action="store_true",
                    help="parse a synthetic part covering every shape and assert")
    args = ap.parse_args()

    if args.selftest:
        return selftest()

    corpus = load_corpus(args.docs)
    corpus = [d for d in corpus if not d.name.startswith("REGISTER-")]
    os.makedirs(args.out, exist_ok=True)

    tdefs, tunknown = extract_tables(corpus)
    t = emit_tables(os.path.join(args.out, "REGISTER-TABLES.md"), tdefs, tunknown)

    catalogue, groups = extract_catalogue(corpus)
    table_names = frozenset(d.name for d in tdefs)
    uses, evidence, excluded = extract_permissions(corpus, catalogue, table_names)
    p = emit_permissions(os.path.join(args.out, "REGISTER-PERMISSIONS.md"),
                         uses, evidence, catalogue, groups, excluded)

    seen, question, sites = extract_decisions(corpus)
    xxxiv = extract_xxxiv_register(corpus)
    d = emit_decisions(os.path.join(args.out, "REGISTER-DECISIONS.md"),
                       seen, question, sites, xxxiv)

    defs, cites = extract_requirements(corpus)
    r = emit_requirements(os.path.join(args.out, "REGISTER-REQUIREMENTS.md"),
                          defs, cites)

    print(f"Parsed {len(corpus)} documents in {args.docs}\n")
    print("TABLES")
    print(f"  distinct table names                    {t['live']}")
    print(f"  specification sites                    {t['sites']}")
    print(f"  same name, different columns           {t['conflicts']}")
    print(f"  UNKNOWN (looks like a spec, unparsed)  {t['unknown']}")
    print("  clusters (core+derivable): " + ", ".join(
        f"{c}={t['core'][c]}+{t['derivable'][c]}"
        for c in sorted(t['clusters'], key=lambda x: int(x[1:]))))
    print(f"  cluster totals: core={sum(t['core'].values())} "
          f"derivable={sum(t['derivable'].values())}")
    print("\nPERMISSIONS")
    print(f"  Part XXII catalogue                    {p['catalogue']}")
    print(f"  distinct codes used                    {p['used']}")
    print(f"  used but NOT catalogued                {len(p['missing'])}")
    print(f"  catalogued, unused outside Part XXII   {p['unused']}")
    print("  missing by module: " + ", ".join(
        f"{k}={v}" for k, v in sorted(p['by_module'].items())))
    print("\nDECISIONS")
    print(f"  distinct identifiers                   {d['total']}")
    print(f"  rows in Part XXXIV                     {d['xxxiv']}")
    print(f"  raised but NOT in Part XXXIV           {len(d['orphans'])}")
    print(f"  question UNKNOWN                       {len(d['undefined'])}")
    if d["orphans"]:
        print("  orphans: " + ", ".join(d["orphans"]))
    print("\nREQUIREMENTS")
    print(f"  distinct identifiers defined           {r['total']}")
    print(f"  duplicate ids within a part            {len(r['dups'])}")
    print(f"  cited but never defined                {len(r['undefined'])}")
    if r["dups"]:
        print("  duplicates: " + ", ".join(sorted(r["dups"])))
    print(f"\nWrote 4 registers to {args.out}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
