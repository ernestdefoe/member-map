<?php

namespace ErnestDefoe\MemberMap\Api;

use ErnestDefoe\MemberMap\Geocoder;
use ErnestDefoe\MemberMap\Places;
use ErnestDefoe\MemberMap\Precision;
use ErnestDefoe\MemberMap\Settings;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
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
 * A plain handler, not a resource: a lookup at a third-party service, with
 * nothing stored. Only for signed-in members who may see or join the map
 * (search the map, set a place, plan a trip or a meetup), and rate limited
 * per member, since it spends the forum's share of that service.
 */
class GeocodeController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Geocoder $geocoder,
        private readonly Places $places,
        private readonly RateLimit $limit,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        if (! $actor->can(Settings::VIEW) && ! $actor->can(Settings::LIST)) {
            throw new PermissionDeniedException();
        }

        $params = $request->getQueryParams();

        if (! isset($params['q'])) {
            $precision = in_array($params['precision'] ?? null, Precision::ALL, true) ? $params['precision'] : Precision::CITY;

            return new JsonResponse(['label' => $this->places->around($actor, $params, $precision)['label']]);
        }

        $q = is_string($params['q']) ? trim($params['q']) : '';
        if (mb_strlen($q) < 2 || mb_strlen($q) > 120) {
            throw new Problem('member_map_bad_query');
        }
        if ($this->limit->exceeded($actor)) {
            throw new Problem('member_map_slow_down');
        }

        return new JsonResponse(['places' => $this->geocoder->search($q, $this->translator->getLocale(), $actor)]);
    }
}
