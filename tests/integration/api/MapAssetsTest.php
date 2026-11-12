<?php

namespace ErnestDefoe\MemberMap\Tests\integration\api;

use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The map must work with nothing but what Flarum itself publishes. MapLibre's
 * worker once shipped as a file of its own, which a forum left unpublished
 * (404, blank map): it is now a string inside the engine chunk, and this
 * checks that through Flarum's real asset pipeline.
 */
class MapAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-member-map');
    }

    private function published(string $path): ?string
    {
        // Rendering a forum page publishes the lazy chunks, as on a real install.
        $this->send($this->request('GET', '/'));

        $assets = $this->app()->getContainer()->make('filesystem')->disk('flarum-assets');

        return $assets->exists($path) ? $assets->get($path) : null;
    }

    #[Test]
    public function engine_chunk_is_published_with_the_worker_inside(): void
    {
        $engine = $this->published('js/ernestdefoe-member-map/forum/map/engine.js');

        $this->assertNotNull($engine, 'The engine chunk is published.');
        // The worker's own entry point, from maplibre-gl-worker.mjs.
        $this->assertStringContainsString('demWorkerSources', $engine);
        $this->assertStringContainsString('setWorkerUrl', $engine);
    }

    #[Test]
    public function flarum_leaves_the_bundled_worker_intact(): void
    {
        $engine = $this->published('js/ernestdefoe-member-map/forum/map/engine.js');

        // Flarum strips "//# sourceMappingURL" through the end of the line from every chunk, then
        // adds its own on the last line. One left inside the one-line worker string would cut the
        // string, and the whole chunk, short: only Flarum's own may be there.
        $this->assertSame(1, substr_count($engine, 'sourceMappingURL'));
        $lines = explode("\n", rtrim($engine));
        $this->assertStringStartsWith('//# sourceMappingURL=', end($lines));
    }

    #[Test]
    public function the_map_asks_for_no_worker_file(): void
    {
        $this->assertNull($this->published('js/ernestdefoe-member-map/forum/map/maplibre-worker.js'));

        foreach (glob(__DIR__.'/../../../js/dist/forum/**/*.js') ?: [] as $chunk) {
            $this->assertStringNotContainsString('maplibre-worker.js', file_get_contents($chunk), basename($chunk));
        }
    }
}
