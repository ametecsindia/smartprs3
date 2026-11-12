<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * ONE DATE FORMAT FOR PEOPLE: dd-mm-yyyy.
 *
 * Storage, URLs, form values and every engine stay ISO (Y-m-d) — sortable and
 * unambiguous. Only what a PERSON reads or types is dd-mm-yyyy, and that is
 * done here, once, instead of in 400 echo statements:
 *
 *   - filter()   app.php's output buffer. HTML pages get SCRIPT (below), which
 *                rewrites ISO dates in visible text and turns every
 *                <input type="date"> into a dd-mm-yyyy box that still posts ISO.
 *                CSV downloads get their ISO dates rewritten.
 *   - inText()   for writers that bypass the browser (PDF, xlsx text).
 *   - toIso() / csvToIso()   uploads: dd-mm-yyyy, dd/mm/yyyy, dd.mm.yyyy, 07-Oct-2026,
 *                7 October 2026 → ISO before any importer sees the sheet (a typed
 *                date-time too: dd-mm-yyyy hh:mm → Y-m-d H:i). Day first, always.
 *
 * ponytail: display conversion is client-side, so a page with JS off shows ISO.
 */
final class Dates
{
    /** An ISO date standing alone: not inside an id (AIPL-1005_2026-09-22) or a path. */
    private const ISO = '~(?<![\w\-/])(\d{4})-(\d{2})-(\d{2})(?![\d\-])~';

    public static function dmy(string $iso): string
    {
        return self::inText($iso);
    }

    public static function inText(string $s): string
    {
        return preg_replace_callback(self::ISO,
            static fn (array $m): string => checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[3]}-{$m[2]}-{$m[1]}" : $m[0],
            $s) ?? $s;
    }

    /** dd-mm-yyyy (or / .) or Y-m-d → Y-m-d; anything else → null. */
    public static function toIso(string $v): ?string
    {
        $v = trim($v);

        if (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $v, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
        }

        if (preg_match('~^(\d{1,2})([-/.])(\d{1,2})\2(\d{4})$~', $v, $m) === 1 && checkdate((int) $m[3], (int) $m[1], (int) $m[4])) {
            return sprintf('%04d-%02d-%02d', $m[4], $m[3], $m[1]);
        }

        // 07-Oct-2026 / 7 October 2026 — what portals and Excel's long formats write. The word must BE a
        // month (its first three letters, and a prefix of the full name), never a guess.
        if (preg_match('~^(\d{1,2})[-/. ]([A-Za-z]{3,9})\.?[-/., ]+(\d{4})$~', $v, $m) === 1) {
            $months = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
            $word = strtolower($m[2]);

            foreach ($months as $i => $name) {
                if (str_starts_with($name, $word) && checkdate($i + 1, (int) $m[1], (int) $m[3])) {
                    return sprintf('%04d-%02d-%02d', $m[3], $i + 1, $m[1]);
                }
            }
        }

        return null;
    }

    /** A day-first date with a time (dd-mm-yyyy hh:mm[:ss]) → Y-m-d H:i[:s]; anything else → null. */
    public static function toIsoDateTime(string $v): ?string
    {
        if (preg_match('~^(.+?)[ T](\d{1,2}):(\d{2})(?::(\d{2}))?$~', trim($v), $m) !== 1 || (int) $m[2] > 23) {
            return null;
        }

        $date = self::toIso($m[1]);

        return $date === null ? null : sprintf('%s %02d:%s', $date, $m[2], $m[3]) . (($m[4] ?? '') !== '' ? ':' . $m[4] : '');
    }

    /** Every CSV field that is exactly a day-first date becomes ISO. */
    public static function csvToIso(string $csv): string
    {
        return preg_replace_callback('~(?<=^|,|")\d{1,2}[-/. ][^,"\r\n]*?\d{4}(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?(?=$|,|"|\r)~m',
            static fn (array $m): string => self::toIso($m[0]) ?? self::toIsoDateTime($m[0]) ?? $m[0], $csv) ?? $csv;
    }

    /** The output-buffer callback. */
    public static function filter(string $out): string
    {
        $type = '';

        foreach (headers_list() as $h) {
            stripos($h, 'content-type:') === 0 && $type = strtolower($h);
        }

        if (str_contains($type, 'text/csv')) {
            $raw = $out;   // ISO, so the workbook below can write real dates
            $out = self::inText($out);
            // EVERY CSV DOWNLOAD IS HANDED OVER AS A FORMATTED EXCEL FILE (owner, 23 Oct 2026), here at the
            // one place every response passes — so no module can ship a CSV that Excel mangles.
            $disposition = '';
            foreach (headers_list() as $h) {
                stripos($h, 'content-disposition:') === 0 && $disposition = $h;
            }
            if ($disposition === '' || stripos($disposition, 'attachment') === false || headers_sent()) {
                return $out;
            }
            $name = preg_match('/filename="?([^";]+)"?/i', $disposition, $m) === 1 ? (string) $m[1] : 'export.csv';
            $base = (string) preg_replace('/\.csv$/i', '', $name);
            try {
                // A SAMPLE / TEMPLATE is filled in and uploaded again, so it keeps its header on row 1 and its
                // cells as Text (owner, 27 Oct 2026: no last-minute errors on upload); everything else is a report.
                $xlsx = Sheet::fromCsv($raw, ucwords(trim((string) preg_replace('/[-_]+/', ' ', $base))),
                    preg_match('/template|sample/i', $base) === 1);
            } catch (\Throwable $e) {
                // A workbook that cannot be built must not cost the user the data: the CSV goes out as produced.
                error_log('CSV to Excel conversion failed for ' . $name . ': ' . $e->getMessage());

                return $out;
            }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $base . '.xlsx"');

            return $xlsx;
        }

        if ($type !== '' && !str_contains($type, 'text/html')) {
            return $out;
        }

        $at = strripos($out, '</body>');

        return $at === false ? $out : substr($out, 0, $at) . self::SCRIPT . substr($out, $at);
    }

    public const SCRIPT = <<<'HTML'
