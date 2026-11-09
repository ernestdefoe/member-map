import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import stringToColor from 'flarum/common/utils/stringToColor';
import type Mithril from 'mithril';
import { call, openLocationForm, removeLocation, type Member, type Own } from '../api';
import { distanceKm, roughDistance, usesMiles } from '../units';

/**
 * /map — every member who added themselves, clustered, with the members
 * nearest the viewer beside it.
 *
 * One request (GET /api/member-map) brings every marker as a lean row. Leaflet
 * owns the map's DOM: Mithril renders an empty element once and never
 * touches its children; popups are drawn with m.render into their own nodes.
 */

declare const flarum: any;
declare const m: Mithril.Static;

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-member-map.forum.${key}`, params);

/** Core components this page builds on, loaded through the registry: some live in lazy chunks. */
export let PageStructure: any;
export let IndexSidebar: any;
let L: any;
let leaflet: any;
let AvatarIcon: any;

/** Core's page parts and Leaflet (its own chunk, shared with the location form), before the page draws. */
export async function loadCore(): Promise<void> {
  const [structure, sidebar, map] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
    import('../map/leaflet'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
  leaflet = map;
  L = map.default;

  // An avatar pin, built only when Leaflet first draws it, with text set as text: names never become HTML.
  AvatarIcon ||= L.DivIcon.extend({
    createIcon(this: any, old?: HTMLElement) {
      const div: HTMLElement = L.DivIcon.prototype.createIcon.call(this, old);
      div.replaceChildren(avatar(this.options.member));
      return div;
    },
  });
}

/** How many members a popup lists before "and N more". */
const LIST_MAX = 30;
const NEAR_MAX = 10;

type MarkerWithMember = any;

function avatar(member: Member, size = ''): HTMLElement {
  const [, , , name, , url] = member;
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

function AvatarOf({ attrs }: { attrs: { member: Member } }) {
  return {
    view: () => m('span.MemberMap-avatar', { oncreate: (vnode: any) => vnode.dom.replaceChildren(avatar(attrs.member, 'Avatar--size24')) }),
  };
}

function profileUrl(member: Member): string {
  return app.route('user', { username: member[4] });
}

export default class MapPage extends Page {
  loading = true;
  error = false;
  members: Member[] = [];
  me: Own | null = null;

  map: any = null;
  cluster: any = null;
  markers = new Map<number, MarkerWithMember>();
  stopTiles: (() => void) | null = null;
  miles = usesMiles();

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history.push('member-map', extractText(t('page.title')));
    this.bodyClass = 'App--memberMap';
    if (app.forum.attribute('memberMapCanView')) this.load();
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    app.setTitle(extractText(t('page.title')));
    app.setTitleCount(0);
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    this.destroyMap();
  }

  load() {
    this.loading = true;
    this.error = false;
    call<{ members: Member[]; me: Own | null }>('GET', '')
      .then((data) => {
        this.members = data.members;
        this.me = data.me;
        this.fillMarkers();
      })
      .catch(() => (this.error = true))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <PageStructure className="MemberMapPage" sidebar={() => <IndexSidebar />}>
        <div className="MemberMapPage-head">
          <h2 className="MemberMapPage-title">{t('page.title')}</h2>
          <p className="MemberMapPage-intro">{t('page.intro')}</p>
          {this.actions()}
        </div>
        {app.forum.attribute('memberMapCanView') ? this.body() : this.denied()}
      </PageStructure>
    );
  }

  denied() {
    if (app.session.user) return <p className="MemberMapPage-message">{t('page.no_permission')}</p>;
    return (
      <div className="MemberMapPage-message">
        <p>{t('page.guest')}</p>
        <Button
          className="Button Button--primary"
          onclick={() => app.modal.show(() => flarum.reg.asyncModuleImport('flarum/forum/components/LogInModal'))}
        >
          {app.translator.trans('core.forum.header.log_in_link')}
        </Button>
      </div>
    );
  }

  actions() {
    if (!app.session.user) return null;
    const canList = app.forum.attribute('memberMapCanList');
    const me = this.me ?? app.session.user.attribute<Own | null>('memberMapOwn');
    if (!canList && !me) return null;

    return (
      <div className="MemberMapPage-actions">
        {me && <span className="MemberMapPage-you">{me.listed === false ? t('page.not_listed') : t('page.you_are', { label: me.label })}</span>}
        {canList && (
          <Button
            className={'Button' + (me ? '' : ' Button--primary')}
            icon="fas fa-location-dot"
            onclick={() => openLocationForm({ onsave: () => this.load() })}
          >
            {me ? t('page.change') : t('page.add_me')}
          </Button>
        )}
        {me && (
          <Button className="Button Button--link" icon="fas fa-xmark" onclick={() => removeLocation().then(() => this.load())}>
            {t('page.remove')}
          </Button>
        )}
      </div>
    );
  }

  body() {
    return (
      <div className="MemberMapPage-body">
        <div className="MemberMapPage-mapWrap">
          <div className="MemberMap" oncreate={(vnode: Mithril.VnodeDOM) => this.createMap(vnode.dom as HTMLElement)} />
          {this.loading && (
            <div className="MemberMapPage-overlay">
              <LoadingIndicator />
            </div>
          )}
          {!this.loading && (this.error || !this.members.length) && (
            <div className="MemberMapPage-overlay">
              <p>{this.error ? t('page.load_error') : t('page.empty')}</p>
            </div>
          )}
        </div>
        {this.near()}
      </div>
    );
  }

  near() {
    if (!app.session.user) return null;
    const me = this.me;

    let content: Mithril.Children;
    if (!me) {
      content = <p className="helpText">{t('near.add_prompt')}</p>;
    } else {
      const myId = Number(app.session.user.id());
      const nearest = this.members
        .filter((member) => member[0] !== myId)
        .map((member) => ({ member, km: distanceKm(me.lat, me.lng, member[1], member[2]) }))
        .sort((a, b) => a.km - b.km)
        .slice(0, NEAR_MAX);

      content = nearest.length ? (
        <ul className="MemberMapNear-list">
          {nearest.map(({ member, km }) => {
            const rough = roughDistance(km, this.miles);
            return (
              <li key={member[0]} className="MemberMapNear-item">
                <Link href={profileUrl(member)} className="MemberMapNear-member">
                  <AvatarOf member={member} />
                  <span className="MemberMapNear-name">{member[3]}</span>
                </Link>
                <span className="MemberMapNear-distance">
                  {rough === null ? t('near.same_area') : t(this.miles ? 'near.mi' : 'near.km', { count: rough })}
                </span>
                <Button
                  className="Button Button--icon Button--link MemberMapNear-show"
                  icon="fas fa-location-crosshairs"
                  aria-label={extractText(t('near.show', { name: member[3] }))}
                  onclick={() => this.focus(member[0])}
                />
              </li>
            );
          })}
        </ul>
      ) : (
        <p className="helpText">{t('near.none')}</p>
      );
    }

    return (
      <aside className="MemberMapNear">
        <h3 className="MemberMapNear-heading">{t('near.heading')}</h3>
        {content}
      </aside>
    );
  }

  createMap(element: HTMLElement) {
    leaflet.ensureCss();

    const map = (this.map = L.map(element, {
      center: [20, 0],
      zoom: 2,
      // Never smaller than the box: zoomed further out, the world leaves grey bands above and below.
      // Whole zoom levels only: fractional ones leave hairline seams between tiles, stark in dark mode.
      minZoom: Math.max(1, Math.ceil(Math.log2(element.clientHeight / 256))),
      // Places are city centres: closer than this shows nothing more.
      maxZoom: 12,
      worldCopyJump: true,
      // The wheel scrolls the page until the map is clicked: a map that grabs every scroll traps readers.
      scrollWheelZoom: false,
    }));
    map.on('click focus', () => map.scrollWheelZoom.enable());
    map.on('blur mouseout', () => map.scrollWheelZoom.disable());

    this.stopTiles = leaflet.addTiles(map);
    map.on('resize', () => this.clampToWorld());

    this.cluster = L.markerClusterGroup({
      showCoverageOnHover: false,
      zoomToBoundsOnClick: false,
      spiderfyOnMaxZoom: false,
      maxClusterRadius: 48,
      chunkedLoading: true,
      iconCreateFunction: (cluster: any) => {
        const count = cluster.getChildCount();
        const size = count < 10 ? 'small' : count < 100 ? 'medium' : 'large';
        const span = document.createElement('span');
        span.textContent = String(count);
        return L.divIcon({ html: span, className: `MemberMap-cluster MemberMap-cluster--${size}`, iconSize: L.point(40, 40) });
      },
    }).addTo(map);

    this.cluster.on('clusterclick', (e: any) => this.popup(e.layer.getLatLng(), e.layer.getAllChildMarkers(), e.layer));
    this.cluster.on('click', (e: any) => this.popup(e.layer.getLatLng(), [e.layer]));

    this.fillMarkers();
  }

  fillMarkers() {
    if (!this.map || !this.cluster) return;

    this.cluster.clearLayers();
    this.markers.clear();

    const markers = this.members.map((member) => {
      const marker = L.marker([member[1], member[2]], {
        icon: new AvatarIcon({ member, className: 'MemberMap-pin', iconSize: L.point(36, 36), html: '' }),
        title: member[3],
        alt: member[3],
        keyboard: true,
      }) as MarkerWithMember;
      marker.member = member;
      this.markers.set(member[0], marker);
      return marker;
    });
    this.cluster.addLayers(markers);
    this.showEveryone();
  }

  showEveryone() {
    if (!this.map) return;
    if (!this.members.length) return void this.map.setView([20, 0], this.map.getMinZoom());
    this.map.fitBounds(L.latLngBounds(this.members.map((member) => [member[1], member[2]] as [number, number])).pad(0.2), {
      maxZoom: 6,
      animate: false,
    });
    this.clampToWorld();
  }

  /**
   * Keep the view off the grey beyond the poles, at first and when the box
   * resizes. Not on every move: a popup near the top must still be able to
   * pan into view.
   */
  clampToWorld() {
    this.map?.panInsideBounds(
      [
        [-85, -1e5],
        [85, 1e5],
      ],
      { animate: false }
    );
  }

  /** The members at a marker or cluster: avatars, names and profile links, and a way to zoom in. */
  popup(latlng: any, markers: MarkerWithMember[], cluster?: any) {
    if (!this.map) return;
    const members = markers.map((marker) => marker.member).sort((a, b) => a[3].localeCompare(b[3]));
    const element = document.createElement('div');
    const canZoom = !!cluster && this.map.getZoom() < this.map.getMaxZoom();

    m.render(
      element,
      <div className="MemberMapPopup">
        <div className="MemberMapPopup-count">{t('page.members', { count: members.length })}</div>
        <ul className="MemberMapPopup-list">
          {members.slice(0, LIST_MAX).map((member) => (
            <li key={member[0]}>
              <Link href={profileUrl(member)} className="MemberMapPopup-member" onclick={() => this.map?.closePopup()}>
                <AvatarOf member={member} />
                <span className="MemberMapPopup-name">{member[3]}</span>
              </Link>
            </li>
          ))}
        </ul>
        {members.length > LIST_MAX && <div className="MemberMapPopup-more">{t('page.more', { count: members.length - LIST_MAX })}</div>}
        {canZoom && (
          <Button
            className="Button Button--link MemberMapPopup-zoom"
            icon="fas fa-magnifying-glass-plus"
            onclick={() => {
              this.map?.closePopup();
              cluster.zoomToBounds({ padding: [40, 40] });
            }}
          >
            {t('page.zoom_in')}
          </Button>
        )}
      </div>
    );

    L.popup({
      className: 'MemberMapPopup-wrap',
      maxWidth: 260,
      minWidth: 200,
      autoPanPaddingTopLeft: L.point(56, 16),
      autoPanPaddingBottomRight: L.point(16, 16),
    })
      .setLatLng(latlng)
      .setContent(element)
      .on('remove', () => m.render(element, null))
      .openOn(this.map);
  }

  /** From the Near you list: bring a member's marker into view and open it. */
  focus(id: number) {
    const marker = this.markers.get(id);
    if (!marker || !this.map) return;
    this.map.getContainer().scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    this.cluster.zoomToShowLayer(marker, () => {
      const parent = this.cluster.getVisibleParent(marker);
      if (parent && parent !== marker) this.popup(parent.getLatLng(), parent.getAllChildMarkers(), parent);
      else this.popup(marker.getLatLng(), [marker]);
    });
  }

  destroyMap() {
    this.stopTiles?.();
    this.map?.remove();
    this.map = null;
    this.cluster = null;
    this.markers.clear();
  }
}
