<?php

namespace ErnestDefoe\MemberMap;

use Flarum\Foundation\KnownError;
use RuntimeException;

/**
 * Another lookup is running, or the last one was under a second ago.
 * Answered as 429 member_map_busy; the browser asks again a second later.
 */
class GeocoderBusy extends RuntimeException implements KnownError
{
    public function getType(): string
    {
        return 'member_map_busy';
    }
}
