<?php

namespace ErnestDefoe\MemberMap\Api;

use Carbon\Carbon;
use ErnestDefoe\MemberMap\Integrations\Follows;
use ErnestDefoe\MemberMap\Jitter;
use ErnestDefoe\MemberMap\Location;
use ErnestDefoe\MemberMap\MemberQuery;
use ErnestDefoe\MemberMap\Meetup;
use ErnestDefoe\MemberMap\Precision;
use ErnestDefoe\MemberMap\Settings;
use ErnestDefoe\MemberMap\Trip;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Flarum\Http\SlugDriverInterface;
use Flarum\Http\SlugManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/member-map: everything the map draws, as lean rows.
 *
 *   members:  [[id, lat, lng, name, slug, avatar|null, countryCode|null, timezone|null, label, flags], ...]
 *             flags: 1 = online now, 2 = joined the map in the last 3 days (drops in)
 *   trips:    [[userId, lat, lng, label, starts, ends, name, slug, avatar|null], ...]   (members only)
 *   meetups:  [[id, lat, lng, label, startsAt, timezone|null, title, discussionId, discussionSlug], ...]
 *   me:       the viewer's own place and trips, or null
 *
 * ?group=4 and/or ?following=1 narrow the members to that group's, or to the
 * people the viewer follows (with Follow Users; nobody without it).
 *
 * A plain handler, not a resource: up to 10,000 markers as positional rows
 * are a fraction of the size of JSON:API documents, and only what the map
 * draws ever leaves the server. ONE query for the markers (MemberQuery).
 * Names, slugs, avatars and online state come from the loaded rows, so
 * nothing is queried per member. Travelers and meetups are one query each.
 * Never served to crawlers.
 */
class MapController implements RequestHandlerInterface
{
    public const NEW_DAYS = 3;

    public function __construct(
        private readonly MemberQuery $members,
        private readonly SlugManager $slugs,
        private readonly SettingsRepositoryInterface $settings,
        private readonly Follows $follows,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);
        Crawlers::refuse($request);

        $slugs = $this->slugs->forResource(User::class);
        [$members, $me] = $this->markers($actor, $slugs, $request->getQueryParams());

        // Saved, but not shown (no longer allowed to be listed): still theirs to see and remove.
        if (! $me && ! $actor->isGuest() && ($own = Location::find($actor->id))) {
            $me = self::own($own->lat, $own->lng, $own->label, $own->precision, $own->show_on_profile, $own->members_only, false);
        }

        return (new JsonResponse([
            'members' => $members,
            'trips' => $actor->isGuest() ? [] : $this->trips($actor, $slugs),
            'meetups' => $this->meetups($actor),
            'me' => $actor->isGuest() ? null : ['place' => $me, 'trips' => Trip::current()->where('user_id', $actor->id)->orderBy('starts_on')->get()->map(fn (Trip $trip) => $trip->toRow())->all()],
        ]))->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Every marker, and the viewer's own place when they are among them.
     *
     * @param array<string, mixed> $filters
     * @return array{0: list<array<int, mixed>>, 1: array<string, mixed>|null}
     */
    private function markers(User $actor, SlugDriverInterface $slugs, array $filters): array
    {
        $table = (new Location())->getTable();
        $users = $this->narrow($this->members->listable($actor), $actor, $filters)
            ->select('users.*', "$table.lat as mm_lat", "$table.lng as mm_lng", "$table.label as mm_label", "$table.precision as mm_precision", "$table.show_on_profile as mm_profile", "$table.country_code as mm_cc", "$table.timezone as mm_tz", "$table.members_only as mm_members_only", "$table.created_at as mm_created")
            ->orderByDesc("$table.updated_at")
            ->limit(MemberQuery::MAX)
            ->get();

        $jitter = (bool) $this->settings->get(Settings::JITTER);
        $seesLastSeen = $actor->hasPermission('user.viewLastSeenAt');
        $members = [];
        $me = null;

        foreach ($users as $user) {
            $lat = (float) $user->getAttribute('mm_lat');
            $lng = (float) $user->getAttribute('mm_lng');
            $precision = (string) $user->getAttribute('mm_precision');
            $label = (string) $user->getAttribute('mm_label');

            if ($user->id === $actor->id) {
                $me = self::own($lat, $lng, $label, $precision, (bool) $user->getAttribute('mm_profile'), (bool) $user->getAttribute('mm_members_only'), true);
            }
            if ($jitter && $precision === Precision::CITY) {
                [$lat, $lng] = Jitter::apply((int) $user->id, $lat, $lng);
            }

            $members[] = [(int) $user->id, $lat, $lng, $user->display_name, $slugs->toSlug($user), $user->avatar_url, $user->getAttribute('mm_cc'), $user->getAttribute('mm_tz'), $label, self::flags($user, $seesLastSeen)];
        }

        return [$members, $me];
    }

