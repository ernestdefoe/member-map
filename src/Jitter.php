<?php

namespace ErnestDefoe\MemberMap;

/**
 * Optional scatter for city-level places, so members of one city don't sit
 * stacked on its exact centre. Applied when the map is served, from the
 * member's id, so it is the same on every visit and the setting can be
 * switched without rewriting rows. It moves a public point (a city centre)
 * by up to about 3 km: it hides nothing and reveals nothing.
 */
final class Jitter
{
    private const MAX = 0.03;

    /** @return array{float, float} */
    public static function apply(int $userId, float $lat, float $lng): array
    {
        $hash = hash('sha256', 'member-map-jitter:'.$userId);
        $a = hexdec(substr($hash, 0, 8)) / 0xFFFFFFFF;
        $b = hexdec(substr($hash, 8, 8)) / 0xFFFFFFFF;

        return [round($lat + ($a - 0.5) * 2 * self::MAX, 4), round($lng + ($b - 0.5) * 2 * self::MAX, 4)];
    }
}
