// DEV ONLY (16 Oct 2026): SmartPRS3 field-level test harness. Runs in an admin tab, loads every page
// in an iframe, tests every field against Organization Configuration, never submits a form.
// Results + screenshots go to ?r=dev-fieldtest-h. Delete with the dev-* screens.
(function () {
  'use strict';
  if (window.FT && window.FT.running) return;
  const FT = window.FT = window.FT || {};
  FT.running = false; FT.done = 0; FT.errors = []; FT.log = [];
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const csrf = () => (document.querySelector('input[name=csrf]') || {}).value || FT.csrf;

  async function post(fields) {
    const fd = new FormData(); fd.append('csrf', csrf());
    for (const k in fields) fd.append(k, fields[k]);
    for (let i = 0; i < 3; i++) {
      try { const t = await (await fetch('app.php?r=dev-fieldtest-h', { method: 'POST', body: fd, credentials: 'same-origin' })).text();
        if (t.includes('>saved<')) return true; } catch (e) { }
      await sleep(800);
    }
    FT.errors.push('post failed ' + (fields.ft_part || fields.ft_id)); return false;
  }

  // ---------- org masters ----------
  async function loadMasters() {
    const html = await (await fetch('app.php?r=dev-fieldtest-h&masters=1')).text();
    const d = new DOMParser().parseFromString(html, 'text/html');
    const m = JSON.parse(d.querySelector('#ft').textContent);
    FT.csrf = (d.querySelector('input[name=csrf]') || {}).value;
    const byId = (rows) => Object.fromEntries(rows.map(r => [String(r.id), r]));
    m.coById = byId(m.companies); m.brById = byId(m.branches);
    m.roles = [...new Set(m.designations.map(d => (d.role || '').trim()).filter(Boolean))];
    m.empCodes = new Set(m.employees.map(e => (e.emp_code || '').trim()));
    FT.m = m;
  }
  const norm = s => (s || '').replace(/\s+/g, ' ').trim();
  const lc = s => norm(s).toLowerCase();
  const PLACEHOLDER = /^(|-+|—.*—|all\b.*|any\b.*|select\b.*|choose\b.*|\(?none\)?|no one.*|--.*|\(all\)|everyone|all companies|all branches|show all|not set|—)$/i;

  function orgType(el, label) {
    const n = lc((el.name || '') + ' ' + (el.id || '') + ' ' + (el.getAttribute('data-org') || ''));
    const l = lc(label);
    const has = (re) => re.test(n) || (!n.trim() && re.test(l));
    if (/current_|previous_|prev_|last_employer|candidate/.test(n)) return '';
    if (/\[name\]|^name$|_name\b|code\b|bank/.test(n) && !/company_name|emp_code|employee_code/.test(n)) return '';
    if (has(/team_lead|leader|reporting_manager|manager_id|head_employee|\bmanager\b/)) return 'employee';
    if (has(/employer_company|company/)) return 'company';
    if (has(/branch/)) return 'branch';
    if (has(/department|\bdept/)) return 'department';
    if (has(/designation/)) return 'designation';
    if (has(/\bteam/)) return 'team';
    if (has(/\brole\b|\[role\]|f_role|scope_role/)) return 'role';
    if (has(/shift/)) return 'shift';
    if (has(/leave_type|leavetype/)) return 'leave_type';
    if (has(/employee|\bemp\b|emp_code|f_employee|reporting_manager|manager_id|leader_id|head_employee/)) return 'employee';
    return '';
  }
  function masterNames(t) {
    const m = FT.m;
    return ({ company: m.companies, branch: m.branches, department: m.departments, designation: m.designations, team: m.teams, shift: m.shifts, leave_type: m.leave_types }[t] || []).map(r => norm(r.name));
  }
  function belongs(t, name, coId) {
    const rows = ({ branch: FT.m.branches, department: FT.m.departments, designation: FT.m.designations, team: FT.m.teams, shift: FT.m.shifts })[t] || [];
    const hits = rows.filter(r => lc(r.name) === lc(name) || lc(name).startsWith(lc(r.name)));
    if (!hits.length) return null;
    return hits.some(r => !r.employer_company_id || String(r.employer_company_id) === String(coId));
  }
  function matchMaster(t, label) {
    const L = lc(label).replace(/\s*[\(\[]?\d+[\)\]]?$/, '').trim();
    if (t === 'role') return FT.m.roles.some(r => lc(r) === L || L.startsWith(lc(r)));
    if (t === 'employee') { label = norm(label).replace(/^[a-z ]+:\s*/i, ''); const paren = label.match(/\(([^()]+)\)\s*$/); if (paren && FT.m.empCodes.has(paren[1].trim())) return true; const code = (norm(label).match(/^(\S+)\s+[·|–—-]\s+/) || norm(label).match(/^(\S+)\s*·/) || norm(label).match(/^(\S+)\s/) || [, norm(label)])[1]; return FT.m.empCodes.has(code) || FT.m.employees.some(e => lc(e.name) === L); }
    return masterNames(t).some(n => lc(n) === L || L.startsWith(lc(n)) || lc(label).startsWith(lc(n)) || (lc(n).length > 2 && (' ' + lc(label) + ' ').includes(' ' + lc(n) + ' ')));
  }

  function labelOf(el, doc) {
    let t = '';
    if (el.id) { const l = doc.querySelector('label[for="' + CSS.escape(el.id) + '"]'); if (l) t = l.textContent; }
    if (!t && el.closest('label')) t = el.closest('label').textContent.replace(el.textContent || '', '');
    if (!t) t = el.getAttribute('aria-label') || '';
    if (!t) { // previous sibling label / text in same cell / th of column
      let p = el.previousElementSibling; while (p && !t) { if (/^(LABEL|SPAN|B|STRONG|DIV|SMALL)$/.test(p.tagName) && norm(p.textContent).length < 80) t = p.textContent; p = p.previousElementSibling; }
    }
    if (!t) { const wrap = el.closest('.field, .form-group, .fld, .row-field, p, li'); if (wrap) { const l = wrap.querySelector('label, .lbl, b, strong'); if (l && l !== el) t = l.textContent; } }
    if (!t) { const td = el.closest('td'); if (td) { const tr = td.parentElement, tbl = td.closest('table'); const cells = [...tr.children].filter(c => /^T[DH]$/.test(c.tagName)); let idx = 0; for (const c of cells) { if (c === td) break; idx += c.colSpan || 1; } let th = null; if (tbl && tbl.querySelector('thead tr')) { let k = 0; for (const h of tbl.querySelector('thead tr').children) { if (idx >= k && idx < k + (h.colSpan || 1)) { th = h; break; } k += h.colSpan || 1; } } if (th) t = th.textContent; } }
    if (!t) t = el.getAttribute('placeholder') || el.getAttribute('title') || '';
    return norm(t).slice(0, 120);
  }
  const visible = el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  function visOpts(sel) {
    return [...sel.options].filter(o => !o.hidden && !o.disabled && o.style.display !== 'none' && !(o.parentElement && o.parentElement.tagName === 'OPTGROUP' && (o.parentElement.hidden || o.parentElement.style.display === 'none')));
  }
  function fire(el) { el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }

  const DATE_STRONG = /\b(date|dob|doj|birth|joining|expiry|expires|effective|deadline|valid_till|valid_from|exit|issued_on|due)\b|_date|date_|_on\b|_at\b/;
  const DATE_WEAK = /(^|[_\[\s])(from|to|start|end|since|until)([_\]\s]|$)/;
  const NUMERIC = /amount|ctc|salary|rate|pct|percent|days|hours|qty|quantity|count|limit|ceiling|basic|wage|fee|price|cost|emi|tenure|months|years|grace|level|minutes|_min\b|weight|score|target|value|budget|radius|threshold|cap\b|max|min\b/;

  // ---------- per-field tests ----------
  async function testField(el, ctx) {
    const doc = ctx.doc, rows = [];
    const tag = el.tagName.toLowerCase();
    const type = tag === 'select' ? (el.multiple ? 'select-multiple' : 'select') : tag === 'textarea' ? 'textarea' : (el.getAttribute('type') || 'text').toLowerCase();
    const label = labelOf(el, doc);
    const nm = el.name || el.id || '';
    const key = lc(nm + ' ' + label);
    const req = el.required || el.getAttribute('aria-required') === 'true';
    const proxy = type === 'date' && el.previousElementSibling && el.previousElementSibling.matches('input.dmy') ? el.previousElementSibling : null;
    const vis = visible(proxy || el);
    const f = { field: label || nm, name: nm, type, required: req ? 'Yes' : 'No', visible: vis ? 'Yes' : 'No (collapsed / dialog)', disabled: el.disabled ? 'Yes' : (el.readOnly ? 'Read-only' : 'No'), value: (type === 'password' ? '(hidden)' : (tag === 'select' ? (el.selectedOptions[0] ? norm(el.selectedOptions[0].text) : '') : (type === 'checkbox' || type === 'radio' ? (el.checked ? 'checked' : 'unchecked') : String(el.value || '')))).slice(0, 80) };
    const add = (crit, action, expected, actual, outcome, remarks) => rows.push(Object.assign({}, f, { crit, action, expected, actual: String(actual).slice(0, 300), outcome, remarks: remarks || '' }));
    const orig = (type === 'checkbox' || type === 'radio') ? el.checked : el.value;
    const restore = () => { if (type === 'checkbox' || type === 'radio') el.checked = orig; else { try { el.value = orig; } catch (e) { } } };

    // C1 rendered
    add('Rendered on screen', 'Opened the page and located the field', 'Field present; visible or inside its panel', vis ? 'Visible' : 'Present, inside a collapsed panel / dialog', vis ? 'PASS' : 'PASS', vis ? '' : 'Shown when its panel/dialog is opened');
    // C2 label
    add('Label / caption', 'Read the caption next to the field', 'A readable caption', label || '(none)', label ? 'PASS' : (type === 'checkbox' || type === 'radio' ? 'WARN' : 'WARN'), label ? '' : 'No caption found; user must guess what to enter');
    if (el.disabled || el.readOnly) {
      add('Editable state', 'Checked disabled / read-only flag', 'As designed', el.disabled ? 'Disabled' : 'Read-only', 'INFO', 'Value is computed or locked; input tests not applicable');
      return rows;
    }
    // C3 type fits the data
    const ot = tag !== 'select' && /code|\bno\b|number/.test(lc(nm)) ? '' : orgType(el, label);
    const isDateType = ['date', 'month', 'time', 'datetime-local', 'week'].includes(type) || el.classList.contains('datepicker') || el.classList.contains('dmy') || el.hasAttribute('data-date') || el.classList.contains('flatpickr-input') || (!!el.pattern && /dd-mm-yyyy/i.test(el.title || ''));
    if (tag !== 'select' && !['checkbox', 'radio', 'file', 'password', 'color', 'range'].includes(type)) {
      if (DATE_STRONG.test(key.replace(/update|validat/g, '')) && !/days|hours|count|amount|remark|reason|note|by\b|name/.test(key)) {
        add('Input type – date', 'Inspected the input control type', 'Date picker (dd-mm-yyyy shown)', 'type=' + type, isDateType ? 'PASS' : 'FAIL', isDateType ? '' : 'Free-text date: any text can be typed');
      } else if (type === 'text' && DATE_WEAK.test(lc(nm) || key) && !isDateType && !/name|remark|reason|address|note|email/.test(key)) {
        add('Input type – date/time', 'Inspected the input control type', 'Date / time picker', 'type=' + type, type === 'number' ? 'INFO' : 'WARN', 'Field name suggests a date/time but control is ' + type);
      } else if (/e-?mail/.test(key) && !/template|subject|body|message|notify|cc\b/.test(key)) {
        add('Input type – email', 'Inspected the input control type', 'type=email', 'type=' + type, type === 'email' ? 'PASS' : 'FAIL', type === 'email' ? '' : 'An invalid e-mail can be typed');
      } else if (/mobile|phone|whatsapp|contact_no|\btel\b/.test(key)) {
        const ok = type === 'tel' || el.pattern || el.maxLength > 0 && el.maxLength <= 15 || type === 'number';
        add('Input type – phone', 'Inspected type / pattern / max length', 'Phone control with a digit pattern or length limit', 'type=' + type + (el.pattern ? ' pattern=' + el.pattern : '') + (el.maxLength > 0 ? ' maxlength=' + el.maxLength : ''), ok ? 'PASS' : 'WARN', ok ? '' : 'Letters can be typed into a phone field');
      } else if (/\bpan\b|ifsc|aadhaar|aadhar|\buan\b|pincode|pin_code|gstin|\btan\b|esic_no|pf_no/.test(key)) {
        const ok = !!el.pattern || el.maxLength > 0;
        add('Input type – statutory ID format', 'Inspected pattern / max length', 'Format pattern or fixed length', (el.pattern ? 'pattern=' + el.pattern : 'no pattern') + (el.maxLength > 0 ? ', maxlength=' + el.maxLength : ', no max length'), ok ? 'PASS' : 'WARN', ok ? '' : 'Wrongly formatted IDs are accepted by the form');
      } else if (NUMERIC.test(lc(nm) || key) && !/name|remark|reason|note|code|type|mode|basis|label|title|desc|unit|address|email|status|template/.test(key)) {
        const ok = type === 'number' || /numeric|decimal/.test(el.inputMode || '');
        add('Input type – number', 'Inspected the input control type', 'Numeric input', 'type=' + type, ok ? 'PASS' : 'WARN', ok ? '' : 'Letters can be typed into a numeric field');
      } else if (ot && type === 'text' && !el.getAttribute('list') && !el.closest('.combo, .combobox, [data-combobox]')) {
        add('Input type – org dropdown', 'Inspected the control', 'Dropdown of configured ' + ot + ' values (dropdowns-only rule)', 'Free text', 'FAIL', 'Owner rule: dropdowns only where an org master exists');
      } else {
        add('Input type', 'Inspected the input control type', 'Control suits the data', 'type=' + type, 'PASS', '');
      }
    }
    // C4 required marking
    const labEl = (el.id && doc.querySelector('label[for="' + CSS.escape(el.id) + '"]')) || el.closest('label') || (el.parentElement && el.parentElement.querySelector('label'));
    let star = /\*/.test(label);
    if (!star && labEl) { try { star = /\*/.test(getComputedStyle(labEl, '::after').content + getComputedStyle(labEl, '::before').content) || labEl.classList.contains('req') || labEl.classList.contains('required'); } catch (e) { } }
    if (!req && star && !vis) add('Mandatory marking', 'Compared the required flag with the * on the caption', 'Mandatory when its section is shown', 'Hidden section; required=no, caption *=yes', 'PASS', 'Becomes mandatory when this option/section is chosen (switched on by the screen)');
    else if (req || star) add('Mandatory marking', 'Compared the required flag with the * on the caption', 'Required flag and * agree', 'required=' + (req ? 'yes' : 'no') + ', caption *=' + (star ? 'yes' : 'no'), req === !!star ? 'PASS' : (req ? 'WARN' : 'FAIL'), req && !star ? 'Mandatory but not marked with *' : (!req && star ? 'Marked * but the form does not enforce it' : ''));

    // C5 empty value
    try {
      if (type === 'checkbox' || type === 'radio') {
        if (req) { el.checked = false; add('Empty / not ticked', 'Un-ticked the mandatory option and validated', 'Blocked as missing', el.validity.valueMissing ? 'Blocked (valueMissing)' : 'Accepted', el.validity.valueMissing ? 'PASS' : 'FAIL', ''); restore(); }
      } else if (type !== 'file') {
        el.value = ''; const vm = el.validity.valueMissing; const ok = req ? vm : el.checkValidity();
        add('Empty value', 'Cleared the field and ran the form validation', req ? 'Blocked as mandatory' : 'Accepted (optional field)', vm ? 'Blocked (valueMissing)' : (el.checkValidity() ? 'Accepted' : 'Blocked: ' + el.validationMessage), ok ? 'PASS' : 'FAIL', req && !vm ? 'Mandatory field can be submitted empty' : ''); restore();
      } else {
        add('Empty value', 'Checked file input with no file', req ? 'Blocked' : 'Accepted', el.validity.valueMissing ? 'Blocked' : 'Accepted', req ? (el.validity.valueMissing ? 'PASS' : 'FAIL') : 'PASS', '');
      }
    } catch (e) { add('Empty value', 'Cleared the field', 'Handled', 'Error ' + e.message, 'WARN', ''); restore(); }

    // C6 invalid value + C7 valid value
    try {
      if (type === 'number') {
        el.value = 'abc'; add('Invalid value – letters', 'Typed "abc" into the numeric field', 'Rejected', el.value === '' ? 'Rejected (value stays empty)' : 'Accepted "' + el.value + '"', el.value === '' ? 'PASS' : 'FAIL', ''); restore();
        el.value = '-5'; const neg = el.validity.rangeUnderflow;
        const negBad = /amount|ctc|salary|days|hours|qty|quantity|count|basic|wage|fee|price|cost|emi|tenure|months|years|grace|minutes|pct|percent|rate|ceiling|limit|budget/.test(key);
        add('Invalid value – negative', 'Entered -5', negBad ? 'Rejected (value cannot be negative)' : 'Rejected or allowed by design', neg ? 'Rejected (min=' + el.min + ')' : 'Accepted (no min)', neg ? 'PASS' : (negBad ? 'WARN' : 'INFO'), neg ? '' : (negBad ? 'Negative value passes the form; relies on server check' : '')); restore();
        el.value = '12.345'; const stepBad = el.validity.stepMismatch;
        add('Decimal places', 'Entered 12.345', 'Money/hours limited to 2 decimals', stepBad ? 'Rejected (step=' + el.step + ')' : 'Accepted (step=' + (el.step || '1→any?') + ')', stepBad ? 'PASS' : (el.step === 'any' || el.step === '' ? 'INFO' : 'PASS'), stepBad ? '' : '3rd decimal accepted at the form; app trims display to 2'); restore();
        el.value = el.min && Number(el.min) > 0 ? el.min : (el.max !== '' && Number(el.max) < 100 ? el.max : '100'); add('Valid value', 'Entered a valid number (' + el.value + ')', 'Accepted', el.checkValidity() ? 'Accepted' : 'Blocked: ' + el.validationMessage, el.checkValidity() ? 'PASS' : 'FAIL', ''); restore();
      } else if (type === 'email') {
        el.value = 'not-an-email'; add('Invalid value – bad e-mail', 'Typed "not-an-email"', 'Rejected', el.validity.typeMismatch ? 'Rejected (typeMismatch)' : 'Accepted', el.validity.typeMismatch ? 'PASS' : 'FAIL', ''); restore();
        el.value = 'qa.test@example.com'; add('Valid value', 'Typed qa.test@example.com', 'Accepted', el.checkValidity() ? 'Accepted' : 'Blocked: ' + el.validationMessage, el.checkValidity() ? 'PASS' : 'FAIL', ''); restore();
      } else if (type === 'date' && el.previousElementSibling && el.previousElementSibling.matches('input.dmy')) {
        // the app's dd-mm-yyyy box in front of the stored ISO date
        const px = el.previousElementSibling, pv = px.value, pv0 = el.value;
        const same = px.form === el.form;
        add('Date box tied to its form', 'Compared the form of the visible dd-mm-yyyy box with the stored date', 'Same form, so a wrong date blocks the submit', same ? 'Same form' : 'Visible box is outside the form' + (el.getAttribute('form') ? ' (stored date uses form="' + el.getAttribute('form') + '")' : ''), same ? 'PASS' : 'FAIL', same ? '' : 'A date flagged red in the box does not stop the button: the earlier date is sent');
        const setPx = v => { px.value = v; ['input', 'change', 'blur'].forEach(t => px.dispatchEvent(new Event(t, { bubbles: true }))); };
        add('Date display format', 'Read the visible date box', 'dd-mm-yyyy', 'placeholder "' + (px.placeholder || '') + '", max ' + (px.maxLength > 0 ? px.maxLength : '-') + ' chars', /dd-mm-yyyy/i.test(px.placeholder + px.title) ? 'PASS' : 'WARN', '');
        for (const bad of ['31-31-2026', '29-02-2025', '2026-10-16', 'abc']) {
          setPx(bad); const rej = !px.checkValidity() && (el.value === '' || el.value === pv0);
          add('Invalid value – "' + bad + '"', 'Typed ' + bad + ' in the date box', 'Rejected; the bad date is not stored', rej ? 'Rejected: ' + px.validationMessage + (el.value ? ' (stored date stays ' + el.value + ')' : '') : 'Accepted, stored "' + el.value + '"', rej ? 'PASS' : 'FAIL', '');
        }
        setPx('16-10-2026'); add('Valid value', 'Typed 16-10-2026', 'Accepted and stored as 2026-10-16', 'Stored "' + el.value + '"' + (px.checkValidity() ? '' : ' (box says: ' + px.validationMessage + ')'), el.value === '2026-10-16' ? 'PASS' : (el.min || el.max ? 'INFO' : 'FAIL'), el.min || el.max ? 'Allowed range ' + (el.min || '…') + ' to ' + (el.max || '…') : '');
        setPx(pv); restore();
        if (req) { setPx(''); add('Empty value (date box)', 'Cleared the date box', 'Blocked as mandatory', el.validity.valueMissing || px.validity.valueMissing ? 'Blocked' : 'Accepted', el.validity.valueMissing || px.validity.valueMissing ? 'PASS' : 'FAIL', ''); setPx(pv); restore(); }
      } else if (['date', 'month', 'time', 'datetime-local', 'week'].includes(type)) {
        const bad = { date: '2026-02-31', month: '2026-13', time: '25:61', 'datetime-local': '2026-02-31T10:00', week: '2026-W60' }[type];
        el.value = bad; add('Invalid value – impossible ' + type, 'Entered ' + bad, 'Rejected', el.value === '' ? 'Rejected (cleared)' : 'Accepted', el.value === '' ? 'PASS' : 'FAIL', ''); restore();
        const good = { date: '2026-10-16', month: '2026-10', time: '09:30', 'datetime-local': '2026-10-16T09:30', week: '2026-W42' }[type];
        el.value = good; const ok = el.checkValidity();
        add('Valid value', 'Entered ' + good, 'Accepted (or outside the allowed range by design)', ok ? 'Accepted' : 'Blocked: ' + el.validationMessage, ok ? 'PASS' : (el.validity.rangeOverflow || el.validity.rangeUnderflow ? 'INFO' : 'FAIL'), el.min || el.max ? 'Allowed range ' + (el.min || '…') + ' to ' + (el.max || '…') : ''); restore();
        if (type === 'date') add('Date display format', 'Checked how the picker shows the date', 'dd-mm-yyyy', (el.getAttribute('data-display') || doc.documentElement.lang || 'browser locale') + ' picker', 'INFO', 'Native date picker shows the browser locale format; stored ISO');
      } else if (tag === 'select') {
        const opts = [...el.options].map(o => norm(o.text));
        const real = [...el.options].filter(o => o.value !== '' && !/^—/.test(norm(o.text)) && !PLACEHOLDER.test(norm(o.text))).map(o => norm(o.text));
        el.value = '__invalid__'; add('Invalid value – not in list', 'Forced a value that is not in the list', 'Rejected', el.value === '' || el.selectedIndex === -1 ? 'Rejected' : 'Accepted', 'PASS', ''); restore();
        const dup = opts.filter((t, i) => t && opts.indexOf(t) !== i);
        const blanks = [...el.options].filter((o, i) => i > 0 && !norm(o.text)).length;
        add('Dropdown options – duplicates / blanks', 'Listed every option', 'No duplicates, no blank rows', opts.length + ' options' + (dup.length ? '; duplicates: ' + [...new Set(dup)].join(', ') : '') + (blanks ? '; blank options: ' + blanks : ''), dup.length || blanks ? 'FAIL' : (opts.length === 0 ? 'WARN' : 'PASS'), opts.length === 0 ? 'Dropdown is empty' : '');
        if (req) { const first = [...el.options].find(o => o.value === ''); el.value = ''; add('Empty value', 'Selected the blank option', 'Blocked as mandatory', el.validity.valueMissing ? 'Blocked' : (first ? 'Accepted' : 'No blank option'), el.validity.valueMissing || !first ? 'PASS' : 'FAIL', ''); restore(); }
        const pick = [...el.options].find(o => o.value !== '' && !o.disabled);
        if (pick) { el.value = pick.value; add('Valid value', 'Selected "' + norm(pick.text).slice(0, 40) + '"', 'Accepted', el.checkValidity() ? 'Accepted' : 'Blocked', el.checkValidity() ? 'PASS' : 'FAIL', ''); restore(); }
        // C8 org config
        if (ot) {
          const unknown = real.filter(t => !matchMaster(ot, t));
          const universe = ot === 'role' ? FT.m.roles : ot === 'employee' ? FT.m.employees.filter(e => !/inactive|exited|left/i.test(e.status || '')).map(e => e.emp_code) : masterNames(ot);
          const missing = ot === 'employee' ? [] : universe.filter(n => !real.some(t => lc(t) === lc(n) || lc(t).startsWith(lc(n))));
          add('Options match Organization Configuration (' + ot + ')', 'Compared every option with the ' + ot + ' master', 'Only configured ' + ot + ' values', real.length + ' values; unknown: ' + (unknown.length ? unknown.slice(0, 8).join(' | ') : 'none') + (missing.length ? '; not offered: ' + missing.slice(0, 8).join(' | ') : ''), unknown.length ? 'FAIL' : (missing.length ? 'INFO' : 'PASS'), unknown.length ? 'Values not in Organization Configuration' : (missing.length ? 'Some configured values not offered (scoped list or cascade)' : ''));
          if (ot === 'employee' && real.length) {
            const fmt = real.filter(t => /^[A-Za-z0-9][A-Za-z0-9\-\/_]*\s+·\s+\S/.test(t)).length;
            add('Employee shown as "CODE · Name"', 'Read each employee option', 'Every option = ID · Name', fmt + ' of ' + real.length + ' in ID · Name form', fmt === real.length ? 'PASS' : (fmt ? 'WARN' : 'FAIL'), '');
            const searchable = !!(el.closest('[data-combobox], .combo, .combobox') || el.classList.contains('searchable') || el.hasAttribute('data-search') || (el.previousElementSibling && el.previousElementSibling.tagName === 'INPUT') || (el.nextElementSibling && /combo|search/i.test(el.nextElementSibling.className || '')) || ctx.doc.querySelector('[data-combo-for="' + (el.id || '§') + '"]') || el.dataset.combo);
            add('Employee dropdown searchable', 'Looked for type-to-search on the dropdown', 'Searchable by typing', searchable ? 'Search box attached' : 'Plain dropdown (browser type-ahead only)', searchable ? 'PASS' : 'WARN', '');
          }
        }
      } else if (type === 'checkbox' || type === 'radio') {
        el.checked = !orig; const ok = el.checkValidity(); add('Toggle', 'Ticked / un-ticked the option', 'Both states accepted (unless mandatory)', ok ? 'Accepted' : 'Blocked', ok || req ? 'PASS' : 'FAIL', ''); restore();
      } else if (type === 'file') {
        add('Allowed file types', 'Read the accept attribute', 'Restricted to the expected types', el.accept || '(any file)', el.accept ? 'PASS' : 'WARN', el.accept ? '' : 'Any file type can be chosen');
      } else if (type === 'url') {
        el.value = 'not a url'; add('Invalid value – not a web address', 'Typed "not a url"', 'Rejected', el.validity.typeMismatch ? 'Rejected (typeMismatch)' : 'Accepted', el.validity.typeMismatch ? 'PASS' : 'FAIL', ''); restore();
        el.value = 'https://example.com/job'; add('Valid value', 'Typed https://example.com/job', 'Accepted', el.checkValidity() ? 'Accepted' : 'Blocked: ' + el.validationMessage, el.checkValidity() ? 'PASS' : 'FAIL', ''); restore();
      } else if (type !== 'password') {
        // text / textarea / tel / search
        if (el.pattern) { let vOk = true; try { new RegExp('^(?:' + el.pattern + ')$', 'v'); } catch (e) { vOk = false; }
          el.value = '###'; const pm = el.validity.patternMismatch; add('Invalid value – wrong format', 'Typed "###"', 'Rejected by the format rule', pm ? 'Rejected (pattern)' : 'Accepted', pm ? 'PASS' : 'FAIL', 'pattern=' + el.pattern + (vOk ? '' : ' — this pattern is INVALID in the browser (unescaped "-" or "/" inside [...] under the v flag), so the browser ignores it and accepts any text')); restore(); }
        else if (/date|dob|doj/.test(key) && type === 'text') { el.value = '31-31-2026'; add('Invalid value – impossible date', 'Typed 31-31-2026', 'Rejected', el.checkValidity() ? 'Accepted' : 'Rejected', el.checkValidity() ? 'FAIL' : 'PASS', 'Text date field'); restore(); }
        else if (type === 'tel' || /mobile|phone/.test(key)) { el.value = 'abcdefghij'; add('Invalid value – letters in phone', 'Typed letters', 'Rejected', el.checkValidity() ? 'Accepted' : 'Rejected', el.checkValidity() ? 'WARN' : 'PASS', ''); restore(); }
        const ml = el.maxLength > 0 ? el.maxLength : 0;
        add('Maximum length', 'Read the max-length limit', 'A limit on free text', ml ? ml + ' characters' : 'Not limited at the form', ml ? 'PASS' : 'INFO', ml ? '' : 'Server column size applies');
        el.value = /mobile|phone/.test(key) ? '9876543210' : (el.pattern ? (orig || '') : 'QA Test'); const ok = el.checkValidity();
        add('Valid value', 'Typed a valid sample (' + (el.value || '(existing)').slice(0, 20) + ')', 'Accepted', ok ? 'Accepted' : 'Blocked: ' + el.validationMessage, ok ? 'PASS' : (el.pattern ? 'INFO' : 'FAIL'), ''); restore();
        if (/^\s*\d+\.\d{3,}\s*$/.test(String(orig)) && !/lat|lng|long/.test(key)) add('Decimal display', 'Read the stored value', '2 decimal places', String(orig), 'WARN', 'More than 2 decimals shown');
      }
    } catch (e) { add('Value tests', 'Ran value tests', 'No error', 'Error ' + e.message, 'WARN', ''); restore(); }
    return rows;
  }

  // employee picker (.emp-pick: visible combobox + hidden value + JSON choices)
  async function testEmpPick(wrap, ctx) {
    const doc = ctx.doc, rows = [];
    const box = wrap.querySelector('.emp-pick-text'), hid = wrap.querySelector('input[type=hidden]');
    let data = []; try { data = JSON.parse(wrap.querySelector('.emp-pick-data').textContent); } catch (e) { }
    const label = labelOf(box, doc) || 'Employee';
    const req = box.required;
    const f = { field: label, name: hid ? hid.name : '', type: 'employee picker (type to search)', required: req ? 'Yes' : 'No', visible: visible(box) ? 'Yes' : 'No (collapsed / dialog)', disabled: box.disabled ? 'Yes' : 'No', value: box.value.slice(0, 60) };
    const add = (crit, action, expected, actual, outcome, remarks) => rows.push(Object.assign({}, f, { crit, action, expected, actual: String(actual).slice(0, 300), outcome, remarks: remarks || '' }));
    add('Rendered on screen', 'Located the employee picker', 'Present', f.visible, 'PASS', '');
    add('Label / caption', 'Read the caption', 'A readable caption', label, label ? 'PASS' : 'WARN', '');
    const labels = data.map(r => String(r[1] || ''));
    const fmt = labels.filter(t => /^[A-Za-z0-9][A-Za-z0-9\-\/_]*\s+·\s+\S/.test(t)).length;
    add('Employee shown as "CODE · Name"', 'Read every choice', 'Every choice = ID · Name', fmt + ' of ' + labels.length, labels.length && fmt === labels.length ? 'PASS' : (labels.length ? 'FAIL' : 'WARN'), labels.length ? '' : 'No choices');
    const unknown = labels.filter(t => !matchMaster('employee', t));
    add('Options match Organization Configuration (employee)', 'Compared every choice with the employee register', 'Only real employees', labels.length + ' choices; unknown: ' + (unknown.length ? unknown.slice(0, 6).join(' | ') : 'none'), unknown.length ? 'FAIL' : 'PASS', '');
    const dup = labels.filter((t, i) => labels.indexOf(t) !== i);
    add('Dropdown options – duplicates / blanks', 'Checked the list', 'No duplicates', dup.length ? 'Duplicates: ' + [...new Set(dup)].slice(0, 5).join(' | ') : 'None', dup.length ? 'FAIL' : 'PASS', '');
    if (labels.length && !box.disabled) {
      const orig = box.value;
      box.focus(); box.value = ''; box.dispatchEvent(new Event('input', { bubbles: true })); await sleep(120);
      const lst0 = wrap.querySelector('.emp-pick-list'); const firstVis = lst0 ? [...lst0.querySelectorAll('.emp-pick-opt')].filter(c => !c.hidden && c.style.display !== 'none')[0] : null;
      const sampleLabel = firstVis ? norm(firstVis.textContent) : labels[0];
      const sample = sampleLabel.split(' · ')[1] || sampleLabel;
      const word = sample.split(' ')[0].slice(0, 4);
      box.focus(); box.value = word; box.dispatchEvent(new Event('input', { bubbles: true })); await sleep(120);
      const list = wrap.querySelector('.emp-pick-list');
      const items = list ? [...list.querySelectorAll('.emp-pick-opt')].filter(c => !c.hidden && c.style.display !== 'none') : [];
      const okItems = items.filter(c => lc(c.textContent).includes(lc(word))).length;
      add('Search by name', 'Typed "' + word + '"', 'List narrows to matching people', items.length + ' shown, ' + okItems + ' match', items.length && okItems === items.length ? 'PASS' : (items.length ? 'WARN' : 'FAIL'), '');
      const code = sampleLabel.split(' · ')[0];
      box.value = code; box.dispatchEvent(new Event('input', { bubbles: true })); await sleep(120);
      const items2 = list ? [...list.querySelectorAll('.emp-pick-opt')].filter(c => !c.hidden && c.style.display !== 'none') : [];
      add('Search by employee ID', 'Typed "' + code + '"', 'That employee is offered', items2.length + ' shown' + (items2[0] ? ', first "' + norm(items2[0].textContent).slice(0, 40) + '"' : ''), items2.some(c => norm(c.textContent).startsWith(code)) ? 'PASS' : 'FAIL', '');
      box.value = 'zzqq-no-such'; box.dispatchEvent(new Event('input', { bubbles: true })); await sleep(120);
      const items3 = list ? [...list.querySelectorAll('.emp-pick-opt')].filter(c => !c.hidden && c.style.display !== 'none') : [];
      add('Invalid value – unknown person', 'Typed a name that does not exist', 'Nothing offered; no value stored', items3.length + ' offered; stored "' + (hid ? hid.value : '') + '"', items3.length === 0 ? 'PASS' : 'FAIL', '');
      box.value = orig; box.dispatchEvent(new Event('input', { bubbles: true })); box.blur(); if (list) list.hidden = true;
    }
    if (req) { const ov = hid.value; hid.value = ''; add('Empty value', 'Cleared the picker', 'Blocked as mandatory', box.validity.valueMissing || !box.checkValidity() ? 'Blocked' : 'Accepted', 'INFO', 'Required is on the visible box'); hid.value = ov; }
    return rows;
  }

  // C9 cascade: company → branch/department/designation/team within the same row/form
  async function testCascade(sel, ctx) {
    const rows = [];
    const scope = sel.closest('[data-org-scope], tr, fieldset, .org-filter, form') || ctx.doc.body;
    const deps = [...scope.querySelectorAll('select')].filter(s => s !== sel).map(s => ({ s, t: orgType(s, labelOf(s, ctx.doc)) })).filter(x => ['branch', 'department', 'designation', 'team', 'shift'].includes(x.t));
    if (!deps.length) return rows;
    const orig = sel.value; const label = labelOf(sel, ctx.doc) || sel.name;
    const restoreAll = deps.map(d => [d.s, d.s.value]);
    for (const o of visOpts(sel).filter(o => o.value && !PLACEHOLDER.test(norm(o.text)))) {
      const co = FT.m.companies.find(c => lc(o.text).startsWith(lc(c.name)) || String(c.id) === o.value || lc(c.name) === lc(o.value));
      if (!co) continue;
      sel.value = o.value; fire(sel); await sleep(80);
      for (const d of deps) {
        const shown = visOpts(d.s).map(x => norm(x.text)).filter(t => !PLACEHOLDER.test(t));
        const wrong = shown.filter(t => belongs(d.t, t, co.id) === false);
        rows.push({ field: label, name: sel.name, type: 'select', required: sel.required ? 'Yes' : 'No', visible: visible(sel) ? 'Yes' : 'No', disabled: 'No', value: norm(o.text),
          crit: 'Cascade Company → ' + d.t, action: 'Selected company "' + co.name + '" and read the ' + d.t + ' dropdown (' + (labelOf(d.s, ctx.doc) || d.s.name) + ')',
          expected: 'Only ' + d.t + ' values of ' + co.name + ' (or shared)', actual: shown.length + ' shown' + (wrong.length ? '; other company: ' + wrong.slice(0, 6).join(' | ') : ''),
          outcome: wrong.length ? 'FAIL' : 'PASS', remarks: wrong.length ? 'Dropdown does not follow the selected company' : '' });
      }
    }
    sel.value = orig; fire(sel); restoreAll.forEach(([s, v]) => { s.value = v; }); await sleep(30);
    return rows;
  }

  // ---------- page-level ----------
  function pageChecks(ctx, P) {
    const doc = ctx.doc, rows = [], txt = doc.body ? doc.body.innerText : '';
    const add = (crit, action, expected, actual, outcome, remarks) => rows.push({ crit, action, expected, actual: String(actual).slice(0, 400), outcome, remarks: remarks || '' });
    add('Page opens', 'Opened ' + P.q, 'HTTP 200 within 15 s', 'HTTP ' + ctx.status + ' in ' + ctx.ms + ' ms', ctx.status === 200 ? (ctx.ms > 8000 ? 'WARN' : 'PASS') : 'FAIL', ctx.ms > 8000 ? 'Slow page' : '');
    const php = (txt.match(/(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught [A-Za-z\\]+)\s*:[^\n]{0,160}/g) || []).filter(s => !/^Warning: (the|this|you)/i.test(s));
    add('No PHP errors / warnings', 'Scanned the page text', 'None', php.length ? php.slice(0, 3).join(' || ') : 'None', php.length ? 'FAIL' : 'PASS', '');
    add('No browser script errors', 'Captured JavaScript errors while loading', 'None', ctx.jsErr.length ? ctx.jsErr.slice(0, 3).join(' || ') : 'None', ctx.jsErr.length ? 'FAIL' : 'PASS', '');
    const denied = /not allowed|permission denied|you do not have (permission|access)|access denied|switched off/i.test(txt.slice(0, 3000));
    add('Access for administrator', 'Checked for a denied / switched-off message', 'Opens for Admin (D-OWN-01)', denied ? 'Denied / switched-off text shown' : 'Opens', denied ? 'WARN' : 'PASS', '');
    const bc = doc.querySelector('.breadcrumb'); const bcT = bc ? norm(bc.textContent) : '';
    const secOk = bcT && (lc(bcT).includes(lc(P.section).split(' / ')[0].split(' ')[0]) || P.section === 'Main');
    add('Breadcrumb shows menu section', 'Read the breadcrumb', 'SmartPRS > ' + P.section + ' > …', bcT || '(none)', bc ? (secOk ? 'PASS' : 'WARN') : 'FAIL', '');
    const iso = (txt.match(/\b20\d\d-[01]\d-[0-3]\d\b/g) || []);
    add('Dates shown dd-mm-yyyy', 'Scanned visible text for yyyy-mm-dd dates', 'No yyyy-mm-dd in visible text', iso.length ? iso.length + ' found, e.g. ' + [...new Set(iso)].slice(0, 4).join(', ') : 'None', iso.length ? 'FAIL' : 'PASS', iso.length ? 'Owner rule: every date dd-mm-yyyy' : '');
    const dec = (txt.match(/(?<![\d.])\d{1,9}\.\d{3,4}(?![\d.])/g) || []).filter(s => !/^1[0-9]\.\d{4}$|^7[0-9]\.\d{4}$/.test(s));
    add('Numbers shown with 2 decimals', 'Scanned visible numbers', 'No x.yyy / x.yyyy values', dec.length ? dec.length + ' found, e.g. ' + [...new Set(dec)].slice(0, 4).join(', ') : 'None', dec.length ? 'WARN' : 'PASS', '');
    const tables = [...doc.querySelectorAll('table')].filter(visible);
    tables.slice(0, 12).forEach((t, i) => {
      const head = t.querySelector('thead tr') || t.querySelector('tr');
      if (!head) return;
      const hc = [...head.children].reduce((a, c) => a + (c.colSpan || 1), 0);
      const hdrs = [...head.children].map(c => norm(c.textContent));
      const body = [...t.querySelectorAll(':scope > tbody > tr, :scope > tr')].filter(r => r !== head);
      const cellsOf = r => [...r.children].filter(c => /^T[DH]$/.test(c.tagName));
      const bad = body.filter(r => { const n = cellsOf(r).reduce((a, c) => a + (c.colSpan || 1), 0); return n !== hc && cellsOf(r).length > 1; });
      const emptyH = hdrs.filter((h, j) => !h && j > 0 && j < hdrs.length - 1).length;
      const onlyActions = bad.length && bad.every(r => { const n = cellsOf(r).reduce((a, c) => a + (c.colSpan || 1), 0); const extra = cellsOf(r).slice(-(Math.max(1, n - hc) + 1)); return n > hc && extra.every(c => c.querySelector('button, a') && norm(c.textContent).length < 25); });
      add('Table ' + (i + 1) + ' columns aligned', 'Counted header cells vs row cells (' + hdrs.filter(Boolean).slice(0, 6).join(', ') + (hdrs.length > 6 ? '…' : '') + ')', 'Every row has the header\'s column count', hc + ' columns; ' + body.length + ' rows; ' + bad.length + ' misaligned' + (emptyH ? '; ' + emptyH + ' blank headers' : ''), bad.length ? (onlyActions ? 'WARN' : 'FAIL') : 'PASS', bad.length ? (onlyActions ? 'Only the trailing action buttons (Edit/Delete) run past the last header' : 'Values sit under the wrong headers') : '');
    });
    const isReport = /report|register|ledger|summary|reconciliation|log|history|form 16|remittance|directory|breakup|attendance|payroll|deduction/i.test(P.menu + ' ' + P.section);
    if (isReport && tables.length) {
      const exp = [...doc.querySelectorAll('a, button, input[type=submit]')].some(b => /export|csv|excel|xlsx|pdf|download|print/i.test((b.textContent || b.value || '') + ' ' + (b.getAttribute('href') || '')));
      add('Export available', 'Looked for Export / CSV / PDF', 'Export on report pages', exp ? 'Present' : 'Not found', exp ? 'PASS' : 'WARN', '');
    }
    const forms = [...doc.querySelectorAll('form')];
    const orgBar = doc.querySelector('.org-filter, [data-org-filter], form[method=get] select[name^=f_]');
    if (orgBar) add('Org filter bar present', 'Looked for Company / Branch / … filters', 'Filter bar wired to Organization Configuration', 'Present', 'PASS', '');
    add('Page content', 'Counted forms, fields and tables', 'Page renders its content', forms.length + ' forms, ' + doc.querySelectorAll('input:not([type=hidden]),select,textarea').length + ' fields, ' + tables.length + ' tables', 'INFO', '');
    return rows;
  }

  function formName(form, doc, i) {
    if (!form) return 'Fields outside a form';
    const act = (form.querySelector('input[name=action]') || {}).value || '';
    const method = (form.getAttribute('method') || 'get').toLowerCase();
    let h = '';
    let p = form; for (let k = 0; k < 6 && p && !h; k++) { let s = p.previousElementSibling; for (let j = 0; j < 4 && s && !h; j++) { if (/^H[1-6]$/.test(s.tagName) || s.classList.contains('card-title') || s.classList.contains('section-title')) h = s.textContent; s = s.previousElementSibling; } p = p.parentElement; }
    const inH = form.querySelector('h1,h2,h3,h4,legend,.card-title'); if (inH) h = inH.textContent;
    const sub = [...form.querySelectorAll('button[type=submit], input[type=submit], button:not([type])')].map(b => norm(b.textContent || b.value)).filter(Boolean)[0] || '';
    return { name: norm(h).slice(0, 60) || ('Form ' + (i + 1)), kind: method === 'post' ? 'Data entry' : 'Filter / search', action: act, submit: sub.slice(0, 40) };
  }

  // ---------- run one page ----------
  async function runPage(P, idx) {
    const fr = FT.frame;
    const ctx = { jsErr: [], status: 0, ms: 0 };
    const t0 = performance.now();
    const oldDoc = fr.contentDocument;
    fr.src = 'app.php?' + P.q;
    let hooked = false; const hook = () => {
      const w = fr.contentWindow, d = fr.contentDocument;
      if (!d || d === oldDoc || hooked) return; hooked = true;
      try { w.alert = () => {}; w.confirm = () => false; w.prompt = () => null; w.print = () => {};
        w.addEventListener('error', e => ctx.jsErr.push((e.message || 'error') + ' @' + (e.filename || '').split('/').pop() + ':' + e.lineno));
        w.addEventListener('unhandledrejection', e => ctx.jsErr.push('promise: ' + (e.reason && e.reason.message || e.reason)));
        w.open = () => null;
      } catch (e) { }
    };
    const loaded = new Promise(res => { fr.onload = () => res(true); setTimeout(() => res(false), 30000); });
    const poll = setInterval(hook, 4);
    const ok = await loaded; clearInterval(poll); hook();
    ctx.ms = Math.round(performance.now() - t0);
    const doc = fr.contentDocument, win = fr.contentWindow; ctx.doc = doc;
    try { const nav = win.performance.getEntriesByType('navigation')[0]; ctx.status = nav && nav.responseStatus || (ok ? 200 : 0); } catch (e) { ctx.status = ok ? 200 : 0; }
    // never let the page navigate or submit while we test
    try { win.HTMLFormElement.prototype.submit = function () {}; win.HTMLFormElement.prototype.requestSubmit = function () {};
      doc.addEventListener('submit', e => { e.preventDefault(); e.stopImmediatePropagation(); }, true);
      doc.addEventListener('click', e => { if (e.target.closest && e.target.closest('a[href], button[type=submit], input[type=submit]')) { e.preventDefault(); } }, true);
      win.onbeforeunload = null; } catch (e) { }
    await sleep(350);
    const res = { idx, page: P, final: (win.location.search || '').replace(/^\?/, ''), title: doc.title, page_checks: [], forms: [], fields: [], shots: [] };
    if (!ok) { res.page_checks.push({ crit: 'Page opens', action: 'Opened ' + P.q, expected: 'Loads within 30 s', actual: 'Timed out', outcome: 'FAIL', remarks: '' }); return res; }
    res.page_checks = pageChecks(ctx, P);
    // discover add/new forms on this route for the next pass
    res.links = [...doc.querySelectorAll('a[href*="app.php?"], a[href^="?"]')].map(a => ({ t: norm(a.textContent).slice(0, 40), h: (a.getAttribute('href') || '').replace(/^.*\?/, '') })).filter(l => /(^|&)(add|new|create)=/.test(l.h) || /^(\+\s*)?(add|new|create)\b/i.test(l.t)).slice(0, 12);
    // open collapsed panels so fields render
    doc.querySelectorAll('details').forEach(d => { d.open = true; });
    // unlock in-row editing (plain "Edit" buttons that only toggle the row; never links, never submit)
    let unlocked = 0;
    [...doc.querySelectorAll('button')].filter(b => /^\s*edit\s*$/i.test(b.textContent) && (b.getAttribute('type') || '').toLowerCase() === 'button' && !b.closest('form[method=post] button[type=submit]')).slice(0, 40).forEach(b => { try { b.click(); unlocked++; } catch (e) { } });
    if (unlocked) { await sleep(200); res.page_checks.push({ crit: 'In-row Edit unlocks the fields', action: 'Clicked ' + unlocked + ' row Edit button(s)', expected: 'Row fields become editable', actual: doc.querySelectorAll('input[readonly]:not([type=hidden])').length + ' fields still read-only after Edit', outcome: 'INFO', remarks: '' }); }
    // variants to test next: pages that need an employee chosen first
    res.variants = [];
    [...doc.querySelectorAll('form')].filter(f => (f.getAttribute('method') || 'get').toLowerCase() === 'get').forEach(f => {
      const pick = f.querySelector('.emp-pick');
      if (!pick || f.querySelectorAll('input:not([type=hidden]),select,textarea').length > 4) return;
      let data = []; try { data = JSON.parse(pick.querySelector('.emp-pick-data').textContent); } catch (e) { }
      const hid = pick.querySelector('input[type=hidden]'); if (!data.length || !hid) return;
      const fd = new FormData(f); fd.set(hid.name, data[0][0]);
      res.variants.push({ q: new URLSearchParams(fd).toString(), who: data[0][1] });
    });
    // fields
    const els = [...doc.querySelectorAll('input, select, textarea')].filter(el => {
      const t = (el.getAttribute('type') || '').toLowerCase();
      if (['hidden', 'submit', 'button', 'reset', 'image'].includes(t)) return false;
      if (['csrf', '_once', 'action'].includes(el.name)) return false;
      if (el.matches('input.dmy, input.emp-pick-text') || el.closest('.emp-pick')) return false;
      if (el.closest('.topbar, header .search, #theme-panel, .theme-picker, nav, aside, .sidebar, [data-theme-panel]')) return false;
      return true;
    }).slice(0, 700);
    const forms = [...new Set(els.map(e => e.form || null))];
    const fmeta = new Map(forms.map((f, i) => [f, formName(f, doc, i)]));
    res.forms = forms.map((f, i) => Object.assign({ i }, typeof fmeta.get(f) === 'string' ? { name: fmeta.get(f), kind: 'Standalone', action: '', submit: '' } : fmeta.get(f), { fields: els.filter(e => (e.form || null) === f).length }));
    for (const el of els) {
      const fi = forms.indexOf(el.form || null);
      let rows = [];
      try { rows = await testField(el, ctx); } catch (e) { rows = [{ field: el.name, name: el.name, crit: 'Field test', action: 'Ran tests', expected: 'No error', actual: e.message, outcome: 'WARN', remarks: '' }]; }
      if (el.tagName === 'SELECT' && orgType(el, labelOf(el, doc)) === 'company') { try { rows = rows.concat(await testCascade(el, ctx)); } catch (e) { } }
      rows.forEach(r => { r.form = fi; });
      res.fields.push(...rows);
    }
    for (const w of [...doc.querySelectorAll('.emp-pick')].filter(w => !w.closest('.topbar, nav, aside')).slice(0, 30)) {
      const hid = w.querySelector('input[type=hidden]'); const fm = hid ? hid.form : null;
      let fi = forms.indexOf(fm || null);
      if (fi < 0) { forms.push(fm || null); const meta = formName(fm, doc, forms.length - 1); res.forms.push(Object.assign({ i: forms.length - 1 }, typeof meta === 'string' ? { name: meta, kind: 'Standalone', action: '', submit: '' } : meta, { fields: 1 })); fi = forms.length - 1; }
      let rows = [];
      try { rows = await testEmpPick(w, ctx); } catch (e) { rows = [{ field: 'Employee picker', name: '', crit: 'Field test', action: 'Ran tests', expected: 'No error', actual: e.message, outcome: 'WARN', remarks: '' }]; }
      rows.forEach(r => { r.form = fi; });
      res.fields.push(...rows);
    }
    // screenshots: whole page, then each data-entry form with invalid fields outlined
    try {
      const H = Math.min(doc.documentElement.scrollHeight, 3200);
      const c = await window.html2canvas(doc.body, { scale: 0.55, logging: false, windowWidth: 1366, height: H, windowHeight: H, useCORS: true, backgroundColor: '#ffffff' });
      const id = 'p' + String(idx).padStart(3, '0');
      if (await post({ ft_kind: 'shot', ft_id: id, data: c.toDataURL('image/jpeg', 0.62) })) res.shots.push(id);
      let k = 0;
      for (const f of forms) {
        if (!f || (f.getAttribute('method') || '').toLowerCase() !== 'post' || k >= 5) continue;
        const req = [...f.querySelectorAll('[required]')];
        if (!req.length && f.querySelectorAll('input:not([type=hidden]),select,textarea').length < 3) continue;
        const saved = req.map(e => [e, e.value, e.style.outline]);
        req.forEach(e => { if (e.type !== 'checkbox' && e.type !== 'radio') e.value = ''; e.style.outline = '3px solid #d32f2f'; });
        f.scrollIntoView();
        const r = f.getBoundingClientRect();
        if (r.height < 20 || r.width < 20) { saved.forEach(([e, v, o]) => { e.value = v; e.style.outline = o; }); continue; }
        const fc = await window.html2canvas(f, { scale: 0.6, logging: false, height: Math.min(r.height, 2600), backgroundColor: '#ffffff' });
        saved.forEach(([e, v, o]) => { try { e.value = v; } catch (x) { } e.style.outline = o; });
        const fid = 'p' + String(idx).padStart(3, '0') + '-f' + forms.indexOf(f);
        if (await post({ ft_kind: 'shot', ft_id: fid, data: fc.toDataURL('image/jpeg', 0.62) })) res.shots.push(fid);
        k++;
      }
    } catch (e) { res.shot_error = String(e && e.message || e); }
    return res;
  }

  FT.start = async function (pages, from) {
    if (FT.running) return 'already running';
    FT.running = true; FT.pages = pages; FT.done = from || 0;
    if (!window.html2canvas) await new Promise((res, rej) => { const s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/html2canvas-pro@1.5.8/dist/html2canvas-pro.min.js'; s.onload = res; s.onerror = rej; document.head.appendChild(s); });
    await loadMasters();
    if (!FT.frame) { const fr = document.createElement('iframe'); fr.style.cssText = 'position:fixed;left:0;top:0;width:1366px;height:900px;z-index:99999;background:#fff;border:3px solid #B8893B'; document.body.appendChild(fr); FT.frame = fr; }
    window.alert = () => {}; window.confirm = () => false;
    for (let i = FT.done; i < pages.length && FT.running; i++) {
      let r;
      const ix = pages[i].idx != null ? pages[i].idx : i;
      try { r = await runPage(pages[i], ix); } catch (e) { r = { idx: ix, page: pages[i], fatal: String(e && e.stack || e), page_checks: [], fields: [], forms: [], shots: [] }; }
      await post({ ft_kind: 'res', ft_part: 'p' + String(ix).padStart(3, '0'), payload: JSON.stringify(r) });
      FT.done = i + 1; FT.last = pages[i].q;
    }
    FT.running = false; FT.finished = true;
    return 'finished';
  };
  FT.stop = () => { FT.running = false; };
  FT.status = () => JSON.stringify({ running: FT.running, done: FT.done, total: FT.pages && FT.pages.length, last: FT.last, errors: FT.errors.slice(-3) });
})();
