<?php

namespace ErnestDefoe\MemberMap\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class MapTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-member-map');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'located', 'email' => 'located@machine.local', 'is_email_confirmed' => 1, 'avatar_url' => null],
                ['id' => 4, 'username' => 'unconfirmed', 'email' => 'unconfirmed@machine.local', 'is_email_confirmed' => 0, 'avatar_url' => null],
                ['id' => 5, 'username' => 'private', 'email' => 'private@machine.local', 'is_email_confirmed' => 1, 'avatar_url' => null],
            ],
            'member_map_locations' => [
                ['user_id' => 3, 'lat' => 36.16, 'lng' => -86.77, 'precision' => 'city', 'label' => 'Nashville, United States', 'show_on_profile' => 1, 'updated_at' => Carbon::now()],
                ['user_id' => 4, 'lat' => 51.51, 'lng' => -0.13, 'precision' => 'city', 'label' => 'London, United Kingdom', 'show_on_profile' => 1, 'updated_at' => Carbon::now()],
                ['user_id' => 5, 'lat' => 52.5, 'lng' => 13.4, 'precision' => 'region', 'label' => 'Berlin, Germany', 'show_on_profile' => 0, 'updated_at' => Carbon::now()],
            ],
        ]);
    }

    /**
     * Exactly these grants, replacing the migration's defaults. Permissions
     * are read once per process, so call this before the first request.
     *
     * @param list<array{int, string}> $grants
     */
    private function grant(array $grants = [[Group::MEMBER_ID, 'member-map.view'], [Group::MEMBER_ID, 'member-map.list']]): void
    {
        $table = $this->database()->table('group_permission');
        $table->clone()->where('permission', 'like', 'member-map.%')->delete();
        $table->clone()->insert(array_map(fn ($g) => ['group_id' => $g[0], 'permission' => $g[1]], $grants));
    }

    /** @return array{int, array<string, mixed>|null} */
    private function get(string $path, ?int $as = 2): array
    {
        $response = $this->send($this->request('GET', $path, array_filter(['authenticatedAs' => $as])));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function guests_cannot_see_the_map_by_default()
    {
        $this->grant();
        $this->assertSame(403, $this->get('/api/member-map', null)[0]);
    }

    #[Test]
    public function guests_see_the_map_when_allowed()
    {
        $this->grant([[Group::GUEST_ID, 'member-map.view'], [Group::MEMBER_ID, 'member-map.list']]);
        [$status, $body] = $this->get('/api/member-map', null);
        $this->assertSame(200, $status);
        $this->assertCount(2, $body['members']);
    }

    #[Test]
    public function the_map_lists_confirmed_members_as_lean_rows()
    {
        $this->grant();
        [$status, $body] = $this->get('/api/member-map');

        $this->assertSame(200, $status);
        $ids = array_column($body['members'], 0);
        sort($ids);
        $this->assertSame([3, 5], $ids);

        $row = array_values(array_filter($body['members'], fn ($m) => $m[0] === 3))[0];
        $this->assertSame([3, 36.16, -86.77, 'located', 'located', null], $row);
        $this->assertNull($body['me']);
    }

    #[Test]
    public function losing_the_list_permission_takes_members_off_the_map_but_admins_stay()
    {
        $this->grant([[Group::MEMBER_ID, 'member-map.view']]);
        $this->database()->table('member_map_locations')->insert(['user_id' => 1, 'lat' => 1.1, 'lng' => 2.2, 'precision' => 'city', 'label' => 'X', 'show_on_profile' => 1]);

        [, $body] = $this->get('/api/member-map');
        $this->assertSame([1], array_column($body['members'], 0));

        [, $own] = $this->get('/api/member-map', 3);
        $this->assertFalse($own['me']['listed']);
    }

    #[Test]
    public function a_group_granted_the_permission_is_listed()
    {
        $this->grant([[Group::MEMBER_ID, 'member-map.view'], [Group::MODERATOR_ID, 'member-map.list']]);
        $this->database()->table('group_user')->insert(['user_id' => 5, 'group_id' => Group::MODERATOR_ID]);

        [, $body] = $this->get('/api/member-map');
        $this->assertSame([5], array_column($body['members'], 0));
    }

    #[Test]
    public function the_profile_line_is_a_label_never_coordinates()
    {
        $this->grant();
        [, $located] = $this->get('/api/users/3');
        $attributes = $located['data']['attributes'];
        $this->assertSame('Nashville, United States', $attributes['memberMapLocation']);
        $this->assertArrayNotHasKey('memberMapOwn', $attributes);

        [, $hidden] = $this->get('/api/users/5');
        $this->assertNull($hidden['data']['attributes']['memberMapLocation']);
    }

    #[Test]
    public function viewers_who_cannot_see_the_map_get_no_profile_line()
    {
        $this->grant([[Group::MEMBER_ID, 'member-map.list']]);
        [, $noView] = $this->get('/api/users/3');
        $this->assertNull($noView['data']['attributes']['memberMapLocation']);
    }

    #[Test]
    public function members_see_their_own_saved_place()
    {
        $this->grant();
        [, $body] = $this->get('/api/users/5', 5);

        $this->assertSame(['lat' => 52.5, 'lng' => 13.4, 'label' => 'Berlin, Germany', 'precision' => 'region', 'profile' => false, 'listed' => true], $body['data']['attributes']['memberMapOwn']);
    }

    #[Test]
    public function deleting_a_user_deletes_their_place()
    {
        $this->grant();
        $response = $this->send($this->request('DELETE', '/api/users/3', ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame(0, $this->database()->table('member_map_locations')->where('user_id', 3)->count());
    }
}
