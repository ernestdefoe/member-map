<?php

namespace ErnestDefoe\MemberMap\Tests\integration\api;

use ErnestDefoe\MemberMap\Geocoder;
use ErnestDefoe\MemberMap\Location;
use ErnestDefoe\MemberMap\Tests\fixtures\FakeGeocoder;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class LocationTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private FakeGeocoder $geocoder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-member-map');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'member-map.view'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'member-map.list'],
            ],
        ]);
    }

    private function fake(): FakeGeocoder
    {
        $container = $this->app()->getContainer();
        $this->geocoder = $container->make(FakeGeocoder::class);
        $container->instance(Geocoder::class, $this->geocoder);

        return $this->geocoder;
    }

    /** @return array{int, array<string, mixed>|null} */
    private function post(array $body, ?int $as = 2): array
    {
        $response = $this->send($this->request('POST', '/api/member-map/location', array_filter(['authenticatedAs' => $as, 'json' => $body])));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function saving_stores_the_rounded_centre_of_the_city_never_the_point_sent()
    {
        $this->fake();

        [$status, $body] = $this->post(['lat' => 36.15123, 'lng' => -86.78456, 'precision' => 'city', 'profile' => true]);

        $this->assertSame(200, $status);
        $this->assertSame('Nashville, United States', $body['me']['label']);

        $row = Location::find(2);
        $this->assertSame(36.16, $row->lat);
        $this->assertSame(-86.77, $row->lng);
        $this->assertSame('city', $row->precision);
    }

    #[Test]
    public function region_and_country_save_their_own_centres_rounded_to_one_decimal()
    {
        $this->fake();

        $this->post(['lat' => 36.15123, 'lng' => -86.78456, 'precision' => 'region']);
        $this->assertSame([35.8, -86.3, 'Tennessee, United States'], [Location::find(2)->lat, Location::find(2)->lng, Location::find(2)->label]);

        $this->post(['lat' => 36.15123, 'lng' => -86.78456, 'precision' => 'country']);
        $this->assertSame([39.8, -100.4, 'United States'], [Location::find(2)->lat, Location::find(2)->lng, Location::find(2)->label]);
    }

    #[Test]
    public function a_point_with_no_place_saves_nothing()
    {
        $this->fake();

        [$status, $body] = $this->post(['lat' => -40, 'lng' => -120, 'precision' => 'city']);

        $this->assertSame(422, $status);
        $this->assertSame('member_map_no_place', $body['errors'][0]['code']);
        $this->assertNull(Location::find(2));
    }

    #[Test]
    public function bad_input_is_refused_before_any_lookup()
    {
        $geocoder = $this->fake();

        foreach ([['lat' => 91, 'lng' => 0, 'precision' => 'city'], ['lat' => 1, 'lng' => 2, 'precision' => 'street'], ['lat' => 'x', 'lng' => 2, 'precision' => 'city']] as $body) {
            $this->assertSame(422, $this->post($body)[0]);
        }
        $this->assertSame(0, $geocoder->calls);
    }

    #[Test]
    public function a_longitude_from_a_map_scrolled_round_the_world_is_wrapped()
    {
        $geocoder = $this->fake();

        $this->assertSame(200, $this->post(['lat' => 36.15, 'lng' => 273.22, 'precision' => 'city'])[0]);
        $this->assertSame(1, $geocoder->calls);
    }

    #[Test]
    public function guests_cannot_save()
    {
        $this->fake();

        // A guest never reaches the controller: no CSRF token, no session.
        $this->assertContains($this->post(['lat' => 36, 'lng' => -86, 'precision' => 'city'], null)[0], [400, 401]);
    }

    #[Test]
    public function members_without_the_permission_cannot_save()
    {
        $this->database()->table('group_permission')->where('permission', 'member-map.list')->delete();
        $this->fake();
        $this->assertSame(403, $this->post(['lat' => 36, 'lng' => -86, 'precision' => 'city'])[0]);
    }

    #[Test]
    public function the_profile_switch_alone_changes_without_a_lookup()
    {
        $geocoder = $this->fake();
        $this->post(['lat' => 36.15, 'lng' => -86.78, 'precision' => 'city', 'profile' => true]);

        [$status, $body] = $this->post(['profile' => false]);

        $this->assertSame(200, $status);
        $this->assertFalse($body['me']['profile']);
        $this->assertFalse(Location::find(2)->show_on_profile);
        $this->assertSame(1, $geocoder->calls);
    }

    #[Test]
    public function the_same_place_is_looked_up_once()
    {
        $geocoder = $this->fake();

        $this->post(['lat' => 36.15, 'lng' => -86.78, 'precision' => 'city']);
        $this->post(['lat' => 36.15, 'lng' => -86.78, 'precision' => 'city']);

        $this->assertSame(1, $geocoder->calls);
    }

    #[Test]
    public function a_geocoder_that_is_down_answers_503_and_saves_nothing()
    {
        $this->fake()->down = true;

        [$status, $body] = $this->post(['lat' => 36.15, 'lng' => -86.78, 'precision' => 'city']);

        $this->assertSame(503, $status);
        $this->assertSame('member_map_unavailable', $body['errors'][0]['code']);
        $this->assertNull(Location::find(2));
    }

    #[Test]
    public function leaving_the_map_works_even_without_the_permission()
    {
        $this->fake();
        $this->post(['lat' => 36.15, 'lng' => -86.78, 'precision' => 'city']);
        $this->database()->table('group_permission')->where('permission', 'member-map.list')->delete();

        $response = $this->send($this->request('DELETE', '/api/member-map/location', ['authenticatedAs' => 2]));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull(Location::find(2));
    }

    #[Test]
    public function search_needs_two_letters_and_is_rate_limited_per_member()
    {
        $this->fake();

        $search = fn (string $q) => $this->send($this->request('GET', '/api/member-map/geocode', ['authenticatedAs' => 2])->withQueryParams(['q' => $q]));

        $this->assertSame(422, $search('N')->getStatusCode());

        $first = $search('Nashville');
        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame('Nashville, Davidson County, Tennessee, United States', json_decode((string) $first->getBody(), true)['places'][0]['label']);

        $statuses = [];
        for ($i = 0; $i < 15; $i++) {
            $statuses[] = $search('Nashville')->getStatusCode();
        }
        $this->assertContains(429, $statuses);
    }
}
