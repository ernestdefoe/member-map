<?php

namespace ErnestDefoe\MemberMap\Api;

use Carbon\Carbon;
use ErnestDefoe\MemberMap\Geocoder;
use ErnestDefoe\MemberMap\GeocoderUnavailable;
use ErnestDefoe\MemberMap\Location;
use ErnestDefoe\MemberMap\Precision;
use ErnestDefoe\MemberMap\Settings;
use ErnestDefoe\MemberMap\Timezones;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/member-map/location   {lat, lng, precision, profile, membersOnly}   puts the viewer on the map
 *                                  {profile, membersOnly}                       only changes who sees it
 *
 * 🚨 The point sent is never stored. It is looked up (Geocoder::place) and the
 * centre of the city, region or country around it is saved, rounded. So the
 * privacy promise holds even against a hand-made request.
 */
class SaveLocationController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Geocoder $geocoder,
        private readonly RateLimit $limit,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::LIST);

        $body = (array) $request->getParsedBody();
        $profile = array_key_exists('profile', $body) ? (bool) $body['profile'] : null;
        $membersOnly = array_key_exists('membersOnly', $body) ? (bool) $body['membersOnly'] : null;

        if (! isset($body['lat']) && ! isset($body['lng'])) {
            $location = Location::find($actor->id);
            if (! $location || ($profile === null && $membersOnly === null)) {
                return Errors::make(422, 'member_map_bad_point');
            }
            $location->show_on_profile = $profile ?? $location->show_on_profile;
            $location->members_only = $membersOnly ?? $location->members_only;
            $location->save();

            return $this->respond($location);
        }

        $precision = $body['precision'] ?? null;
        if (! Point::valid($body) || ! in_array($precision, Precision::ALL, true)) {
            return Errors::make(422, 'member_map_bad_point');
        }

        if ($this->limit->exceeded($actor)) {
            return Errors::make(429, 'member_map_slow_down');
        }

        try {
            $place = $this->geocoder->place((float) $body['lat'], Point::lng((float) $body['lng']), $precision, Point::locale($this->settings));
        } catch (GeocoderUnavailable) {
            return Errors::make(503, 'member_map_unavailable');
        }

        if (! $place) {
            return Errors::make(422, 'member_map_no_place');
        }

        $location = Location::find($actor->id) ?? new Location();
        $location->user_id = $actor->id;
        $location->lat = $place['lat'];
        $location->lng = $place['lng'];
        $location->label = $place['label'];
        $location->country_code = $place['country_code'];
        $location->timezone = Timezones::pick($body['timezone'] ?? null, $place['country_code'], $place['lat'], $place['lng'], $precision, $place['region']);
        $location->precision = $precision;
        $location->show_on_profile = $profile ?? $location->show_on_profile ?? true;
        $location->members_only = $membersOnly ?? $location->members_only ?? false;
        $location->created_at ??= Carbon::now();
        $location->updated_at = Carbon::now();
        $location->save();

        return $this->respond($location);
    }

    private function respond(Location $location): JsonResponse
    {
        return new JsonResponse(['place' => MapController::own($location->lat, $location->lng, $location->label, $location->precision, $location->show_on_profile, (bool) $location->members_only, true)]);
    }
}
