import type { Member } from '../../api';
import type { Selected } from './shared';
import type MapPage from '../MapPage';

const DEEP_LINKS = ['meetup', 'traveler', 'member'];

/**
 * Where the members are on average, as directions from the earth's centre (a
 * bounding box's middle lands in the ocean for a community on two continents),
 * and how tightly together they are (1 = one spot, 0 = all round the world).
 */
function centreOf(rows: Member[]): { center: [number, number]; spread: number } {
  let x = 0;
  let y = 0;
  let z = 0;
  for (const row of rows) {
    const lat = (row[1] * Math.PI) / 180;
    const lng = (row[2] * Math.PI) / 180;
    x += Math.cos(lat) * Math.cos(lng);
    y += Math.cos(lat) * Math.sin(lng);
    z += Math.sin(lat);
  }
  const center: [number, number] = [(Math.atan2(y, x) * 180) / Math.PI, (Math.atan2(z, Math.hypot(x, y)) * 180) / Math.PI];

  return { center, spread: Math.hypot(x, y, z) / rows.length };
}

/** Opens as a globe, then eases down into where the community is. */
export function intro(page: MapPage): void {
  const map = page.map;
  if (!map || !page.ready || page.loading || page.introDone) return;
  page.drawLayers();

  // An empty map keeps its intro for the first member to add themselves.
  const rows = page.data.members;
  if (!rows.length) return;
  page.introDone = true;
  // A deep link (deepLink below) flies straight to its target instead.
  const params = new URLSearchParams(window.location.search);
  if (DEEP_LINKS.some((key) => params.has(key))) return;

  const { center, spread } = centreOf(rows);
  const bounds = new page.engine.default.LngLatBounds();
  rows.forEach((row) => bounds.extend([row[2], row[1]]));
  // A globe a little narrower than the box. Its diameter is 512 * 2^zoom / (π cos lat) pixels:
  // MapLibre's globe zoom is the Mercator zoom at the centre's latitude.
  const box = map.getContainer();
  const width = Math.max(200, Math.min(box.clientWidth, box.clientHeight));
  const globeZoom = Math.log2((width * Math.PI * Math.cos((center[1] * Math.PI) / 180)) / 512);
  // Close together: ease down to fit them. Spread round the world: stay a globe, turned to face them.
  const land = (animate: boolean) =>
    spread > 0.9
      ? map.fitBounds(bounds, { padding: 60, maxZoom: 5, ...(animate ? { duration: 2800, essential: true } : { animate: false }) })
      : map.easeTo({ center, zoom: globeZoom, duration: animate ? 2800 : 0, essential: true });

  if (page.engine.reducedMotion()) {
    map.jumpTo({ center, zoom: globeZoom });
    land(false);
    return;
  }
  map.jumpTo({ center: [center[0] - 60, center[1] * 0.5], zoom: 0.6 });
  setTimeout(() => page.map && land(true), 400);
}

/** ?meetup=<discussion id>, ?traveler=<user id>, ?member=<user id>: from a notification, a post or a profile. */
export function deepLink(page: MapPage): void {
  if (!page.map || !page.ready || page.loading) return;
  const params = new URLSearchParams(window.location.search);
  const meetup = page.data.meetups.find((row) => String(row[7]) === params.get('meetup'));
  const trip = page.data.trips.find((row) => String(row[0]) === params.get('traveler'));
  const member = page.byId.get(Number(params.get('member')));
  const target: [[number, number], Selected] | null = meetup
    ? [[meetup[2], meetup[1]], { kind: 'meetup', meetup }]
    : trip
      ? [[trip[2], trip[1]], { kind: 'trip', trip }]
      : member
        ? [[member[2], member[1]], { kind: 'member', member }]
        : null;
  if (!target) return;

  page.introDone = true;
  page.map.flyTo({ center: target[0], zoom: 7, duration: page.engine.reducedMotion() ? 0 : 1500 });
  page.map.once('moveend', () => page.select(target[1], target[0]));
}
