<?php

namespace ErnestDefoe\MemberMap\Integrations;

use Flarum\User\User;

/** With Follow Users (ianm-follow-users): the members the viewer follows, through its followedUsers relationship. */
class FollowUsersFollows extends Follows
{
    public function followedBy(User $actor): array
    {
        return $actor->followedUsers()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }
}