<style>input.dmy{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Crect x='3' y='5' width='18' height='16' rx='2'/%3E%3Cpath d='M16 3v4M8 3v4M3 10h18'/%3E%3C/svg%3E")!important;background-repeat:no-repeat!important;background-position:right 8px center!important;background-size:16px!important;padding-right:30px!important}</style>
<script>
(function () {
  if (window.__dmy) return; window.__dmy = 1;
  // TWO DECIMALS (owner, 7 Oct): DECIMAL(18,4) columns print as 8.5000; the last two
  // places are dropped only when they are 00, so no value is ever rounded.
  var DEC4 = /(?<![\d.])(-?\d+\.\d{2})00(?![\d.])/g;
  var ISO = /(?<![\w\-\/])(\d{4})-(\d{2})-(\d{2})(?![\d\-])/g, SKIP = {SCRIPT: 1, STYLE: 1, TEXTAREA: 1, NOSCRIPT: 1};
  var VAL = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function iso2dmy(v) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || ''); return m ? m[3] + '-' + m[2] + '-' + m[1] : ''; }
  function dmy2iso(v) {
    var m = /^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/.exec((v || '').trim()); if (!m) return null;
    var d = +m[1], mo = +m[2], y = +m[3], t = new Date(Date.UTC(y, mo - 1, d));
    return t.getUTCMonth() === mo - 1 && t.getUTCDate() === d ? y + '-' + pad(mo) + '-' + pad(d) : null;
  }
  function text(n) {
    var p = n.parentNode;
    if (!p || SKIP[p.nodeName] || (p.closest && p.closest('[contenteditable],[data-keep-iso]'))) return;
    var v = n.nodeValue.replace(ISO, function (m, y, mo, d) { return d + '-' + mo + '-' + y; }).replace(DEC4, '$1');
    if (v !== n.nodeValue) n.nodeValue = v;
  }
  function texts(root) {
    if (root.nodeType === 3) return /\d{4}-\d\d-\d\d|\.\d{4}/.test(root.nodeValue) && text(root);
    var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT), n, list = [];
    while ((n = w.nextNode())) /\d{4}-\d\d-\d\d|\.\d{4}/.test(n.nodeValue) && list.push(n);
    list.forEach(text);
  }
  // <input type="date"> shows the browser's locale order. Put a dd-mm-yyyy box in
  // front of it; the original stays in the form (hidden) and still posts ISO.
  function shim(inp) {
    if (inp._dmy) return; inp._dmy = 1;
    var t = document.createElement('input');
    t.type = 'text'; t.className = (inp.className + ' dmy').trim(); t.style.cssText = inp.style.cssText;
    t.placeholder = 'dd-mm-yyyy'; t.inputMode = 'numeric'; t.autocomplete = 'off'; t.maxLength = 10;
    t.title = inp.title || 'dd-mm-yyyy';
    // A date that belongs to a form by its form="…" attribute (a row's Approve / Issue form) has its box
    // belong to the same form, so a date flagged red stops that button too (17 Oct, Leave Approvals).
    if (inp.getAttribute('form')) t.setAttribute('form', inp.getAttribute('form'));
    function mirror() {
      t.disabled = inp.disabled; t.readOnly = inp.readOnly; t.required = inp.required;
      t.hidden = inp.hidden; t.style.display = inp.style.display === 'none' ? 'none' : '';
    }
    function check(iso) {
      var msg = '';
      if (t.value.trim() !== '' && !iso) msg = 'Enter the date as dd-mm-yyyy, e.g. 07-10-2026.';
      else if (iso && inp.min && iso < inp.min) msg = 'The date must be on or after ' + iso2dmy(inp.min) + '.';
      else if (iso && inp.max && iso > inp.max) msg = 'The date must be on or before ' + iso2dmy(inp.max) + '.';
      t.setCustomValidity(msg);
    }
    var busy = 0;
    function pull() { if (busy) return; t.value = iso2dmy(VAL.get.call(inp)); check(VAL.get.call(inp) || null); }
    function push(ev) {
      if (/^\d{8}$/.test(t.value)) t.value = t.value.slice(0, 2) + '-' + t.value.slice(2, 4) + '-' + t.value.slice(4);
      var iso = dmy2iso(t.value), next = iso || '';
      check(iso);
      if (next !== VAL.get.call(inp) || ev === 'change') { VAL.set.call(inp, next); busy = 1; try { inp.dispatchEvent(new Event(ev, {bubbles: true})); } finally { busy = 0; } }
      if (ev === 'change' && iso) t.value = iso2dmy(iso);
    }
    Object.defineProperty(inp, 'value', {configurable: true, get: function () { return VAL.get.call(inp); },
      set: function (v) { VAL.set.call(inp, v); pull(); }});
    inp.parentNode.insertBefore(t, inp);
    inp.tabIndex = -1;
    // min/max-width !important (5 Nov 2026): the app-wide "controls fit their text" rule gave this hidden 1px picker a
    // 130px minimum, which poked past the right edge of the last box in a row and slid the whole page sideways.
    inp.style.cssText += ';position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;border:0;padding:0;margin:0';
    inp.style.setProperty('min-width', '0', 'important'); inp.style.setProperty('max-width', '1px', 'important');
    mirror(); pull();
    t.addEventListener('input', function () { push('input'); });
    t.addEventListener('change', function () { push('change'); });
    inp.addEventListener('change', pull);
    inp.addEventListener('input', pull);
    inp.addEventListener('focus', function () { if (!inp._picking) t.focus(); });
    // A TAP ON THE CALENDAR ICON OPENS THE CALENDAR ONLY (owner, 6 Nov 2026: in the phone app it opened the number
    // pad) — the box is not focused by that tap, so no keyboard comes up; typing still works by tapping the text part.
    function onIcon(e) { return !t.disabled && !t.readOnly && e.offsetX >= t.clientWidth - 30; }
    t.addEventListener('pointerdown', function (e) {
      if (!onIcon(e)) return;
      e.preventDefault();
      t.inputMode = 'none';
      if (document.activeElement === t) t.blur();
    });
    t.addEventListener('pointerup', function (e) {
      if (!onIcon(e)) return;
      e.preventDefault();
      inp._picking = 1;
      try { inp.showPicker(); } catch (x) { inp.focus(); }
      setTimeout(function () { inp._picking = 0; t.inputMode = 'numeric'; }, 800);
    });
    new MutationObserver(mirror).observe(inp, {attributes: true, attributeFilter: ['disabled', 'readonly', 'required', 'hidden', 'style']});
    if (inp.form && !inp.form._dmy) { inp.form._dmy = 1; inp.form.addEventListener('reset', function (f) { setTimeout(function () { f.target.querySelectorAll('input[type=date]').forEach(function (i) { i.value = VAL.get.call(i); }); }); }); }
  }
  function scan(root) {
    if (root.nodeType !== 1 && root.nodeType !== 9 && root.nodeType !== 3) return;
    texts(root);
    if (root.nodeType === 3) return;
    if (root.matches && root.matches('input[type=date]')) shim(root);
    root.querySelectorAll && root.querySelectorAll('input[type=date]').forEach(shim);
    root.querySelectorAll && root.querySelectorAll('input:not([type=hidden]):not([type=date])').forEach(function (i) {
      if (/^-?\d+\.\d{2}00$/.test(i.value)) { VAL.set.call(i, i.value.slice(0, -2)); i.defaultValue = i.value; }
    });
  }
  function boot() {
    scan(document.body);
    new MutationObserver(function (ms) {
      ms.forEach(function (m) {
        if (m.type === 'characterData') return texts(m.target);
        m.addedNodes.forEach(scan);
      });
    }).observe(document.body, {childList: true, subtree: true, characterData: true});
  }
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', boot) : boot();
})();
</script>
HTML;
}
