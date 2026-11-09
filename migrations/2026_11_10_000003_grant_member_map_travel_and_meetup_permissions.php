<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'member-map.travel' => Group::MEMBER_ID,
    'member-map.meetup' => Group::MEMBER_ID,
]);
