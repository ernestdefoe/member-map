import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

const t = (key: string) => app.translator.trans(`ernestdefoe-member-map.admin.${key}`, {}, true);
const setting = (key: string) => `ernestdefoe-member-map.${key}`;

/** Tags a meetup discussion can go in, when flarum/tags is on. */
function tagOptions(): Record<string, string> {
  const options: Record<string, string> = { '': String(t('settings.meetup_tag_none')) };
  for (const tag of app.store.all<any>('tags')) options[tag.id()] = tag.name();
  return options;
}

export default [
  new Extend.Admin()
    .setting(() => ({ setting: setting('nav'), type: 'boolean', label: t('settings.nav') }), 100)
    .setting(() => ({ setting: setting('profile'), type: 'boolean', label: t('settings.profile') }), 95)
    .setting(() => ({ setting: setting('jitter'), type: 'boolean', label: t('settings.jitter'), help: t('settings.jitter_help') }), 90)
    .setting(
      () => ({ setting: setting('allow_indexing'), type: 'boolean', label: t('settings.allow_indexing'), help: t('settings.allow_indexing_help') }),
      85
    )
    .setting(
      () =>
        'flarum-tags' in ((globalThis as any).flarum?.extensions || {})
          ? {
              setting: setting('meetup_tag'),
              type: 'select',
              options: tagOptions(),
              default: '',
              label: t('settings.meetup_tag'),
              help: t('settings.meetup_tag_help'),
            }
          : null,
      80
    )
    .setting(
      () => ({
        setting: setting('style_url'),
        type: 'text',
        label: t('settings.style_url'),
        help: t('settings.style_url_help'),
        placeholder: 'https://tiles.openfreemap.org/styles/liberty',
      }),
      70
    )
    .setting(
      () => ({
        setting: setting('style_dark_url'),
        type: 'text',
        label: t('settings.style_dark_url'),
        help: t('settings.style_dark_url_help'),
        placeholder: 'https://tiles.openfreemap.org/styles/dark',
      }),
      65
    )
    .setting(
      () => ({
        setting: setting('tiles_url'),
        type: 'text',
        label: t('settings.tiles_url'),
        help: t('settings.tiles_url_help'),
        placeholder: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
      }),
      60
    )
    .setting(
      () => ({ setting: setting('tiles_attribution'), type: 'text', label: t('settings.attribution'), help: t('settings.attribution_help') }),
      55
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
    .permission(() => ({ permission: 'member-map.list', icon: 'fas fa-location-dot', label: t('permissions.list') }), 'reply', 60)
    .permission(() => ({ permission: 'member-map.travel', icon: 'fas fa-suitcase-rolling', label: t('permissions.travel') }), 'reply', 59)
    .permission(() => ({ permission: 'member-map.meetup', icon: 'fas fa-calendar-plus', label: t('permissions.meetup') }), 'start', 60),
];
