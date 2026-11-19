import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
import FieldSet from 'flarum/common/components/FieldSet';
import Icon from 'flarum/common/components/Icon';
import { openLocationForm, removeLocation, t, type Place } from './api';
import MiniMap from './components/MiniMap';
import { registerWidgetHosts } from './WhereWeAre';

export { default as extend } from './extend';

/*
 * forum.js is loaded on every page, so it only wires things up: the sidebar
 * link, the profile line and mini-map, the settings section, the notification
 * preference and the "Where we are" widget (the route and the notification
 * are declared in extend.ts). The map page, the forms and the map engine are
 * chunks fetched when opened.
 */

// String form throughout: these components live in lazy chunks, and extending an imported copy patches one core never renders.

function addSidebarLink() {
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<any>) {
    if (!app.forum.attribute('memberMapCanView') || !app.forum.attribute('memberMapNav')) return;
    items.add('member-map', LinkButton.component({ href: app.route('member-map'), icon: 'fas fa-map-location-dot' }, t('nav')), 80);
  });
}

function addProfileLines() {
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
}

function addNotificationPreference() {
  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    if (!app.forum.attribute('memberMapCanView')) return;
    items.add('memberMapTripNearby', { name: 'memberMapTripNearby', icon: 'fas fa-suitcase-rolling', label: t('notification.preference') });
  });
}

function settingsSection(own: Place | null) {
  return (
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
    </FieldSet>
  );
}

function addSettings() {
  extend('flarum/forum/components/SettingsPage', 'settingsItems', function (this: any, items: ItemList<any>) {
    const own: Place | null = this.user.attribute('memberMapOwn') || null;
    if (!own && !app.forum.attribute('memberMapCanList')) return;
    items.add('member-map', settingsSection(own), 45);
  });
}

app.initializers.add('ernestdefoe/member-map', () => {
  addSidebarLink();
  addProfileLines();
  addNotificationPreference();
  addSettings();
  registerWidgetHosts();
});
