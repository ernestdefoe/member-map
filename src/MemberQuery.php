<?php

namespace ErnestDefoe\MemberMap;

use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\User\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * The members who appear on the map for a viewer, in ONE query: users the
 * viewer may see, with a location, whose groups currently grant
 * `member-map.list`. Losing the permission (a group change, a suspension)
 * takes someone off the map at once, without touching their row.
 */
class MemberQuery
{
    /** Upper bound on markers in one answer, so a huge forum can't send megabytes. */
    public const MAX = 10000;

    public function __construct(private readonly ExtensionManager $extensions)
    {
    }

    /** @return Builder<User> */
    public function listable(User $actor): Builder
    {
        $table = (new Location())->getTable();

        $query = User::whereVisibleTo($actor)
            ->join($table, "$table.user_id", '=', 'users.id')
            ->where('users.is_email_confirmed', true);

        // Whose groups grant the permission, in the same query. Granted to
        // Members, it covers every confirmed account; admins have every
        // permission without a row.
        $granted = fn ($sub) => $sub->select('group_id')->from('group_permission')->where('permission', Settings::LIST);

        $query->where(fn ($q) => $q
            ->whereExists(fn ($sub) => $sub->selectRaw('1')->from('group_permission')->where('permission', Settings::LIST)->where('group_id', Group::MEMBER_ID))
            ->orWhereIn('users.id', fn ($sub) => $sub->select('user_id')->from('group_user')->where(fn ($in) => $in
                ->where('group_id', Group::ADMINISTRATOR_ID)
                ->orWhereIn('group_id', $granted))));

        if ($this->extensions->isEnabled('flarum-suspend')) {
            $query->where(fn ($q) => $q->whereNull('users.suspended_until')->orWhere('users.suspended_until', '<', Carbon::now()));
        }

        return $query;
    }
}
