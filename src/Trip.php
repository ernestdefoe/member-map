<?php

namespace ErnestDefoe\MemberMap;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $user_id
 * @property float $lat
 * @property float $lng
 * @property string $label
 * @property string|null $country_code
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon|null $created_at
 */
class Trip extends AbstractModel
{
    protected $table = 'member_map_trips';

    public $timestamps = false;

    protected $casts = [
        'user_id' => 'integer',
        'lat' => 'float',
        'lng' => 'float',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'created_at' => 'datetime',
    ];

    /**
     * Trips that haven't ended yet. A day of slack: "today" differs round the world.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Trip>
     */
    public static function current(): \Illuminate\Database\Eloquent\Builder
    {
        return self::query()->where('ends_on', '>=', Carbon::today()->subDay()->toDateString());
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return ['id' => $this->id, 'lat' => $this->lat, 'lng' => $this->lng, 'label' => $this->label, 'starts' => $this->starts_on->toDateString(), 'ends' => $this->ends_on->toDateString()];
    }
}
