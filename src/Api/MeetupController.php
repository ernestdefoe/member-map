<?php

namespace ErnestDefoe\MemberMap\Api;

use Carbon\Carbon;
use ErnestDefoe\MemberMap\Geocoder;
use ErnestDefoe\MemberMap\GeocoderUnavailable;
use ErnestDefoe\MemberMap\Meetup;
use ErnestDefoe\MemberMap\Precision;
use ErnestDefoe\MemberMap\Settings;
use ErnestDefoe\MemberMap\Timezones;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * POST   /api/member-map/meetups        {title, details, lat, lng, startsAt: "2026-12-06T19:00"}
 * DELETE /api/member-map/meetups/{id}   takes the pin down (the discussion stays)
 *
 * A meetup is a discussion, started as the member through Flarum's own API so
 * every rule and extension applies, plus a pin. The time is local to the
 * place (its time zone is worked out from the place). With Calendar enabled,
 * and the member allowed to add events, it goes on the calendar too.
 */
class MeetupController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Geocoder $geocoder,
        private readonly RateLimit $limit,
        private readonly SettingsRepositoryInterface $settings,
        private readonly JsonApi $api,
        private readonly ExtensionManager $extensions,
        private readonly TranslatorInterface $translator,
        private readonly UrlGenerator $url,
        private readonly LoggerInterface $log,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        if ($request->getMethod() === 'DELETE') {
            $meetup = Meetup::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
            $discussion = Discussion::whereVisibleTo($actor)->find($meetup->discussion_id);
            if ($meetup->user_id !== $actor->id && ! ($discussion && $actor->can('edit', $discussion))) {
                return Errors::make(403, 'permission_denied');
            }
            $meetup->delete();

            return new EmptyResponse(204);
        }

        $actor->assertCan(Settings::MEETUP);
        // process() skips the endpoint's own check: ask it here.
        $actor->assertCan('startDiscussion');

        $body = (array) $request->getParsedBody();
        $title = is_string($body['title'] ?? null) ? trim($body['title']) : '';
        $details = is_string($body['details'] ?? null) ? trim($body['details']) : '';
        $local = is_string($body['startsAt'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $body['startsAt']) ? $body['startsAt'] : null;

        if (mb_strlen($title) < 3 || mb_strlen($title) > 80 || mb_strlen($details) > 2000 || ! $local || ! Point::valid($body)) {
            return Errors::make(422, 'member_map_bad_meetup');
        }

        $tags = null;
        if ($this->extensions->isEnabled('flarum-tags')) {
            $tag = (int) $this->settings->get(Settings::MEETUP_TAG);
            $model = $tag && class_exists(\Flarum\Tags\Tag::class) ? \Flarum\Tags\Tag::whereVisibleTo($actor)->find($tag) : null;
            if ($model) {
                // process() skips the tag's own permission check: a restricted tag stays restricted.
                if (! $actor->can('startDiscussion', $model)) {
                    return Errors::make(403, 'member_map_meetup_tag_denied');
                }
                $tags = [$tag];
            } elseif (! $actor->hasPermission('bypassTagCounts')) {
                return Errors::make(422, 'member_map_meetup_needs_tag');
            }
        }

        if ($this->limit->exceeded($actor) || $this->limit->dailyExceeded($actor, 'meetups', 3)) {
            return Errors::make(429, 'member_map_slow_down');
        }

        try {
            $place = $this->geocoder->place((float) $body['lat'], Point::lng((float) $body['lng']), Precision::CITY, Point::locale($this->settings));
        } catch (GeocoderUnavailable) {
            return Errors::make(503, 'member_map_unavailable');
        }
        if (! $place) {
            return Errors::make(422, 'member_map_no_place');
        }

        $place['timezone'] = Timezones::pick($body['timezone'] ?? null, $place['country_code'], $place['lat'], $place['lng'], Precision::CITY, $place['region']);
        $timezone = $place['timezone'] ?: 'UTC';
        $starts = Carbon::createFromFormat('Y-m-d\TH:i', $local, $timezone);
        if (! $starts || $starts->isPast() || $starts > Carbon::now()->addYear()) {
            return Errors::make(422, 'member_map_bad_meetup');
        }

        $data = ['type' => 'discussions', 'attributes' => ['title' => $title, 'content' => $this->content($place['label'], $starts, $details)]];
        if ($tags) {
            $data['relationships']['tags']['data'] = array_map(fn (int $id) => ['type' => 'tags', 'id' => (string) $id], $tags);
        }

        /** @var Discussion $discussion */
        $discussion = $this->api->forResource(DiscussionResource::class)
            ->forEndpoint('create')
            // process() merges the base request's data into ours: start from an empty one.
            ->withRequest($request->withParsedBody([])->withQueryParams([]))
            ->process(['data' => $data], [], ['actor' => $actor]);

        $meetup = new Meetup();
        $meetup->user_id = $actor->id;
        $meetup->discussion_id = $discussion->id;
        $meetup->lat = $place['lat'];
        $meetup->lng = $place['lng'];
        $meetup->label = $place['label'];
        $meetup->starts_at = $starts->copy()->utc();
        $meetup->timezone = $place['timezone'];
        $meetup->created_at = Carbon::now();
        $meetup->save();

        $this->calendar($actor, $meetup, $title, $details, $starts);

        return new JsonResponse(['meetup' => [$meetup->id, $meetup->lat, $meetup->lng, $meetup->label, $meetup->starts_at->toIso8601String(), $meetup->timezone, $discussion->title, $discussion->id, $discussion->slug]], 201);
    }

    /** The first post: where and when, the member's words, and a way back to the map. */
    private function content(string $label, Carbon $starts, string $details): string
    {
        $when = $starts->locale(Point::locale($this->settings))->isoFormat('dddd, LL, LT').' ('.$starts->format('T').')';
        $map = $this->url->to('forum')->route('member-map');

        return trim(implode("\n\n", array_filter([
            '📍 **'.$label."**  \n🗓 ".$when,
            $details,
            '['.$this->translator->trans('ernestdefoe-member-map.lib.meetup_on_map').']('.$map.')',
        ])));
    }

    /** With Calendar enabled and the member allowed to add events, the meetup is an event there too, on the same discussion. */
    private function calendar(User $actor, Meetup $meetup, string $title, string $details, Carbon $starts): void
    {
        $class = 'ErnestDefoe\\Calendar\\Event';
        if (! $this->extensions->isEnabled('ernestdefoe-calendar') || ! class_exists($class) || ! $actor->can('calendar.create')) {
            return;
        }

        try {
            $event = new $class();
            $event->user_id = $actor->id;
            $event->discussion_id = $meetup->discussion_id;
            $event->title = $title;
            $event->slug = Str::slug($title).'-'.Str::lower(Str::random(5));
            $event->description = $details ?: null;
            $event->start_at = $starts->copy()->utc();
            $event->end_at = $starts->copy()->utc()->addHours(2);
            $event->all_day = false;
            $event->timezone = $meetup->timezone ?: 'UTC';
            $event->location = $meetup->label;
            $event->is_published = true;
            $event->save();
        } catch (Throwable $e) {
            // The meetup stands without the calendar entry; never lose it over the add-on.
            $this->log->warning('[member-map] meetup '.$meetup->id.' not added to the calendar: '.$e->getMessage());
        }
    }
}
