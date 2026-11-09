<?php

namespace ErnestDefoe\MemberMap;

/**
 * The time zone of each state or province in the US, Canada and Australia,
 * by ISO code (the same whatever language the forum asks Nominatim for).
 * Where a state spans two zones, the one most of its people live in.
 */
final class RegionZones
{
    private const ZONES = [
        'America/New_York' => 'US-CT US-DE US-DC US-FL US-GA US-ME US-MD US-MA US-NH US-NJ US-NY US-NC US-OH US-PA US-RI US-SC US-VT US-VA US-WV',
        'America/Detroit' => 'US-MI',
        'America/Kentucky/Louisville' => 'US-KY',
        'America/Indiana/Indianapolis' => 'US-IN',
        'America/Chicago' => 'US-AL US-AR US-IL US-IA US-KS US-LA US-MN US-MS US-MO US-NE US-ND US-OK US-SD US-TN US-TX US-WI',
        'America/Denver' => 'US-CO US-MT US-NM US-UT US-WY',
        'America/Boise' => 'US-ID',
        'America/Phoenix' => 'US-AZ',
        'America/Los_Angeles' => 'US-CA US-NV US-OR US-WA',
        'America/Anchorage' => 'US-AK',
        'Pacific/Honolulu' => 'US-HI',
        'America/Toronto' => 'CA-ON CA-QC',
        'America/Halifax' => 'CA-NS CA-NB CA-PE',
        'America/St_Johns' => 'CA-NL',
        'America/Winnipeg' => 'CA-MB',
        'America/Regina' => 'CA-SK',
        'America/Edmonton' => 'CA-AB CA-NT',
        'America/Vancouver' => 'CA-BC',
        'America/Whitehorse' => 'CA-YT',
        'America/Iqaluit' => 'CA-NU',
        'Australia/Sydney' => 'AU-NSW AU-ACT',
        'Australia/Melbourne' => 'AU-VIC',
        'Australia/Brisbane' => 'AU-QLD',
        'Australia/Adelaide' => 'AU-SA',
        'Australia/Darwin' => 'AU-NT',
        'Australia/Perth' => 'AU-WA',
        'Australia/Hobart' => 'AU-TAS',
    ];

    /** @param string|null $region an ISO 3166-2 code, as Nominatim gives it in `ISO3166-2-lvl4` ("US-TX") */
    public static function find(?string $region): ?string
    {
        if (! $region || ! preg_match('/^[A-Z]{2}-[A-Z0-9]{1,3}$/', $region)) {
            return null;
        }
        foreach (self::ZONES as $zone => $regions) {
            if (in_array($region, explode(' ', $regions), true)) {
                return $zone;
            }
        }

        return null;
    }
}
