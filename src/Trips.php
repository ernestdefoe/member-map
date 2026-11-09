<?php

namespace ErnestDefoe\MemberMap;

use Carbon\Carbon;
use ErnestDefoe\MemberMap\Notification\TripNearbyBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;

/**
 * Who hears about a trip: members who live within 160 km (100 mi) of it, may
 * see the map, and haven't switched the alert off (NotificationSyncer reads
 * their preference). The 50 nearest at most, found with one bounding-box query.
 */
class Trips
{
    public const RADIUS_KM = 160;
    private const MAX_RECIPIENTS = 50;

    public function __construct(
        private readonly MemberQuery $members,
        private readonly NotificationSyncer $notifications,
    ) {
    }

    public function notify(Trip $trip, User $traveler): void
    {
        $table = (new Location())->getTable();
        $dLat = self::RADIUS_KM / 111;
        $dLng = $dLat / max(0.1, cos(deg2rad($trip->lat)));

        $nearby = $this->members->permitted($traveler, Settings::VIEW)
            ->join($table, "$table.user_id", '=', 'users.id')
            ->where('users.id', '!=', $traveler->id)
            ->whereBetween("$table.lat", [$trip->lat - $dLat, $trip->lat + $dLat])
            ->whereBetween("$table.lng", [$trip->lng - $dLng, $trip->lng + $dLng])
            ->limit(500)
            ->get(['users.*', "$table.lat as mm_lat", "$table.lng as mm_lng"])
            ->map(fn (User $user) => [$user, self::km($trip->lat, $trip->lng, (float) $user->getAttribute('mm_lat'), (float) $user->getAttribute('mm_lng'))])
            ->filter(fn ($pair) => $pair[1] <= self::RADIUS_KM)
            ->sortBy(fn ($pair) => $pair[1])
            ->take(self::MAX_RECIPIENTS)
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

    /** Ended trips are kept a month (notifications still name them), then deleted. */
    public static function prune(): void
    {
        Trip::query()->where('ends_on', '<', Carbon::today()->subDays(30)->toDateString())->delete();
    }

    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 12742 * asin(min(1, sqrt($a)));
    }
}
