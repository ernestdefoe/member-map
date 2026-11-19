import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import stringToColor from 'flarum/common/utils/stringToColor';
import type { MeetupRow, Member, TripRow } from '../../api';

export const LIST_MAX = 30;
export const NEAR_MAX = 10;
export const SOURCE = 'member-map';

export type Selected =
  | { kind: 'member'; member: Member }
  | { kind: 'cluster'; members: Member[]; clusterId: number; lngLat: [number, number] }
  | { kind: 'trip'; trip: TripRow }
  | { kind: 'meetup'; meetup: MeetupRow };

export function profileUrl(slug: string): string {
  return app.route('user', { username: slug });
}

/** An avatar as DOM, text set as text. */
export function avatarEl(name: string, url: string | null, size = ''): HTMLElement {
  if (url) {
    const img = document.createElement('img');
    img.className = 'Avatar ' + size;
    img.src = url;
    img.alt = '';
    img.loading = 'lazy';
    img.decoding = 'async';
    return img;
  }
  const span = document.createElement('span');
  span.className = 'Avatar ' + size;
  span.style.setProperty('--avatar-bg', '#' + stringToColor(name));
  span.textContent = (name || '?').charAt(0).toUpperCase();
  return span;
}

/** The same avatar inside Mithril views (cards, lists): drawn once as DOM. */
export class Avatar extends Component<{ name: string; url: string | null; size?: string }> {
  view() {
    return (
      <span
        className="MemberMap-avatar"
        oncreate={(vnode: any) => vnode.dom.replaceChildren(avatarEl(this.attrs.name, this.attrs.url, this.attrs.size || ''))}
      />
    );
  }
}

export function icon(name: string): HTMLElement {
  const i = document.createElement('i');
  i.className = name;
  i.setAttribute('aria-hidden', 'true');
  return i;
}

/** "Sat, Dec 6, 7:00 PM CST": a meetup's time in its own place's zone. */
export function meetupWhen(row: MeetupRow): string {
  try {
    return new Intl.DateTimeFormat(app.translator.getLocale() || undefined, {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      timeZone: row[5] || undefined,
      timeZoneName: 'short',
    }).format(new Date(row[4]));
  } catch {
    // An unknown zone name in an old browser: the viewer's own time instead.
    return new Date(row[4]).toLocaleString();
  }
}
