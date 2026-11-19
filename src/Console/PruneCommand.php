<?php

namespace ErnestDefoe\MemberMap\Console;

use Carbon\Carbon;
use ErnestDefoe\MemberMap\Geocoder;
use ErnestDefoe\MemberMap\Trip;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Daily: drops place lookups older than the 30 days they are kept, and trips
 * that ended over a month ago (their alerts name them until then). The map
 * never shows either, so a forum without the scheduler only keeps extra rows.
 */
class PruneCommand extends Command
{
    protected $signature = 'member-map:prune';

    protected $description = 'Delete expired Member Map place lookups and old trips.';

    public function handle(ConnectionInterface $db): int
    {
        $lookups = $db->table('member_map_geocode')->where('created_at', '<', Carbon::now()->subDays(Geocoder::KEEP_DAYS))->delete();
        $trips = Trip::query()->where('ends_on', '<', Carbon::today()->subDays(30)->toDateString())->delete();

        $this->info("Deleted $lookups place lookups and $trips trips.");

        return self::SUCCESS;
    }
}
