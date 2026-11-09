<?php

use Flarum\Database\Migration;

/*
 * country_code feeds the stats strip and the list view, timezone the "3:42 PM
 * there" on member cards (both worked out on the server from the place, never
 * asked of a third party at render time). members_only hides the member from
 * guests and search engines. created_at marks newcomers for the drop-in.
 */
return Migration::addColumns('member_map_locations', [
    'country_code' => ['string', 'length' => 2, 'nullable' => true],
    'timezone' => ['string', 'length' => 64, 'nullable' => true],
    'members_only' => ['boolean', 'default' => false],
    'created_at' => ['timestamp', 'nullable' => true],
]);
