<?php

namespace ErnestDefoe\MemberMap;

use Carbon\Carbon;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Place search and place lookup, server-side, against Nominatim or anything
 * that speaks its API (a self-hosted Nominatim, LocationIQ).
 *
 * Nominatim's usage policy, and how it is met:
 *  - at most one request a second, from the whole forum: a cache lock plus a
 *    timestamp space every call (throttle()). A call that would come too soon
 *    is refused at once (GeocoderBusy, "try again in a moment"), and the
 *    browser asks again a second later: no PHP worker ever waits for a turn;
 *  - an identifying User-Agent naming the forum;
 *  - results cached: every answer is kept 30 days in member_map_geocode;
 *  - no search-as-you-type: the browser only searches when asked to.
 * Nothing here is ever called while rendering a page: only when a member
 * searches for, or saves, their own location. That lookup stays in the
 * request on purpose: the member is waiting for the answer (a list of places,
 * or the place their pin is in), every answer is cached, and the request is
 * capped at 6 seconds. Old answers are pruned by the member-map:prune command.
 */
class Geocoder
{
    public const KEEP_DAYS = 30;

    /** Seconds between two calls to the geocoder, from the whole forum. */
    private const SPACING = 1.05;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected UrlGenerator $url,
        protected ConnectionInterface $db,
        protected Cache $cache,
        protected Client $http,
    ) {
    }

    /**
     * Places matching what a member typed.
     *
     * @return list<array{label: string, lat: float, lng: float, bbox: list<float>|null}>
     */
    public function search(string $query, string $locale): array
    {
        $results = $this->remember('search', ['q' => mb_strtolower($query), 'lang' => $locale], fn () => $this->fetch('search', [
            'q' => $query,
            'limit' => 6,
            'addressdetails' => 0,
            'accept-language' => $locale,
        ]));

        $places = [];
        foreach (is_array($results) ? $results : [] as $result) {
            if (! isset($result['lat'], $result['lon'], $result['display_name'])) {
                continue;
            }
            // Nominatim's boundingbox is [south, north, west, east]: the map flies to fit it.
            $box = is_array($result['boundingbox'] ?? null) && count($result['boundingbox']) === 4 ? array_map('floatval', $result['boundingbox']) : null;
            $places[] = [
                'label' => (string) $result['display_name'],
                'lat' => (float) $result['lat'],
                'lng' => (float) $result['lon'],
                'bbox' => $box,
            ];
        }

        return $places;
    }

    /**
     * The named place of the given size around a point: its centre, rounded,
     * and its name. The point itself is never returned, so it is never stored.
     *
     * @return array{lat: float, lng: float, label: string, country_code: string|null, region: string|null, timezone: string|null}|null null when there is no such place there (open sea)
     */
    public function place(float $lat, float $lng, string $precision, string $locale): ?array
    {
        $lat = round($lat, 4);
        $lng = round($lng, 4);
        $zoom = Precision::zoom($precision);

        $result = $this->remember('reverse', compact('lat', 'lng', 'zoom', 'locale'), fn () => $this->fetch('reverse', [
            'lat' => $lat,
            'lon' => $lng,
            'zoom' => $zoom,
            'addressdetails' => 1,
            'accept-language' => $locale,
        ]));

        if (! is_array($result) || isset($result['error']) || ! isset($result['lat'], $result['lon'])) {
            return null;
        }

        $label = self::label(is_array($result['address'] ?? null) ? $result['address'] : [], (string) ($result['name'] ?? ''), $precision);

        if ($label === null) {
            return null;
        }

        $decimals = Precision::decimals($precision);
        $lat = round((float) $result['lat'], $decimals);
        $lng = round((float) $result['lon'], $decimals);
        $countryCode = strtolower((string) ($result['address']['country_code'] ?? '')) ?: null;
        $region = isset($result['address']['ISO3166-2-lvl4']) ? (string) $result['address']['ISO3166-2-lvl4'] : null;

        return [
            'lat' => $lat,
            'lng' => $lng,
            'label' => $label,
            'country_code' => $countryCode && strlen($countryCode) === 2 ? $countryCode : null,
            'region' => $region,
            'timezone' => Timezones::guess($countryCode, $lat, $lng, $precision, $region),
        ];
    }

    /**
     * "City, Country", "Region, Country" or "Country".
     *
     * @param array<string, mixed> $address
     */
    public static function label(array $address, string $name, string $precision): ?string
    {
        $country = trim((string) ($address['country'] ?? ''));

        $keys = match ($precision) {
            Precision::CITY => ['city', 'town', 'village', 'municipality', 'hamlet', 'suburb', 'county', 'state'],
            Precision::REGION => ['state', 'province', 'region', 'territory', 'state_district', 'county'],
            default => [],
        };

        $place = '';
        foreach ($keys as $key) {
            if (! empty($address[$key])) {
                $place = trim((string) $address[$key]);
                break;
            }
        }
        if ($place === '' && $keys) {
            $place = trim($name);
        }

        $parts = array_values(array_unique(array_filter([$place, $country], fn ($part) => $part !== '')));

        return $parts ? mb_substr(implode(', ', $parts), 0, 150) : null;
    }

    /**
     * One HTTP request to the geocoder, at least a second after the last.
     *
     * Protected so tests can answer without the network.
     *
     * @param array<string, scalar> $query
     */
    protected function fetch(string $endpoint, array $query): mixed
    {
        $base = rtrim(trim((string) $this->settings->get(Settings::GEOCODER)), '/');

        $query['format'] = 'jsonv2';
        if ($email = trim((string) $this->settings->get(Settings::GEOCODER_EMAIL))) {
            $query['email'] = $email;
        }
        if ($key = trim((string) $this->settings->get(Settings::GEOCODER_KEY))) {
            $query['key'] = $key;
        }

        return $this->throttle(function () use ($base, $endpoint, $query) {
            $response = $this->http->get("$base/$endpoint", [
                'query' => $query,
                'timeout' => 6,
                'connect_timeout' => 3,
                'headers' => [
                    'User-Agent' => 'Flarum Member Map (+'.$this->url->to('forum')->base().')',
                    'Accept' => 'application/json',
                ],
                'http_errors' => true,
            ]);

            return json_decode((string) $response->getBody(), true);
        });
    }

    protected function throttle(callable $request): mixed
    {
        if ($this->cache->get('member-map:geocoder:down')) {
            throw new GeocoderUnavailable('Geocoder failed recently.');
        }

        $store = $this->cache->getStore();
        $lock = $store instanceof LockProvider ? $store->lock('member-map:geocoder', 10) : null;

        // Another member's lookup is running: refuse now rather than hold this worker until it ends.
        if ($lock && ! $lock->get()) {
            throw new GeocoderBusy('Geocoder busy.');
        }

        try {
            return $this->spaced($request);
        } finally {
            $lock?->release();
        }
    }

    private function spaced(callable $request): mixed
    {
        if ((float) $this->cache->get('member-map:geocoder:last', 0) + self::SPACING > microtime(true)) {
            throw new GeocoderBusy('Geocoder called less than a second ago.');
        }

        try {
            return $request();
        } catch (Throwable $e) {
            // Don't hammer a provider that is down: everyone waits half a minute.
            $this->cache->put('member-map:geocoder:down', true, 30);

            throw new GeocoderUnavailable('Geocoder request failed.', 0, $e);
        } finally {
            $this->cache->put('member-map:geocoder:last', sprintf('%.4f', microtime(true)), 60);
        }
    }

    /**
     * @param array<string, scalar> $parts
     * @param callable(): mixed $fetch
     */
    private function remember(string $type, array $parts, callable $fetch): mixed
    {
        $key = hash('sha256', json_encode([$type, $parts, (string) $this->settings->get(Settings::GEOCODER)]) ?: $type);
        $table = $this->db->table('member_map_geocode');

        $row = $table->clone()->where('key', $key)->where('created_at', '>', Carbon::now()->subDays(self::KEEP_DAYS))->value('result');
        if ($row !== null) {
            return json_decode((string) $row, true);
        }

        $result = $fetch();

        $table->clone()->upsert([['key' => $key, 'result' => json_encode($result), 'created_at' => Carbon::now()]], ['key'], ['result', 'created_at']);

        return $result;
    }
}
