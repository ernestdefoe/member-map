<?php

namespace ErnestDefoe\MemberMap\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\MemberMap\Geocoder;
use ErnestDefoe\MemberMap\Tests\fixtures\FakeGeocoder;
use ErnestDefoe\MemberMap\Trip;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class TripTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-member-map');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'neighbor', 'email' => 'neighbor@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'faraway', 'email' => 'faraway@machine.local', 'is_email_confirmed' => 1],
            ],
            'member_map_locations' => [
                // Franklin, near Nashville; and Berlin.
                ['user_id' => 3, 'lat' => 35.93, 'lng' => -86.87, 'precision' => 'city', 'label' => 'Franklin, United States', 'show_on_profile' => 1, 'updated_at' => Carbon::now()],
                ['user_id' => 4, 'lat' => 52.52, 'lng' => 13.4, 'precision' => 'city', 'label' => 'Berlin, Germany', 'show_on_profile' => 1, 'updated_at' => Carbon::now()],
            ],
        ]);
    }

    private function fake(): void
    {
        $container = $this->app()->getContainer();
        $container->instance(Geocoder::class, $container->make(FakeGeocoder::class));
    }

    /** @return array{int, array<string, mixed>|null} the status and the answer, the trip (id + attributes) as `trip` */
    private function post(array $attributes): array
    {
        $response = $this->send($this->request('POST', '/api/member-map-trips', ['authenticatedAs' => 2, 'json' => ['data' => ['type' => 'member-map-trips', 'attributes' => $attributes]]]));
        $body = json_decode((string) $response->getBody(), true);
        if (isset($body['data'])) {
            $body['trip'] = ['id' => $body['data']['id']] + $body['data']['attributes'];
        }

        return [$response->getStatusCode(), $body];
    }

    private function days(int $n): string
    {
        return Carbon::today()->addDays($n)->toDateString();
    }

    #[Test]
    public function a_trip_saves_the_city_centre_and_tells_members_who_live_nearby()
    {
        $this->fake();

        [$status, $body] = $this->post(['lat' => 36.15, 'lng' => -86.78, 'starts' => $this->days(3), 'ends' => $this->days(7)]);

        $this->assertSame(201, $status);
        $this->assertSame('Nashville, United States', $body['trip']['label']);
        $this->assertSame(36.16, Trip::query()->first()->lat);

        $notified = $this->database()->table('notifications')->where('type', 'memberMapTripNearby')->pluck('user_id')->all();
        $this->assertSame([3], array_map('intval', $notified));

        [, $map] = [null, json_decode((string) $this->send($this->request('GET', '/api/member-map', ['authenticatedAs' => 3]))->getBody(), true)];
        $this->assertSame(2, $map['trips'][0][0]);
    }

    #[Test]
    public function bad_dates_are_refused()
    {
        $this->fake();

        foreach ([[5, 2], [-10, -5], [1, 90]] as [$from, $to]) {
            $this->assertSame(422, $this->post(['lat' => 36.15, 'lng' => -86.78, 'starts' => $this->days($from), 'ends' => $this->days($to)])[0]);
        }
        $this->assertSame(422, $this->post(['lat' => 36.15, 'lng' => -86.78, 'starts' => '2026-02-30', 'ends' => $this->days(3)])[0]);
        $this->assertSame(0, Trip::query()->count());
    }

    #[Test]
    public function guests_never_see_trips()
    {
        $this->fake();
        $this->post(['lat' => 36.15, 'lng' => -86.78, 'starts' => $this->days(1), 'ends' => $this->days(2)]);
        $this->database()->table('group_permission')->insert(['group_id' => 2, 'permission' => 'member-map.view']);

        $map = json_decode((string) $this->send($this->request('GET', '/api/member-map'))->getBody(), true);

        $this->assertSame([], $map['trips']);
    }

    #[Test]
    public function only_the_traveler_can_take_a_trip_down_and_its_alerts_go_with_it()
    {
        $this->fake();
        [, $body] = $this->post(['lat' => 36.15, 'lng' => -86.78, 'starts' => $this->days(1), 'ends' => $this->days(2)]);
        $id = $body['trip']['id'];

        $this->assertSame(404, $this->send($this->request('DELETE', "/api/member-map-trips/$id", ['authenticatedAs' => 3]))->getStatusCode());
        $this->assertSame(204, $this->send($this->request('DELETE', "/api/member-map-trips/$id", ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertSame(0, Trip::query()->count());
        $this->assertSame(0, $this->database()->table('notifications')->where('type', 'memberMapTripNearby')->where('is_deleted', false)->count());
    }

    #[Test]
    public function the_neighbors_are_told_by_a_queued_job_not_by_the_request()
    {
        $this->fake();
        $queue = new \Illuminate\Support\Testing\Fakes\QueueFake($this->app()->getContainer());
        $this->app()->getContainer()->instance(\Illuminate\Contracts\Queue\Queue::class, $queue);

        $this->assertSame(201, $this->post(['lat' => 36.15, 'lng' => -86.78, 'starts' => $this->days(3), 'ends' => $this->days(7)])[0]);

        $queue->assertPushed(\ErnestDefoe\MemberMap\Job\NotifyNearbyMembers::class);
        $this->assertSame(0, $this->database()->table('notifications')->where('type', 'memberMapTripNearby')->count());
    }

    #[Test]
    public function neighbors_hear_about_a_trip_when_the_map_is_open_to_everyone()
    {
        $permissions = $this->database()->table('group_permission');
        $permissions->clone()->where('permission', 'like', 'member-map.%')->delete();
        foreach (['member-map.view', 'member-map.list', 'member-map.travel'] as $permission) {
            $permissions->clone()->insert(['group_id' => \Flarum\Group\Group::GUEST_ID, 'permission' => $permission]);
        }
        $this->fake();

        $this->assertSame(201, $this->post(['lat' => 36.15, 'lng' => -86.78, 'starts' => $this->days(3), 'ends' => $this->days(7)])[0]);

        $notified = $this->database()->table('notifications')->where('type', 'memberMapTripNearby')->pluck('user_id')->all();
        $this->assertSame([3], array_map('intval', $notified));
    }

    #[Test]
    public function the_admin_sets_how_far_the_alert_reaches()
    {
        $this->setting('ernestdefoe-member-map.trip_radius_km', '10');
        $this->fake();

        $this->assertSame(201, $this->post(['lat' => 36.15, 'lng' => -86.78, 'starts' => $this->days(3), 'ends' => $this->days(7)])[0]);

        // Franklin is about 25 km from Nashville: outside a 10 km radius.
        $this->assertSame(0, $this->database()->table('notifications')->where('type', 'memberMapTripNearby')->count());
    }

    #[Test]
    public function asking_again_while_the_place_is_looked_up_counts_the_trip_once()
    {
        $container = $this->app()->getContainer();
        $queue = new \Illuminate\Support\Testing\Fakes\QueueFake($container);
        $container->instance(\Illuminate\Contracts\Queue\Queue::class, $queue);
        $geocoder = $container->make(FakeGeocoder::class);
        $container->instance(Geocoder::class, $geocoder);
        $trip = ['lat' => 36.15, 'lng' => -86.78, 'starts' => $this->days(3), 'ends' => $this->days(7)];

        // The browser asks three times while the lookup waits for a worker.
        foreach ([1, 2, 3] as $ask) {
            $this->assertSame(429, $this->post($trip)[0]);
        }
        $queue->pushed(\ErnestDefoe\MemberMap\Job\LookUpPlace::class)->first()->handle($geocoder);
        $this->assertSame(201, $this->post($trip)[0]);

        $cache = $container->make(\Illuminate\Contracts\Cache\Repository::class);
        $this->assertSame(1, (int) $cache->get('member-map:rate:trips:2:'.intdiv(time(), 86400)));
        $this->assertSame(1, (int) $cache->get('member-map:rate:m:2:'.intdiv(time(), 60)));
    }
}
