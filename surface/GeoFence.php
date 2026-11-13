<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * IS THIS PUNCH INSIDE THE PERSON'S FENCE? (owner, 12 Oct: "it should be checked while logging in
 * and allow punch in only if it is within the specified range").
 *
 * The fence is the employee's Geo-Fence Rule (Time & Attendance → Geo-Fence Rules; the newest row
 * wins), else the Home Latitude / Longitude / Allowed Radius on their record. Nobody fenced = no
 * check: office staff on biometric devices are not affected. The rule's Outside Rule widens the
 * circle: Strict = none, "1km" / "2km" = that much more. Distance is great-circle (haversine).
 *
 * Coordinates are geometry, not money, so floats are right here (Law 7 is about money).
 */
final class GeoFence
{
    public const RULES = 'geofence_rules';

    public function __construct(private readonly RowStore $store)
    {
    }

    /** @return array{lat: float, lng: float, radius_km: float, tolerance_km: float, source: string, label: string}|null */
    public function fenceFor(array $employment): ?array
    {
        $rule = null;

        try {
            foreach ($this->store->all(self::RULES) as $r) {
                if ((string) ($r['employee_id'] ?? '') === (string) ($employment['id'] ?? '') && trim((string) ($r['deleted_at'] ?? '')) === ''
                    && ($rule === null || strcmp((string) ($r['created_at'] ?? ''), (string) ($rule['created_at'] ?? '')) >= 0)) {
                    $rule = $r;
                }
            }
        } catch (\Throwable) { // no geofence_rules register on this installation: the home fence alone applies
            $rule = null;
        }

        if ($rule !== null && is_numeric($rule['lat'] ?? null) && is_numeric($rule['lng'] ?? null)) {
            $tol = preg_match('/(\d+(?:\.\d+)?)\s*km/i', (string) ($rule['outside'] ?? ''), $m) === 1 ? (float) $m[1] : 0.0;

            return ['lat' => (float) $rule['lat'], 'lng' => (float) $rule['lng'], 'radius_km' => max(0.0, (float) ($rule['radius_km'] ?? 0)),
                'tolerance_km' => $tol, 'source' => 'rule', 'label' => 'your ' . strtolower(trim((string) ($rule['start'] ?? '')) ?: 'start') . ' point'];
        }

        if (is_numeric($employment['home_lat'] ?? null) && is_numeric($employment['home_lng'] ?? null)) {
            return ['lat' => (float) $employment['home_lat'], 'lng' => (float) $employment['home_lng'],
                'radius_km' => max(0.0, (float) ($employment['geo_radius_km'] ?? 0)) ?: 0.5, 'tolerance_km' => 0.0, 'source' => 'home', 'label' => 'your home location'];
        }

        return null;
    }

    /** @return array{ok: bool, why: string, distance_km?: float} */
    public function check(array $employment, string $lat, string $lng): array
    {
        $fence = $this->fenceFor($employment);

        if ($fence === null) {
            return ['ok' => true, 'why' => 'No geo-fence is set for you.'];
        }

        $allowed = $fence['radius_km'] + $fence['tolerance_km'];

        if (!is_numeric($lat) || !is_numeric($lng)) {
            return ['ok' => false, 'why' => 'Your location is needed to punch: you may punch within ' . self::km($allowed)
                . ' of ' . $fence['label'] . '. Allow location for this site in your browser and try again.'];
        }

        $d = self::distanceKm((float) $lat, (float) $lng, $fence['lat'], $fence['lng']);

        return $d <= $allowed
            ? ['ok' => true, 'distance_km' => $d, 'why' => 'Inside your geo-fence (' . self::km($d) . ' from ' . $fence['label'] . ').']
            : ['ok' => false, 'distance_km' => $d, 'why' => 'You are ' . self::km($d) . ' from ' . $fence['label'] . '; punching is allowed only within '
                . self::km($allowed) . '. Move inside the area, or ask HR to correct your Geo-Fence Rule.'];
    }

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private static function km(float $km): string
    {
        return rtrim(rtrim(number_format($km, 1, '.', ''), '0'), '.') . ' km';
    }
}
