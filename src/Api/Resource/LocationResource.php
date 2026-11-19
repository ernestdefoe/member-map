<?php

namespace ErnestDefoe\MemberMap\Api\Resource;

use Carbon\Carbon;
use ErnestDefoe\MemberMap\Location;
use ErnestDefoe\MemberMap\Places;
use ErnestDefoe\MemberMap\Settings;
use ErnestDefoe\MemberMap\Timezones;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `member-map-locations` type: the viewer's own place on the map, keyed by their user id.
 *
 * POST   /api/member-map-locations       {lat, lng, precision, profile?, membersOnly?, browserTimezone?}  puts the viewer on the map
 * PATCH  /api/member-map-locations/{me}  {profile?, membersOnly?}                                         only changes who sees it
 * DELETE /api/member-map-locations/{me}                                                                    takes them off (allowed without the list permission: leaving must always work)
 *
 * 🚨 The point sent is never stored. It is looked up (Places::around) and the
 * centre of the city, region or country around it is saved, rounded, so the
 * privacy promise holds even against a hand-made request. POST replaces the
 * viewer's place if they already have one.
 *
 * @extends AbstractDatabaseResource<Location>
 */
class LocationResource extends AbstractDatabaseResource
{
    public function __construct(private readonly Places $places)
    {
    }

    public function type(): string
    {
        return 'member-map-locations';
    }

    public function model(): string
    {
        return Location::class;
    }

    /** @param Builder<Location> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $query->where('user_id', $context->getActor()->isGuest() ? 0 : $context->getActor()->id);
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Create::make()->authenticated()->can(Settings::LIST),
            Endpoint\Update::make()->authenticated()->can(Settings::LIST),
            Endpoint\Delete::make()->authenticated(),
        ];
    }

    public function fields(): array
    {
        return [
            // The point to look up: read on create, never written to the model.
            Schema\Number::make('lat')->writableOnCreate()->requiredOnCreate()->set(fn () => null),
            Schema\Number::make('lng')->writableOnCreate()->requiredOnCreate()->set(fn () => null),
            Schema\Str::make('browserTimezone')->writableOnCreate()->nullable()->maxLength(64)->visible(false)->set(fn () => null),
            Schema\Str::make('precision')->writableOnCreate()->requiredOnCreate(),
            Schema\Str::make('label'),
            Schema\Boolean::make('profile')->property('show_on_profile')->writable(),
            Schema\Boolean::make('membersOnly')->property('members_only')->writable(),
            Schema\Boolean::make('listed')->get(fn (Location $location, Context $context) => $context->getActor()->can(Settings::LIST)),
        ];
    }

    /** @param Context $context */
    public function creating(object $model, BaseContext $context): ?object
    {
        $actor = $context->getActor();
        $input = (array) ($context->body()['data']['attributes'] ?? []);
        $place = $this->places->around($actor, $input, (string) $model->precision);

        // One place per member: a second POST moves it.
        $location = Location::find($actor->id) ?? $model;
        $location->user_id = $actor->id;
        $location->lat = $place['lat'];
        $location->lng = $place['lng'];
        $location->label = $place['label'];
        $location->country_code = $place['country_code'];
        $location->timezone = Timezones::pick($input['browserTimezone'] ?? null, $place['country_code'], $place['lat'], $place['lng'], (string) $model->precision, $place['region']);
        $location->precision = (string) $model->precision;
        $location->show_on_profile = $model->getAttribute('show_on_profile') ?? $location->getAttribute('show_on_profile') ?? true;
        $location->members_only = $model->getAttribute('members_only') ?? $location->getAttribute('members_only') ?? false;
        $location->created_at ??= Carbon::now();

        return $location;
    }

    /** @param Context $context */
    public function saving(object $model, BaseContext $context): ?object
    {
        $model->updated_at = Carbon::now();

        return $model;
    }
}
