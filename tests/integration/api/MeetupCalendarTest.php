<?php

namespace ErnestDefoe\MemberMap\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Calendar\Event as CalendarEvent;
use ErnestDefoe\MemberMap\Geocoder;
use ErnestDefoe\MemberMap\Meetup;
use ErnestDefoe\MemberMap\Tests\fixtures\FakeGeocoder;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** With Calendar enabled, a meetup goes on the calendar too, on the meetup's own discussion. */
class MeetupCalendarTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-calendar', 'ernestdefoe-member-map');

        $this->prepareDatabase([User::class => [$this->normalUser()]]);
    }

    #[Test]
    public function the_meetup_is_an_event_on_the_same_discussion()
    {
        $container = $this->app()->getContainer();
        $container->instance(Geocoder::class, $container->make(FakeGeocoder::class));
        $when = Carbon::now('America/Chicago')->addDays(10)->setTime(19, 0);

        $response = $this->send($this->request('POST', '/api/member-map-meetups', ['authenticatedAs' => 2, 'json' => ['data' => ['type' => 'member-map-meetups', 'attributes' => [
            'title' => 'Coffee in Nashville', 'lat' => 36.16, 'lng' => -86.78, 'startsLocal' => $when->format('Y-m-d\TH:i'),
        ]]]]));

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $event = CalendarEvent::query()->firstOrFail();
        $this->assertSame(Meetup::query()->value('discussion_id'), (int) $event->discussion_id);
        $this->assertSame('Coffee in Nashville', $event->title);
        $this->assertSame($when->copy()->utc()->format('Y-m-d H:i'), $event->start_at->utc()->format('Y-m-d H:i'));
    }
}
