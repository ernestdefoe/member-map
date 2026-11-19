<?php

namespace ErnestDefoe\MemberMap;

use ErnestDefoe\MemberMap\Notification\TripNearbyBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Who hears about a trip: members who live within the admin's radius of it
 * (160 km, 100 mi, by default), may see the map, and haven't switched the
 * alert off (NotificationSyncer reads their preference). The nearest 50 at
 * most by default, found with one bounding-box query.
 */
class Trips
{
    public function __construct(
        private readonly MemberQuery $members,
        private readonly NotificationSyncer $notifications,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function notify(Trip $trip, User $traveler): void
    {
        // Clamped here as well as in the admin form: a hand-made settings request can't make the query unbounded.
        $radius = max(10, min(1000, (int) $this->settings->get(Settings::TRIP_RADIUS_KM)));
        $recipients = max(1, min(200, (int) $this->settings->get(Settings::TRIP_MAX_ALERTS)));
        $table = (new Location())->getTable();
        $dLat = $radius / 111;
        $dLng = $dLat / max(0.1, cos(deg2rad($trip->lat)));

        $nearby = $this->members->permitted($traveler, Settings::VIEW)
            ->join($table, "$table.user_id", '=', 'users.id')
            ->where('users.id', '!=', $traveler->id)
            ->whereBetween("$table.lat", [$trip->lat - $dLat, $trip->lat + $dLat])
            ->whereBetween("$table.lng", [$trip->lng - $dLng, $trip->lng + $dLng])
            ->limit(500)
            ->get(['users.*', "$table.lat as mm_lat", "$table.lng as mm_lng"])
            ->map(fn (User $user) => [$user, self::km($trip->lat, $trip->lng, (float) $user->getAttribute('mm_lat'), (float) $user->getAttribute('mm_lng'))])
            ->filter(fn ($pair) => $pair[1] <= $radius)
            ->sortBy(fn ($pair) => $pair[1])
            ->take($recipients)
            ->map(fn ($pair) => $pair[0])
            ->values()
            ->all();

        if ($nearby) {
            $this->notifications->sync(new TripNearbyBlueprint($trip, $traveler), $nearby);
        }
    }

    public function forget(Trip $trip, User $traveler): void
    {
        $this->notifications->delete(new TripNearbyBlueprint($trip, $traveler));
    }

    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 12742 * asin(min(1, sqrt($a)));
    }
}
