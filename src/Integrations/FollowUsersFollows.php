<?php

namespace ErnestDefoe\MemberMap\Integrations;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/** With Follow Users (ianm-follow-users): the members the viewer follows, from its own table. */
class FollowUsersFollows extends Follows
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function followedBy(User $actor): array
    {
        return $this->db->table('user_followers')->where('user_id', $actor->id)->pluck('followed_user_id')->map(fn ($id) => (int) $id)->all();
    }
}
