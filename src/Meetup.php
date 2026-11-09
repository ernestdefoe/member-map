<?php

namespace ErnestDefoe\MemberMap;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int $discussion_id
 * @property float $lat
 * @property float $lng
 * @property string $label
 * @property Carbon $starts_at
 * @property string|null $timezone
 * @property Carbon|null $created_at
 * @property Discussion|null $discussion
 */
class Meetup extends AbstractModel
{
    protected $table = 'member_map_meetups';

    public $timestamps = false;

    protected $casts = [
        'user_id' => 'integer',
        'discussion_id' => 'integer',
        'lat' => 'float',
        'lng' => 'float',
        'starts_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }
}
