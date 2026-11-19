import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import { dateRange, NEW, ONLINE, t, type MeetupRow, type Member, type TripRow } from '../../api';
import { avatarEl, icon, meetupWhen, SOURCE } from './shared';
import type MapPage from '../MapPage';

/*
 * The map's markers are HTML elements built from DOM text, never HTML
 * strings, so a name can never become markup.
 */

/** HTML markers for what the clustered source shows right now: avatar pins and avatar stacks. */
export function syncMarkers(page: MapPage): void {
  const map = page.map;
  if (!map || !map.getSource(SOURCE) || !map.isSourceLoaded(SOURCE)) return;

  const seen = new Set<string>();
  for (const feature of map.querySourceFeatures(SOURCE)) {
    const props = feature.properties || {};
    const key = props.cluster ? `c${props.cluster_id}` : `m${props.id}`;
    if (seen.has(key)) continue;
    seen.add(key);
    if (page.markers.has(key)) continue;

    const coordinates = (feature.geometry as any).coordinates as [number, number];
    const element = props.cluster ? stackEl(page, props, coordinates) : pinEl(page, props.id, coordinates);
    if (element) page.markers.set(key, marker(page, element, coordinates));
  }

  for (const [key, existing] of page.markers) {
    if (!seen.has(key)) {
      existing.remove();
      page.markers.delete(key);
    }
  }
}

/**
 * 🚨 MapLibre positions a marker by writing `transform` on its element, so
 * our button sits INSIDE a plain wrapper: the hover zoom and the drop-in
 * animation transform the button, never the element MapLibre moves.
 */
export function marker(page: MapPage, element: HTMLElement, lngLat: [number, number], options: Record<string, any> = {}): any {
  const wrapper = document.createElement('div');
  wrapper.className = 'MemberMapMarker';
  wrapper.append(element);
  // Hidden, not ghosted, on the far side of the globe.
  return new page.engine.default.Marker({ element: wrapper, anchor: 'center', opacityWhenCovered: '0', ...options })
    .setLngLat(lngLat)
    .addTo(page.map);
}

function button(className: string, label: string, onclick: () => void): HTMLButtonElement {
  const el = document.createElement('button');
  el.type = 'button';
  el.className = className;
  el.setAttribute('aria-label', label);
  el.addEventListener('click', (e) => {
    e.stopPropagation();
    onclick();
  });
  return el;
}

function pinEl(page: MapPage, id: number, lngLat: [number, number]): HTMLElement | null {
  const row = page.byId.get(id);
  if (!row) return null;
  const pin = button('MemberMapPin', extractText(t('pin.member', { name: row[3], place: row[8] })), () =>
    page.select({ kind: 'member', member: row }, lngLat)
  );
  if (row[9] & ONLINE) pin.classList.add('is-online');
  if (row[9] & NEW) pin.classList.add('is-new');
  if (app.session.user && Number(app.session.user.id()) === id) pin.classList.add('is-me');
  pin.append(avatarEl(row[3], row[5]));
  return pin;
}

function stackEl(page: MapPage, props: any, lngLat: [number, number]): HTMLElement {
  const count = props.point_count as number;
  const source = page.map.getSource(SOURCE);
  const members = (leaves: any[]) => leaves.map((leaf) => page.byId.get(leaf.properties.id)).filter((row): row is Member => !!row);

  const stack = button('MemberMapStack', extractText(t('pin.cluster', { count })), () => {
    source.getClusterLeaves(props.cluster_id, Infinity, 0).then((leaves: any[]) => {
      page.select({ kind: 'cluster', members: members(leaves), clusterId: props.cluster_id, lngLat }, lngLat);
    });
  });
  if (props.online > 0) stack.classList.add('is-online');

  const faces = document.createElement('span');
  faces.className = 'MemberMapStack-faces';
  const badge = document.createElement('span');
  badge.className = 'MemberMapStack-count';
  badge.textContent = count > 999 ? `${Math.round(count / 100) / 10}k` : String(count);
  stack.append(faces, badge);

  source.getClusterLeaves(props.cluster_id, 3, 0).then((leaves: any[]) => {
    faces.replaceChildren(...members(leaves).map((row) => avatarEl(row[3], row[5])));
  });
  return stack;
}

function tripMarker(page: MapPage, trip: TripRow): any {
  const pin = button(
    'MemberMapPin MemberMapPin--trip',
    extractText(t('pin.trip', { name: trip[6], place: trip[3], dates: dateRange(trip[4], trip[5]) })),
    () => page.select({ kind: 'trip', trip }, [trip[2], trip[1]])
  );
  const badge = document.createElement('span');
  badge.className = 'MemberMapPin-badge';
  badge.append(icon('fas fa-suitcase-rolling'));
  pin.append(avatarEl(trip[6], trip[8]), badge);
  return new page.engine.default.Marker({ element: pin, anchor: 'center', offset: [16, -16] }).setLngLat([trip[2], trip[1]]).addTo(page.map);
}

function meetupMarker(page: MapPage, meetup: MeetupRow): any {
  const pin = button('MemberMapMeetupPin', extractText(t('pin.meetup', { title: meetup[6], when: meetupWhen(meetup) })), () =>
    page.select({ kind: 'meetup', meetup }, [meetup[2], meetup[1]])
  );
  pin.append(icon('fas fa-calendar-day'));
  // Above a member stack in the same city, not hidden under it.
  const added = marker(page, pin, [meetup[2], meetup[1]], { anchor: 'bottom', offset: [0, -24] });
  added.getElement().classList.add('MemberMapMarker--meetup');
  return added;
}

/** Travelers and meetups: a few markers each, drawn directly. */
export function drawLayers(page: MapPage): void {
  page.layerMarkers.forEach((existing) => existing.remove());
  page.layerMarkers = [];
  if (!page.map) return;

  if (page.filters.travelers) page.data.trips.forEach((trip) => page.layerMarkers.push(tripMarker(page, trip)));
  if (page.filters.meetups) page.data.meetups.forEach((meetup) => page.layerMarkers.push(meetupMarker(page, meetup)));
}
