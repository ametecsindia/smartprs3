<?php declare(strict_types=1);
// SEND TO THE VERIFICATION AGENCY (owner, 21 Oct 2026; reworked 22 Oct): pick several employees, then tick, per
// employee, which checks the agency should verify (a table: employees × the checks in config/bgv.json), then send.
// Shown on the BGV desk (?r=bgv) and on the Background Verification register (?r=m&k=bgv); $bgaBack says which
// page to return to. ?send=<employee id> (after "Save & send email to agency") opens it pre-filled for that employee.
use SmartPRS\Surface\Employment;
$bgaD = $bgv->declared();
$bgaPeople = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
// AGENCY → EMAIL, from the records already saved, so picking a known agency fills its email.
$bgAgencyMail = [];
foreach ($bgv->all() as $bgAr) { ($bgAn = trim((string) ($bgAr['agency'] ?? ''))) === '' || trim((string) ($bgAr['agency_email'] ?? '')) === '' || $bgAgencyMail[$bgAn] = trim((string) $bgAr['agency_email']); }
foreach ($bgv->all() as $bgAr) { ($bgAn = trim((string) ($bgAr['agency'] ?? ''))) === '' || $bgAgencyMail[$bgAn] ??= ''; }
$bgaSend = (string) ($_GET['send'] ?? '');
$bgaSendRow = $bgaSend === '' ? null : $bgv->forEmployment($bgaSend);
?>
  <style>#bg-agency:not([open]){display:none !important}
    #bg-agency-table th,#bg-agency-table td{padding:6px 8px;font-size:12.5px;white-space:normal !important}
    #bg-agency-table td:first-child{white-space:nowrap}
    #bg-agency-table td.c,#bg-agency-table th.c{text-align:center}</style>
  <dialog id="bg-agency" style="margin:auto;position:fixed;inset:0;border:0;border-radius:14px;padding:0;width:min(1000px,96vw)">
    <form method="post" enctype="multipart/form-data" style="padding:20px 24px" onsubmit="var n=this.querySelectorAll('input[name=&quot;emp[]&quot;]:checked').length,f=this.codes_file.files.length;var m=document.getElementById('bg-agency-msg');m.hidden=!!(n||f);return !!(n||f);">
      <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="bgv-agency-send"><input type="hidden" name="back" value="<?= e($bgaBack ?? 'bgv') ?>">
      <h2 style="margin:0 0 4px;font-size:18px">Send to the verification agency</h2>
      <p class="note" style="margin:0 0 10px">1. Agency &nbsp;&rarr;&nbsp; 2. Select employees &nbsp;&rarr;&nbsp; 3. Tick what the agency should verify for each &nbsp;&rarr;&nbsp; Send. One email goes with each employee's details, an attached sheet and their documents on file; each employee's BGV record is opened (or given this agency).</p>
      <div class="row"><div><label>Agency name</label><input name="agency" id="bg-agency-name" list="bg-agency-list" placeholder="agency name" oninput="bgAgencyPickName()"><datalist id="bg-agency-list"><?php foreach (array_keys($bgAgencyMail) as $bgAg): ?><option value="<?= e((string) $bgAg) ?>"><?php endforeach; ?></datalist></div>
        <div style="flex:2"><label>Agency email <span class="no">*</span></label><input name="agency_email" id="bg-agency-email" required placeholder="verify@agency.com (several: separate with commas)"></div></div>
      <label style="margin-top:10px">Select employees <span class="note" id="bg-agency-count">0 selected</span></label>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><input type="search" id="bg-agency-q" placeholder="type code, name, company or branch to narrow" style="flex:1;min-width:220px" oninput="bgAgencyFilter()">
        <button type="button" class="btn btn-outline btn-sm" onclick="bgAgencyAll(true)">Select all shown</button><button type="button" class="btn btn-outline btn-sm" onclick="bgAgencyAll(false)">Clear</button></div>
      <div id="bg-agency-emps" style="max-height:170px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:6px 10px;margin-top:6px">
<?php foreach ($bgaPeople as $bgAe): $bgAline = trim(implode(' · ', array_filter([orgValueOf($store, $bgAe, 'company'), orgValueOf($store, $bgAe, 'branch')]))); ?>
        <label style="display:flex;gap:8px;align-items:center;font-weight:400;margin:2px 0" data-s="<?= e(strtolower(Employment::label($bgAe) . ' ' . $bgAline)) ?>" data-l="<?= e(Employment::label($bgAe)) ?>"><input type="checkbox" name="emp[]" value="<?= e((string) $bgAe['id']) ?>" onchange="bgAgencyCount()"> <span><?= e(Employment::label($bgAe)) ?> <span class="note">— <?= e($bgAline) ?></span></span></label>
<?php endforeach; ?>
      </div>
      <div id="bg-agency-checks" hidden style="margin-top:12px">
        <label>What should the agency verify? <span class="note">(tick per employee; a column heading ticks the whole column)</span></label>
        <div class="table-wrap" style="max-height:260px;overflow:auto"><table id="bg-agency-table"><thead><tr><th>Employee</th>
