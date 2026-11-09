<?php

namespace ErnestDefoe\MemberMap\Api;

use Flarum\Settings\SettingsRepositoryInterface;

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

    /** Saved names are in the forum's language, so every reader sees the same label. */
    public static function locale(SettingsRepositoryInterface $settings): string
    {
        return (string) ($settings->get('default_locale') ?: 'en');
    }
}
