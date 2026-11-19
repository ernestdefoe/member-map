<?php

use Flarum\Settings\DatabaseSettingsRepository;
use Illuminate\Database\Schema\Builder;

/*
 * Emptied tile, credit and place search addresses meant "the default", but a
 * saved empty value hides the default registered in extend.php. Remove them
 * once (from now on ForgetClearedSettings does it when an admin saves), and
 * note which were removed, so going back puts exactly those back.
 */
$keys = [
    'ernestdefoe-member-map.tiles_url',
    'ernestdefoe-member-map.tiles_attribution',
    'ernestdefoe-member-map.geocoder_url',
];
$note = 'ernestdefoe-member-map.cleared_by_migration';

return [
    'up' => function (Builder $schema) use ($keys, $note) {
        $settings = new DatabaseSettingsRepository($schema->getConnection());
        $cleared = array_values(array_filter($keys, fn (string $key) => $settings->get($key) === ''));

        foreach ($cleared as $key) {
            $settings->delete($key);
        }
        if ($cleared) {
            $settings->set($note, json_encode($cleared));
        }
    },
    'down' => function (Builder $schema) use ($note) {
        $settings = new DatabaseSettingsRepository($schema->getConnection());

        foreach ((array) json_decode((string) $settings->get($note, '[]'), true) as $key) {
            $settings->set((string) $key, '');
        }
        $settings->delete($note);
    },
];
