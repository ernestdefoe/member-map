<?php

namespace ErnestDefoe\MemberMap\Api;

use Carbon\Carbon;
use ErnestDefoe\MemberMap\Geocoder;
use ErnestDefoe\MemberMap\GeocoderUnavailable;
use ErnestDefoe\MemberMap\Precision;
use ErnestDefoe\MemberMap\Settings;
use ErnestDefoe\MemberMap\Trip;
use ErnestDefoe\MemberMap\Trips;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST   /api/member-map/trips        {lat, lng, starts, ends}   "I'm visiting Chicago Dec 3-7"
 * DELETE /api/member-map/trips/{id}                               the traveler takes it down
 *
 * Saved like a home place: the city's centre, never the point sent. Members
 * who live near it are told (Trips::notify). It stops showing after it ends.
 */
class TripController implements RequestHandlerInterface
{
    public const MAX_CURRENT = 5;
    public const MAX_DAYS = 60;

    public function __construct(
        private readonly Geocoder $geocoder,
        private readonly RateLimit $limit,
        private readonly SettingsRepositoryInterface $settings,
        private readonly Trips $trips,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if ($request->getMethod() === 'DELETE') {
            $trip = Trip::query()->where('user_id', $actor->id)->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
            $this->trips->forget($trip, $actor);
            $trip->delete();

            return new EmptyResponse(204);
        }

        $actor->assertCan(Settings::TRAVEL);
        $body = (array) $request->getParsedBody();

        $starts = self::date($body['starts'] ?? null);
        $ends = self::date($body['ends'] ?? null);
        $today = Carbon::today();
        if (! Point::valid($body) || ! $starts || ! $ends || $ends < $starts || $ends < $today->copy()->subDay()
            || $starts > $today->copy()->addYear() || $starts->diffInDays($ends) > self::MAX_DAYS) {
            return Errors::make(422, 'member_map_bad_trip');
        }

        if (Trip::current()->where('user_id', $actor->id)->count() >= self::MAX_CURRENT) {
            return Errors::make(422, 'member_map_too_many_trips');
        }
        if ($this->limit->exceeded($actor)) {
            return Errors::make(429, 'member_map_slow_down');
        }

        try {
            $place = $this->geocoder->place((float) $body['lat'], Point::lng((float) $body['lng']), Precision::CITY, Point::locale($this->settings));
        } catch (GeocoderUnavailable) {
            return Errors::make(503, 'member_map_unavailable');
        }
        if (! $place) {
            return Errors::make(422, 'member_map_no_place');
        }

        $trip = new Trip();
        $trip->user_id = $actor->id;
        $trip->lat = $place['lat'];
        $trip->lng = $place['lng'];
        $trip->label = $place['label'];
        $trip->country_code = $place['country_code'];
        $trip->starts_on = $starts;
        $trip->ends_on = $ends;
        $trip->created_at = Carbon::now();
        $trip->save();

        Trips::prune();
        $this->trips->notify($trip, $actor);

        return new JsonResponse(['trip' => $trip->toRow()], 201);
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = Carbon::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
