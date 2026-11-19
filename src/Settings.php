<?php

namespace ErnestDefoe\MemberMap;

final class Settings
{
    public const PREFIX = 'ernestdefoe-member-map.';

    public const GEOCODER = self::PREFIX.'geocoder_url';
    public const GEOCODER_KEY = self::PREFIX.'geocoder_key';
    public const GEOCODER_EMAIL = self::PREFIX.'geocoder_email';
    public const JITTER = self::PREFIX.'jitter';
    public const INDEX = self::PREFIX.'allow_indexing';
    public const MEETUP_TAG = self::PREFIX.'meetup_tag';
    public const PROFILE = self::PREFIX.'profile';
    public const TRIP_RADIUS_KM = self::PREFIX.'trip_radius_km';
    public const TRIP_MAX_ALERTS = self::PREFIX.'trip_max_alerts';

    public const VIEW = 'member-map.view';
    public const LIST = 'member-map.list';
    public const TRAVEL = 'member-map.travel';
    public const MEETUP = 'member-map.meetup';
}
