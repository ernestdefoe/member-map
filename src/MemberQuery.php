<?php

namespace ErnestDefoe\MemberMap;

use ErnestDefoe\MemberMap\Integrations\Suspension;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Members a viewer may see who currently hold a permission, in ONE query:
 * visible to the viewer, confirmed, not suspended, and in a group granted the
 * permission (checked in SQL, so losing it takes someone off the map at once,
 * without touching their rows).
 */
class MemberQuery
{
    /** Upper bound on markers in one answer, so a huge forum can't send megabytes. */
    public const MAX = 10000;

    public function __construct(private readonly Suspension $suspension)
    {
    }

    /**
     * Members on the map: allowed to be listed, with a location (joined).
     *
     * @return Builder<User>
     */
    public function listable(User $actor): Builder
    {
        $table = (new Location())->getTable();

        $query = $this->permitted($actor, Settings::LIST)->join($table, "$table.user_id", '=', 'users.id');

        // Guests (and so search engines) never see members who asked to be shown to members only.
        if ($actor->isGuest()) {
            $query->where("$table.members_only", false);
        }

        return $query;
    }

    /** @return Builder<User> */
    public function permitted(User $actor, string $permission): Builder
    {
        $query = User::whereVisibleTo($actor)->where('users.is_email_confirmed', true);

        // Granted to Everyone (stored on Guests, which every member inherits) or to Members, it covers
        // every confirmed account; admins have every permission without a row.
        $granted = fn ($sub) => $sub->select('group_id')->from('group_permission')->where('permission', $permission);

        $query->where(fn ($q) => $q
            ->whereExists(fn ($sub) => $sub->from('group_permission')->where('permission', $permission)->whereIn('group_id', [Group::GUEST_ID, Group::MEMBER_ID]))
            ->orWhereIn('users.id', fn ($sub) => $sub->select('user_id')->from('group_user')->where(fn ($in) => $in
                ->where('group_id', Group::ADMINISTRATOR_ID)
                ->orWhereIn('group_id', $granted))));

        $this->suspension->exclude($query);

        return $query;
    }
}
