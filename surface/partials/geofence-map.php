<?php declare(strict_types=1);
// GEO-FENCE MAP — the lower section of the single Geo-Fence screen (?r=m&k=geofence), owner 5 Nov 2026: "have these
// settings in a single tab with different sections". Rules (add / edit / list / bulk upload) are the register above;
// this draws them. Moved verbatim from the old ?r=geofence-map route, which now opens this screen. Included from
// public/app.php, so $store, $scope and $may are in scope; the register's own Company … Employee filter (same GET
// keys) narrows the map.
use SmartPRS\Surface\Employment;
?>
<?php
    // EVERY GEO-FENCE RULE ON ONE MAP. No map tiles are loaded — this build adds
    // no dependency and the Laragon machine may be offline — so the rules are
    // drawn to scale on a plain grid with the branch points, and each one links
    // to OpenStreetMap for the real map. Degrees are held as integer micro-degrees.
    $gfFilter = orgFilter();
    $gfScoped = $scope->only($store->all('employees'), $may('employee.list'));
    $gfVisible = orgFilterApply($store, $gfScoped, $gfFilter);
    $gfById = [];
    foreach ($gfVisible as $gfE) { $gfById[(string) $gfE['id']] = $gfE; }
    $gfMicro = static fn ($v): ?int => trim((string) $v) === '' || !is_numeric((string) $v) ? null : (int) round(((int) explode('.', (string) $v)[0]) * 1000000 + (int) substr(str_pad(explode('.', (string) $v . '.')[1], 6, '0'), 0, 6) * ((string) $v[0] === '-' ? -1 : 1));
    $gfRules = [];
    foreach ($store->all('geofence_rules') as $gfR) {
        if (!isset($gfById[(string) ($gfR['employee_id'] ?? '')])) { continue; }
        $la = $gfMicro($gfR['lat'] ?? ''); $ln = $gfMicro($gfR['lng'] ?? '');
        if ($la === null || $ln === null) { continue; }
        $gfRules[] = $gfR + ['lat_u' => $la, 'lng_u' => $ln, 'e' => $gfById[(string) $gfR['employee_id']]];
    }
    // EVERY FENCE, NOT ONLY THE RULES (owner, 12 Oct): an employee with no Geo-Fence Rule is fenced by
    // the Home Latitude / Longitude / Allowed Radius on their record — drawn too, marked "home".
    $gfRuled = array_flip(array_map(static fn (array $r): string => (string) $r['employee_id'], $gfRules));
    foreach ($gfVisible as $gfE) {
        $la = $gfMicro($gfE['home_lat'] ?? ''); $ln = $gfMicro($gfE['home_lng'] ?? '');
        if ($la === null || $ln === null || isset($gfRuled[(string) $gfE['id']])) { continue; }
        $gfRules[] = ['employee_id' => (string) $gfE['id'], 'start' => 'home', 'radius_km' => (string) ($gfE['geo_radius_km'] ?? '') ?: '0.5',
            'outside' => '', 'lat_u' => $la, 'lng_u' => $ln, 'e' => $gfE];
    }
    // BRANCHES: all of them with no filter; with a filter, the branches of the people it leaves.
    $gfFiltered = array_filter($gfFilter, static fn ($v): bool => (string) $v !== '') !== [];
    $gfBranchIds = array_flip(array_map(static fn (array $e): string => (string) ($e['branch_id'] ?? ''), $gfVisible));
    $gfBranches = [];
    $gfNoCoords = [];
    foreach (liveRows($store, 'branches') as $gfB) {
        if ($gfFiltered && !isset($gfBranchIds[(string) $gfB['id']])) { continue; }
        $la = $gfMicro($gfB['lat'] ?? ''); $ln = $gfMicro($gfB['lng'] ?? '');
        if ($la !== null && $ln !== null) { $gfBranches[] = $gfB + ['lat_u' => $la, 'lng_u' => $ln]; } else { $gfNoCoords[] = (string) $gfB['name']; }
    }
    $gfPts = array_merge($gfRules, $gfBranches);
    $gfLat = array_column($gfPts, 'lat_u'); $gfLng = array_column($gfPts, 'lng_u');
    // A BOUNDED, LABELLED FRAME (owner, 12 Oct): axes with degree ticks, a km scale bar, a legend.
    $gfPad = 6000; // ~0.66 km round the outermost point
    $gfMinLat = $gfPts === [] ? 0 : min($gfLat) - $gfPad; $gfMaxLat = $gfPts === [] ? 1 : max($gfLat) + $gfPad;
    $gfMinLng = $gfPts === [] ? 0 : min($gfLng) - $gfPad; $gfMaxLng = $gfPts === [] ? 1 : max($gfLng) + $gfPad;
    $gfL = 78; $gfT = 16; $gfPw = 800; $gfPh = 470; // the plot inside the frame
    $gfW = $gfL + $gfPw + 22; $gfH = $gfT + $gfPh + 64;
    $gfSpanLat = max(1, $gfMaxLat - $gfMinLat); $gfSpanLng = max(1, $gfMaxLng - $gfMinLng);
    // MILLIONTHS of a px per micro-degree: thousandths rounded to 0 once two cities were on one map.
    $gfScale = min(intdiv($gfPw * 1000000, $gfSpanLng), intdiv($gfPh * 1000000, $gfSpanLat));
    // The frame is as tall as the drawing needs, not a fixed box with empty bands.
    $gfPh = max(260, intdiv($gfSpanLat * $gfScale, 1000000)); $gfH = $gfT + $gfPh + 64;
    $gfX = static fn (int $lng) => $gfL + intdiv(($lng - $gfMinLng) * $gfScale, 1000000);
    $gfY = static fn (int $lat) => $gfT + $gfPh - intdiv(($lat - $gfMinLat) * $gfScale, 1000000);
    // 1 km ≈ 9,000 micro-degrees of latitude.
    $gfKmPx = static fn (string $km): int => intdiv((int) round(((int) explode('.', $km . '.')[0]) * 9000 + (int) substr(str_pad(explode('.', $km . '.')[1], 3, '0'), 0, 3) * 9) * $gfScale, 1000000);
    $gfDeg = static fn (int $u): string => ($u < 0 ? '-' : '') . intdiv(abs($u), 1000000) . '.' . str_pad((string) (abs($u) % 1000000), 6, '0', STR_PAD_LEFT);
    $gfStep = 1000;
    foreach ([1000, 2000, 5000, 10000, 20000, 50000, 100000, 200000, 500000, 1000000, 2000000] as $gfS) { $gfStep = $gfS; if (max($gfSpanLat, $gfSpanLng) / $gfS <= 8) { break; } }
    $gfTick = static fn (int $u): string => rtrim(rtrim($gfDeg($u), '0'), '.');
    $gfBarKm = 1;
    foreach ([1, 2, 5, 10, 20, 50, 100, 200] as $gfK) { $gfBarKm = $gfK; if ($gfKmPx((string) $gfK) >= 70) { break; } }
    $gfLabels = count($gfRules) <= 30; // more fences than that: dots with hover names, the table below has them all
