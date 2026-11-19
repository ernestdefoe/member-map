<?php

namespace ErnestDefoe\MemberMap\Listener;

use Flarum\Settings\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * An admin who empties the tile, credit or place search address means "the
 * default": the saved empty value is removed, so the default registered in
 * extend.php applies again and nothing reading it needs a fallback.
 */
class ForgetClearedSettings
{
    private const KEYS = [
        'ernestdefoe-member-map.tiles_url',
        'ernestdefoe-member-map.tiles_attribution',
        'ernestdefoe-member-map.geocoder_url',
    ];

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function handle(Saved $event): void
    {
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $event->settings) && trim((string) $event->settings[$key]) === '') {
                $this->settings->delete($key);
            }
        }
    }
}
