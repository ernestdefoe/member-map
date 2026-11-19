<?php

namespace ErnestDefoe\MemberMap;

final class Settings
{
    public const PREFIX = 'ernestdefoe-member-map.';

    public const TILES = self::PREFIX.'tiles_url';
    public const TILES_DARK = self::PREFIX.'tiles_dark_url';
    public const ATTRIBUTION = self::PREFIX.'tiles_attribution';
    public const GEOCODER = self::PREFIX.'geocoder_url';
    public const GEOCODER_KEY = self::PREFIX.'geocoder_key';
    public const GEOCODER_EMAIL = self::PREFIX.'geocoder_email';
    public const STYLE = self::PREFIX.'style_url';
    public const STYLE_DARK = self::PREFIX.'style_dark_url';
    public const JITTER = self::PREFIX.'jitter';
    public const INDEX = self::PREFIX.'allow_indexing';
    public const MEETUP_TAG = self::PREFIX.'meetup_tag';
    public const NAV = self::PREFIX.'nav';
    public const PROFILE = self::PREFIX.'profile';

    public const VIEW = 'member-map.view';
    public const LIST = 'member-map.list';
    public const TRAVEL = 'member-map.travel';
    public const MEETUP = 'member-map.meetup';
}
