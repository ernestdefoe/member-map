<?php

namespace ErnestDefoe\MemberMap\Api;

use ErnestDefoe\MemberMap\Geocoder;
use ErnestDefoe\MemberMap\GeocoderUnavailable;
use ErnestDefoe\MemberMap\Precision;
use ErnestDefoe\MemberMap\Settings;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/member-map/geocode
 *
 *   ?q=Nashville                          places matching the text
 *   ?lat=36.15&lng=-86.78&precision=city  what a saved location there would show as
 *
 * Only for signed-in members who may see or join the map (search the map,
 * set a place, plan a trip or a meetup): it spends the forum's share of a
 * third-party service, so it is rate limited per member.
 */
class GeocodeController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Geocoder $geocoder,
        private readonly RateLimit $limit,
        private readonly TranslatorInterface $translator,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        if (! $actor->can(Settings::VIEW) && ! $actor->can(Settings::LIST)) {
            return Errors::make(403, 'permission_denied');
        }

        $params = $request->getQueryParams();

        if (isset($params['q'])) {
            $q = is_string($params['q']) ? trim($params['q']) : '';
            if (mb_strlen($q) < 2 || mb_strlen($q) > 120) {
                return Errors::make(422, 'member_map_bad_query');
            }
        } elseif (! Point::valid($params)) {
            return Errors::make(422, 'member_map_bad_point');
        }

        if ($this->limit->exceeded($actor)) {
            return Errors::make(429, 'member_map_slow_down');
        }

        try {
            if (isset($q)) {
                return new JsonResponse(['places' => $this->geocoder->search($q, $this->translator->getLocale())]);
            }

            $precision = in_array($params['precision'] ?? null, Precision::ALL, true) ? $params['precision'] : Precision::CITY;
            $place = $this->geocoder->place((float) $params['lat'], Point::lng((float) $params['lng']), $precision, Point::locale($this->settings));

            return $place ? new JsonResponse(['label' => $place['label']]) : Errors::make(422, 'member_map_no_place');
        } catch (GeocoderUnavailable) {
            return Errors::make(503, 'member_map_unavailable');
        }
    }
}