    /**
     * @param Builder<User> $query
     * @param array<string, mixed> $filters
     * @return Builder<User>
     */
    private function narrow(Builder $query, User $actor, array $filters): Builder
    {
        if (isset($filters['group'])) {
            $group = is_numeric($filters['group']) ? Group::whereVisibleTo($actor)->find((int) $filters['group']) : null;
            $query->whereIn('users.id', $group ? fn ($sub) => $sub->select('user_id')->from('group_user')->where('group_id', $group->id) : []);
        }
        if (! empty($filters['following'])) {
            $query->whereIn('users.id', $actor->isGuest() ? [] : $this->follows->followedBy($actor));
        }

        return $query;
    }

    /** 1 = online now (when the viewer may know it), 2 = joined the map in the last few days (drops in). */
    private static function flags(User $user, bool $seesLastSeen): int
    {
        $flags = 0;
        if ($user->last_seen_at && $user->last_seen_at > Carbon::now()->subMinutes(5) && ($seesLastSeen || $user->getPreference('discloseOnline'))) {
            $flags |= 1;
        }
        $created = $user->getAttribute('mm_created');
        if ($created && Carbon::parse($created) > Carbon::now()->subDays(self::NEW_DAYS)) {
            $flags |= 2;
        }

        return $flags;
    }

    /** @return list<array<int, mixed>> */
    private function trips(User $actor, SlugDriverInterface $slugs): array
    {
        $table = (new Trip())->getTable();

        return $this->members->permitted($actor, Settings::TRAVEL)
            ->join($table, "$table.user_id", '=', 'users.id')
            ->where("$table.ends_on", '>=', Carbon::today()->subDay()->toDateString())
            ->select('users.*', "$table.lat as mm_lat", "$table.lng as mm_lng", "$table.label as mm_label", "$table.starts_on as mm_starts", "$table.ends_on as mm_ends")
            ->orderBy("$table.starts_on")
            ->limit(500)
            ->get()
            ->map(fn (User $user) => [(int) $user->id, (float) $user->getAttribute('mm_lat'), (float) $user->getAttribute('mm_lng'), (string) $user->getAttribute('mm_label'), substr((string) $user->getAttribute('mm_starts'), 0, 10), substr((string) $user->getAttribute('mm_ends'), 0, 10), $user->display_name, $slugs->toSlug($user), $user->avatar_url])
            ->all();
    }

    /** @return list<array<int, mixed>> */
    private function meetups(User $actor): array
    {
        $table = (new Meetup())->getTable();
        $discussions = (new Discussion())->getTable();

        return Meetup::query()
            ->join($discussions, "$discussions.id", '=', "$table.discussion_id")
            ->whereIn("$table.discussion_id", Discussion::whereVisibleTo($actor)->select('discussions.id'))
            ->where("$table.starts_at", '>=', Carbon::now()->subDay())
            ->orderBy("$table.starts_at")
            ->limit(200)
            ->get(["$table.*", "$discussions.title as mm_title", "$discussions.slug as mm_slug"])
            ->map(fn (Meetup $m) => [$m->id, $m->lat, $m->lng, $m->label, $m->starts_at->toIso8601String(), $m->timezone, (string) $m->getAttribute('mm_title'), $m->discussion_id, (string) $m->getAttribute('mm_slug')])
            ->all();
    }

    /** @return array<string, mixed> */
    public static function own(float $lat, float $lng, string $label, string $precision, bool $profile, bool $membersOnly, bool $listed): array
    {
        return compact('lat', 'lng', 'label', 'precision', 'profile', 'membersOnly', 'listed');
    }
}
