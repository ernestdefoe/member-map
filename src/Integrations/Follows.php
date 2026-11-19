<?php

namespace ErnestDefoe\MemberMap\Integrations;

use Flarum\User\User;

/** Without Follow Users nobody follows anybody: the "People I follow" filter finds no one. */
class Follows
{
    /** @return list<int> */
    public function followedBy(User $actor): array
    {
        return [];
    }
}
