<?php

namespace ErnestDefoe\MemberMap\Api\Resource;

use ErnestDefoe\MemberMap\Api\Crawlers;
use ErnestDefoe\MemberMap\Location;
use ErnestDefoe\MemberMap\MemberQuery;
use ErnestDefoe\MemberMap\Settings;
use Flarum\Api\Context as FlarumContext;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractResource;
use Flarum\Api\Resource\Contracts\Findable;
use Flarum\Api\Schema;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Tobyz\JsonApiServer\Context;

/**
 * The `member-map-stats` type, one record: GET /api/member-map-stats/map
 *  ->  {members, countries: [[code, count], ...]}
 *
 * For the "Where we are" widget, which sits on pages that aren't the map:
 * counts only, no names or places. One grouped query, cached ten minutes
 * per set of groups: who a viewer may see (members-only places, hidden users)
 * follows their groups, so viewers in the same groups share one count.
 * Never served to crawlers.
 */
class StatsResource extends AbstractResource implements Findable
{
    public function __construct(
        private readonly MemberQuery $members,
        private readonly Cache $cache,
    ) {
    }

    public function type(): string
    {
        return 'member-map-stats';
    }

    public function getId(object $model, Context $context): string
    {
        return 'map';
    }

    public function find(string $id, Context $context): ?object
    {
        Crawlers::refuse($context->request);
        $actor = $context->getActor();

        $groups = $actor->isGuest() ? 'guest' : implode(',', $actor->groups->pluck('id')->sort()->all());

        return (object) $this->cache->remember('member-map:stats:'.sha1($groups), 600, fn () => $this->count($actor));
    }

    public function endpoints(): array
    {
        return [
            // Asked before the counts are found (and not with ->can(), which would pass them to the gate as a model).
            Endpoint\Show::make()->before(fn (FlarumContext $context) => $context->getActor()->assertCan(Settings::VIEW)),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('members')->get(fn (object $stats) => $stats->members),
            Schema\Arr::make('countries')->get(fn (object $stats) => $stats->countries),
        ];
    }

    /** @return array{members: int, countries: list<array{0: string, 1: int}>} */
    private function count(User $actor): array
    {
        $table = (new Location())->getTable();
        $rows = $this->members->listable($actor)
            ->toBase()
            // An aggregate beside a grouped column: the builder has no method for count() in a select list.
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
    }
}
