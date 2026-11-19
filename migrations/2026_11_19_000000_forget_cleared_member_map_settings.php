<?php

use Illuminate\Database\Schema\Builder;

/*
 * Emptied tile, credit and place search addresses meant "the default", but a
 * saved empty value hides the default registered in extend.php. Remove them
 * once; from now on ForgetClearedSettings does it when an admin saves.
 */
$keys = [
    'ernestdefoe-member-map.tiles_url',
    'ernestdefoe-member-map.tiles_attribution',
    'ernestdefoe-member-map.geocoder_url',
];

return [
    'up' => function (Builder $schema) use ($keys) {
        $schema->getConnection()->table('settings')->whereIn('key', $keys)->where('value', '')->delete();
    },
    'down' => function (Builder $schema) {
        // Nothing to put back: an empty value and no value both mean the default.
    },
];
