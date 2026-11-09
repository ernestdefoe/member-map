<?php

namespace ErnestDefoe\MemberMap;

use RuntimeException;

/** The geocoder could not answer now: down, slow, or busy with someone else's lookup. */
class GeocoderUnavailable extends RuntimeException
{
}
