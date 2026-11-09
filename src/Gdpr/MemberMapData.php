<?php

namespace ErnestDefoe\MemberMap\Gdpr;

use ErnestDefoe\MemberMap\Location;
use ErnestDefoe\MemberMap\Trip;
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
        $export = $location ? [['member-map/location.json' => $this->encodeForExport($location->only(['label', 'precision', 'lat', 'lng', 'country_code', 'timezone', 'show_on_profile', 'members_only', 'created_at', 'updated_at']))]] : [];

        foreach (Trip::query()->where('user_id', $this->user->id)->get() as $trip) {
            $export[] = ["member-map/trip-{$trip->id}.json" => $this->encodeForExport($trip->only(['label', 'lat', 'lng', 'starts_on', 'ends_on', 'created_at']))];
        }

        return $export;
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        Location::query()->where('user_id', $this->user->id)->delete();
        Trip::query()->where('user_id', $this->user->id)->delete();
    }
}
