<?php

namespace ErnestDefoe\MemberMap\Api;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Lookups a member may start. The geocoder is shared by the whole forum at
 * one request a second, so one member must not use it all; and every new
 * answer is a cached row, so the day's total is capped too.
 *
 * Checking never counts: a lookup counts when it is actually queued at the
 * provider (Geocoder), and a trip or meetup when it is saved. So the
 * browser asking again while a lookup is queued costs the member nothing.
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
        return $this->peek($this->minuteKey($actor)) >= self::PER_MINUTE || $this->peek($this->dayKey($actor)) >= self::PER_DAY;
    }

    /** One lookup queued at the provider for this member. */
    public function countLookup(User $actor): void
    {
        $this->hit($this->minuteKey($actor), 120);
        $this->hit($this->dayKey($actor), 90000);
    }

    /**
     * Trips and meetups a member may start in a day: each one alerts
     * neighbors or starts a discussion, which core's flood control never sees.
     */
    public function dailyExceeded(User $actor, string $kind, int $max): bool
    {
        return $this->peek($this->kindKey($actor, $kind)) >= $max;
    }

    /** One trip or meetup saved. */
    public function countDaily(User $actor, string $kind): void
    {
        $this->hit($this->kindKey($actor, $kind), 90000);
    }

    private function minuteKey(User $actor): string
    {
        return 'member-map:rate:m:'.$actor->id.':'.intdiv(time(), 60);
    }

    private function dayKey(User $actor): string
    {
        return 'member-map:rate:d:'.$actor->id.':'.intdiv(time(), 86400);
    }

    private function kindKey(User $actor, string $kind): string
    {
        return 'member-map:rate:'.$kind.':'.$actor->id.':'.intdiv(time(), 86400);
    }

    private function peek(string $key): int
    {
        return (int) $this->cache->get($key, 0);
    }

    private function hit(string $key, int $ttl): void
    {
        $this->cache->add($key, 0, $ttl);
        $this->cache->increment($key);
    }
}
