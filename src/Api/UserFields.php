<?php

namespace ErnestDefoe\MemberMap\Api;

use ErnestDefoe\MemberMap\Location;
use ErnestDefoe\MemberMap\MemberQuery;
use ErnestDefoe\MemberMap\Settings;
use Flarum\Api\Context;
use Flarum\Api\Schema\Attribute;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * `memberMapLocation` / `memberMapPoint` on every serialized user: the "City,
 * Country" line and the mini-map's point for the profile and user card, for
 * members who are on the map (MemberQuery: still allowed to be listed, not
 * suspended, visible to the viewer), chose to show it, and when the viewer
 * may see the map.
 *
 * `memberMapOwn` only on the viewer's own user: their saved place, listed or
 * not, for the settings page and the location form (one lookup).
 *
 * 🚨 One query per request for the profile line, however many users are on
 * the page: the getter queues its user and returns a deferred value; the first
 * one resolved loads every queued user's place at once. Viewers who can't see
 * the map, or a forum with the line turned off, cost nothing. State is keyed
 * by the request in a WeakMap, never kept on a model.
 */
class UserFields
{
    /** @var WeakMap<ServerRequestInterface, array{queued: array<int, true>, rows: array<int, object|null>}> */
    private WeakMap $byRequest;

    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly MemberQuery $members,
    ) {
        $this->byRequest = new WeakMap();
    }

    /** @return list<Attribute> */
    public function __invoke(): array
    {
        return [
            Attribute::make('memberMapLocation')
                ->get(fn (User $user, Context $context) => $this->shows($context)
                    ? $this->deferred($context, $user, fn (object $row) => (string) $row->label)
                    : null),
            // The same place as a point, for the profile's mini-map: the rounded centre the map already shows.
            Attribute::make('memberMapPoint')
                ->get(fn (User $user, Context $context) => $this->shows($context)
                    ? $this->deferred($context, $user, fn (object $row) => [(float) $row->lat, (float) $row->lng, (string) $row->precision])
                    : null),
            Attribute::make('memberMapOwn')
                ->visible(fn (User $user, Context $context) => $context->getActor()->id === $user->id)
                ->get(function (User $user) {
                    $row = Location::find($user->id);

                    return $row ? MapController::own($row->lat, $row->lng, $row->label, $row->precision, $row->show_on_profile, (bool) $row->members_only, $user->can(Settings::LIST)) : null;
                }),
        ];
    }

    private function shows(Context $context): bool
    {
        return (bool) $this->settings->get(Settings::PROFILE) && $context->getActor()->can(Settings::VIEW);
    }

    /** Queue the user, and answer once every queued user's place is loaded. */
    private function deferred(Context $context, User $user, callable $value): \Closure
    {
        $request = $context->request;
        $id = (int) $user->id;
        $state = $this->byRequest[$request] ?? ['queued' => [], 'rows' => []];
        if (! array_key_exists($id, $state['rows'])) {
            $state['queued'][$id] = true;
            $this->byRequest[$request] = $state;
        }

        return function () use ($context, $request, $id, $value) {
            $row = $this->load($context, $request)[$id] ?? null;

            return $row ? $value($row) : null;
        };
    }

    /**
     * Every queued member who is on the map and chose to show their place on
     * their profile (members-only places are already left out for guests).
     *
     * @return array<int, object|null>
     */
    private function load(Context $context, ServerRequestInterface $request): array
    {
        $state = $this->byRequest[$request];
        if (! $state['queued']) {
            return $state['rows'];
        }

        $table = (new Location())->getTable();
        $ids = array_keys($state['queued']);
        $rows = $this->members->listable($context->getActor())
            ->whereIn('users.id', $ids)
            ->where("$table.show_on_profile", true)
            ->toBase()
            ->get(['users.id as uid', "$table.label", "$table.lat", "$table.lng", "$table.precision"])
            ->keyBy('uid');

        foreach ($ids as $id) {
            $state['rows'][$id] = $rows[$id] ?? null;
        }
        $state['queued'] = [];
        $this->byRequest[$request] = $state;

        return $state['rows'];
    }
}
