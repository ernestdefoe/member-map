<?php

namespace ErnestDefoe\MemberMap\Api\Resource;

use Carbon\Carbon;
use ErnestDefoe\MemberMap\Api\Problem;
use ErnestDefoe\MemberMap\Api\RateLimit;
use ErnestDefoe\MemberMap\Event\MeetupPlanned;
use ErnestDefoe\MemberMap\Integrations\MeetupTags;
use ErnestDefoe\MemberMap\Meetup;
use ErnestDefoe\MemberMap\Meetups;
use ErnestDefoe\MemberMap\Places;
use ErnestDefoe\MemberMap\Precision;
use ErnestDefoe\MemberMap\Settings;
use ErnestDefoe\MemberMap\Timezones;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `member-map-meetups` type: a place, a local time and a discussion.
 *
 * POST   /api/member-map-meetups       {title, details?, lat, lng, startsLocal: "2026-12-06T19:00", browserTimezone?}
 * DELETE /api/member-map-meetups/{id}  the host, or anyone who may edit its discussion, takes the pin down (the discussion stays)
 *
 * The time is local to the place (its time zone is worked out from the place).
 * Who can see a meetup is who can see its discussion. Calendar adds an event
 * when it is enabled (Integrations\AddMeetupToCalendar, on MeetupPlanned).
 *
 * @extends AbstractDatabaseResource<Meetup>
 */
class MeetupResource extends AbstractDatabaseResource
{
    public const MAX_PER_DAY = 3;

    public function __construct(
        private readonly Places $places,
        private readonly Meetups $meetups,
        private readonly MeetupTags $tags,
        private readonly Dispatcher $dispatcher,
        private readonly RateLimit $limit,
    ) {
    }

    public function type(): string
    {
        return 'member-map-meetups';
    }

    public function model(): string
    {
        return Meetup::class;
    }

    /** @param Builder<Meetup> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $query->whereIn('discussion_id', Discussion::whereVisibleTo($context->getActor())->select('discussions.id'));
    }

    public function endpoints(): array
    {
        return [
            // Starting the discussion through the API skips its own startDiscussion check: asked here.
            Endpoint\Create::make()->authenticated()->can(Settings::MEETUP)
                ->before(fn (Context $context) => $context->getActor()->assertCan('startDiscussion')),
            Endpoint\Delete::make()->authenticated(),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Number::make('lat')->writableOnCreate()->requiredOnCreate()->set(fn () => null),
            Schema\Number::make('lng')->writableOnCreate()->requiredOnCreate()->set(fn () => null),
            Schema\Str::make('title')->writableOnCreate()->requiredOnCreate()->set(fn () => null)
                ->get(fn (Meetup $meetup) => (string) $meetup->discussion?->title),
            Schema\Str::make('details')->writableOnCreate()->nullable()->visible(false)->set(fn () => null),
            Schema\Str::make('startsLocal')->writableOnCreate()->requiredOnCreate()->visible(false)->set(fn () => null),
            Schema\Str::make('browserTimezone')->writableOnCreate()->nullable()->maxLength(64)->visible(false)->set(fn () => null),
            Schema\Str::make('label'),
            Schema\DateTime::make('startsAt')->property('starts_at'),
            Schema\Str::make('timezone')->nullable(),
            Schema\Integer::make('discussionId')->property('discussion_id'),
            Schema\Str::make('discussionSlug')->get(fn (Meetup $meetup) => (string) $meetup->discussion?->slug),
        ];
    }

    /** @param Context $context */
    public function creating(object $model, BaseContext $context): ?object
    {
        $actor = $context->getActor();
        $input = (array) ($context->body()['data']['attributes'] ?? []);
        [$title, $details, $local] = $this->words($input);
        $tags = $this->tags->pick($actor);

        $place = $this->places->around($actor, $input, Precision::CITY, ['meetups', self::MAX_PER_DAY]);
        $timezone = Timezones::pick($input['browserTimezone'] ?? null, $place['country_code'], $place['lat'], $place['lng'], Precision::CITY, $place['region']);
        $starts = Carbon::createFromFormat('Y-m-d\TH:i', $local, $timezone ?: 'UTC');
        if (! $starts || $starts->isPast() || $starts > Carbon::now()->addYear()) {
            throw new Problem('member_map_bad_meetup');
        }

        /** @var JsonApi $api */
        $api = $context->api;
        $body = $this->meetups->firstPost($place['label'], $starts, $details, $this->places->locale());
        $discussion = $this->meetups->startDiscussion($api, $context->request, $actor, $title, $body, $tags);

        $model->user_id = $actor->id;
        $model->discussion_id = $discussion->id;
        $model->lat = $place['lat'];
        $model->lng = $place['lng'];
        $model->label = $place['label'];
        $model->starts_at = $starts->copy()->utc();
        $model->timezone = $timezone;
        $model->created_at = Carbon::now();
        $model->setRelation('discussion', $discussion);

        return $model;
    }

    /**
     * The discussion is started before the meetup is saved: if that save fails,
     * the discussion goes too, so no meetup discussion is left without its pin.
     *
     * @param Meetup $model
     * @param Context $context
     */
    public function create(object $model, BaseContext $context): object
    {
        try {
            return parent::create($model, $context);
        } catch (Throwable $e) {
            $model->discussion?->delete();

            throw $e;
        }
    }

    /** @param Context $context */
    public function created(object $model, BaseContext $context): ?object
    {
        $input = (array) ($context->body()['data']['attributes'] ?? []);
        [$title, $details] = $this->words($input);
        $startsLocal = $model->starts_at->copy()->setTimezone($model->timezone ?: 'UTC');
        $this->limit->countDaily($context->getActor(), 'meetups');

        $this->dispatcher->dispatch(new MeetupPlanned($model, $context->getActor(), $title, $details, $startsLocal));

        return $model;
    }

    /** @param Context $context */
    public function deleting(object $model, BaseContext $context): void
    {
        $actor = $context->getActor();

        if ($model->user_id !== $actor->id && ! ($model->discussion && $actor->can('edit', $model->discussion))) {
            throw new PermissionDeniedException();
        }
    }

    /**
     * The title (3–80 characters), the details (up to 2,000) and the local start time.
     *
     * @param array<string, mixed> $input
     * @return array{0: string, 1: string, 2: string}
     */
    private function words(array $input): array
    {
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $details = is_string($input['details'] ?? null) ? trim($input['details']) : '';
        $local = is_string($input['startsLocal'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $input['startsLocal']) ? $input['startsLocal'] : '';

        if (mb_strlen($title) < 3 || mb_strlen($title) > 80 || mb_strlen($details) > 2000 || $local === '') {
            throw new Problem('member_map_bad_meetup');
        }

        return [$title, $details, $local];
    }
}
