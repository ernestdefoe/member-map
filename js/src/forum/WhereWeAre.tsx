import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import type Mithril from 'mithril';
import { countryName, flag, t } from './api';
import { fof, hasFofWidgets, placed } from '../common/fofWidgets';

/**
 * "Where we are": how many members are on the map, in how many countries,
 * the top few, and a way in. Counts only, from /api/member-map/stats (one
 * grouped query, cached ten minutes on the server), fetched once per page
 * load and shared by every place the widget is shown.
 */

declare const m: Mithril.Static;

const EXT = 'ernestdefoe-member-map';
const KEY = 'whereWeAre';
const store: { stats: { members: number; countries: [string, number][] } | null; loading: boolean } = { stats: null, loading: false };

function load() {
  if (store.stats || store.loading || !app.forum.attribute('memberMapCanView')) return;
  store.loading = true;
  app
    .request<any>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/member-map/stats' })
    .then((stats) => (store.stats = stats))
    .catch(() => {})
    .finally(() => {
      store.loading = false;
      m.redraw();
    });
}

export function hasContent(): boolean {
  return !!app.forum.attribute('memberMapCanView') && (!store.stats || store.stats.members > 0);
}

export const WhereWeAre: Mithril.Component<{ heading?: boolean }> = {
  oninit: load,
  view(vnode) {
    const stats = store.stats;
    if (!app.forum.attribute('memberMapCanView') || !stats || !stats.members) return null;
    return (
      <div className="MemberMapWidget">
        {vnode.attrs.heading !== false && <h4 className="MemberMapWidget-title">{t('widget.title')}</h4>}
        <p className="MemberMapWidget-line">{t('widget.line', { members: stats.members, countries: stats.countries.length })}</p>
        <ul className="MemberMapWidget-list">
          {stats.countries.slice(0, 5).map(([cc, count]) => (
            <li>
              <span aria-hidden="true">{flag(cc)}</span> <span className="MemberMapWidget-country">{countryName(cc)}</span>{' '}
              <span className="MemberMapWidget-count">{count}</span>
            </li>
          ))}
        </ul>
        <Link href={app.route('member-map')} className="MemberMapWidget-open">
          {t('widget.open')}
        </Link>
      </div>
    );
  },
};

/** Offer the widget to every host that is installed: Bespoke, Page Builder, fof/forum-widgets-core. */
export function registerWidgetHosts(): void {
  const g = globalThis as any;
  const extensions = g.flarum?.extensions || {};

  if ('ernestdefoe-bespoke' in extensions) {
    g.BespokeWidgetQueue = g.BespokeWidgetQueue || [];
    g.BespokeWidgetQueue.push({
      type: 'member-map-where-we-are',
      label: `${EXT}.forum.widget.title`,
      icon: 'fas fa-earth-americas',
      zones: ['sidebar', 'above-list', 'below-list', 'footer'],
      schema: [],
      component: { view: () => m(WhereWeAre) },
    });
  }

  if ('ernestdefoe-page-builder' in extensions) {
    g.PageBuilderBlockQueue = g.PageBuilderBlockQueue || [];
    g.PageBuilderBlockQueue.push({ type: 'member-map-where-we-are', component: { view: () => m(WhereWeAre) } });
  }

  if (hasFofWidgets()) {
    const Widgets = fof('common/extend/Widgets');
    const WidgetBase = fof('common/components/Widget');
    if (!Widgets || !WidgetBase || !(app as any).widgets) return;

    class WhereWeAreWidget extends WidgetBase {
      className() {
        return 'MemberMapFofWidget';
      }
      icon() {
        return 'fas fa-earth-americas';
      }
      title() {
        return t('widget.title');
      }
      content() {
        return m(WhereWeAre, { heading: false });
      }
    }

    new Widgets()
      .add({
        key: KEY,
        component: WhereWeAreWidget,
        isDisabled: () => !placed(app, EXT, KEY) || !hasContent(),
        isUnique: true,
        placement: 'end',
        position: 1,
      })
      .extend(app, EXT);
  }
}
