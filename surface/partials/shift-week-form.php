<?php
/**
 * ADD / EDIT A SHIFT IN THE SAME FORMAT AS ITS CARD (owner, 5 Nov 2026: "all the shifts, even if it is ONE, format must
 * be the same"). The Add / Edit form used to ask for one Start and one End; the saved shift then showed as a Mon–Sun card
 * (shift-week.php). Now the form itself carries the Mon–Sun Start / End rows. On Save the most common day pair becomes the
 * shift's regular time (start_time / end_time, still what every other module reads) and any day that differs is kept as
 * that day's own time — the same map the card saves (Shifts::WEEK_KEY), written by the module-save handler in app.php.
 */
declare(strict_types=1);

$swfId = (string) ($current['id'] ?? '');
$swfWeek = $swfId === '' ? [] : (array) ((new SmartPRS\Surface\Shifts($store))->week()[$swfId] ?? []);
$swfRegStart = substr((string) ($current['start_time'] ?? ''), 0, 5);
$swfRegEnd = substr((string) ($current['end_time'] ?? ''), 0, 5);
$swfDays = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$swfPosted = (array) ($_POST['sw_days'] ?? []);
?>
<style>
  /* the single Start / End boxes are replaced by the week below (they are still filled and posted) */
  form:has(.swf) fieldset.form-section:has(#f_start_time), form:has(.swf) .row > div:has(> #f_start_time), form:has(.swf) .row > div:has(> #f_end_time){display:none}
  .swf .sw-t input[type=time]{width:100%;max-width:120px;text-align:center;padding:5px 4px}
  .swf-tools{display:flex;gap:8px;justify-content:flex-end;padding:8px 0 0}
</style>
      <fieldset class="form-section swf">
      <legend>Day-wise timings</legend>
      <div class="table-wrap"><table class="sw-t">
        <thead><tr><th class="sw-row"></th><?php foreach ($swfDays as $swfDay): ?><th><?= $swfDay ?></th><?php endforeach; ?></tr></thead>
        <tbody>
<?php foreach (['start' => 'Start', 'end' => 'End'] as $swfK => $swfL): ?>
          <tr><th class="sw-row"><?= $swfL ?> <span class="no">*</span></th>
<?php   foreach ($swfDays as $swfN => $swfDay):
            $swfV = (string) ($swfPosted[$swfN][$swfK] ?? ($swfWeek[(string) $swfN][$swfK] ?? ($swfK === 'start' ? $swfRegStart : $swfRegEnd))); ?>
            <td><input type="time" name="sw_days[<?= $swfN ?>][<?= $swfK ?>]" value="<?= e($swfV) ?>" aria-label="<?= $swfDay ?> <?= strtolower($swfL) ?>"></td>
<?php   endforeach; ?>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="swf-tools"><button type="button" class="btn btn-outline btn-sm" data-swf-copy><i class="fas fa-clone"></i> Copy Monday to all days</button></div>
      <div class="note">Set each day's start and end. The most common timing becomes the shift's regular time; a day that differs (e.g. Saturday 09:30–14:00) keeps its own. Weekly offs still come from the W.OFF schedule. An end earlier than the start = night shift.</div>
      </fieldset>
<script>(function(){
  var box=document.currentScript.previousElementSibling, fm=box.closest('form'); if(!fm){return;}
  // the week sits right after the Shift details, before Applies to / Attendance duration — the card's order
  var dur=fm.querySelector('fieldset.form-section:has(#f_full_day_hours)'); if(dur){dur.parentNode.insertBefore(box,dur);}
  var cell=function(n,k){return fm.querySelector('[name="sw_days['+n+']['+k+']"]');};
  box.querySelector('[data-swf-copy]').addEventListener('click',function(){var s=cell(1,'start').value,e=cell(1,'end').value;for(var n=2;n<=7;n++){cell(n,'start').value=s;cell(n,'end').value=e;}sync();});
  var st=fm.querySelector('#f_start_time'),en=fm.querySelector('#f_end_time');
  // the hidden single boxes are filled from the week, so the browser must not stop on them (the server still checks)
  [st,en].forEach(function(i){if(i){i.removeAttribute('required');}});
  function sync(){var count={},best='',bestN=0;
    for(var n=1;n<=7;n++){var s=cell(n,'start').value,e=cell(n,'end').value;if(s!==''&&e!==''){var k=s+'|'+e;count[k]=(count[k]||0)+1;if(count[k]>bestN){bestN=count[k];best=k;}}}
    var p=best===''?['','']:best.split('|');if(st){st.value=p[0];}if(en){en.value=p[1];}return best;}
  box.addEventListener('input',sync);box.addEventListener('change',sync);sync();
  fm.addEventListener('submit',function(ev){
    var bad=null;
    for(var n=1;n<=7;n++){var s=cell(n,'start').value,e=cell(n,'end').value;if((s===''||e==='')&&s+e!==''){bad=cell(n,s===''?'start':'end');break;}}
    if(bad===null&&sync()===''){bad=cell(1,'start');}
    if(bad){ev.preventDefault();ev.stopImmediatePropagation();bad.setCustomValidity('Give both a start and an end time for this day (at least one day).');bad.reportValidity();
      bad.addEventListener('input',function(){bad.setCustomValidity('');},{once:true});}
  },true);
})();</script>
