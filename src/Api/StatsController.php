<?php

namespace ErnestDefoe\MemberMap\Api;

use ErnestDefoe\MemberMap\Location;
use ErnestDefoe\MemberMap\MemberQuery;
use ErnestDefoe\MemberMap\Settings;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/member-map/stats  ->  {members, countries: [[code, count], ...]}
 *
 * For the "Where we are" widget, which sits on pages that aren't the map:
 * counts only, no names or places. One grouped query, cached ten minutes
 * (one entry for guests, one for members: guests don't count members-only).
 */
class StatsController implements RequestHandlerInterface
{
    public function __construct(
        private readonly MemberQuery $members,
        private readonly Cache $cache,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        if (Crawlers::is($request)) {
            return Errors::make(403, 'permission_denied');
        }

        $table = (new Location())->getTable();
        $key = 'member-map:stats:'.($actor->isGuest() ? 'guest' : 'member');

        $stats = $this->cache->remember($key, 600, function () use ($actor, $table) {
            $rows = $this->members->listable($actor)
                ->toBase()
                ->selectRaw('count(*) as aggregate')
                ->addSelect("$table.country_code")
                ->groupBy("$table.country_code")
                ->get();

            $countries = [];
            $total = 0;
            foreach ($rows as $row) {
                $total += (int) $row->aggregate;
                if ($row->country_code) {
                    $countries[] = [(string) $row->country_code, (int) $row->aggregate];
                }
            }
            usort($countries, fn ($a, $b) => $b[1] <=> $a[1]);

            return ['members' => $total, 'countries' => $countries];
        });

        return (new JsonResponse($stats))->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
