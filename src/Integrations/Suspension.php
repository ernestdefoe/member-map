<?php

namespace ErnestDefoe\MemberMap\Integrations;

use Illuminate\Database\Eloquent\Builder;

/** Without Suspend nobody is suspended: the map query is left as it is. */
class Suspension
{
    /** @param Builder<\Flarum\User\User> $query */
    public function exclude(Builder $query): void
    {
    }
}
