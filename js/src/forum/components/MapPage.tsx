import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import Stream from 'flarum/common/utils/Stream';
import extractText from 'flarum/common/utils/extractText';
import stringToColor from 'flarum/common/utils/stringToColor';
import type Mithril from 'mithril';
import {
  call,
  countryName,
  dateRange,
  flag,
  localTime,
  NEW,
  ONLINE,
  openLocationForm,
  removeLocation,
  t,
  type MapData,
  type MeetupRow,
  type Member,
  type SearchResult,
  type TripRow,
} from '../api';
import { CONTINENTS, continentOf } from '../continents';
import { distanceKm, roughDistance, usesMiles } from '../units';

/**
 * /map — every member who added themselves, on a globe that eases down into
 * where the community is; avatar pins that stack into clusters; who's online,
 * who's visiting, and meetups; a list view of the same people for keyboards
 * and screen readers; and a bottom sheet for cards on phones.
 *
 * One request (GET /api/member-map) brings everything as lean rows. MapLibre
 * owns the map's DOM: Mithril renders an empty element once and never touches
 * its children. Markers are HTML elements built from DOM text, never HTML
 * strings, so a name can never become markup.
 */

declare const flarum: any;
declare const m: Mithril.Static;

/** Core page parts, loaded through the registry before the page draws. The map engine is its own chunk. */
export let PageStructure: any;
export let IndexSidebar: any;
let engine: any = null;

