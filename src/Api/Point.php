<?php

namespace ErnestDefoe\MemberMap\Api;

/** A point a member sent: checked before it is looked up, and never stored. */
final class Point
{
    /** @param array<string, mixed> $input */
    public static function valid(array $input): bool
    {
        return is_numeric($input['lat'] ?? null) && is_numeric($input['lng'] ?? null)
            && abs((float) $input['lat']) <= 90 && is_finite((float) $input['lng']) && abs((float) $input['lng']) <= 100000;
    }

    /** A longitude from a map scrolled round the world (200 = -160). */
    public static function lng(float $lng): float
    {
        return fmod(fmod($lng + 180, 360) + 360, 360) - 180;
    }
}
