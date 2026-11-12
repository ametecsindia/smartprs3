<?php

declare(strict_types=1);

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\IdCardFormat;
use SmartPRS\Surface\IdCardPdf;
use SmartPRS\Surface\Store;

group('id card format');

$store = (new Store(sys_get_temp_dir() . '/prs3-icf-' . bin2hex(random_bytes(4))))->forTenant('1');
$f = new IdCardFormat(dirname(__DIR__) . '/config/id-card.json', new Customisation($store));

$shipped = $f->format();
same('CR80', $shipped['size'], 'ships as a CR80');
same('landscape', $shipped['orientation'], 'landscape');
same([856, 539], $f->dimensions($shipped), 'dimensions in tenths of a millimetre, integers all the way');
same('242.64', IdCardFormat::points(856), '85.6 mm is 242.64 points, by integer arithmetic');
same('0.121 0.227 0.372', IdCardFormat::rgb('#1F3A5F'), 'a hex colour becomes a PDF rgb triple');

$saved = $f->save(['size' => 'A7', 'orientation' => 'portrait', 'fields' => ['name', 'emp_code', 'bogus', 'signature'],
    'colors' => ['header' => '#123456', 'accent' => 'not-a-colour'], 'title' => 'STAFF', 'show_photo' => '1'], 'u', 'U');
ok($saved['ok'], 'a format saves');
$now = $f->format();
same('A7', $now['size'], 'the size is kept');
same([740, 1050], $f->dimensions($now), 'A7 portrait is 74 × 105 mm');
same(['name', 'emp_code', 'signature'], $now['fields'], 'undeclared fields are dropped, the order kept');
same('#123456', $now['colors']['header'], 'a valid colour is kept');
same('#B8893B', $now['colors']['accent'], 'an invalid one falls back to the declared default');
same('STAFF', $now['title'], 'the title is kept');
ok(!$f->save(['fields' => [], 'show_photo' => '0'], 'u', 'U')['ok'], 'a card with nothing on it is refused');

$custom = $f->save(['size' => 'custom', 'custom_w' => '90', 'custom_h' => '60.5', 'orientation' => 'landscape', 'fields' => ['name']], 'u', 'U');
same([900, 605], $f->dimensions($f->format()), 'a custom size in millimetres, to a tenth');
$f->save(['size' => 'custom', 'custom_w' => '5', 'custom_h' => '9999', 'fields' => ['name']], 'u', 'U');
same([856, 540], $f->dimensions($f->format()), 'an absurd custom size falls back to the declared default');

$lines = $f->lines(array_merge($now, ['fields' => ['emp_code', 'blood_group', 'signature', 'valid_till'], 'valid_till' => '2027-03-31']),
    ['emp_code' => 'SP-001', 'name' => 'A', 'blood_group' => 'B+']);
same(['Emp. ID', 'Blood Group', 'Employee Signature', 'Valid Till'], array_column($lines, 'label'), 'lines follow the format order');
same('line', $lines[2]['kind'], 'a signature is a line to sign on');
same('2027-03-31', $lines[3]['value'], 'valid till is the format\'s text, not the record\'s');

$pdf = IdCardPdf::renderCard(['w' => '209.76', 'h' => '297.63', 'portrait' => true, 'colors' => $now['colors'], 'company' => 'Co',
    'tagline' => '', 'show_company_name' => true, 'title' => 'STAFF', 'name' => 'Asha', 'designation' => 'Officer',
    'lines' => $lines, 'photo' => null, 'logo' => null, 'footer' => 'Return to Co', 'show_photo' => true]);
ok(str_starts_with($pdf, '%PDF-1.4') && str_contains($pdf, '/MediaBox [0 0 209.76 297.63]'), 'the PDF is the card\'s size');
ok(str_contains($pdf, '(STAFF) Tj') && str_contains($pdf, '(SP-001) Tj'), 'and carries the title and the fields');

group('id card format: named formats by scope');
same([], $f->entries(), 'none saved to begin with');
$r = $f->saveEntry('', 'Field agents', ['branch' => '5', 'designation' => '9'], ['size' => 'A7', 'fields' => ['emp_code', 'name'], 'show_photo' => '1'], '1', 'admin');
ok($r['ok'], 'a named, scoped format saves: ' . $r['why']);
$dup = $f->saveEntry('', 'field agents', ['branch' => '5'], ['fields' => ['name']], '1', 'admin');
ok(!$dup['ok'], 'the same name twice is refused');
$noScope = $f->saveEntry('', 'Nobody', [], ['fields' => ['name']], '1', 'admin');
ok(!$noScope['ok'], 'a named format with no scope is refused');
$r2 = $f->saveEntry('', 'Kondapur', ['branch' => '5'], ['size' => 'CR79', 'fields' => ['name']], '1', 'admin');
ok($r2['ok'], 'a broader format saves too');
same('A7', $f->formatFor(['id' => '77', 'branch_id' => '5', 'designation_id' => '9'])['format']['size'], 'branch + designation beats branch alone');
same('CR79', $f->formatFor(['id' => '78', 'branch_id' => '5', 'designation_id' => '2'])['format']['size'], 'the branch-wide format for the rest of the branch');
same(null, $f->formatFor(['id' => '79', 'branch_id' => '6'])['entry'], 'another branch falls back to the default');
$r3 = $f->saveEntry('', 'Just Asha', ['employee' => '78'], ['size' => 'CR100', 'fields' => ['name']], '1', 'admin');
same('CR100', $f->formatFor(['id' => '78', 'branch_id' => '5', 'designation_id' => '2'])['format']['size'], 'one employee beats the branch');
$edit = $f->saveEntry((string) $r2['id'], 'Kondapur', ['branch' => '5'], ['size' => 'CR80', 'fields' => ['name']], '1', 'admin');
ok($edit['ok'] && count($f->entries()) === 3, 'editing by id replaces, never duplicates');
ok($f->deleteEntry((string) $r3['id'], '1', 'admin')['ok'], 'delete');
same('CR80', $f->formatFor(['id' => '78', 'branch_id' => '5'])['format']['size'], 'and the person falls back to the branch format');
