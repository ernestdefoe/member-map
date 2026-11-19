import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import type Model from 'flarum/common/Model';

/** [id, lat, lng, name, slug, avatar, countryCode, timezone, label, flags]; flags: 1 online, 2 new on the map. */
export type Member = [
  id: number,
  lat: number,
  lng: number,
  name: string,
  slug: string,
  avatar: string | null,
  cc: string | null,
  tz: string | null,
  label: string,
  flags: number,
];
/** [userId, lat, lng, label, starts, ends, name, slug, avatar] */
export type TripRow = [
  userId: number,
  lat: number,
  lng: number,
  label: string,
  starts: string,
  ends: string,
  name: string,
  slug: string,
  avatar: string | null,
];
/** [id, lat, lng, label, startsAt, timezone, title, discussionId, discussionSlug] */
export type MeetupRow = [
  id: number,
  lat: number,
  lng: number,
  label: string,
  startsAt: string,
  tz: string | null,
  title: string,
  discussionId: number,
  slug: string,
];

export const ONLINE = 1;
export const NEW = 2;

export interface Place {
  lat: number;
  lng: number;
  label: string;
  precision: 'city' | 'region' | 'country';
  profile: boolean;
  membersOnly: boolean;
  listed: boolean;
}

export interface OwnTrip {
  id: number;
  lat: number;
  lng: number;
  label: string;
  starts: string;
  ends: string;
}

export interface MapData {
  members: Member[];
  trips: TripRow[];
  meetups: MeetupRow[];
  me: { place: Place | null; trips: OwnTrip[] } | null;
}

export interface SearchResult {
  label: string;
  lat: number;
  lng: number;
  bbox: [number, number, number, number] | null;
}

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-member-map.forum.${key}`, params);

/** The sentence for an error answer from our endpoints. */
export function errorText(error: any): string {
  const first = error?.response?.errors?.[0];
  const key = `ernestdefoe-member-map.forum.errors.${first?.code}`;
  if (first?.code && app.translator.trans(key) !== key) return extractText(app.translator.trans(key));
  if (first?.code === 'validation_error' && first.detail) return String(first.detail);
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  return extractText(app.translator.trans('ernestdefoe-member-map.forum.page.load_error'));
}

const isBusy = (error: any) => error?.response?.errors?.[0]?.code === 'member_map_busy';
const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

const rethrow = (error: any): never => {
  throw error;
};

/**
 * Sends a request, with any error shown to the member. A place the forum
 * hasn't looked up yet is queued on the server, which answers "busy" rather
 * than keep the request waiting: that answer is asked again, quietly, every
 * second, for about 15 seconds (a queue worker may be busy with other jobs).
 */
function retrying<T>(send: () => Promise<T>, retries = 14): Promise<T> {
  return send().catch((error) => {
    if (retries > 0 && isBusy(error)) return wait(1100).then(() => retrying(send, retries - 1));
    app.alerts.show({ type: 'error' }, errorText(error));
    throw error;
  });
}

/** Our read-only endpoints under /api/member-map: the map's rows and the place lookup. */
export function call<T>(path: string, query?: Record<string, string>): Promise<T> {
  const url = app.forum.attribute<string>('apiUrl') + '/member-map' + path;
  return retrying(() =>
    app.request<T>({ method: 'GET', url: query ? url + '?' + new URLSearchParams(query).toString() : url, errorHandler: rethrow })
  );
}

/** A record of ours as the store knows it: a new one (no id), or one that exists (put in the store if it isn't yet). */
function record<M extends Model>(type: string, id: string | null): M {
  if (!id) return app.store.createRecord<M>(type, { attributes: {} });
  return app.store.getById<M>(type, id) || (app.store.pushObject<M>({ type, id, attributes: {} }) as M);
}

/** Saves one of our resources through the store: member-map-locations, -trips or -meetups. */
export function saveRecord<M extends Model>(type: string, id: string | null, attributes: Record<string, any>): Promise<M> {
  const target = record<M>(type, id);
  return retrying(() => target.save(attributes, { errorHandler: rethrow })) as Promise<M>;
}

export function deleteRecord(type: string, id: string): Promise<void> {
  const target = record(type, id);
  return retrying(() => target.delete(undefined, { errorHandler: rethrow }));
}

/** For .catch() after call(), saveRecord() or deleteRecord(): the member has already been shown the error. */
export function shown(): void {
  // request() alerted the member; the caller only resets its own state in .finally().
}

/** After saving or removing: the session user's own copy, so profile, card and settings update without a reload. */
export function remember(place: Place | null): void {
  const user = app.session.user;
  if (!user) return;
  const shown = place && place.profile && app.forum.attribute('memberMapProfile');
  user.pushAttributes({
    memberMapOwn: place,
    memberMapLocation: shown ? place!.label : null,
    memberMapPoint: shown ? [place!.lat, place!.lng, place!.precision] : null,
  });
}

export function openLocationForm(attrs: { onsave?: (place: Place | null) => void } = {}): void {
  app.modal.show(() => import('./components/LocationModal'), attrs);
}

export function removeLocation(): Promise<void> {
  return deleteRecord('member-map-locations', String(app.session.user!.id())).then(() => {
    remember(null);
    app.alerts.show({ type: 'success' }, t('location.removed'));
  });
}

/** The browser's own time zone: the server uses it when it belongs to the place's country. */
export function browserZone(): string | undefined {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone;
  } catch {
    return undefined;
  }
}

/** "Dec 3 – 7" style ranges in the viewer's language. */
export function dateRange(starts: string, ends: string): string {
  const a = new Date(starts + 'T12:00:00');
  const b = new Date(ends + 'T12:00:00');
  const format = new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { month: 'short', day: 'numeric' });
  return starts === ends
    ? format.format(a)
    : (format as any).formatRange
      ? (format as any).formatRange(a, b)
      : `${format.format(a)} – ${format.format(b)}`;
}

/** "3:42 PM" in a place's time zone, or null without one. */
export function localTime(timezone: string | null, at: Date = new Date()): string | null {
  if (!timezone) return null;
  try {
    return new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { hour: 'numeric', minute: '2-digit', timeZone: timezone }).format(at);
  } catch {
    return null;
  }
}

/** 🇺🇸 from "us". */
export function flag(cc: string | null): string {
  if (!cc || !/^[a-z]{2}$/i.test(cc)) return '';
  return String.fromCodePoint(
    ...cc
      .toUpperCase()
      .split('')
      .map((c) => 0x1f1e6 + c.charCodeAt(0) - 65)
  );
}

/** "United States" from "us", in the viewer's language. */
export function countryName(cc: string | null): string {
  if (!cc) return '';
  try {
    return new Intl.DisplayNames([app.translator.getLocale() || 'en'], { type: 'region' }).of(cc.toUpperCase()) || cc.toUpperCase();
  } catch {
    return cc.toUpperCase();
  }
}
