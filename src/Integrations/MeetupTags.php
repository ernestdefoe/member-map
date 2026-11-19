<?php

namespace ErnestDefoe\MemberMap\Integrations;

use Flarum\User\User;

/** Without Tags a meetup discussion needs no tag. */
class MeetupTags
{
    /**
     * The tag ids a meetup discussion starts in. Throws a Problem when the
     * member may not start one there, or no tag is set and one is required.
     *
     * @return list<int>
     */
    public function pick(User $actor): array
    {
        return [];
    }
}
