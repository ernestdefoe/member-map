import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
import FieldSet from 'flarum/common/components/FieldSet';
import Icon from 'flarum/common/components/Icon';
import Notification from 'flarum/forum/components/Notification';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { dateRange, openLocationForm, removeLocation, t, type Place } from './api';
import { isDark } from './theme';
import { registerWidgetHosts } from './WhereWeAre';

/*
 * forum.js is loaded on every page, so it only wires things up: the route,
 * the sidebar link, the profile line and mini-map, the settings section, the
 * travel notification and the "Where we are" widget. The map page, the forms
 * and the map engine are chunks fetched when opened.
 */

class TripNearbyNotification extends Notification {
  icon() {
    return 'fas fa-suitcase-rolling';
  }

  href() {
    const notification: any = this.attrs.notification;
    return app.route('member-map') + '?traveler=' + notification.fromUser()?.id();
  }

  content(): Mithril.Children {
    const notification: any = this.attrs.notification;
    return t('notification.trip', { username: notification.fromUser()?.displayName() });
  }

  excerpt(): Mithril.Children {
    const data: any = (this.attrs.notification as any).content() || {};
    return data.label ? `${data.label} · ${dateRange(data.starts, data.ends)}` : null;
  }
}

/**
 * A static map of the member's place: four tiles around the rounded centre,
 * no engine. 🚨 The images send the forum's origin as Referer: Flarum's
 * same-origin policy sends none, and OpenStreetMap blocks tiles without one.
 */
function MiniMap({ attrs }: { attrs: { point: [number, number, string]; label: string; userId: string } }) {
  const [lat, lng, precision] = attrs.point;
  const zoom = precision === 'city' ? 8 : precision === 'region' ? 5 : 3;
  const n = 2 ** zoom;
  const px = ((lng + 180) / 360) * n * 256;
  const rad = (lat * Math.PI) / 180;
  const py = ((1 - Math.log(Math.tan(rad) + 1 / Math.cos(rad)) / Math.PI) / 2) * n * 256;
  const tx = Math.floor(px / 256 - 0.5);
  const ty = Math.floor(py / 256 - 0.5);
  const template = app.forum.attribute<string>('memberMapTiles');
  const tiles = [0, 1].flatMap((dy) =>
    [0, 1].map((dx) => {
      const x = (((tx + dx) % n) + n) % n;
      const y = Math.min(Math.max(ty + dy, 0), n - 1);
      return {
        src: template.replace('{z}', String(zoom)).replace('{x}', String(x)).replace('{y}', String(y)).replace('{s}', 'a').replace('{r}', ''),
        dx,
        dy,
      };
    })
  );

  return {
    view: () => (
      <Link
        href={app.route('member-map') + '?member=' + attrs.userId}
        className={'MemberMapMini' + (isDark() ? ' is-dark' : '')}
        aria-label={extractText(t('profile.mini_label', { place: attrs.label }))}
      >
        <span className="MemberMapMini-tiles" style={{ marginLeft: `${-(px - tx * 256)}px`, marginTop: `${-(py - ty * 256)}px` }}>
          {tiles.map((tile) => (
            <img
              src={tile.src}
              alt=""
              loading="lazy"
              referrerpolicy="strict-origin-when-cross-origin"
              decoding="async"
              width="256"
              height="256"
              style={{ left: `${tile.dx * 256}px`, top: `${tile.dy * 256}px` }}
            />
          ))}
        </span>
        <span className="MemberMapMini-dot" />
        <span className="MemberMapMini-label">
          <Icon name="fas fa-location-dot" /> {attrs.label}
        </span>
      </Link>
    ),
  };
}

declare const m: Mithril.Static;

app.initializers.add('ernestdefoe/member-map', () => {
  app.routes['member-map'] = {
    path: '/map',
    component: () => import('./components/MapPage').then(async (page) => (await page.loadCore(), page)),
  };

  app.notificationComponents.memberMapTripNearby = TripNearbyNotification;

  // String form: these components live in lazy chunks, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<any>) {
    if (!app.forum.attribute('memberMapCanView') || !app.forum.attribute('memberMapNav')) return;
    items.add('member-map', LinkButton.component({ href: app.route('member-map'), icon: 'fas fa-map-location-dot' }, t('nav')), 80);
  });

  extend('flarum/forum/components/UserCard', 'infoItems', function (this: any, items: ItemList<any>) {
    const label = this.attrs.user.attribute('memberMapLocation');
    if (!label) return;
    items.add(
      'member-map',
      <Link href={app.route('member-map') + '?member=' + this.attrs.user.id()} className="UserCard-memberMap">
        <Icon name="fas fa-location-dot" /> {label}
      </Link>,
      85
    );
  });

  extend('flarum/forum/components/UserPage', 'sidebarItems', function (this: any, items: ItemList<any>) {
    const user = this.user;
    const point = user?.attribute('memberMapPoint');
    const label = user?.attribute('memberMapLocation');
    if (!point || !label) return;
    items.add('member-map', <MiniMap point={point} label={label} userId={String(user.id())} />, -50);
  });

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    if (!app.forum.attribute('memberMapCanView')) return;
    items.add('memberMapTripNearby', { name: 'memberMapTripNearby', icon: 'fas fa-suitcase-rolling', label: t('notification.preference') });
  });

  extend('flarum/forum/components/SettingsPage', 'settingsItems', function (this: any, items: ItemList<any>) {
    const own: Place | null = this.user.attribute('memberMapOwn') || null;
    if (!own && !app.forum.attribute('memberMapCanList')) return;

    items.add(
      'member-map',
      <FieldSet className="Settings-memberMap FieldSet--min" label={t('settings.heading')}>
        <p className="helpText">{own ? t('settings.shown_as', { label: own.label }) : t('settings.none')}</p>
        {own?.membersOnly && <p className="helpText">{t('settings.members_only')}</p>}
        <div className="Settings-memberMap-actions">
          {app.forum.attribute('memberMapCanList') && (
            <Button className="Button" icon="fas fa-location-dot" onclick={() => openLocationForm()}>
              {own ? t('page.change') : t('page.add_me')}
            </Button>
          )}
          {own && (
            <Button className="Button Button--link" icon="fas fa-xmark" onclick={() => removeLocation().then(() => m.redraw())}>
              {t('page.remove')}
            </Button>
          )}
        </div>
      </FieldSet>,
      45
    );
  });

  registerWidgetHosts();
});
