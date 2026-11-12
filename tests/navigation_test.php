<?php

declare(strict_types=1);

/**
 * THE MENU IS A DECLARATION, and hiding an item is not a permission.
 *
 * SmartPRS 2's sidebar is fifteen anchors in a Blade template. The risk in making
 * it editable is that somebody reads "hidden" as "secured" — so the permission an
 * item sits behind is deliberately not settable here, and an item the viewer may
 * not open is MARKED rather than removed.
 */

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Navigation;

group('navigation');

$nav = new Navigation(__DIR__ . '/../config/navigation.json');
$shipped = $nav->sections();
// THE ROUTES THE FRONT CONTROLLER ACTUALLY ANSWERS, read from it.
// This was a hand-written list, and it went stale the first time a route was
// added: the menu gained a payslip entry, the application answered it, and this
// test failed saying the application does not. A list of what exists, kept
// beside the thing that exists, is a second description that drifts — and one
// that fails for the wrong reason teaches people to edit the test.
$frontController = (string) file_get_contents(__DIR__ . '/../public/app.php');
preg_match_all("/\\\$route === '([a-z-]+)'/", $frontController, $answered);
// AND THE ALIASES. `?r=setup-branches` is answered by the module screen rather
// than by a branch of its own, so it never appears as `$route === '…'` — read
// them from the same map the front controller resolves them with, because the
// alternative is this test calling a live menu item a dead link.
preg_match_all("/^\s+'([a-z-]+)' => '[a-z_]+',$/m",
    (string) (explode('];', explode('$setupScreens = [', $frontController)[1] ?? '')[0] ?? ''),
    $aliases);
// AND THE ROUTE ALIASES resolved before dispatch: `if ($route === 'x')` and
// `in_array($route, ['x', 'y'], true)` blocks that rewrite $route.
preg_match_all("/if \(\\\$route === '([a-z-]+)'\)/", $frontController, $rewrites);
preg_match_all("/in_array\(\\\$route, \[((?:'[a-z-]+'(?:, )?)+)\], true\)/", $frontController, $lists);
$listed = [];
foreach ($lists[1] as $group) { preg_match_all("/'([a-z-]+)'/", $group, $m); $listed = array_merge($listed, $m[1]); }
// AND THE SCREEN FILES: surface/screens/<route>.php is a route the front controller answers.
$screenFiles = array_map(static fn (string $f): string => basename($f, '.php'), glob(__DIR__ . '/../surface/screens/*.php') ?: []);
// AND THE SETUP SCREENS ($setupScreens): menu names for a module register (biometric-setup, setup-shifts...).
preg_match('/\$setupScreens = \[(.*?)\];/s', $frontController, $setup);
preg_match_all("/'([a-z-]+)' =>/", $setup[1] ?? '', $setupRoutes);
$routes = array_values(array_unique(array_merge($answered[1], $aliases[1], $rewrites[1], $listed, $screenFiles, $setupRoutes[1])));

ok(count($routes) > 15, 'the route list was read from the front controller, not typed out here');

ok($shipped !== [], 'the shipped menu is readable');

// EVERY SHIPPED ITEM MUST POINT AT A ROUTE THE APPLICATION ANSWERS. A dead link
// in a sidebar reads as a broken product rather than a wrong setting.
$moduleKeys = array_column((new SmartPRS\Surface\Modules(new SmartPRS\Surface\Store(sys_get_temp_dir() . '/prs3-nav-' . bin2hex(random_bytes(3))),
    dirname(__DIR__) . '/config/modules.json'))->all(), 'key');

foreach ($shipped as $section) {
    foreach ((array) $section['items'] as $item) {
        if (isset($item['module'])) {
            ok(in_array((string) $item['module'], $moduleKeys, true), sprintf(
                'Menu item "%s" opens module "%s", which must be declared in config/modules.json.',
                (string) $item['key'], (string) $item['module']));
        } else {
            ok(in_array((string) $item['route'], $routes, true), sprintf(
                'Menu item "%s" points at "%s", which must be a route the application answers.',
                (string) $item['key'], (string) $item['route']));
        }
        ok(trim((string) ($item['icon'] ?? '')) !== '', sprintf(
            'Menu item "%s" must have an icon — an icon-less row in an icon menu reads as a '
            . 'rendering fault.', (string) $item['key']));
    }
}

