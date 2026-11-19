<?php

namespace ErnestDefoe\MemberMap\Integrations;

use ErnestDefoe\MemberMap\Api\Problem;
use ErnestDefoe\MemberMap\Settings;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Flarum\User\User;

/** With Tags (flarum-tags): the admin's meetup tag, checked like starting a discussion there. */
class TagsMeetupTags extends MeetupTags
{
    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function pick(User $actor): array
    {
        // Saved as "t5" by the admin select (older installs: "5").
        $id = (int) ltrim((string) $this->settings->get(Settings::MEETUP_TAG), 't');
        $tag = $id ? Tag::whereVisibleTo($actor)->find($id) : null;

        if (! $tag) {
            if ($actor->hasPermission('bypassTagCounts')) {
                return [];
            }

            throw new Problem('member_map_meetup_needs_tag');
        }

        // Starting the discussion through the API skips the tag's own check: a restricted tag stays restricted.
        if (! $actor->can('startDiscussion', $tag)) {
            throw new Problem('member_map_meetup_tag_denied');
        }

        return [(int) $tag->id];
    }
}
