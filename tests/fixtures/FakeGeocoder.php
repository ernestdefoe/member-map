<?php

namespace ErnestDefoe\MemberMap\Tests\fixtures;

use ErnestDefoe\MemberMap\Geocoder;
use ErnestDefoe\MemberMap\GeocoderUnavailable;

/** Answers like Nominatim, without the network, and counts the calls. */
class FakeGeocoder extends Geocoder
{
    public int $calls = 0;
    public bool $down = false;
    /** Space lookups a second apart, as production does (off by default, so tests can look up quickly). */
    public bool $throttled = false;

    protected function throttle(callable $request): ?array
    {
        return $this->throttled ? parent::throttle($request) : [$request()];
    }

    protected function fetch(string $endpoint, array $query): mixed
    {
        $this->calls++;
        if ($this->down) {
            throw new GeocoderUnavailable('down');
        }

        if ($endpoint === 'search') {
            return [['display_name' => 'Nashville, Davidson County, Tennessee, United States', 'lat' => '36.1622767', 'lon' => '-86.7742984']];
        }

        // Open sea.
        if ((float) $query['lat'] < 0) {
            return ['error' => 'Unable to geocode'];
        }

        return match ($query['zoom']) {
            10 => ['lat' => '36.1622767', 'lon' => '-86.7742984', 'name' => 'Nashville', 'address' => ['city' => 'Nashville', 'county' => 'Davidson County', 'state' => 'Tennessee', 'ISO3166-2-lvl4' => 'US-TN', 'country' => 'United States', 'country_code' => 'us']],
            5 => ['lat' => '35.7730076', 'lon' => '-86.2820081', 'name' => 'Tennessee', 'address' => ['state' => 'Tennessee', 'ISO3166-2-lvl4' => 'US-TN', 'country' => 'United States', 'country_code' => 'us']],
            default => ['lat' => '39.7837304', 'lon' => '-100.4458820', 'name' => 'United States', 'address' => ['country' => 'United States', 'country_code' => 'us']],
        };
    }
}
