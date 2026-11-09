<?php

namespace ErnestDefoe\MemberMap\Api;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Lookups a member may start per minute. The geocoder is shared by the whole
 * forum at one request a second, so one member must not use it all.
 */
class RateLimit
{
    private const PER_MINUTE = 15;

    public function __construct(private readonly Cache $cache)
    {
    }

    public function exceeded(User $actor): bool
    {
        $key = 'member-map:rate:'.$actor->id.':'.intdiv(time(), 60);
        $this->cache->add($key, 0, 120);

        return (int) $this->cache->increment($key) > self::PER_MINUTE;
    }
}