?>
  <div class="card" id="gf-map" style="margin-top:18px">
    <h3 style="font-size:15px;font-weight:700;margin:0 0 4px">Geo-Fence Map</h3>
    <p class="note" style="margin:0">Where each employee may punch from: their Geo-Fence Rule above, or else the home location and allowed radius on their record &middot; <?= count($gfRules) ?> fence(s), <?= count($gfBranches) ?> branch(es) drawn to scale. The filter above applies here too.</p>
  </div>
<?php if ($gfNoCoords !== []): ?><div class="problem"><b>Not on the map — no latitude/longitude:</b> <?= e(implode(', ', $gfNoCoords)) ?>. Add them under <a href="?r=setup-branches">Branches</a>.</div><?php endif; ?>
<?php if ($gfPts === []): ?>
  <div class="card"><div class="empty"><i class="fas fa-map-location-dot"></i>No coordinates for this selection. Add latitude/longitude on Branches, on a Geo-Fence Rule above, or as Home Latitude / Longitude on the employee.</div></div>
<?php else: ?>
<?php if (!$gfFiltered && $gfSpanLng > 900000): ?><p class="note">These locations are far apart (more than one city), so each one is small. Pick a <b>Company</b> or <b>Branch</b> in the filter above and press Show to zoom in.</p><?php endif; ?>
  <div class="card" style="padding:8px">
    <svg viewBox="0 0 <?= $gfW ?> <?= $gfH ?>" style="width:100%;height:auto;display:block;background:#fff" font-family="inherit">
      <defs><clipPath id="gf-clip"><rect x="<?= $gfL ?>" y="<?= $gfT ?>" width="<?= $gfPw ?>" height="<?= $gfPh ?>"/></clipPath></defs>
      <rect x="<?= $gfL ?>" y="<?= $gfT ?>" width="<?= $gfPw ?>" height="<?= $gfPh ?>" fill="#f8fafc"/>
