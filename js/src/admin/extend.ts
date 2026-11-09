import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

const t = (key: string) => app.translator.trans(`ernestdefoe-member-map.admin.${key}`, {}, true);
const setting = (key: string) => `ernestdefoe-member-map.${key}`;

export default [
  new Extend.Admin()
    .setting(() => ({ setting: setting('nav'), type: 'boolean', label: t('settings.nav') }), 100)
    .setting(() => ({ setting: setting('profile'), type: 'boolean', label: t('settings.profile') }), 90)
    .setting(
      () => ({
        setting: setting('tiles_url'),
        type: 'text',
        label: t('settings.tiles_url'),
        help: t('settings.tiles_url_help'),
        placeholder: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
      }),
      80
    )
    .setting(
      () => ({ setting: setting('tiles_dark_url'), type: 'text', label: t('settings.tiles_dark_url'), help: t('settings.tiles_dark_url_help') }),
      70
    )
    .setting(
      () => ({ setting: setting('tiles_attribution'), type: 'text', label: t('settings.attribution'), help: t('settings.attribution_help') }),
      60
    )
    .setting(
      () => ({
        setting: setting('geocoder_url'),
        type: 'text',
        label: t('settings.geocoder_url'),
        help: t('settings.geocoder_url_help'),
        placeholder: 'https://nominatim.openstreetmap.org',
      }),
      50
    )
    .setting(
      () => ({ setting: setting('geocoder_key'), type: 'password', label: t('settings.geocoder_key'), help: t('settings.geocoder_key_help') }),
      40
    )
    .setting(
      () => ({ setting: setting('geocoder_email'), type: 'email', label: t('settings.geocoder_email'), help: t('settings.geocoder_email_help') }),
      30
    )
    .permission(
      () => ({ permission: 'member-map.view', icon: 'fas fa-map-location-dot', label: t('permissions.view'), allowGuest: true }),
      'view',
      60
    )
    .permission(() => ({ permission: 'member-map.list', icon: 'fas fa-location-dot', label: t('permissions.list') }), 'reply', 60),
];
