import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Button from 'flarum/common/components/Button';
import FieldSet from 'flarum/common/components/FieldSet';
import Icon from 'flarum/common/components/Icon';
import { openLocationForm, removeLocation, type Own } from './api';

/*
 * forum.js is loaded on every page, so it only wires things up: the route,
 * the sidebar link, the profile line and the settings section. The map page
 * and the location form (with Leaflet) are chunks fetched when opened.
 */

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-member-map.forum.${key}`, params);

app.initializers.add('ernestdefoe/member-map', () => {
  app.routes['member-map'] = {
    path: '/map',
    component: () => import('./components/MapPage').then(async (page) => (await page.loadCore(), page)),
  };

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
      <span className="UserCard-memberMap">
        <Icon name="fas fa-location-dot" /> {label}
      </span>,
      85
    );
  });

  extend('flarum/forum/components/SettingsPage', 'settingsItems', function (this: any, items: ItemList<any>) {
    const own: Own | null = this.user.attribute('memberMapOwn') || null;
    if (!own && !app.forum.attribute('memberMapCanList')) return;

    items.add(
      'member-map',
      <FieldSet className="Settings-memberMap FieldSet--min" label={t('settings.heading')}>
        <p className="helpText">{own ? t('settings.shown_as', { label: own.label }) : t('settings.none')}</p>
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
});