<?php for ($gfU = intdiv($gfMinLng, $gfStep) * $gfStep + $gfStep; $gfU < $gfMaxLng; $gfU += $gfStep): $gfTx = $gfX($gfU); if ($gfTx > $gfL + $gfPw) { break; } ?>
      <line x1="<?= $gfTx ?>" y1="<?= $gfT ?>" x2="<?= $gfTx ?>" y2="<?= $gfT + $gfPh ?>" stroke="#e2e8f0"/><text x="<?= $gfTx ?>" y="<?= $gfT + $gfPh + 16 ?>" font-size="10" fill="#64748b" text-anchor="middle"><?= e($gfTick($gfU)) ?>°E</text>
<?php endfor; ?>
<?php for ($gfU = intdiv($gfMinLat, $gfStep) * $gfStep + $gfStep; $gfU < $gfMaxLat; $gfU += $gfStep): $gfTy = $gfY($gfU); if ($gfTy < $gfT) { break; } ?>
      <line x1="<?= $gfL ?>" y1="<?= $gfTy ?>" x2="<?= $gfL + $gfPw ?>" y2="<?= $gfTy ?>" stroke="#e2e8f0"/><text x="<?= $gfL - 6 ?>" y="<?= $gfTy + 3 ?>" font-size="10" fill="#64748b" text-anchor="end"><?= e($gfTick($gfU)) ?>°N</text>
<?php endfor; ?>
      <g clip-path="url(#gf-clip)">
<?php foreach ($gfRules as $gfR): $gfCx = $gfX($gfR['lng_u']); $gfCy = $gfY($gfR['lat_u']); $gfRad = max(4, $gfKmPx((string) ($gfR['radius_km'] ?? '0'))); $gfHome = ($gfR['start'] ?? '') === 'home'; $gfC = $gfHome ? '#0284c7' : '#f97316'; ?>
        <g><title><?= e(Employment::label($gfR['e']) . ' · ' . ($gfHome ? 'home' : 'rule: ' . ($gfR['start'] ?? '')) . ' · ' . ($gfR['radius_km'] ?? '') . ' km') ?></title>
          <circle cx="<?= $gfCx ?>" cy="<?= $gfCy ?>" r="<?= $gfRad ?>" fill="<?= $gfC ?>" fill-opacity=".12" stroke="<?= $gfC ?>" stroke-width="1.2"/>
          <circle cx="<?= $gfCx ?>" cy="<?= $gfCy ?>" r="3" fill="<?= $gfC ?>"/>
