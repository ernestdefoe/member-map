<?php

namespace ErnestDefoe\MemberMap;

use ErnestDefoe\MemberMap\Api\Point;
use ErnestDefoe\MemberMap\Api\Problem;
use ErnestDefoe\MemberMap\Api\RateLimit;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * The place a member's point is in, for saving: the point is checked, the
 * member's lookups are counted, and only the place's centre comes back (see
 * Geocoder::place). Shared by the location, trip and meetup resources.
 */
class Places
{
    public function __construct(
        private readonly Geocoder $geocoder,
        private readonly RateLimit $limit,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $input with lat and lng
     * @param array{0: string, 1: int}|null $daily a per-day cap on this kind of save: [kind, max], checked here and counted by the caller once saved
     * @return array{lat: float, lng: float, label: string, country_code: string|null, region: string|null, timezone: string|null}
     */
    public function around(User $actor, array $input, string $precision, ?array $daily = null): array
    {
        if (! Point::valid($input) || ! in_array($precision, Precision::ALL, true)) {
            throw new Problem('member_map_bad_point');
        }

        if ($this->limit->exceeded($actor) || ($daily && $this->limit->dailyExceeded($actor, $daily[0], $daily[1]))) {
            throw new Problem('member_map_slow_down');
        }

        $place = $this->geocoder->place((float) $input['lat'], Point::lng((float) $input['lng']), $precision, $this->locale(), $actor);

        if (! $place) {
            throw new Problem('member_map_no_place');
        }

        return $place;
    }

    /** Saved names are in the forum's language, so every reader sees the same label. */
    public function locale(): string
    {
        return (string) $this->settings->get('default_locale');
    }
}
