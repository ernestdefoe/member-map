<?php

namespace ErnestDefoe\MemberMap;

use Flarum\Database\AbstractModel;

/**
 * @property int $user_id
 * @property float $lat
 * @property float $lng
 * @property string $precision
 * @property string $label
 * @property bool $show_on_profile
 * @property \Carbon\Carbon|null $updated_at
 */
class Location extends AbstractModel
{
    protected $table = 'member_map_locations';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'user_id' => 'integer',
        'lat' => 'float',
        'lng' => 'float',
        'show_on_profile' => 'boolean',
        'updated_at' => 'datetime',
    ];
}
