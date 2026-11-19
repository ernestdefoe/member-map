<?php

namespace ErnestDefoe\MemberMap;

use Carbon\Carbon;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A meetup's discussion, started as the member through Flarum's own API so
 * every rule and extension applies. The first post says where and when, in
 * the member's words, with a way back to the map.
 */
class Meetups
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UrlGenerator $url,
    ) {
    }

    /**
     * @param list<int> $tags
     */
    public function startDiscussion(JsonApi $api, ServerRequestInterface $request, User $actor, string $title, string $body, array $tags): Discussion
    {
        $data = ['type' => 'discussions', 'attributes' => ['title' => $title, 'content' => $body]];
        if ($tags) {
            $data['relationships']['tags']['data'] = array_map(fn (int $id) => ['type' => 'tags', 'id' => (string) $id], $tags);
        }

        /** @var Discussion */
        return $api->forResource(DiscussionResource::class)
            ->forEndpoint('create')
            // process() merges the base request's data into ours: start from an empty one.
            ->withRequest($request->withParsedBody([])->withQueryParams([]))
            ->process(['data' => $data], [], ['actor' => $actor]);
    }

    public function firstPost(string $label, Carbon $starts, string $details, string $locale): string
    {
        $when = $starts->locale($locale)->isoFormat('dddd, LL, LT').' ('.$starts->format('T').')';
        $map = $this->url->to('forum')->route('member-map');

        return trim(implode("\n\n", array_filter([
            '📍 **'.$label."**  \n🗓 ".$when,
            $details,
            '['.$this->translator->trans('ernestdefoe-member-map.lib.meetup_on_map').']('.$map.')',
        ])));
    }
}
