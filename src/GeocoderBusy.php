<?php

namespace ErnestDefoe\MemberMap;

use Flarum\Foundation\KnownError;
use RuntimeException;

/**
 * The place isn't looked up yet: the lookup is queued (Geocoder::remember).
 * Answered as 429 member_map_busy; the browser asks again a second later.
 */
class GeocoderBusy extends RuntimeException implements KnownError
{
    public function getType(): string
    {
        return 'member_map_busy';
    }
}
