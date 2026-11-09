<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Members may view the map and add themselves; guests may not see it until an admin says so.
return Migration::addPermissions([
    'member-map.view' => Group::MEMBER_ID,
    'member-map.list' => Group::MEMBER_ID,
]);
