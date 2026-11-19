<?php

namespace ErnestDefoe\MemberMap\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** With Follow Users, "People I follow" narrows the map to them, through its own relationship. */
class FollowFilterTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'fof-follow-tags', 'ianm-follow-users', 'ernestdefoe-member-map');

        $place = fn (int $id, string $label) => ['user_id' => $id, 'lat' => 36.16, 'lng' => -86.77, 'precision' => 'city', 'label' => $label, 'show_on_profile' => 1, 'updated_at' => Carbon::now()];
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'followed', 'email' => 'followed@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'stranger', 'email' => 'stranger@machine.local', 'is_email_confirmed' => 1],
            ],
            'member_map_locations' => [$place(3, 'Nashville, United States'), $place(4, 'Nashville, United States')],
            'user_followers' => [['user_id' => 2, 'followed_user_id' => 3, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]],
        ]);
    }

    #[Test]
    public function the_map_narrows_to_the_people_the_viewer_follows()
    {
        $response = $this->send($this->request('GET', '/api/member-map', ['authenticatedAs' => 2])->withQueryParams(['following' => '1']));
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([3], array_column($body['members'], 0));
    }
}
