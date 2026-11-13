<?php

declare(strict_types=1);

use SmartPRS\Surface\Navigation;

/**
 * Owner, 13 Nov 2026: an employee sees only My Letters; the HR letter screens (draft, issue, templates, sign-off) are
 * for those who can issue letters — even if a role was ticked "View" on them in Roles & Permissions.
 */
group('letters hr only');

$nav = new Navigation(__DIR__ . '/../config/navigation.json');
$allowed = static function (callable $may) use ($nav): array {
    $out = [];
    foreach ($nav->forViewer($may) as $s) { foreach ($s['items'] as $i) { $out[(string) ($i['key'] ?? '')] = (bool) $i['allowed']; } }
    return $out;
};
$employee = $allowed(static fn (string $c): bool => in_array($c, ['employee.letters.view', 'ess.profile.view'], true));
foreach (['letters-offer', 'letters-increment', 'letters-warning', 'letters-relieving', 'letters-nda', 'letter-signoff', 'letters-templates'] as $k) {
    ok(!($employee[$k] ?? true), "{$k} hidden from a role that only views letters");
}
ok($employee['letters-mine'] ?? false, 'My Letters shown to the employee');
$hr = $allowed(static fn (string $c): bool => in_array($c, ['employee.letters.view', 'employee.update'], true));
ok($hr['letters-offer'] ?? false, 'HR who can issue sees Offer Letters');
$ok = json_decode((string) file_get_contents(__DIR__ . '/../config/role-defaults.json'), true);
ok(in_array('ess.profile.view', (array) $ok['every_role'], true), 'every role gets its own profile and letters');
