<?php

namespace ErnestDefoe\MemberMap;

use Flarum\Foundation\KnownError;
use RuntimeException;

/** The geocoder could not answer now: down or slow. Answered as 503 member_map_unavailable. */
class GeocoderUnavailable extends RuntimeException implements KnownError
{
    public function getType(): string
    {
        return 'member_map_unavailable';
    }
}
