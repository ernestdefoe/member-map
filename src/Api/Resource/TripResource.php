<?php

namespace ErnestDefoe\MemberMap\Api\Resource;

use Carbon\Carbon;
use ErnestDefoe\MemberMap\Api\Problem;
use ErnestDefoe\MemberMap\Api\RateLimit;
use ErnestDefoe\MemberMap\Job\NotifyNearbyMembers;
use ErnestDefoe\MemberMap\Places;
use ErnestDefoe\MemberMap\Precision;
use ErnestDefoe\MemberMap\Settings;
use ErnestDefoe\MemberMap\Trip;
use ErnestDefoe\MemberMap\Trips;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `member-map-trips` type: "I'm visiting Chicago, Dec 3–7".
 *
 * POST   /api/member-map-trips       {lat, lng, starts, ends}
 * DELETE /api/member-map-trips/{id}  the traveler takes it down, with its alerts
 *
 * Saved like a home place: the city's centre, never the point sent. Members
 * who live near it are told by a queued job (NotifyNearbyMembers). A trip
 * stops showing after it ends; member-map:prune deletes it a month later.
 *
 * @extends AbstractDatabaseResource<Trip>
 */
class TripResource extends AbstractDatabaseResource
{
    public const MAX_CURRENT = 5;
    public const MAX_DAYS = 60;
    public const MAX_PER_DAY = 5;

    public function __construct(
        private readonly Places $places,
        private readonly Trips $trips,
        private readonly Queue $queue,
        private readonly RateLimit $limit,
    ) {
    }

    public function type(): string
    {
        return 'member-map-trips';
    }

    public function model(): string
    {
        return Trip::class;
    }

    /** @param Builder<Trip> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $query->where('user_id', $context->getActor()->isGuest() ? 0 : $context->getActor()->id);
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Create::make()->authenticated()->can(Settings::TRAVEL),
            Endpoint\Delete::make()->authenticated(),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Number::make('lat')->writableOnCreate()->requiredOnCreate()->set(fn () => null),
            Schema\Number::make('lng')->writableOnCreate()->requiredOnCreate()->set(fn () => null),
            Schema\Str::make('label'),
            Schema\Str::make('starts')->writableOnCreate()->requiredOnCreate()
                ->get(fn (Trip $trip) => $trip->starts_on->toDateString())->set(fn () => null),
            Schema\Str::make('ends')->writableOnCreate()->requiredOnCreate()
                ->get(fn (Trip $trip) => $trip->ends_on->toDateString())->set(fn () => null),
        ];
    }

    /** @param Context $context */
    public function creating(object $model, BaseContext $context): ?object
    {
        $actor = $context->getActor();
        $input = (array) ($context->body()['data']['attributes'] ?? []);
        [$starts, $ends] = $this->dates($input);

        if (Trip::current()->where('user_id', $actor->id)->count() >= self::MAX_CURRENT) {
            throw new Problem('member_map_too_many_trips');
        }

        $place = $this->places->around($actor, $input, Precision::CITY, ['trips', self::MAX_PER_DAY]);

        $model->user_id = $actor->id;
        $model->lat = $place['lat'];
        $model->lng = $place['lng'];
        $model->label = $place['label'];
        $model->country_code = $place['country_code'];
        $model->starts_on = $starts;
        $model->ends_on = $ends;
        $model->created_at = Carbon::now();

        return $model;
    }

    /** @param Context $context */
    public function created(object $model, BaseContext $context): ?object
    {
        $this->limit->countDaily($context->getActor(), 'trips');
        $this->queue->push(new NotifyNearbyMembers($model->id, $context->getActor()->id));

        return $model;
    }

    /** @param Context $context */
    public function deleting(object $model, BaseContext $context): void
    {
        $this->trips->forget($model, $context->getActor());
    }

    /**
     * Up to 60 days long, starting within a year, not already over.
     *
     * @param array<string, mixed> $input
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dates(array $input): array
    {
        $starts = self::date($input['starts'] ?? null);
        $ends = self::date($input['ends'] ?? null);
        $today = Carbon::today();

        if (! $starts || ! $ends || $ends < $starts || $ends < $today->copy()->subDay()
            || $starts > $today->copy()->addYear() || $starts->diffInDays($ends) > self::MAX_DAYS) {
            throw new Problem('member_map_bad_trip');
        }

        return [$starts, $ends];
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
