<?php

namespace ErnestDefoe\MemberMap\Api;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Lookups a member may start. The geocoder is shared by the whole forum at
 * one request a second, so one member must not use it all; and every new
 * answer is a cached row, so the day's total is capped too.
 */
class RateLimit
{
    private const PER_MINUTE = 15;
    private const PER_DAY = 100;

    public function __construct(private readonly Cache $cache)
    {
    }

    public function exceeded(User $actor): bool
    {
        return $this->hit('m:'.$actor->id.':'.intdiv(time(), 60), 120) > self::PER_MINUTE
            || $this->hit('d:'.$actor->id.':'.intdiv(time(), 86400), 90000) > self::PER_DAY;
    }

    private function hit(string $key, int $ttl): int
    {
        $key = 'member-map:rate:'.$key;
        $this->cache->add($key, 0, $ttl);

        return (int) $this->cache->increment($key);
    }
}
