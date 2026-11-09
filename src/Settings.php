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
    public const NAV = self::PREFIX.'nav';
    public const PROFILE = self::PREFIX.'profile';

    public const DEFAULT_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    public const DEFAULT_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
    public const DEFAULT_GEOCODER = 'https://nominatim.openstreetmap.org';

    public const VIEW = 'member-map.view';
    public const LIST = 'member-map.list';
}
