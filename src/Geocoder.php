<?php

namespace ErnestDefoe\MemberMap;

use Carbon\Carbon;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use ErnestDefoe\MemberMap\Api\RateLimit;
use ErnestDefoe\MemberMap\Job\LookUpPlace;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Place search and place lookup, server-side, against Nominatim or anything
 * that speaks its API (a self-hosted Nominatim, LocationIQ).
 *
 * A request never calls the provider itself. It answers from the 30-day
 * cache in member_map_geocode; on a miss it queues the lookup (LookUpPlace)
 * and answers "ask again in a moment" (GeocoderBusy), and the browser asks
 * again a second later. With Flarum's default sync queue the job has already
 * run by then, so the first answer is usually there at once.
 *
 * Nominatim's usage policy, and how it is met:
 *  - at most one request a second, from the whole forum: a cache lock plus a
 *    timestamp space every call (throttle()). A lookup that would come too
 *    soon is dropped, and the member's next ask queues it again;
 *  - an identifying User-Agent naming the forum;
 *  - every answer kept 30 days (pruned daily by member-map:prune);
 *  - no search-as-you-type: the browser only searches when asked to.
 * Nothing here is ever called while rendering a page: only when a member
 * searches for, or saves, their own location.
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
        protected Queue $queue,
        protected LoggerInterface $log,
        protected RateLimit $limit,
    ) {
    }

    /**
     * Places matching what a member typed.
     *
     * @return list<array{label: string, lat: float, lng: float, bbox: list<float>|null}>
     */
    public function search(string $query, string $locale, ?User $for = null): array
    {
        $results = $this->remember($for, ['search', mb_strtolower($query), $locale], 'search', [
            'q' => $query,
            'limit' => 6,
            'addressdetails' => 0,
            'accept-language' => $locale,
        ]);

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
    public function place(float $lat, float $lng, string $precision, string $locale, ?User $for = null): ?array
    {
        $lat = round($lat, 4);
        $lng = round($lng, 4);
        $zoom = Precision::zoom($precision);

        $result = $this->remember($for, ['reverse', $lat, $lng, $zoom, $locale], 'reverse', [
            'lat' => $lat,
            'lon' => $lng,
            'zoom' => $zoom,
            'addressdetails' => 1,
            'accept-language' => $locale,
        ]);

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
     * The queued half (LookUpPlace): one call to the provider, spaced from the
     * last, its answer stored for the member's next ask. A call that would come
     * too soon is dropped; a failure is logged and the provider rested.
     *
     * @param array<string, scalar> $query
     */
    public function lookUp(string $key, string $endpoint, array $query): void
    {
        try {
            // Not its turn (under a second since the last call): dropped, and the member's next ask queues it again.
            $answer = $this->cached($key) ? null : $this->throttle(fn () => $this->fetch($endpoint, $query));
            if ($answer) {
                $this->store($key, $answer[0]);
            }
        } catch (GeocoderUnavailable $e) {
            // Don't hammer a provider that is down: every lookup waits half a minute.
            $this->cache->put('member-map:geocoder:down', true, 30);
            $this->log->warning('[member-map] place lookup failed: '.($e->getPrevious()?->getMessage() ?? $e->getMessage()));
        } finally {
            $this->cache->forget('member-map:geocoder:queued:'.$key);
        }
    }

    /**
     * One HTTP request to the geocoder.
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
    }

    /**
     * The provider's answer, wrapped, or null when it isn't this call's turn:
     * the provider is resting after a failure, another lookup is running, or
     * the last one was under a second ago. Never waits for a turn.
     *
     * @return array{0: mixed}|null
     */
    protected function throttle(callable $request): ?array
    {
        $store = $this->cache->getStore();
        $lock = $store instanceof LockProvider ? $store->lock('member-map:geocoder', 10) : null;

        if ($this->resting() || ($lock && ! $lock->get())) {
            return null;
        }

        try {
            return $this->spaced($request);
        } finally {
            $lock?->release();
        }
    }

    /** @return array{0: mixed}|null */
    private function spaced(callable $request): ?array
    {
        if ((float) $this->cache->get('member-map:geocoder:last', 0) + self::SPACING > microtime(true)) {
            return null;
        }

        try {
            return [$request()];
        } catch (Throwable $e) {
            throw new GeocoderUnavailable('Geocoder request failed.', 0, $e);
        } finally {
            $this->cache->put('member-map:geocoder:last', sprintf('%.4f', microtime(true)), 60);
        }
    }

    /**
     * The cached answer, or the lookup queued and "ask again in a moment".
     * A queued lookup counts against the member's limits ($for); asking
     * again while it waits does not.
     *
     * @param list<scalar> $parts what makes this lookup unique
     * @param array<string, scalar> $query
     */
    private function remember(?User $for, array $parts, string $endpoint, array $query): mixed
    {
        $key = hash('sha256', json_encode([...$parts, (string) $this->settings->get(Settings::GEOCODER)]) ?: $endpoint);

        if ($found = $this->cached($key)) {
            return $found[0];
        }
        if ($this->resting()) {
            throw new GeocoderUnavailable('Geocoder failed recently.');
        }
        if ($this->cache->add('member-map:geocoder:queued:'.$key, true, 15)) {
            if ($for) {
                $this->limit->countLookup($for);
            }
            $this->queue->push(new LookUpPlace($key, $endpoint, $query));
        }

        // A sync queue has already run the lookup.
        if ($found = $this->cached($key)) {
            return $found[0];
        }
        if ($this->resting()) {
            throw new GeocoderUnavailable('Geocoder failed just now.');
        }

        throw new GeocoderBusy('Looking it up: ask again in a moment.');
    }

    /**
     * Whether the provider failed in the last half minute. Impure: a sync
     * queue's lookup may have just failed and set it.
     *
     * @phpstan-impure
     */
    private function resting(): bool
    {
        return (bool) $this->cache->get('member-map:geocoder:down');
    }

    /** @return array{0: mixed}|null the stored answer, wrapped (an answer may itself be null) */
    private function cached(string $key): ?array
    {
        $row = $this->db->table('member_map_geocode')->where('key', $key)->where('created_at', '>', Carbon::now()->subDays(self::KEEP_DAYS))->value('result');

        return $row === null ? null : [json_decode((string) $row, true)];
    }

    private function store(string $key, mixed $result): void
    {
        $this->db->table('member_map_geocode')->upsert([['key' => $key, 'result' => json_encode($result), 'created_at' => Carbon::now()]], ['key'], ['result', 'created_at']);
    }
}
