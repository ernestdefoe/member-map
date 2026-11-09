<?php

namespace ErnestDefoe\MemberMap\Gdpr;

use ErnestDefoe\MemberMap\Location;
use Flarum\Gdpr\Data\Type;

/** The member's map location in a data export, and gone on erasure. Registered only when flarum/gdpr is enabled. */
class MemberMapData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-member-map.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-member-map.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $location = Location::find($this->user->id);

        return $location ? [['member-map/location.json' => $this->encodeForExport($location->only(['label', 'precision', 'lat', 'lng', 'show_on_profile', 'updated_at']))]] : [];
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        Location::query()->where('user_id', $this->user->id)->delete();
    }
}
