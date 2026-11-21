<?php

namespace ErnestDefoe\MemberMap\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/** An emptied tile, credit or place search address means "the default", never an empty address. */
class SettingsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-member-map');
    }

    private function stored(string $key): ?string
    {
        $value = $this->database()->table('settings')->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    #[Test]
    public function an_admin_emptying_the_tile_address_gets_the_default_back()
    {
        $response = $this->send($this->request('POST', '/api/settings', [
            'authenticatedAs' => 1,
            'json' => ['ernestdefoe-member-map.tiles_url' => '', 'ernestdefoe-member-map.jitter' => '1'],
        ]));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull($this->stored('ernestdefoe-member-map.tiles_url'));
        $this->assertSame('1', $this->stored('ernestdefoe-member-map.jitter'));

        $forum = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true);
        $this->assertSame('https://tile.openstreetmap.org/{z}/{x}/{y}.png', $forum['data']['attributes']['memberMapTiles']);
    }

    #[Test]
    public function the_migration_removes_empty_addresses_and_going_back_restores_exactly_those()
    {
        $this->database()->table('settings')->insert([
            ['key' => 'ernestdefoe-member-map.tiles_url', 'value' => ''],
            ['key' => 'ernestdefoe-member-map.geocoder_url', 'value' => 'https://geo.example.com'],
        ]);
        $migration = require __DIR__.'/../../../migrations/2026_11_19_000000_forget_cleared_member_map_settings.php';
        $schema = $this->database()->getSchemaBuilder();

        $migration['up']($schema);
        $this->assertNull($this->stored('ernestdefoe-member-map.tiles_url'));
        $this->assertSame('https://geo.example.com', $this->stored('ernestdefoe-member-map.geocoder_url'));

        $migration['down']($schema);
        $this->assertSame('', $this->stored('ernestdefoe-member-map.tiles_url'));
        $this->assertNull($this->stored('ernestdefoe-member-map.tiles_attribution'));
        $this->assertNull($this->stored('ernestdefoe-member-map.cleared_by_migration'));
    }

    /** @return array<string, array{0: string|null, 1: string}> */
    public static function units(): array
    {
        return ['not set' => [null, 'auto'], 'kilometers' => ['km', 'km'], 'miles' => ['mi', 'mi'], 'anything else' => ['furlongs', 'auto']];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('units')]
    public function distance_units_reach_the_browser_as_one_of_three_choices(?string $saved, string $sent)
    {
        if ($saved !== null) {
            $this->setting('ernestdefoe-member-map.distance_units', $saved);
        }

        $forum = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true);

        $this->assertSame($sent, $forum['data']['attributes']['memberMapDistanceUnits']);
    }
}
