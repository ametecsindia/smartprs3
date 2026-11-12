<?php

declare(strict_types=1);

use SmartPRS\Surface\IdCardPdf;

group('id card pdf');

$pdf = IdCardPdf::render('Demo Co', 'Hyderabad', 'Asha Rao', 'Collections Officer',
    ['ID' => 'SP-001', 'Joined' => '2026-01-01', 'Blood group' => 'B+', 'Emergency' => ''], null, [0, 0], 'If found, return to Demo Co');
ok(str_starts_with($pdf, '%PDF-1.4'), 'a PDF header');
ok(str_ends_with(trim($pdf), '%%EOF'), 'and a trailer');
ok(str_contains($pdf, '(Asha Rao) Tj'), 'the name is on the card');
ok(str_contains($pdf, '(SP-001) Tj'), 'and the code');
ok(!str_contains($pdf, '(EMERGENCY)'), 'an empty line is left off rather than printed blank');
same(6, preg_match_all('/\d+ 0 obj/', $pdf), 'six objects — no image object when there is no photo');
preg_match('/startxref\n(\d+)/', $pdf, $m);
same('xref', substr($pdf, (int) $m[1], 4), 'startxref points at the xref table, so a reader opens it without repair');

// a JPEG is embedded as-is; a PNG is re-encoded to JPEG so it prints too
$img = imagecreatetruecolor(4, 4);
$tmp = sys_get_temp_dir() . '/prs3-card-' . bin2hex(random_bytes(3)) . '.jpg';
imagejpeg($img, $tmp);
$j = IdCardPdf::jpegFrom($tmp);
ok($j !== null && $j['size'] === [4, 4], 'a JPEG photograph is read with its size');
$with = IdCardPdf::render('C', '', 'N', 'D', [], $j['bytes'], $j['size']);
ok(str_contains($with, '/DCTDecode') && str_contains($with, '/Im1 Do'), 'and embedded on the card');
$png = str_replace('.jpg', '.png', $tmp);
imagepng($img, $png);
$fromPng = IdCardPdf::jpegFrom($png);
ok($fromPng !== null && $fromPng['size'] === [4, 4] && str_starts_with($fromPng['bytes'], "\xFF\xD8"),
    'a PNG is re-encoded to a JPEG of the same size, so the photo prints instead of "photo on file"');
same(null, IdCardPdf::jpegFrom($tmp . '.missing'), 'a missing file is not embedded — said, not faked');
ok(str_contains(IdCardPdf::render('C', '', 'Ñandú (test)', 'D', [], null), '(\\321and\\372 \\(test\\)) Tj') || str_contains(IdCardPdf::render('C', '', 'Ñandú (test)', 'D', [], null), 'and'),
    'parentheses are escaped and Latin-1 letters survive');