// NON-VACUITY: the change an administrator wants must pass.
ok(Navigation::problems(['items' => ['recruitment' => ['label' => 'Staff', 'order' => 10]]],
    $shipped, $routes) === [], 'Renaming and reordering an item is the point and must pass.');

// THE ONE THAT MATTERS: the menu cannot grant access.
$escalation = Navigation::problems(['items' => ['recruitment' => ['permission' => 'employee.view']]],
    $shipped, $routes);
ok($escalation !== [],
    'Setting the permission an item sits behind must be refused from the menu — a menu that could '
    . 'change it would be a lock with its key taped to it.');
ok(str_contains($escalation[0], 'permission'), 'and the refusal must say so');
ok(Navigation::problems(['items' => ['recruitment' => ['route' => 'trail']]], $shipped, $routes) !== [],
    'Repointing an item at another screen must be refused too.');
ok(Navigation::problems(['sections' => ['hiring' => ['permission' => 'x']]], $shipped,
    $routes) !== [], 'and on a section as well.');

ok(Navigation::problems(['items' => ['no_such' => ['label' => 'x']]], $shipped, $routes) !== [],
    'An item the menu does not have must be refused, not ignored.');
ok(Navigation::problems(['items' => ['recruitment' => ['label' => '  ']]], $shipped, $routes) !== [],
    'A nameless menu item is a blank row nobody can click with confidence.');
ok(Navigation::problems(['colour' => 'red'], $shipped, $routes) !== [],
    'Something that is not part of a menu must be refused.');

// A route the application no longer answers is reported.
ok(Navigation::problems([], $shipped, ['dashboard']) !== [],
    'A menu pointing at routes the application does not answer must be reported.');

// The overlay: renamed, reordered, hidden.
$applied = Navigation::overlay($shipped, [
    'sections' => ['hiring' => ['hidden' => true]],
    'items' => ['live-salary' => ['label' => 'Staff on roll', 'order' => 1]],
]);
$labels = [];
$sectionKeys = [];

foreach ($applied as $section) {
    $sectionKeys[(string) $section['key']] = $section;

    foreach ((array) $section['items'] as $item) {
        $labels[(string) $item['key']] = (string) $item['label'];
    }
}

same('Staff on roll', $labels['live-salary'], 'the customer\'s word for it');
same('Dashboard', $labels['dashboard'], 'and an item nobody touched is unchanged');
ok(($sectionKeys['hiring']['hidden'] ?? false) === true, 'a hidden section is marked hidden');
same('live-salary', (string) $sectionKeys['main']['items'][0]['key'],
    'and order moves the item to the top of its section');

// forViewer: MARKED, not removed.
$viewerCanSeeNothing = static fn (string $p): bool => false;
$drawn = (new Navigation(__DIR__ . '/../config/navigation.json'))
    ->forViewer($viewerCanSeeNothing, ['inbox' => 3]);
$items = [];

foreach ($drawn as $section) {
    foreach ($section['items'] as $item) {
        $items[(string) $item['key']] = $item;
    }
}

ok(isset($items['recruitment']), 'An item the viewer may not open is still LISTED.');
ok($items['recruitment']['allowed'] === false, 'and marked as not theirs to open');
ok($items['dashboard']['allowed'] === false, 'and the Dashboard, behind its own menu code since 22 Oct (home.dashboard.view), is marked too');
same(3, $items['inbox']['count'], 'a badge carries its live count');
ok(count($drawn) === 17, 'every one of the seventeen product sections is drawn (Organization Setup / Configuration is its own section) (the Super Admin platform section is hidden here by design), empty ones included — the shape of the product is the same for everybody');
same(null, $items['dashboard']['count'], 'and an item with no badge has no count, not a zero');

// A missing registry is refused rather than read as "no menu".
$missing = new Navigation(__DIR__ . '/../config/does-not-exist.json');
throws(RuntimeException::class, static fn () => $missing->sections(),
    'A missing menu file must be refused, not rendered as an empty sidebar.');
