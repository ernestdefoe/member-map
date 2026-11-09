<?php

namespace ErnestDefoe\MemberMap;

use DateTimeZone;

/**
 * The time zone of a place, worked out offline from PHP's own zone data: a
 * country with one zone has it; otherwise the zone scoring best on distance
 * to its reference city plus how far its standard offset is from what the
 * longitude suggests. Nearest city alone put Seattle in Boise's zone; the
 * offset term fixes that without breaking Detroit or Madrid. No third party.
 */
final class Timezones
{
    /**
     * The browser's own zone when it belongs to the place's country (a member
     * usually saves from where they are), else the guess below. Reference
     * cities alone can't place every city right without boundary data: Austin
     * scored nearer Denver than Chicago.
     */
    public static function pick(mixed $browser, ?string $countryCode, float $lat, float $lng, string $precision, ?string $region = null): ?string
    {
        if (is_string($browser) && $countryCode && strlen($countryCode) === 2
            && in_array($browser, DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, strtoupper($countryCode)), true)) {
            return $browser;
        }

        return self::guess($countryCode, $lat, $lng, $precision, $region);
    }

    public static function guess(?string $countryCode, float $lat, float $lng, string $precision, ?string $region = null): ?string
    {
        if (! $countryCode || strlen($countryCode) !== 2) {
            return null;
        }

        $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, strtoupper($countryCode));
        if (count($zones) === 1) {
            return $zones[0];
        }
        // A whole country with several zones has no one local time.
        if (! $zones || $precision === Precision::COUNTRY) {
            return null;
        }
        // The state's own zone, where we know it (the US, Canada, Australia).
        if ($zone = RegionZones::find($region)) {
            return $zone;
        }

        // Standard time is the smaller offset of January and July (one of them is summer, wherever you are).
        $jan = new \DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC'));
        $jul = new \DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone('UTC'));
        $solar = $lng / 15;
        $best = null;
        $bestScore = INF;
        foreach ($zones as $zone) {
            $tz = new DateTimeZone($zone);
            $location = $tz->getLocation();
            if (! $location) {
                continue;
            }
            $dLat = deg2rad($location['latitude'] - $lat);
            $dLng = deg2rad($location['longitude'] - $lng) * cos(deg2rad($lat));
            $km = 6371 * sqrt($dLat * $dLat + $dLng * $dLng);
            $score = $km + 1000 * abs(min($tz->getOffset($jan), $tz->getOffset($jul)) / 3600 - $solar);
            if ($score < $bestScore) {
                [$best, $bestScore] = [$zone, $score];
            }
        }

        return $best;
    }
}
