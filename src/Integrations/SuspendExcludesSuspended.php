<?php

namespace ErnestDefoe\MemberMap\Integrations;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/** With Suspend (flarum-suspend), suspended members leave the map until their suspension ends. */
class SuspendExcludesSuspended extends Suspension
{
    public function exclude(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNull('users.suspended_until')->orWhere('users.suspended_until', '<', Carbon::now()));
    }
}
