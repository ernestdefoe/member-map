<?php

/*
 * Member Map: an opt-in map of where the community's members are.
 *
 * Privacy first. A member is only ever stored as the centre of a named place
 * (a city, a region or a country) chosen by them and looked up on the SERVER
 * (Geocoder::place); the point they searched or tapped is thrown away. The
 * map's markers come from one lean endpoint (MapController) that only the
 * view permission opens; being on it needs the list permission.
 *
 * Leaflet and the map page are lazy chunks: a forum page that is not the map
 * loads none of it.
 */

use ErnestDefoe\MemberMap\Api;
use ErnestDefoe\MemberMap\Gdpr\MemberMapData;
use ErnestDefoe\MemberMap\Http\MapPageContent;
use ErnestDefoe\MemberMap\Location;
use ErnestDefoe\MemberMap\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Flarum\User\Event\Deleted;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the map page and the location form are chunks in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/map', 'member-map', MapPageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/member-map', 'member-map.index', Api\MapController::class)
        ->get('/member-map/geocode', 'member-map.geocode', Api\GeocodeController::class)
        ->post('/member-map/location', 'member-map.location.save', Api\SaveLocationController::class)
        ->delete('/member-map/location', 'member-map.location.delete', Api\DeleteLocationController::class),

    (new Extend\ApiResource(UserResource::class))
        ->fields(Api\UserFields::class),

    // The viewer's own permissions: already loaded, so no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('memberMapCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW)),
            Schema\Boolean::make('memberMapCanList')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::LIST)),
        ]),

    (new Extend\Settings())
        ->default(Settings::TILES, Settings::DEFAULT_TILES)
        ->default(Settings::ATTRIBUTION, Settings::DEFAULT_ATTRIBUTION)
        ->default(Settings::GEOCODER, Settings::DEFAULT_GEOCODER)
        ->default(Settings::NAV, true)
        ->default(Settings::PROFILE, true)
        ->serializeToForum('memberMapTiles', Settings::TILES, fn ($value) => trim((string) $value) ?: Settings::DEFAULT_TILES)
        ->serializeToForum('memberMapTilesDark', Settings::TILES_DARK, fn ($value) => trim((string) $value) ?: null)
        ->serializeToForum('memberMapAttribution', Settings::ATTRIBUTION, fn ($value) => trim((string) $value) ?: Settings::DEFAULT_ATTRIBUTION)
        ->serializeToForum('memberMapNav', Settings::NAV, 'boolval')
        ->serializeToForum('memberMapProfile', Settings::PROFILE, 'boolval'),

    // The foreign key does this too, where the database enforces it (SQLite may not).
    (new Extend\Event())
        ->listen(Deleted::class, fn (Deleted $event) => Location::query()->where('user_id', $event->user->id)->delete()),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(MemberMapData::class),
        ]),
];
