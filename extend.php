<?php

/*
 * Member Map: an opt-in map of where the community's members are.
 *
 * Privacy first. A member is only ever stored as the centre of a named place
 * (a city, a region or a country) chosen by them and looked up on the SERVER
 * (Geocoder::place); the point they searched or tapped is thrown away.
 *
 * Places, trips, meetups and the widget's counts are JSON:API resources.
 * Two read-only plain handlers serve what has no record behind it: the map's
 * lean marker rows and the place lookup (each says why).
 *
 * The map engine and the map page are lazy chunks: a forum page that is not
 * the map loads none of it.
 */

use ErnestDefoe\MemberMap\Api;
use ErnestDefoe\MemberMap\Console\PruneCommand;
use ErnestDefoe\MemberMap\Event\MeetupPlanned;
use ErnestDefoe\MemberMap\Extend\Binding;
use ErnestDefoe\MemberMap\Gdpr\MemberMapData;
use ErnestDefoe\MemberMap\Integrations;
use ErnestDefoe\MemberMap\Http\MapPageContent;
use ErnestDefoe\MemberMap\Location;
use ErnestDefoe\MemberMap\Listener\ForgetClearedSettings;
use ErnestDefoe\MemberMap\Notification\TripNearbyBlueprint;
use ErnestDefoe\MemberMap\Trip;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
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
        ->get('/member-map/geocode', 'member-map.geocode', Api\GeocodeController::class),

    new Extend\ApiResource(Api\Resource\LocationResource::class),
    new Extend\ApiResource(Api\Resource\TripResource::class),
    new Extend\ApiResource(Api\Resource\MeetupResource::class),
    new Extend\ApiResource(Api\Resource\StatsResource::class),

    (new Extend\ApiResource(UserResource::class))
        ->fields(Api\UserFields::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumFields::class),

    // Statuses for the answers members can act on (Api\Problem and the geocoder's own); the browser words them.
    (new Extend\ErrorHandling())
        ->status('member_map_bad_point', 422)
        ->status('member_map_bad_query', 422)
        ->status('member_map_no_place', 422)
        ->status('member_map_bad_trip', 422)
        ->status('member_map_too_many_trips', 422)
        ->status('member_map_bad_meetup', 422)
        ->status('member_map_meetup_needs_tag', 422)
        ->status('member_map_meetup_tag_denied', 403)
        ->status('member_map_slow_down', 429)
        ->status('member_map_busy', 429)
        ->status('member_map_unavailable', 503),

    (new Extend\Notification())
        ->type(TripNearbyBlueprint::class, ['alert']),

    (new Extend\Console())
        ->command(PruneCommand::class)
        ->schedule(PruneCommand::class, function ($event) {
            $event->daily();
        }),

    // String keys, not Settings:: constants: this file is read on every boot, also mid-deploy.
    (new Extend\Settings())
        ->default('ernestdefoe-member-map.style_url', 'https://tiles.openfreemap.org/styles/liberty')
        ->default('ernestdefoe-member-map.style_dark_url', 'https://tiles.openfreemap.org/styles/dark')
        ->default('ernestdefoe-member-map.tiles_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png')
        ->default('ernestdefoe-member-map.tiles_attribution', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors')
        ->default('ernestdefoe-member-map.geocoder_url', 'https://nominatim.openstreetmap.org')
        ->default('ernestdefoe-member-map.nav', true)
        ->default('ernestdefoe-member-map.profile', true)
        ->default('ernestdefoe-member-map.trip_radius_km', 160)
        ->default('ernestdefoe-member-map.trip_max_alerts', 50)
        ->default('ernestdefoe-member-map.distance_units', 'auto')
        // An emptied style means "use the picture tiles", so empty is sent as null.
        ->serializeToForum('memberMapStyle', 'ernestdefoe-member-map.style_url', fn ($value) => trim((string) $value) ?: null)
        ->serializeToForum('memberMapStyleDark', 'ernestdefoe-member-map.style_dark_url', fn ($value) => trim((string) $value) ?: null)
        ->serializeToForum('memberMapTiles', 'ernestdefoe-member-map.tiles_url', fn ($value) => trim((string) $value))
        ->serializeToForum('memberMapTilesDark', 'ernestdefoe-member-map.tiles_dark_url', fn ($value) => trim((string) $value) ?: null)
        ->serializeToForum('memberMapAttribution', 'ernestdefoe-member-map.tiles_attribution', fn ($value) => trim((string) $value))
        ->serializeToForum('memberMapNav', 'ernestdefoe-member-map.nav', 'boolval')
        ->serializeToForum('memberMapProfile', 'ernestdefoe-member-map.profile', 'boolval')
        // Only the three known choices reach the browser: anything else is treated as "auto".
        ->serializeToForum('memberMapDistanceUnits', 'ernestdefoe-member-map.distance_units', fn ($value) => in_array($value, ['km', 'mi'], true) ? $value : 'auto'),

    (new Extend\Event())
        // Emptied tile, credit and place search addresses go back to their defaults.
        ->listen(\Flarum\Settings\Event\Saved::class, ForgetClearedSettings::class)
        // The foreign key does this too, where the database enforces it (SQLite may not).
        ->listen(Deleted::class, function (Deleted $event) {
            Location::query()->where('user_id', $event->user->id)->delete();
            Trip::query()->where('user_id', $event->user->id)->delete();
        }),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(MemberMapData::class),
        ])
        ->whenExtensionEnabled('flarum-suspend', fn () => [
            new Binding(Integrations\Suspension::class, Integrations\SuspendExcludesSuspended::class),
        ])
        ->whenExtensionEnabled('ianm-follow-users', fn () => [
            new Binding(Integrations\Follows::class, Integrations\FollowUsersFollows::class),
        ])
        ->whenExtensionEnabled('flarum-tags', fn () => [
            new Binding(Integrations\MeetupTags::class, Integrations\TagsMeetupTags::class),
        ])
        ->whenExtensionEnabled('ernestdefoe-calendar', fn () => [
            (new Extend\Event())->listen(MeetupPlanned::class, Integrations\AddMeetupToCalendar::class),
        ])
        // Page Builder's block contract has a server half.
        ->whenExtensionEnabled('ernestdefoe-page-builder', fn () => [
            new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(\ErnestDefoe\MemberMap\Widget\WhereWeAreBlock::class),
        ]),
];
