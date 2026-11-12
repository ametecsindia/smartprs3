<?php

declare(strict_types=1);

use SmartPRS\Surface\BgvAgencyMail;
use SmartPRS\Surface\Smtp;

/**
 * BGV DETAILS TO THE AGENCY (owner, 21 Oct 2026): codes read from an uploaded list, one email for many
 * employees with a details sheet and their documents attached, and a message that really carries them.
 */

group('bgv agency mail');

same(['ABCR-2003', 'AI2001'], BgvAgencyMail::codes("EMP CODE\r\nABCR-2003\r\nai2001,x\r\n\r\nAI2001\r\n"), 'header skipped, first column, de-duplicated');
same(['ABCR-2003'], BgvAgencyMail::codes("ABCR-2003\n"), 'a list without a header keeps its first row');

$dir = sys_get_temp_dir() . '/bgvam-' . bin2hex(random_bytes(4));
mkdir($dir);
file_put_contents($dir . '/a.pdf', str_repeat('A', 100));
file_put_contents($dir . '/b.pdf', str_repeat('B', 100));
$m = BgvAgencyMail::build([
    ['code' => 'ABCR-2003', 'name' => 'Madhuri', 'details' => ['Employee Code' => 'ABCR-2003', 'Date of Birth' => '01-02-1990'], 'docs' => ['ABCR-2003_Aadhaar.pdf' => $dir . '/a.pdf']],
    ['code' => 'AI2001', 'name' => 'Ejaz', 'details' => ['Employee Code' => 'AI2001', 'Date of Birth' => ''], 'docs' => ['AI2001_PAN.pdf' => $dir . '/b.pdf', 'AI2001_Gone.pdf' => '']],
], ['Identity / KYC', 'Address'], 'In-house HR', 'Ametecs', 150, '2026-10-21');
ok(str_contains($m['subject'], '2 employees') && str_contains($m['subject'], 'Ametecs'), 'subject names the count and company');
ok(str_contains($m['body'], '1. ABCR-2003 · Madhuri') && str_contains($m['body'], 'Date of Birth: 01-02-1990') && str_contains($m['body'], 'Identity / KYC, Address'), 'body carries each employee and the checks');
same(['bgv-request-2026-10-21.csv', 'ABCR-2003_Aadhaar.pdf'], array_column($m['attachments'], 0), 'sheet first, then documents within the limit');
same(['AI2001_PAN.pdf (over the attachment limit)', 'AI2001_Gone.pdf (file missing)'], $m['skipped'], 'what could not be attached is named');
ok(str_contains($m['attachments'][0][1], "ABCR-2003,01-02-1990"), 'the sheet has one row per employee');

$raw = Smtp::message(['from_email' => 'hr@x.com', 'from_name' => 'HR'], ['a@b.com'], [], 'S', 'text', '<p>h</p>', $m['attachments']);
ok(str_contains($raw, 'multipart/mixed') && str_contains($raw, 'filename="ABCR-2003_Aadhaar.pdf"') && str_contains($raw, base64_encode(str_repeat('A', 57))), 'the message carries the attachments');
$m2 = BgvAgencyMail::build([['code' => 'E1', 'name' => 'A', 'details' => ['Employee Code' => 'E1'], 'docs' => [], 'checks' => ['Address']]], ['Address'], '', 'X', 100, '2026-10-22');
ok(str_contains($m2['body'], 'Checks to verify: Address') && str_contains($m2['attachments'][0][1], 'E1,Address'), 'each employee carries its own checks, in the body and the sheet');
ok(!str_contains(Smtp::message(['from_email' => 'hr@x.com', 'from_name' => 'HR'], ['a@b.com'], [], 'S', 't', 'h'), 'multipart/mixed'), 'no attachments, message unchanged');
