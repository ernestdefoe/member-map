# Member Map

An opt-in map of where your community's members are, for Flarum 2.

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/ernestdefoe/member-map.svg)](https://packagist.org/packages/ernestdefoe/member-map)

- **Opt-in, privacy first.** Nobody is on the map until they add themselves. Members search for their city, or drop a pin, and choose how exactly to be shown: city, region or country. The server saves only the **center of that place**, rounded, never the point they searched or tapped. They can remove themselves any time.
- **A /map page** with every member who opted in, clustered. Click a marker or a cluster to see who is there, with avatars and links to their profiles.
- **Near you**: the members closest to you, with rough distances in miles or kilometers depending on your locale.
- **Location on profiles**: an optional "City, Country" line on the profile and user card.
- **Permissions**: one to view the map, one to appear on it. Guests can't see it unless you allow them.
- **No paid key needed**: OpenStreetMap tiles and Nominatim place search, with settings to use any other tile provider or Nominatim-compatible geocoder. Lookups run on your server, one a second at most, and every answer is cached for 30 days, as Nominatim's usage policy asks.
- **Light on your forum**: the map and Leaflet load only on the map page. The map's data is one query.
- **Follows dark mode** and your theme's colors. Works on phones.
- **GDPR**: with Flarum's GDPR extension enabled, the location is part of a member's data export and is removed on erasure.

## Install

```sh
composer require ernestdefoe/member-map
```

## Update

```sh
composer update ernestdefoe/member-map
php flarum migrate
php flarum cache:clear
```

## Links

- [Packagist](https://packagist.org/packages/ernestdefoe/member-map)
- [GitHub](https://github.com/ernestdefoe/member-map)

Map data © [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors.

☕ If this saves you time, you can [buy me a coffee](https://buymeacoffee.com/ernestdefoe).
