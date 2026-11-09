import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

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
  const code = error?.response?.errors?.[0]?.code;
  const key = `ernestdefoe-member-map.forum.errors.${code}`;
  if (code && app.translator.trans(key) !== key) return extractText(app.translator.trans(key));
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  return extractText(app.translator.trans('ernestdefoe-member-map.forum.page.load_error'));
}

export function call<T>(method: 'GET' | 'POST' | 'DELETE', path: string, data?: Record<string, any>): Promise<T> {
  const url = app.forum.attribute<string>('apiUrl') + '/member-map' + path;
  return app.request<T>({
    method,
    url: method === 'GET' && data ? url + '?' + new URLSearchParams(data).toString() : url,
    body: method === 'GET' ? undefined : data,
    errorHandler: (error: any) => {
      app.alerts.show({ type: 'error' }, errorText(error));
      throw error;
    },
  });
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
  return call<void>('DELETE', '/location').then(() => {
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
