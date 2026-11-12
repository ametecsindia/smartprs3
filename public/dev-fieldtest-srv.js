// DEV ONLY (16 Oct 2026): server-side validation pass. For each chosen CREATE form: post it with every
// mandatory field empty (must be refused), and post it without the CSRF code (must be refused).
// Never used on edit / delete / approve / generate forms. Results -> storage/audit/fieldtest/server.json
(function () {
  const FT = window.FT;
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const norm = s => (s || '').replace(/\s+/g, ' ').trim();
  function verdict(html) {
    const d = new DOMParser().parseFromString(html, 'text/html');
    const pr = [...d.querySelectorAll('.problem[data-flash], .problem')].map(x => norm(x.textContent)).filter(Boolean);
    const no = [...d.querySelectorAll('.notice[data-flash]')].map(x => norm(x.textContent)).filter(Boolean);
    const php = (norm(d.body ? d.body.textContent : '').match(/(Warning|Notice|Fatal error|Deprecated|Uncaught)[^.]{0,140}/) || [''])[0];
    return { problem: pr[0] || '', notice: no[0] || '', php };
  }
  async function loadPage(q) {
    let fr = document.getElementById('ft2'); if (!fr) { fr = document.createElement('iframe'); fr.id = 'ft2'; fr.style.cssText = 'position:fixed;left:0;top:0;width:1366px;height:900px;visibility:hidden'; document.body.appendChild(fr); }
    fr.src = 'app.php?' + q;
    await new Promise(res => { fr.onload = res; setTimeout(res, 30000); });
    await sleep(300);
    const w = fr.contentWindow; try { w.alert = () => {}; w.confirm = () => false; } catch (e) { }
    return fr.contentDocument;
  }
  FT.serverValidate = async function (items) {
    const out = [];
    FT.srvDone = 0; FT.srvRunning = true;
    for (const it of items) {
      try {
        const doc = await loadPage(it.q);
        const isEdit = f => [...f.querySelectorAll('input[type=hidden]')].some(h => /^(id|edit|row_id|record_id|entry|uuid)$/.test(h.name) && h.value !== '' && h.value !== '0' && h.value !== 'new');
        const form = [...doc.querySelectorAll('form')].find(f => (f.querySelector('input[name=action]') || {}).value === it.action && !isEdit(f));
        const editOnly = !form && [...doc.querySelectorAll('form')].some(f => (f.querySelector('input[name=action]') || {}).value === it.action);
        if (!form) { out.push({ menu: it.menu, form: it.form, action_name: it.action, field: '(form)', crit: 'Create form found', action: 'Opened ' + it.q, expected: 'A create form (no record id)', actual: editOnly ? 'Only edit forms of existing records — not posted, to protect live data' : 'Not found on the page', outcome: 'INFO' }); FT.srvDone++; continue; }
        const target = form.getAttribute('action') || ('app.php?' + it.q);
        const reqEls = [...form.querySelectorAll('[required]')];
        const reqNames = new Set();
        reqEls.forEach(el => {
          if (el.name) reqNames.add(el.name);
          const pick = el.closest('.emp-pick'); if (pick) { const h = pick.querySelector('input[type=hidden]'); if (h && h.name) reqNames.add(h.name); }
          if (el.matches('input.dmy') && el.nextElementSibling && el.nextElementSibling.name) reqNames.add(el.nextElementSibling.name);
        });
        const labels = [...reqNames].join(', ');
        // 1) every mandatory field empty
        const fd = new FormData(form);
        reqNames.forEach(n => fd.set(n, ''));
        fd.set('_once', 'ft' + Date.now() + Math.random().toString(36).slice(2));
        let html = await (await fetch(target, { method: 'POST', body: fd, credentials: 'same-origin' })).text();
        let v = verdict(html);
        out.push({ menu: it.menu, form: it.form, action_name: it.action, field: labels || '(no field marked mandatory)', crit: 'Server refuses an empty mandatory form',
          action: 'Posted the form with ' + (reqNames.size ? reqNames.size + ' mandatory field(s) emptied' : 'no mandatory fields (all optional) — sent as it opened'),
          expected: 'Refused with a message; nothing saved', actual: v.problem ? 'Refused: ' + v.problem.slice(0, 200) : (v.notice ? 'ACCEPTED: ' + v.notice.slice(0, 200) : 'No message shown') + (v.php ? ' · PHP: ' + v.php : ''),
          outcome: v.problem && !v.notice ? (v.php ? 'WARN' : 'PASS') : (v.notice ? (reqNames.size ? 'FAIL' : 'WARN') : 'WARN'),
          remarks: v.notice ? 'A record may have been created by this test — listed for clean-up' : (v.problem ? '' : 'Page re-rendered without a message') });
        await sleep(400);
        // 2) no CSRF code
        const fd2 = new FormData(form); fd2.delete('csrf'); fd2.set('_once', 'ft' + Date.now() + Math.random().toString(36).slice(2));
        reqNames.forEach(n => fd2.set(n, ''));
        html = await (await fetch(target, { method: 'POST', body: fd2, credentials: 'same-origin' })).text();
        v = verdict(html);
        out.push({ menu: it.menu, form: it.form, action_name: it.action, field: '(security code)', crit: 'Server refuses a post without the CSRF code',
          action: 'Posted the form without its csrf code', expected: 'Refused; nothing changed', actual: /one-time\s+code|was refused/i.test(v.problem) ? 'Refused: ' + v.problem.slice(0, 120) : (v.notice ? 'ACCEPTED: ' + v.notice : (v.problem || 'No message')),
          outcome: /one-time\s+code|was refused/i.test(v.problem) ? 'PASS' : 'FAIL', remarks: '' });
        await sleep(400);
      } catch (e) { out.push({ menu: it.menu, form: it.form, action_name: it.action, field: '', crit: 'Server test', action: 'Ran', expected: 'No error', actual: String(e), outcome: 'WARN' }); }
      FT.srvDone++;
    }
    const fd = new FormData(); fd.append('csrf', document.querySelector('input[name=csrf]').value); fd.append('ft_kind', 'res'); fd.append('ft_part', 'server'); fd.append('payload', JSON.stringify(out));
    await fetch('app.php?r=dev-fieldtest-h', { method: 'POST', body: fd });
    FT.srvRunning = false;
    return out.length;
  };
})();
