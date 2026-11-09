<?php

namespace ErnestDefoe\MemberMap;

/**
 * How exactly a member is placed. Each level maps to the Nominatim zoom that
 * returns a place of that size, and to how far its centre is rounded.
 */
final class Precision
{
    public const CITY = 'city';
    public const REGION = 'region';
    public const COUNTRY = 'country';

    public const ALL = [self::CITY, self::REGION, self::COUNTRY];

    /** Nominatim reverse zoom: 10 = city, 5 = state, 3 = country. */
    public static function zoom(string $precision): int
    {
        return match ($precision) {
            self::CITY => 10,
            self::REGION => 5,
            default => 3,
        };
    }

    /** Decimal places kept: 2 is about 1 km, 1 is about 11 km. */
    public static function decimals(string $precision): int
    {
        return $precision === self::CITY ? 2 : 1;
    }
}