<?php foreach ($bgaD['checks'] as $bgK => $bgL): ?>          <th class="c"><label style="margin:0;font-weight:700;display:inline-flex;gap:5px;align-items:center;cursor:pointer"><input type="checkbox" checked data-col="<?= e((string) $bgK) ?>" onchange="bgAgencyCol(this)"> <?= e((string) $bgL) ?></label></th>
<?php endforeach; ?>        </tr></thead><tbody></tbody></table></div>
      </div>
      <div class="row" style="margin-top:10px"><div style="flex:2"><label>Bulk upload — employee codes (CSV / Excel, codes in the first column; these get every check)</label><input type="file" name="codes_file" accept=".csv,.xlsx,.xls"></div>
        <div><a class="btn btn-outline btn-sm" download="bgv-agency-employees-sample.csv" href="data:text/csv;charset=utf-8,EMP%20CODE%0D%0A<?= e(rawurlencode($bgaPeople !== [] ? Employment::code($bgaPeople[0]) : 'EMP-001')) ?>%0D%0A"><i class="fas fa-download"></i> Sample file</a></div></div>
      <label style="font-weight:400;display:inline-flex;gap:6px;align-items:center;margin-top:8px"><input type="checkbox" name="attach_docs" value="1" checked> Attach the documents on file (up to <?= e((string) ((json_decode((string) file_get_contents(__DIR__ . '/../../config/bgv.json'), true)['agency_mail']['attach_limit_mb'] ?? 20))) ?> MB in total)</label>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px"><span id="bg-agency-msg" class="no" hidden style="align-self:center;font-weight:600">Select at least one employee or upload a list of employee codes.</span><button type="button" class="btn btn-outline" onclick="document.getElementById('bg-agency').close()">Cancel</button><button class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send email to agency</button></div>
    </form></dialog>
  <script>
  var bgAgencyMails = <?= json_encode($bgAgencyMail, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var bgAgencyCols = <?= json_encode(array_keys($bgaD['checks'])) ?>;
  var bgAgencyKeep = {}; // employee id → {check: ticked} survives re-drawing the table
  function bgAgencyPickName(){var n=document.getElementById('bg-agency-name').value.trim(),m=document.getElementById('bg-agency-email');if(bgAgencyMails[n]&&!m.value){m.value=bgAgencyMails[n];}}
  function bgAgencyTable(){
    var body=document.querySelector('#bg-agency-table tbody');
    body.querySelectorAll('input[data-emp]').forEach(function(c){(bgAgencyKeep[c.getAttribute('data-emp')]=bgAgencyKeep[c.getAttribute('data-emp')]||{})[c.value]=c.checked;});
    body.innerHTML='';
    var picked=[].filter.call(document.querySelectorAll('#bg-agency-emps input'),function(c){return c.checked;});
    picked.forEach(function(c){
      var tr=document.createElement('tr'),td=document.createElement('td');td.textContent=c.closest('label').getAttribute('data-l');tr.appendChild(td);
      bgAgencyCols.forEach(function(k){var cell=document.createElement('td'),box=document.createElement('input');cell.className='c';box.type='checkbox';box.name='chk['+c.value+'][]';box.value=k;box.setAttribute('data-emp',c.value);
        var kept=(bgAgencyKeep[c.value]||{})[k];box.checked=kept===undefined?document.querySelector('#bg-agency-table th input[data-col="'+k+'"]').checked:kept;cell.appendChild(box);tr.appendChild(cell);});
      body.appendChild(tr);});
    document.getElementById('bg-agency-checks').hidden=picked.length===0;
  }
  function bgAgencyCol(h){document.querySelectorAll('#bg-agency-table tbody input[value="'+h.getAttribute('data-col')+'"]').forEach(function(c){c.checked=h.checked;});}
  function bgAgencyCount(){document.getElementById('bg-agency-count').textContent=document.querySelectorAll('#bg-agency-emps input:checked').length+' selected';bgAgencyTable();}
  function bgAgencyFilter(){var q=document.getElementById('bg-agency-q').value.trim().toLowerCase().split(/\s+/);document.querySelectorAll('#bg-agency-emps > label').forEach(function(l){var s=l.getAttribute('data-s');l.hidden=!q.every(function(t){return s.indexOf(t)>=0;});});}
  function bgAgencyAll(on){document.querySelectorAll('#bg-agency-emps > label:not([hidden]) input').forEach(function(c){c.checked=on;});bgAgencyCount();}
  // bgAgencyOpen(id, agency, email, checks): checks = {check: true/false} from that employee's record (N/A → unticked)
  function bgAgencyOpen(id,agency,email,checks){if(id){document.querySelectorAll('#bg-agency-emps input').forEach(function(c){if(c.value===id){c.checked=true;}});if(checks){bgAgencyKeep[id]=checks;}}
    if(agency){document.getElementById('bg-agency-name').value=agency;}if(email){document.getElementById('bg-agency-email').value=email;}bgAgencyPickName();bgAgencyCount();document.getElementById('bg-agency').showModal();}
<?php if ($bgaSend !== '' && $bgaSendRow !== null): $bgaChk = []; foreach (array_keys($bgaD['checks']) as $bgK) { $bgaChk[$bgK] = strtolower((string) ($bgaSendRow['outcomes'][$bgK] ?? $bgaSendRow['chk_' . $bgK] ?? 'pending')) !== 'na'; } ?>
  document.addEventListener('DOMContentLoaded',function(){bgAgencyOpen(<?= json_encode($bgaSend) ?>,<?= json_encode((string) ($bgaSendRow['agency'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,<?= json_encode((string) ($bgaSendRow['agency_email'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,<?= json_encode($bgaChk) ?>);});
<?php endif; ?>
  </script>
