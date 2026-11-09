<?php

namespace ErnestDefoe\MemberMap\Api;

use ErnestDefoe\MemberMap\Location;
use ErnestDefoe\MemberMap\Settings;
use Flarum\Api\Context;
use Flarum\Api\Schema\Attribute;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * `memberMapLocation` on every serialized user: the "City, Country" line for
 * the profile and user card, when the member chose to show it and the viewer
 * may see the map.
 *
 * `memberMapOwn` only on the viewer's own user: their saved place, for the
 * settings page and the location form.
 *
 * 🚨 One query per request, however many users are on the page: the getter
 * queues its user and returns a deferred value; the first one resolved loads
 * every queued user's location at once. Viewers who can't see the map, or a
 * forum with the line turned off, cost nothing. State is keyed by the request
 * in a WeakMap, never kept on a model.
 */
class UserFields
{
    /** @var WeakMap<ServerRequestInterface, array{queued: array<int, true>, rows: array<int, Location|null>}> */
    private WeakMap $byRequest;

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
        $this->byRequest = new WeakMap();
    }

    /** @return list<Attribute> */
    public function __invoke(): array
    {
        return [
            Attribute::make('memberMapLocation')
                ->get(function (User $user, Context $context) {
                    if (! $this->settings->get(Settings::PROFILE) || ! $context->getActor()->can(Settings::VIEW)) {
                        return null;
                    }

                    return $this->deferred($context, $user, fn (Location $row) => $this->shown($row, $context) ? $row->label : null);
                }),

            // The same place as a point, for the profile's mini-map: the rounded centre the map already shows.
            Attribute::make('memberMapPoint')
                ->get(function (User $user, Context $context) {
                    if (! $this->settings->get(Settings::PROFILE) || ! $context->getActor()->can(Settings::VIEW)) {
                        return null;
                    }

                    return $this->deferred($context, $user, fn (Location $row) => $this->shown($row, $context) ? [$row->lat, $row->lng, $row->precision] : null);
                }),

            Attribute::make('memberMapOwn')
                ->visible(fn (User $user, Context $context) => $context->getActor()->id === $user->id)
                ->get(fn (User $user, Context $context) => $this->deferred($context, $user, fn (Location $row) => MapController::own(
                    $row->lat, $row->lng, $row->label, $row->precision, $row->show_on_profile, (bool) $row->members_only, $user->can(Settings::LIST),
                ))),
        ];
    }

    /** Shown on profiles: the member chose it, and asked to be seen by guests too if the viewer is one. */
    private function shown(Location $row, Context $context): bool
    {
        return $row->show_on_profile && ! ($row->members_only && $context->getActor()->isGuest());
    }

    /** Queue the user, and answer once every queued user's row is loaded. */
    private function deferred(Context $context, User $user, callable $value): \Closure
    {
        $request = $context->request;
        $id = (int) $user->id;
        $state = $this->byRequest[$request] ?? ['queued' => [], 'rows' => []];

        if (! array_key_exists($id, $state['rows'])) {
            $state['queued'][$id] = true;
            $this->byRequest[$request] = $state;
        }

        return function () use ($request, $id, $value) {
            $row = $this->load($request)[$id] ?? null;

            return $row ? $value($row) : null;
        };
    }

    /** @return array<int, Location|null> */
    private function load(ServerRequestInterface $request): array
    {
        $state = $this->byRequest[$request];

        if (! $state['queued']) {
            return $state['rows'];
        }

        $ids = array_keys($state['queued']);
        $rows = Location::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');

        foreach ($ids as $id) {
            $state['rows'][$id] = $rows[$id] ?? null;
        }
        $state['queued'] = [];
        $this->byRequest[$request] = $state;

        return $state['rows'];
    }
}
