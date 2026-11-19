import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Stream from 'flarum/common/utils/Stream';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, NEW, ONLINE, openLocationForm, removeLocation, shown, t, type MapData, type Member, type SearchResult } from '../api';
import { usesMiles } from '../units';
import { card } from './mapPage/cards';
import { deepLink, intro } from './mapPage/intro';
import { drawLayers, syncMarkers } from './mapPage/markers';
import { list, meetupList, myTrips, near, stats } from './mapPage/panels';
import { SOURCE, type Selected } from './mapPage/shared';
import { toolbar } from './mapPage/toolbar';

/**
 * /map — every member who added themselves, on a globe that eases down into
 * where the community is; avatar pins that stack into clusters; who's online,
 * who's visiting, and meetups; a list view of the same people for keyboards
 * and screen readers; and a bottom sheet for cards on phones.
 *
 * One request (GET /api/member-map) brings everything as lean rows. MapLibre
 * owns the map's DOM: Mithril renders an empty element once and never touches
 * its children. The page's parts live in ./mapPage, imported only from here
 * so they stay in this chunk.
 */

/** Core page parts, loaded through the registry before the page draws. The map engine is its own chunk. */
export let PageStructure: any;
export let IndexSidebar: any;

export async function loadCore(): Promise<void> {
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

export default class MapPage extends Page {
  loading = true;
  error = false;
  data: MapData = { members: [], trips: [], meetups: [], me: null };
  byId = new Map<number, Member>();

  mode: 'map' | 'list' = 'map';
  filters = { online: false, following: false, group: '', travelers: true, meetups: true };
  filterIds: Set<number> | null = null;
  query = Stream('');
  searching = false;
  results: SearchResult[] | null = null;
  selected: Selected | null = null;
  phone = window.matchMedia('(max-width: 767px)');
  miles = usesMiles();
  supported = true;
  /** The map engine couldn't start (a chunk failed to load, say): the list shows, with a note why. */
  mapFailed = false;
  justAdded: number | null = null;

  /** The map engine module (map/engine), once loaded. */
  engine: any = null;
  handle: any = null;
  map: any = null;
  popup: any = null;
  markers = new Map<string, any>();
  layerMarkers: any[] = [];
  /** Set on the map's first `load`. Not map.loaded(): that stays false while any tile is still loading. */
  ready = false;
  introDone = false;
  private syncQueued = false;
  private onPhoneChange = () => m.redraw();
  /** Whether the last input was the keyboard: cards take focus only then, so a click leaves no focus ring. */
  private keyboard = false;
  private onPointer = () => (this.keyboard = false);
  private onKey = (e: KeyboardEvent) => {
    this.keyboard = true;
    if (e.key === 'Escape' && this.selected) {
      this.close();
      m.redraw();
    }
  };

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history.push('member-map', extractText(t('page.title')));
    this.bodyClass = 'App--memberMap';
    this.phone.addEventListener('change', this.onPhoneChange);
    document.addEventListener('keydown', this.onKey);
    document.addEventListener('pointerdown', this.onPointer);
    if (app.forum.attribute('memberMapCanView')) this.load();
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    app.setTitle(extractText(t('page.title')));
    app.setTitleCount(0);
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    this.phone.removeEventListener('change', this.onPhoneChange);
    document.removeEventListener('keydown', this.onKey);
    document.removeEventListener('pointerdown', this.onPointer);
    this.handle?.destroy();
    this.handle = this.map = null;
  }

  load() {
    this.loading = true;
    this.error = false;
    call<MapData>('')
      .then((data) => {
        this.data = data;
        if (this.justAdded) {
          const me = data.members.find((row) => row[0] === this.justAdded);
          if (me) me[9] |= NEW;
        }
        this.byId = new Map(data.members.map((row) => [row[0], row]));
        this.loading = false;
        this.refreshMap();
        intro(this);
        deepLink(this);
      })
      .catch(() => (this.error = true))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  // ---------------------------------------------------------------- view

  view() {
    return (
      <PageStructure className={'MemberMapPage' + (this.selected && this.phone.matches ? ' has-sheet' : '')} sidebar={() => <IndexSidebar />}>
        {app.forum.attribute('memberMapCanView') ? this.body() : this.denied()}
      </PageStructure>
    );
  }

  denied() {
    return (
      <div className="MemberMapPage-head">
        <h2 className="MemberMapPage-title">{t('page.title')}</h2>
        <p className="MemberMapPage-intro">{t('page.intro')}</p>
        {app.session.user ? (
          <p className="MemberMapPage-message">{t('page.no_permission')}</p>
        ) : (
          <div className="MemberMapPage-message">
            <p>{t('page.guest')}</p>
            <Button
              className="Button Button--primary"
              onclick={() => app.modal.show(() => flarum.reg.asyncModuleImport('flarum/forum/components/LogInModal'))}
            >
              {app.translator.trans('core.forum.header.log_in_link')}
            </Button>
          </div>
        )}
      </div>
    );
  }

  body() {
    return [
      <div className="MemberMapPage-head">
        <h2 className="MemberMapPage-title">{t('page.title')}</h2>
        <p className="MemberMapPage-intro">{t('page.intro')}</p>
        {this.actions()}
      </div>,
      toolbar(this),
      stats(this),
      <div className="MemberMapPage-stage">
        {this.mapArea()}
        {this.mapFailed && <p className="MemberMapPage-mapFailed">{t('page.map_failed')}</p>}
        {(this.mode === 'list' || !this.supported) && list(this)}
      </div>,
      <div className="MemberMapPage-below">
        {near(this)}
        {meetupList(this)}
        {myTrips(this)}
      </div>,
      this.selected && this.phone.matches ? this.sheet() : null,
    ];
  }

  mapArea() {
    return (
      <div className="MemberMapPage-mapWrap" hidden={this.mode === 'list' || !this.supported}>
        <div
          className="MemberMap"
          role="region"
          aria-label={extractText(t('page.map_label'))}
          oncreate={(vnode: Mithril.VnodeDOM) => this.createMap(vnode.dom as HTMLElement)}
        />
        {this.loading && (
          <div className="MemberMapPage-overlay">
            <LoadingIndicator />
          </div>
        )}
        {!this.loading && (this.error || !this.data.members.length) && (
          <div className="MemberMapPage-overlay">
            <p>{this.error ? t('page.load_error') : t('page.empty')}</p>
          </div>
        )}
      </div>
    );
  }

  actions() {
    const user = app.session.user;
    if (!user) return null;
    const place = this.data.me ? this.data.me.place : user.attribute<any>('memberMapOwn');
    const modal = (load: () => Promise<any>) => app.modal.show(load, { onsave: () => this.load() });

    return (
      <div className="MemberMapPage-actions">
        {place && <p className="MemberMapPage-you">{place.listed === false ? t('page.not_listed') : t('page.you_are', { label: place.label })}</p>}
        {app.forum.attribute('memberMapCanList') && (
          <Button
            className={'Button' + (place ? '' : ' Button--primary')}
            icon="fas fa-location-dot"
            onclick={() => openLocationForm({ onsave: () => this.saved() })}
          >
            {place ? t('page.change') : t('page.add_me')}
          </Button>
        )}
        {app.forum.attribute('memberMapCanTravel') && (
          <Button className="Button" icon="fas fa-suitcase-rolling" onclick={() => modal(() => import('./TripModal'))}>
            {t('trip.button')}
          </Button>
        )}
        {app.forum.attribute('memberMapCanMeetup') && (
          <Button className="Button" icon="fas fa-calendar-plus" onclick={() => modal(() => import('./MeetupModal'))}>
            {t('meetup.button')}
          </Button>
        )}
        {place && (
          <Button className="Button Button--link" icon="fas fa-xmark" onclick={() => removeLocation().then(() => this.load(), shown)}>
            {t('page.remove')}
          </Button>
        )}
      </div>
    );
  }

  saved() {
    this.justAdded = Number(app.session.user!.id());
    this.introDone = this.data.members.length > 0;
    this.load();
  }

  setMode(mode: 'map' | 'list') {
    this.mode = mode;
    this.close();
    if (mode === 'map') requestAnimationFrame(() => this.map?.resize());
  }

  online(): Member[] {
    return this.data.members.filter((row) => row[9] & ONLINE);
  }

  /** The members the filters let through. */
  visible(): Member[] {
    return this.data.members.filter((row) => (!this.filters.online || row[9] & ONLINE) && (!this.filterIds || this.filterIds.has(row[0])));
  }

  // ---------------------------------------------------------------- cards

  /** Phones: the card slides up from the bottom; drag the handle down or tap the map to close. */
  sheet() {
    let startY = 0;
    let dy = 0;
    return (
      <div className="MemberMapSheet" role="dialog" aria-label={extractText(t('card.label'))}>
        <div
          className="MemberMapSheet-handle"
          onpointerdown={(e: PointerEvent) => {
            startY = e.clientY;
            dy = 0;
            (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
          }}
          onpointermove={(e: PointerEvent) => {
            if (!startY) return;
            dy = Math.max(0, e.clientY - startY);
            ((e.currentTarget as HTMLElement).parentElement as HTMLElement).style.transform = `translateY(${dy}px)`;
          }}
          onpointerup={(e: PointerEvent) => {
            const sheet = (e.currentTarget as HTMLElement).parentElement as HTMLElement;
            startY = 0;
            if (dy > 80) this.close();
            else sheet.style.transform = '';
          }}
        >
          <span />
        </div>
        <Button
          className="Button Button--icon Button--link MemberMapSheet-close"
          icon="fas fa-xmark"
          aria-label={extractText(t('card.close'))}
          onclick={() => this.close()}
        />
        <div className="MemberMapSheet-body">{this.selected && card(this, this.selected)}</div>
      </div>
    );
  }

  select(selected: Selected, lngLat: [number, number]) {
    this.selected = selected;
    if (this.phone.matches || !this.map) {
      this.popup?.remove();
      this.popup = null;
      if (this.map) this.showAboveSheet(lngLat);
      m.redraw();
      return;
    }

    const element = document.createElement('div');
    m.render(element, card(this, selected));
    this.popup?.remove();
    const popup = new this.engine.default.Popup({ offset: 26, maxWidth: '300px', className: 'MemberMapPopup', focusAfterOpen: this.keyboard })
      .setLngLat(lngLat)
      .setDOMContent(element)
      .addTo(this.map);
    popup.on('close', () => {
      m.render(element, null);
      if (this.popup === popup) {
        this.popup = null;
        this.selected = null;
      }
    });
    this.popup = popup;
  }

  /** The map's top just under the header, and what was tapped above the sheet (about half the screen). */
  showAboveSheet(lngLat: [number, number]) {
    const still = this.engine.reducedMotion();
    const box = this.map.getContainer() as HTMLElement;
    const header = (document.querySelector('#header') as HTMLElement | null)?.offsetHeight || 0;
    const top = box.getBoundingClientRect().top - header;
    if (Math.abs(top) > 2) window.scrollBy({ top, behavior: still ? 'auto' : 'smooth' });
    this.map.easeTo({
      center: lngLat,
      duration: still ? 0 : 400,
      padding: { top: 0, bottom: Math.round(box.clientHeight * 0.45), left: 0, right: 0 },
    });
  }

  close() {
    this.selected = null;
    const popup = this.popup;
    this.popup = null;
    popup?.remove();
    this.map?.easeTo({ padding: { top: 0, bottom: 0, left: 0, right: 0 }, duration: 0 });
  }

  // ---------------------------------------------------------------- map

  createMap(element: HTMLElement) {
    import('../map/engine')
      .then(async (module) => {
        this.engine = module;
        await module.prepare();
        if (!module.supported()) {
          this.supported = false;
          this.mode = 'list';
          return;
        }
        if (element.isConnected) this.startMap(element);
      })
      .catch(() => {
        // The engine's chunk or its worker would not load (offline, a strict Content-Security-Policy): the list stands in, with a note why.
        this.supported = false;
        this.mapFailed = true;
        this.mode = 'list';
      })
      .finally(() => m.redraw());
  }

  startMap(element: HTMLElement) {
    this.handle = this.engine.createMap(element, { zoom: 0.6, onStyle: (map: any) => this.addSource(map) });
    this.map = this.handle.map;
    this.map.on('render', () => this.queueSync());
    this.map.on('click', () => {
      if (this.phone.matches && this.selected) {
        this.close();
        m.redraw();
      }
    });
    this.map.once('load', () => {
      this.ready = true;
      intro(this);
      deepLink(this);
    });
  }

  addSource(map: any) {
    if (map.getSource(SOURCE)) return;
    map.addSource(SOURCE, {
      type: 'geojson',
      data: this.geojson(),
      cluster: true,
      clusterRadius: 40,
      clusterMaxZoom: 13,
      clusterProperties: { online: ['+', ['get', 'online']] },
    });
    // Invisible: it makes the source's tiles load so the HTML markers can be read from them.
    map.addLayer({ id: `${SOURCE}-hit`, type: 'circle', source: SOURCE, paint: { 'circle-radius': 0, 'circle-opacity': 0 } });
    this.clearMarkers();
  }

  geojson() {
    return {
      type: 'FeatureCollection',
      features: this.visible().map((row) => ({
        type: 'Feature',
        geometry: { type: 'Point', coordinates: [row[2], row[1]] },
        properties: { id: row[0], online: row[9] & ONLINE ? 1 : 0 },
      })),
    };
  }

  refreshMap() {
    this.close();
    const source = this.map?.getSource(SOURCE);
    if (source) {
      source.setData(this.geojson());
      this.clearMarkers();
    }
    this.drawLayers();
    m.redraw();
  }

  drawLayers() {
    drawLayers(this);
  }

  clearMarkers() {
    this.markers.forEach((marker) => marker.remove());
    this.markers.clear();
  }

  queueSync() {
    if (this.syncQueued) return;
    this.syncQueued = true;
    requestAnimationFrame(() => {
      this.syncQueued = false;
      syncMarkers(this);
    });
  }

  zoomCluster(selected: Extract<Selected, { kind: 'cluster' }>) {
    const source = this.map.getSource(SOURCE);
    this.close();
    source.getClusterExpansionZoom(selected.clusterId).then((zoom: number) => {
      this.map.easeTo({ center: selected.lngLat, zoom: Math.min(zoom, this.map.getMaxZoom()), duration: this.engine.reducedMotion() ? 0 : 700 });
    });
    m.redraw();
  }

  focusMember(id: number) {
    const row = this.byId.get(id);
    if (!row || !this.map) return;
    this.map.getContainer().scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    this.map.flyTo({ center: [row[2], row[1]], zoom: Math.max(this.map.getZoom(), 8), duration: this.engine.reducedMotion() ? 0 : 1200 });
    this.map.once('moveend', () => this.select({ kind: 'member', member: row }, [row[2], row[1]]));
  }

  // ---------------------------------------------------------------- search and filters

  search() {
    const q = this.query().trim();
    if (q.length < 2 || this.searching) return;
    this.searching = true;
    call<{ places: SearchResult[] }>('/geocode', { q })
      .then((data) => (this.results = data.places))
      .catch(shown)
      .finally(() => {
        this.searching = false;
        m.redraw();
      });
  }

  flyToResult(result: SearchResult) {
    this.results = null;
    if (!this.map) return;
    this.setMode('map');
    const duration = this.engine.reducedMotion() ? 0 : 1500;
    if (!result.bbox) {
      this.map.flyTo({ center: [result.lng, result.lat], zoom: 8, duration });
      return;
    }
    const [south, north, west, east] = result.bbox;
    this.map.fitBounds(
      [
        [west, south],
        [east, north],
      ],
      { padding: 40, maxZoom: 9, duration }
    );
  }

  /** Group and People I follow: the server narrows the markers; only their ids are kept, to filter what is drawn. */
  loadFilter() {
    const query: Record<string, string> = {};
    if (this.filters.group) query.group = this.filters.group;
    if (this.filters.following) query.following = '1';
    if (!Object.keys(query).length) {
      this.filterIds = null;
      this.refreshMap();
      return;
    }

    call<MapData>('', query)
      .then((data) => {
        this.filterIds = new Set(data.members.map((row) => row[0]));
        this.refreshMap();
      })
      .catch(shown);
  }
}
