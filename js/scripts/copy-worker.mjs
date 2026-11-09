// MapLibre 6 runs its tile work in a separate module worker. Copy it next to
// the map chunk, where Flarum publishes it (jsDirectory); map/engine.ts starts
// it from a same-origin blob, which also works when assets come from a CDN.
import { copyFileSync, mkdirSync } from 'node:fs';

mkdirSync(new URL('../dist/forum/map/', import.meta.url), { recursive: true });
copyFileSync(new URL('../node_modules/maplibre-gl/dist/maplibre-gl-worker.mjs', import.meta.url), new URL('../dist/forum/map/maplibre-worker.js', import.meta.url));