<?php if ($gfLabels): ?><text x="<?= $gfCx + 6 ?>" y="<?= $gfCy - 5 ?>" font-size="10" fill="#0f172a"><?= e(Employment::code($gfR['e'])) ?></text><?php endif; ?></g>
<?php endforeach; ?>
<?php foreach ($gfBranches as $gfB): $gfCx = $gfX($gfB['lng_u']); $gfCy = $gfY($gfB['lat_u']); ?>
        <rect x="<?= $gfCx - 6 ?>" y="<?= $gfCy - 6 ?>" width="12" height="12" fill="#1e3a5f" stroke="#fff" stroke-width="1.5"/>
        <text x="<?= $gfCx + 10 ?>" y="<?= $gfCy + 4 ?>" font-size="12" font-weight="700" fill="#1e3a5f" paint-order="stroke" stroke="#fff" stroke-width="3"><?= e((string) $gfB['name']) ?></text>
<?php endforeach; ?>
      </g>
      <rect x="<?= $gfL ?>" y="<?= $gfT ?>" width="<?= $gfPw ?>" height="<?= $gfPh ?>" fill="none" stroke="#475569" stroke-width="1.5"/>
<?php $gfBy = $gfT + $gfPh + 42; $gfBw = $gfKmPx((string) $gfBarKm); ?>
      <line x1="<?= $gfL ?>" y1="<?= $gfBy ?>" x2="<?= $gfL + $gfBw ?>" y2="<?= $gfBy ?>" stroke="#0f172a" stroke-width="3"/>
      <text x="<?= $gfL + $gfBw + 6 ?>" y="<?= $gfBy + 4 ?>" font-size="11" fill="#0f172a"><?= $gfBarKm ?> km</text>
      <rect x="<?= $gfL + 200 ?>" y="<?= $gfBy - 6 ?>" width="10" height="10" fill="#1e3a5f"/><text x="<?= $gfL + 216 ?>" y="<?= $gfBy + 3 ?>" font-size="11" fill="#334155">branch</text>
      <circle cx="<?= $gfL + 290 ?>" cy="<?= $gfBy - 1 ?>" r="6" fill="#f97316" fill-opacity=".2" stroke="#f97316"/><text x="<?= $gfL + 302 ?>" y="<?= $gfBy + 3 ?>" font-size="11" fill="#334155">Geo-Fence Rule (radius to scale)</text>
      <circle cx="<?= $gfL + 510 ?>" cy="<?= $gfBy - 1 ?>" r="6" fill="#0284c7" fill-opacity=".2" stroke="#0284c7"/><text x="<?= $gfL + 522 ?>" y="<?= $gfBy + 3 ?>" font-size="11" fill="#334155">home location &amp; allowed radius</text>
      <text x="<?= $gfL + $gfPw - 4 ?>" y="<?= $gfT + 18 ?>" font-size="14" font-weight="700" fill="#475569" text-anchor="end">N &#8593;</text>
    </svg>
<?php if (!$gfLabels): ?><p class="note" style="margin:6px 4px 0">Hover a circle for the person; every fence is listed below.</p><?php endif; ?>
  </div>
  <div class="card" style="padding:0">
    <div class="table-wrap"><table>
      <thead><tr><th>Employee</th><th>Start from</th><th>Latitude</th><th>Longitude</th><th class="n">Radius (km)</th><th>Outside rule</th><th></th></tr></thead><tbody>
<?php foreach ($gfRules as $gfR): ?>
      <tr><td><b><?= e(Employment::label($gfR['e'])) ?></b></td><td><?= e((string) ($gfR['start'] ?? '')) ?></td><td><?= e($gfDeg($gfR['lat_u'])) ?></td><td><?= e($gfDeg($gfR['lng_u'])) ?></td><td class="n"><?= e((string) ($gfR['radius_km'] ?? '')) ?></td><td><?= e((string) ($gfR['outside'] ?? '')) ?></td>
        <td><a href="https://www.openstreetmap.org/?mlat=<?= e($gfDeg($gfR['lat_u'])) ?>&amp;mlon=<?= e($gfDeg($gfR['lng_u'])) ?>#map=15/<?= e($gfDeg($gfR['lat_u'])) ?>/<?= e($gfDeg($gfR['lng_u'])) ?>" target="_blank" rel="noopener">open map &rarr;</a></td></tr>
<?php endforeach; ?>
      </tbody></table></div>
  </div>
<?php endif; ?>
