<?php

namespace ErnestDefoe\MemberMap\Tests\integration\console;

use Carbon\Carbon;
use ErnestDefoe\MemberMap\Trip;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class PruneCommandTest extends ConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-member-map');

        $days = fn (int $n) => Carbon::today()->addDays($n)->toDateString();
        $this->prepareDatabase([
            User::class => [['id' => 2, 'username' => 'traveler', 'email' => 'traveler@machine.local', 'is_email_confirmed' => 1]],
            'member_map_trips' => [
                ['user_id' => 2, 'lat' => 1, 'lng' => 1, 'label' => 'Old', 'starts_on' => $days(-50), 'ends_on' => $days(-40), 'created_at' => Carbon::now()],
                ['user_id' => 2, 'lat' => 1, 'lng' => 1, 'label' => 'Recent', 'starts_on' => $days(-20), 'ends_on' => $days(-10), 'created_at' => Carbon::now()],
            ],
            'member_map_geocode' => [
                ['key' => 'old', 'result' => '[]', 'created_at' => Carbon::now()->subDays(31)],
                ['key' => 'new', 'result' => '[]', 'created_at' => Carbon::now()],
            ],
        ]);
    }

    #[Test]
    public function it_deletes_old_lookups_and_trips_a_month_after_they_ended()
    {
        $this->assertSame('Deleted 1 place lookups and 1 trips.', $this->runCommand(['command' => 'member-map:prune']));

        $this->assertSame(['Recent'], Trip::query()->pluck('label')->all());
        $this->assertSame(['new'], $this->database()->table('member_map_geocode')->pluck('key')->all());
    }
}