export async function loadCore(): Promise<void> {
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

function loadEngine(): Promise<any> {
  return engine ? Promise.resolve(engine) : import('../map/engine').then((module) => (engine = module));
}

const LIST_MAX = 30;
const NEAR_MAX = 10;
const SOURCE = 'member-map';

type Selected =
  | { kind: 'member'; member: Member }
  | { kind: 'cluster'; members: Member[]; clusterId: number; lngLat: [number, number] }
  | { kind: 'trip'; trip: TripRow }
  | { kind: 'meetup'; meetup: MeetupRow };

function profileUrl(slug: string): string {
  return app.route('user', { username: slug });
}

/** An avatar as DOM, text set as text. */
function avatarEl(name: string, url: string | null, size = ''): HTMLElement {
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

function Avatar({ attrs }: { attrs: { name: string; url: string | null; size?: string } }) {
  return {
    view: () =>
      m('span.MemberMap-avatar', { oncreate: (vnode: any) => vnode.dom.replaceChildren(avatarEl(attrs.name, attrs.url, attrs.size || '')) }),
  };
}

function icon(name: string): HTMLElement {
  const i = document.createElement('i');
  i.className = name;
  i.setAttribute('aria-hidden', 'true');
  return i;
}

function meetupWhen(row: MeetupRow): string {
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
    return new Date(row[4]).toLocaleString();
  }
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
  justAdded: number | null = null;

  handle: any = null;
  map: any = null;
  popup: any = null;
  markers = new Map<string, any>();
  layerMarkers: any[] = [];
  private syncQueued = false;
  /** Set on the map's first `load`. Not map.loaded(): that stays false while any tile is still loading. */
  private ready = false;
  private introDone = false;
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
    call<MapData>('GET', '')
      .then((data) => {
        this.data = data;
        if (this.justAdded) {
          const me = data.members.find((row) => row[0] === this.justAdded);
          if (me) me[9] |= NEW;
        }
        this.byId = new Map(data.members.map((row) => [row[0], row]));
        this.loading = false;
        this.refreshMap();
        this.intro();
        this.deepLink();
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
      this.toolbar(),
      this.stats(),
      <div className="MemberMapPage-stage">
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
        {(this.mode === 'list' || !this.supported) && this.list()}
      </div>,
      <div className="MemberMapPage-below">
        {this.near()}
        {this.meetupList()}
        {this.myTrips()}
      </div>,
      this.selected && this.phone.matches ? this.sheet() : null,
    ];
  }

  actions() {
    const user = app.session.user;
    if (!user) return null;
    const canList = app.forum.attribute('memberMapCanList');
    const place = this.data.me ? this.data.me.place : user.attribute<any>('memberMapOwn');

    return (
      <div className="MemberMapPage-actions">
        {place && (
          <span className="MemberMapPage-you">{place.listed === false ? t('page.not_listed') : t('page.you_are', { label: place.label })}</span>
        )}
        {canList && (
          <Button
            className={'Button' + (place ? '' : ' Button--primary')}
            icon="fas fa-location-dot"
            onclick={() => openLocationForm({ onsave: () => this.saved() })}
          >
            {place ? t('page.change') : t('page.add_me')}
          </Button>
        )}
        {app.forum.attribute('memberMapCanTravel') && (
          <Button
            className="Button"
            icon="fas fa-suitcase-rolling"
            onclick={() => app.modal.show(() => import('./TripModal'), { onsave: () => this.load() })}
          >
            {t('trip.button')}
          </Button>
        )}
        {app.forum.attribute('memberMapCanMeetup') && (
          <Button
            className="Button"
            icon="fas fa-calendar-plus"
            onclick={() => app.modal.show(() => import('./MeetupModal'), { onsave: () => this.load() })}
          >
            {t('meetup.button')}
          </Button>
        )}
        {place && (
          <Button className="Button Button--link" icon="fas fa-xmark" onclick={() => removeLocation().then(() => this.load())}>
            {t('page.remove')}
          </Button>
        )}
      </div>
    );
  }

  saved() {
    this.justAdded = Number(app.session.user!.id());
    this.load();
  }

  toolbar() {
    const groups = app.store.all<any>('groups').filter((group) => !['1', '2', '3'].includes(String(group.id())) && !group.isHidden?.());
    const following = !!app.session.user && 'ianm-follow-users' in (flarum.extensions || {});
    const chip = (key: 'online' | 'following' | 'travelers' | 'meetups', iconName: string, label: string, onchange?: () => void) => (
      <button
        type="button"
        className={'MemberMapChip' + (this.filters[key] ? ' is-on' : '')}
        aria-pressed={this.filters[key] ? 'true' : 'false'}
        onclick={() => {
          this.filters[key] = !this.filters[key];
          onchange ? onchange() : this.refreshMap();
        }}
      >
        <Icon name={iconName} /> {label}
      </button>
    );

    return (
      <div className="MemberMapPage-toolbar">
        {app.session.user && (
          <form
            className="MemberMapSearch"
            role="search"
            onsubmit={(e: SubmitEvent) => {
              e.preventDefault();
              this.search();
            }}
          >
            <input
              className="FormControl"
              type="search"
              aria-label={extractText(t('search.label'))}
              placeholder={extractText(t('search.placeholder'))}
              bidi={this.query}
            />
            <Button
              className="Button Button--icon"
              type="submit"
              icon="fas fa-magnifying-glass"
              loading={this.searching}
              aria-label={extractText(t('search.button'))}
            />
            {this.results && (
              <ul className="MemberMapSearch-results">
                {this.results.length ? (
                  this.results.map((result) => (
                    <li>
                      <button type="button" onclick={() => this.flyToResult(result)}>
                        {result.label}
                      </button>
                    </li>
                  ))
                ) : (
                  <li className="helpText">{t('search.none')}</li>
                )}
              </ul>
            )}
          </form>
        )}
        <div className="MemberMapPage-chips" role="group" aria-label={extractText(t('filters.label'))}>
          {chip('online', 'fas fa-circle', extractText(t('filters.online')))}
          {following && chip('following', 'fas fa-user-check', extractText(t('filters.following')), () => this.loadFilter())}
          {groups.length > 0 && (
            <select
              className="FormControl MemberMapChip-select"
              aria-label={extractText(t('filters.group'))}
              value={this.filters.group}
              onchange={(e: Event) => {
                this.filters.group = (e.target as HTMLSelectElement).value;
                this.loadFilter();
              }}
            >
              <option value="">{t('filters.any_group')}</option>
              {groups.map((group) => (
                <option value={group.id()}>{group.namePlural()}</option>
              ))}
            </select>
          )}
          {this.data.trips.length > 0 && chip('travelers', 'fas fa-suitcase-rolling', extractText(t('filters.travelers')))}
          {this.data.meetups.length > 0 && chip('meetups', 'fas fa-calendar-day', extractText(t('filters.meetups')))}
          {this.supported && (
            <div className="MemberMapToggle" role="group" aria-label={extractText(t('view.label'))}>
              <button type="button" aria-pressed={this.mode === 'map' ? 'true' : 'false'} onclick={() => this.setMode('map')}>
                <Icon name="fas fa-earth-americas" /> {t('view.map')}
              </button>
              <button type="button" aria-pressed={this.mode === 'list' ? 'true' : 'false'} onclick={() => this.setMode('list')}>
                <Icon name="fas fa-list" /> {t('view.list')}
              </button>
            </div>
          )}
        </div>
      </div>
    );
  }

  setMode(mode: 'map' | 'list') {
    this.mode = mode;
    this.close();
    if (mode === 'map') requestAnimationFrame(() => this.map?.resize());
  }

  stats() {
    const members = this.data.members;
    if (!members.length) return null;
    const countries = new Set(members.map((row) => row[6]).filter(Boolean) as string[]);
    const continents = new Set([...countries].map((cc) => continentOf(cc)).filter(Boolean));
    const left = CONTINENTS - continents.size;
    const online = this.online().length;

    return (
      <p className="MemberMapStats">
        {t('stats.members', { count: members.length })}
        {countries.size > 0 && [' · ', t('stats.countries', { count: countries.size })]}
        {countries.size > 0 && [' · ', left > 0 ? t('stats.to_go', { count: left }) : t('stats.everywhere')]}
        {online > 0 && [
          ' · ',
          <span className="MemberMapStats-online">
            <span className="MemberMap-onlineDot" /> {t('stats.online', { count: online })}
          </span>,
        ]}
      </p>
    );
  }

  online(): Member[] {
    return this.data.members.filter((row) => row[9] & ONLINE);
  }

  /** The members the filters let through. */
  visible(): Member[] {
    return this.data.members.filter((row) => (!this.filters.online || row[9] & ONLINE) && (!this.filterIds || this.filterIds.has(row[0])));
  }

  /** The list view: the same people, grouped by country, for keyboards and screen readers. */
  list() {
    const groups = new Map<string, Member[]>();
    for (const row of this.visible()) {
      const key = row[6] || '';
      groups.set(key, [...(groups.get(key) || []), row]);
    }
    const sorted = [...groups.entries()].sort((a, b) => b[1].length - a[1].length || countryName(a[0]).localeCompare(countryName(b[0])));

    if (!sorted.length) return <div className="MemberMapPage-message">{this.loading ? <LoadingIndicator /> : t('page.empty')}</div>;

    return (
      <div className="MemberMapList">
        {sorted.map(([cc, rows]) => (
          <section className="MemberMapList-country" aria-labelledby={`member-map-cc-${cc || 'none'}`}>
            <h3 id={`member-map-cc-${cc || 'none'}`}>
              <span aria-hidden="true">{flag(cc)}</span> {cc ? countryName(cc) : t('list.elsewhere')}{' '}
              <span className="MemberMapList-count">{rows.length}</span>
            </h3>
            <ul>
              {rows
                .slice()
                .sort((a, b) => a[3].localeCompare(b[3]))
                .map((row) => (
                  <li key={row[0]}>
                    <Link href={profileUrl(row[4])} className="MemberMapList-member">
                      <Avatar name={row[3]} url={row[5]} size="Avatar--size24" />
                      <span className="MemberMapList-name">{row[3]}</span>
                      {row[9] & ONLINE ? <span className="MemberMap-onlineDot" title={extractText(t('card.online'))} /> : null}
                    </Link>
                    <span className="MemberMapList-meta">
                      {row[8]}
                      {localTime(row[7]) && [' · ', t('card.local_time', { time: localTime(row[7]) })]}
                    </span>
                    {this.supported && (
                      <Button
                        className="Button Button--icon Button--link"
                        icon="fas fa-location-crosshairs"
                        aria-label={extractText(t('near.show', { name: row[3] }))}
                        onclick={() => {
                          this.setMode('map');
                          setTimeout(() => this.focusMember(row[0]), 50);
                        }}
                      />
                    )}
                  </li>
                ))}
            </ul>
          </section>
        ))}
      </div>
    );
  }

  near() {
    if (!app.session.user) return null;
    const place = this.data.me?.place;

    let content: Mithril.Children;
    if (!place) {
      content = <p className="helpText">{t('near.add_prompt')}</p>;
    } else {
      const myId = Number(app.session.user.id());
      const nearest = this.data.members
        .filter((row) => row[0] !== myId)
        .map((row) => ({ row, km: distanceKm(place.lat, place.lng, row[1], row[2]) }))
        .sort((a, b) => a.km - b.km)
        .slice(0, NEAR_MAX);

      content = nearest.length ? (
        <ul className="MemberMapNear-list">
          {nearest.map(({ row, km }) => {
            const rough = roughDistance(km, this.miles);
            return (
              <li key={row[0]} className="MemberMapNear-item">
                <Link href={profileUrl(row[4])} className="MemberMapNear-member">
                  <Avatar name={row[3]} url={row[5]} size="Avatar--size24" />
                  <span className="MemberMapNear-name">{row[3]}</span>
                  {row[9] & ONLINE ? <span className="MemberMap-onlineDot" title={extractText(t('card.online'))} /> : null}
                </Link>
                <span className="MemberMapNear-distance">
                  {rough === null ? t('near.same_area') : t(this.miles ? 'near.mi' : 'near.km', { count: rough })}
                </span>
                {this.supported && (
                  <Button
                    className="Button Button--icon Button--link MemberMapNear-show"
                    icon="fas fa-location-crosshairs"
                    aria-label={extractText(t('near.show', { name: row[3] }))}
                    onclick={() => {
                      this.setMode('map');
                      setTimeout(() => this.focusMember(row[0]), 50);
                    }}
                  />
                )}
              </li>
            );
          })}
        </ul>
      ) : (
        <p className="helpText">{t('near.none')}</p>
      );
    }

    return (
      <section className="MemberMapNear">
        <h3 className="MemberMapSection-heading">{t('near.heading')}</h3>
        {content}
      </section>
    );
  }

  meetupList() {
    if (!this.data.meetups.length) return null;
    return (
      <section className="MemberMapMeetups">
        <h3 className="MemberMapSection-heading">{t('meetup.heading')}</h3>
        <ul>
          {this.data.meetups.map((row) => (
            <li key={row[0]}>
              <Icon name="fas fa-calendar-day" />
              <div>
                <Link href={app.route('discussion', { id: `${row[7]}-${row[8]}` })} className="MemberMapMeetups-title">
                  {row[6]}
                </Link>
                <div className="MemberMapMeetups-meta">
                  {meetupWhen(row)} · {row[3]}
                </div>
              </div>
            </li>
          ))}
        </ul>
      </section>
    );
  }

  myTrips() {
    const trips = this.data.me?.trips || [];
    if (!trips.length) return null;
    return (
      <section className="MemberMapTrips">
        <h3 className="MemberMapSection-heading">{t('trip.mine')}</h3>
        <ul>
          {trips.map((trip) => (
            <li key={trip.id}>
              <Icon name="fas fa-suitcase-rolling" />{' '}
              <span>{t('trip.visiting', { place: trip.label, dates: dateRange(trip.starts, trip.ends) })}</span>
              <Button className="Button Button--link" icon="fas fa-xmark" onclick={() => call('DELETE', `/trips/${trip.id}`).then(() => this.load())}>
                {t('trip.remove')}
              </Button>
            </li>
          ))}
        </ul>
      </section>
    );
  }

  // ---------------------------------------------------------------- cards

  /** A member, a cluster, a traveler or a meetup, for the popup (desktop) or the bottom sheet (phones). */
  card(selected: Selected): Mithril.Children {
    if (selected.kind === 'member') {
      const row = selected.member;
      const time = localTime(row[7]);
      const trips = this.data.trips.filter((trip) => trip[0] === row[0]);
      return (
        <div className="MemberMapCard">
          <div className="MemberMapCard-head">
            <Avatar name={row[3]} url={row[5]} size="Avatar--size48" />
            <div className="MemberMapCard-who">
              <Link href={profileUrl(row[4])} className="MemberMapCard-name" onclick={() => this.close()}>
                {row[3]}
              </Link>
              <div className="MemberMapCard-place">
                <span aria-hidden="true">{flag(row[6])}</span> {row[8]}
              </div>
            </div>
          </div>
          <ul className="MemberMapCard-facts">
            {row[9] & ONLINE ? (
              <li className="MemberMapCard-online">
                <span className="MemberMap-onlineDot" /> {t('card.online')}
              </li>
            ) : null}
            {time && (
              <li>
                <Icon name="far fa-clock" /> {t('card.local_time', { time })}
              </li>
            )}
            {trips.map((trip) => (
              <li>
                <Icon name="fas fa-suitcase-rolling" /> {t('trip.visiting', { place: trip[3], dates: dateRange(trip[4], trip[5]) })}
              </li>
            ))}
          </ul>
          <Link href={profileUrl(row[4])} className="Button Button--primary Button--block" onclick={() => this.close()}>
            {t('card.profile')}
          </Link>
        </div>
      );
    }

    if (selected.kind === 'cluster') {
      const members = selected.members.slice().sort((a, b) => (b[9] & ONLINE) - (a[9] & ONLINE) || a[3].localeCompare(b[3]));
      const canZoom = this.map && this.map.getZoom() < this.map.getMaxZoom() - 0.5;
      return (
        <div className="MemberMapCard">
          <div className="MemberMapCard-count">{t('page.members', { count: members.length })}</div>
          <ul className="MemberMapCard-list">
            {members.slice(0, LIST_MAX).map((row) => (
              <li key={row[0]}>
                <button type="button" className="MemberMapCard-row" onclick={() => this.select({ kind: 'member', member: row }, [row[2], row[1]])}>
                  <Avatar name={row[3]} url={row[5]} size="Avatar--size24" />
                  <span className="MemberMapCard-rowName">{row[3]}</span>
                  {row[9] & ONLINE ? <span className="MemberMap-onlineDot" /> : null}
                </button>
              </li>
            ))}
          </ul>
          {members.length > LIST_MAX && <div className="MemberMapCard-more">{t('page.more', { count: members.length - LIST_MAX })}</div>}
          {canZoom && (
            <Button className="Button Button--link MemberMapCard-zoom" icon="fas fa-magnifying-glass-plus" onclick={() => this.zoomCluster(selected)}>
              {t('page.zoom_in')}
            </Button>
          )}
        </div>
      );
    }

    if (selected.kind === 'trip') {
      const trip = selected.trip;
      return (
        <div className="MemberMapCard">
          <div className="MemberMapCard-head">
            <Avatar name={trip[6]} url={trip[8]} size="Avatar--size48" />
            <div className="MemberMapCard-who">
              <Link href={profileUrl(trip[7])} className="MemberMapCard-name" onclick={() => this.close()}>
                {trip[6]}
              </Link>
              <div className="MemberMapCard-place">
                <Icon name="fas fa-suitcase-rolling" /> {t('trip.visiting', { place: trip[3], dates: dateRange(trip[4], trip[5]) })}
              </div>
            </div>
          </div>
          <Link href={profileUrl(trip[7])} className="Button Button--block" onclick={() => this.close()}>
            {t('card.profile')}
          </Link>
        </div>
      );
    }

    const meetup = selected.meetup;
    return (
      <div className="MemberMapCard">
        <div className="MemberMapCard-meetupTitle">
          <Icon name="fas fa-calendar-day" /> {meetup[6]}
        </div>
        <ul className="MemberMapCard-facts">
          <li>
            <Icon name="far fa-clock" /> {meetupWhen(meetup)}
          </li>
          <li>
            <Icon name="fas fa-location-dot" /> {meetup[3]}
          </li>
        </ul>
        <Link
          href={app.route('discussion', { id: `${meetup[7]}-${meetup[8]}` })}
          className="Button Button--primary Button--block"
          onclick={() => this.close()}
        >
          {t('meetup.open')}
        </Link>
      </div>
    );
  }

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
        <div className="MemberMapSheet-body">{this.selected && this.card(this.selected)}</div>
      </div>
    );
  }

  select(selected: Selected, lngLat: [number, number]) {
    this.selected = selected;
    if (this.phone.matches || !this.map) {
      this.popup?.remove();
      this.popup = null;
      if (this.map) {
        // The map's top just under the header, and what was tapped above the sheet (about half the screen).
        const box = this.map.getContainer() as HTMLElement;
        const header = (document.querySelector('#header') as HTMLElement | null)?.offsetHeight || 0;
        const top = box.getBoundingClientRect().top - header;
        if (Math.abs(top) > 2) window.scrollBy({ top, behavior: engine?.reducedMotion() ? 'auto' : 'smooth' });
        this.map.easeTo({
          center: lngLat,
          duration: engine?.reducedMotion() ? 0 : 400,
          padding: { top: 0, bottom: Math.round(box.clientHeight * 0.45), left: 0, right: 0 },
        });
      }
      m.redraw();
      return;
    }

    const element = document.createElement('div');
    m.render(element, this.card(selected));
    this.popup?.remove();
    const popup = new engine.default.Popup({ offset: 26, maxWidth: '300px', className: 'MemberMapPopup', focusAfterOpen: this.keyboard })
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

  close() {
    this.selected = null;
    const popup = this.popup;
    this.popup = null;
    popup?.remove();
    this.map?.easeTo({ padding: { top: 0, bottom: 0, left: 0, right: 0 }, duration: 0 });
  }

  // ---------------------------------------------------------------- map

  createMap(element: HTMLElement) {
    loadEngine().then(async (module) => {
      await module.prepare();
      if (!module.supported()) {
        this.supported = false;
        this.mode = 'list';
        m.redraw();
        return;
      }
      if (!element.isConnected) return;

      this.handle = module.createMap(element, { zoom: 0.6, onStyle: (map: any) => this.addSource(map) });
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
        this.intro();
        this.deepLink();
      });
    });
  }

  addSource(map: any) {
    if (map.getSource(SOURCE)) return;
    map.addSource(SOURCE, {
      type: 'geojson',
      data: this.geojson(),
      cluster: true,
      clusterRadius: 56,
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

  clearMarkers() {
    this.markers.forEach((marker) => marker.remove());
    this.markers.clear();
  }

  queueSync() {
    if (this.syncQueued) return;
    this.syncQueued = true;
    requestAnimationFrame(() => {
      this.syncQueued = false;
      this.syncMarkers();
    });
  }

  /** HTML markers for what the clustered source shows right now: avatar pins and avatar stacks. */
  syncMarkers() {
    const map = this.map;
    if (!map || !map.getSource(SOURCE) || !map.isSourceLoaded(SOURCE)) return;

    const seen = new Set<string>();
    for (const feature of map.querySourceFeatures(SOURCE)) {
      const props = feature.properties || {};
      const key = props.cluster ? `c${props.cluster_id}` : `m${props.id}`;
      if (seen.has(key)) continue;
      seen.add(key);
      if (this.markers.has(key)) continue;

      const coordinates = (feature.geometry as any).coordinates as [number, number];
      const element = props.cluster ? this.stackEl(props, coordinates) : this.pinEl(props.id, coordinates);
      if (!element) continue;
      this.markers.set(key, this.marker(element, coordinates));
    }

    for (const [key, marker] of this.markers) {
      if (!seen.has(key)) {
        marker.remove();
        this.markers.delete(key);
      }
    }
  }

  /**
   * 🚨 MapLibre positions a marker by writing `transform` on its element, so
   * our button sits INSIDE a plain wrapper: the hover zoom and the drop-in
   * animation transform the button, never the element MapLibre moves.
   */
  marker(element: HTMLElement, lngLat: [number, number], options: Record<string, any> = {}) {
    const wrapper = document.createElement('div');
    wrapper.className = 'MemberMapMarker';
    wrapper.append(element);
    // Hidden, not ghosted, on the far side of the globe.
    return new engine.default.Marker({ element: wrapper, anchor: 'center', opacityWhenCovered: '0', ...options }).setLngLat(lngLat).addTo(this.map);
  }

  pinEl(id: number, lngLat: [number, number]): HTMLElement | null {
    const row = this.byId.get(id);
    if (!row) return null;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'MemberMapPin';
    if (row[9] & ONLINE) button.classList.add('is-online');
    if (row[9] & NEW) button.classList.add('is-new');
    if (app.session.user && Number(app.session.user.id()) === id) button.classList.add('is-me');
    button.setAttribute('aria-label', extractText(t('pin.member', { name: row[3], place: row[8] })));
    button.append(avatarEl(row[3], row[5]));
    button.addEventListener('click', (e) => {
      e.stopPropagation();
      this.select({ kind: 'member', member: row }, lngLat);
    });
    return button;
  }

  stackEl(props: any, lngLat: [number, number]): HTMLElement {
    const count = props.point_count as number;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'MemberMapStack';
    if (props.online > 0) button.classList.add('is-online');
    button.setAttribute('aria-label', extractText(t('pin.cluster', { count })));
    const faces = document.createElement('span');
    faces.className = 'MemberMapStack-faces';
    const badge = document.createElement('span');
    badge.className = 'MemberMapStack-count';
    badge.textContent = count > 999 ? `${Math.round(count / 100) / 10}k` : String(count);
    button.append(faces, badge);

    const source = this.map.getSource(SOURCE);
    source.getClusterLeaves(props.cluster_id, 3, 0).then((leaves: any[]) => {
      faces.replaceChildren(
        ...leaves
          .map((leaf) => this.byId.get(leaf.properties.id))
          .filter((row): row is Member => !!row)
          .map((row) => avatarEl(row[3], row[5]))
      );
    });
    button.addEventListener('click', (e) => {
      e.stopPropagation();
      source.getClusterLeaves(props.cluster_id, Infinity, 0).then((leaves: any[]) => {
        const members = leaves.map((leaf) => this.byId.get(leaf.properties.id)).filter((row): row is Member => !!row);
        this.select({ kind: 'cluster', members, clusterId: props.cluster_id, lngLat }, lngLat);
      });
    });
    return button;
  }

  zoomCluster(selected: Extract<Selected, { kind: 'cluster' }>) {
    const source = this.map.getSource(SOURCE);
    this.close();
    source.getClusterExpansionZoom(selected.clusterId).then((zoom: number) => {
      this.map.easeTo({ center: selected.lngLat, zoom: Math.min(zoom, this.map.getMaxZoom()), duration: engine.reducedMotion() ? 0 : 700 });
    });
    m.redraw();
  }

  /** Travelers and meetups: a few markers each, drawn directly. */
  drawLayers() {
    this.layerMarkers.forEach((marker) => marker.remove());
    this.layerMarkers = [];
    if (!this.map) return;

    if (this.filters.travelers) {
      for (const trip of this.data.trips) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'MemberMapPin MemberMapPin--trip';
        button.setAttribute('aria-label', extractText(t('pin.trip', { name: trip[6], place: trip[3], dates: dateRange(trip[4], trip[5]) })));
        const badge = document.createElement('span');
        badge.className = 'MemberMapPin-badge';
        badge.append(icon('fas fa-suitcase-rolling'));
        button.append(avatarEl(trip[6], trip[8]), badge);
        button.addEventListener('click', (e) => {
          e.stopPropagation();
          this.select({ kind: 'trip', trip }, [trip[2], trip[1]]);
        });
        this.layerMarkers.push(
          new engine.default.Marker({ element: button, anchor: 'center', offset: [16, -16] }).setLngLat([trip[2], trip[1]]).addTo(this.map)
        );
      }
    }

    if (this.filters.meetups) {
      for (const meetup of this.data.meetups) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'MemberMapMeetupPin';
        button.setAttribute('aria-label', extractText(t('pin.meetup', { title: meetup[6], when: meetupWhen(meetup) })));
        button.append(icon('fas fa-calendar-day'));
        button.addEventListener('click', (e) => {
          e.stopPropagation();
          this.select({ kind: 'meetup', meetup }, [meetup[2], meetup[1]]);
        });
        this.layerMarkers.push(this.marker(button, [meetup[2], meetup[1]], { anchor: 'bottom' }));
      }
    }
  }

  /** Opens as a globe, then eases down into where the community is. */
  intro() {
    const map = this.map;
    if (!map || !this.ready || this.loading || this.introDone) return;
    this.introDone = true;
    this.drawLayers();

    const rows = this.data.members;
    // A deep link (deepLink below) flies straight to its target instead.
    const params = new URLSearchParams(window.location.search);
    if (!rows.length || ['meetup', 'traveler', 'member'].some((key) => params.has(key))) return;
    // Where the members are on average, as directions from the earth's centre:
    // a bounding box's middle lands in the ocean for a community on two continents.
    let x = 0;
    let y = 0;
    let z = 0;
    for (const row of rows) {
      const lat = (row[1] * Math.PI) / 180;
      const lng = (row[2] * Math.PI) / 180;
      x += Math.cos(lat) * Math.cos(lng);
      y += Math.cos(lat) * Math.sin(lng);
      z += Math.sin(lat);
    }
    const center: [number, number] = [(Math.atan2(y, x) * 180) / Math.PI, (Math.atan2(z, Math.hypot(x, y)) * 180) / Math.PI];
    const spread = Math.hypot(x, y, z) / rows.length;

    // Close together: ease down to fit them. Spread round the world: stay a globe, turned to face them.
    const bounds = new engine.default.LngLatBounds();
    rows.forEach((row) => bounds.extend([row[2], row[1]]));
    // A globe a little narrower than the box. Its diameter is 512 * 2^zoom / (π cos lat) pixels:
    // MapLibre's globe zoom is the Mercator zoom at the centre's latitude.
    const box = map.getContainer();
    const width = Math.max(200, Math.min(box.clientWidth, box.clientHeight));
    const globeZoom = Math.log2((width * Math.PI * Math.cos((center[1] * Math.PI) / 180)) / 512);
    const land = (animate: boolean) =>
      spread > 0.9
        ? map.fitBounds(bounds, { padding: 60, maxZoom: 5, ...(animate ? { duration: 2800, essential: true } : { animate: false }) })
        : map.easeTo({ center, zoom: globeZoom, duration: animate ? 2800 : 0, essential: true });

    if (engine.reducedMotion()) {
      map.jumpTo({ center, zoom: globeZoom });
      land(false);
      return;
    }
    map.jumpTo({ center: [center[0] - 60, center[1] * 0.5], zoom: 0.6 });
    setTimeout(() => this.map && land(true), 400);
  }

  focusMember(id: number) {
    const row = this.byId.get(id);
    if (!row || !this.map) return;
    this.map.getContainer().scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    this.map.flyTo({ center: [row[2], row[1]], zoom: Math.max(this.map.getZoom(), 8), duration: engine.reducedMotion() ? 0 : 1200 });
    this.map.once('moveend', () => this.select({ kind: 'member', member: row }, [row[2], row[1]]));
  }

  /** ?meetup=<discussion id>, ?traveler=<user id>, ?member=<user id>: from a notification, a post or a profile. */
  deepLink() {
    if (!this.map || !this.ready || this.loading) return;
    const params = new URLSearchParams(window.location.search);
    const meetup = this.data.meetups.find((row) => String(row[7]) === params.get('meetup'));
    const trip = this.data.trips.find((row) => String(row[0]) === params.get('traveler'));
    const member = this.byId.get(Number(params.get('member')));
    const target: [[number, number], Selected] | null = meetup
      ? [[meetup[2], meetup[1]], { kind: 'meetup', meetup }]
      : trip
        ? [[trip[2], trip[1]], { kind: 'trip', trip }]
        : member
          ? [[member[2], member[1]], { kind: 'member', member }]
          : null;
    if (!target) return;

    this.introDone = true;
    this.map.flyTo({ center: target[0], zoom: 7, duration: engine.reducedMotion() ? 0 : 1500 });
    this.map.once('moveend', () => this.select(target[1], target[0]));
  }

  search() {
    const q = this.query().trim();
    if (q.length < 2 || this.searching) return;
    this.searching = true;
    call<{ places: SearchResult[] }>('GET', '/geocode', { q })
      .then((data) => (this.results = data.places))
      .catch(() => {})
      .finally(() => {
        this.searching = false;
        m.redraw();
      });
  }

  flyToResult(result: SearchResult) {
    this.results = null;
    if (!this.map) return;
    this.setMode('map');
    const duration = engine.reducedMotion() ? 0 : 1500;
    if (result.bbox) {
      const [south, north, west, east] = result.bbox;
      this.map.fitBounds(
        [
          [west, south],
          [east, north],
        ],
        { padding: 40, maxZoom: 9, duration }
      );
    } else {
      this.map.flyTo({ center: [result.lng, result.lat], zoom: 8, duration });
    }
  }

  loadFilter() {
    const group = this.filters.group;
    const following = this.filters.following;
    if (!group && !following) {
      this.filterIds = null;
      this.refreshMap();
      return;
    }

    const requests = [];
    if (group) requests.push(call<{ ids: number[] }>('GET', '/filter', { group }));
    if (following) requests.push(call<{ ids: number[] }>('GET', '/filter', { following: '1' }));
    Promise.all(requests).then((answers) => {
      const sets = answers.map((answer) => new Set(answer.ids));
      this.filterIds = new Set([...sets[0]].filter((id) => sets.every((set) => set.has(id))));
      this.refreshMap();
    });
  }
}
