<?php

declare(strict_types=1);

use SmartPRS\Surface\GeoFence;
use SmartPRS\Surface\Store;

group('geofence');

// Owner, 12 Oct: "it should be checked while logging in and allow punch in only if it is within the specified range".
$store = (new Store(sys_get_temp_dir() . '/gf-' . bin2hex(random_bytes(4))))->forTenant('1');
$home = ['id' => '1', 'emp_code' => 'E1', 'name' => 'Field Exec', 'home_lat' => '17.4600', 'home_lng' => '78.3700', 'geo_radius_km' => '1.0000'];
$ruled = ['id' => '2', 'emp_code' => 'E2', 'name' => 'Rule Person', 'home_lat' => '17.4600', 'home_lng' => '78.3700', 'geo_radius_km' => '1'];
$none = ['id' => '3', 'emp_code' => 'E3', 'name' => 'Office Person'];
$store->put('geofence_rules', ['employee_id' => '2', 'start' => 'Office', 'lat' => '17.4485', 'lng' => '78.3908', 'radius_km' => '0.5', 'outside' => '1km']);
$gf = new GeoFence($store);

same(null, $gf->fenceFor($none), 'no rule and no home location: no fence');
same('home', $gf->fenceFor($home)['source'] ?? null, 'the home location on the record is a fence');
same('rule', $gf->fenceFor($ruled)['source'] ?? null, 'a Geo-Fence Rule wins over the home location');

ok($gf->check($none, '', '')['ok'], 'nobody fenced: any punch is allowed');
ok(!$gf->check($home, '', '')['ok'], 'fenced and no location: refused');
ok($gf->check($home, '17.4650', '78.3700')['ok'], 'about 0.55 km from home, radius 1 km: allowed');
$far = $gf->check($home, '17.4800', '78.3700');
ok(!$far['ok'], 'about 2.2 km from home, radius 1 km: refused');
ok(str_contains($far['why'], '2.2 km') && str_contains($far['why'], '1 km'), 'and the reason says how far and the allowed radius: ' . $far['why']);
ok($gf->check($ruled, '17.4485', '78.4040')['ok'], 'about 1.4 km from the office, radius 0.5 + 1 km tolerance: allowed');
ok(!$gf->check($ruled, '17.4485', '78.4150')['ok'], 'about 2.6 km: beyond radius + tolerance, refused');
ok(!$gf->check($home, 'abc', '78')['ok'], 'a location that is not numbers is refused');
