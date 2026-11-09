import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Stream from 'flarum/common/utils/Stream';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, remember, type Own } from '../api';

/**
 * Where a member is on the map: search for a place, or drop a pin; choose how
 * exactly to be shown; see the label before saving.
 *
 * 🚨 Searches run only when asked (the Search button or Enter), never as
 * you type: Nominatim's usage policy forbids autocomplete. The pin and the
 * search hit are only ever sent to our server, which saves the centre of the
 * place around them (see Geocoder::place), not the point itself.
 */

declare const m: Mithril.Static;

type Precision = Own['precision'];

interface Place {
  label: string;
  lat: number;
  lng: number;
}

interface LocationModalAttrs extends IFormModalAttrs {
  onsave?: (me: Own | null) => void;
}

const RANK: Record<Precision, number> = { city: 0, region: 1, country: 2 };

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-member-map.forum.location.${key}`, params);

export default class LocationModal extends FormModal<LocationModalAttrs> {
  own: Own | null = null;
  query = Stream('');
  searching = false;
  results: Place[] | null = null;

  point: { lat: number; lng: number } | null = null;
  pointName = '';
  precision: Precision = 'city';
  profile = Stream(true);

  preview: string | null = null;
  previewing = false;
  private previewFor = '';

  pinOpen = false;
  map: any = null;
  pin: any = null;
  leaflet: any = null;
  stopTiles: (() => void) | null = null;

  oninit(vnode: Mithril.Vnode<LocationModalAttrs, this>) {
    super.oninit(vnode);
    this.own = app.session.user?.attribute<Own | null>('memberMapOwn') || null;
    if (this.own) {
      this.precision = this.own.precision;
      this.profile(this.own.profile);
    }
  }

  onremove(vnode: Mithril.VnodeDOM<LocationModalAttrs, this>) {
    super.onremove(vnode);
    this.stopTiles?.();
    this.map?.remove();
  }

  className() {
    return 'MemberMapLocationModal Modal--small';
  }

  title() {
    return t('title');
  }

  content() {
    const showProfile = app.forum.attribute('memberMapProfile');

    return (
      <div className="Modal-body">
        <div className="Form">
          {this.own && (
            <p className="helpText">{app.translator.trans('ernestdefoe-member-map.forum.settings.shown_as', { label: this.own.label })}</p>
          )}

          <div className="Form-group">
            <label for="member-map-search">{t('search_label')}</label>
            <div className="MemberMapLocationModal-search">
              <input
                id="member-map-search"
                className="FormControl"
                type="search"
                autocomplete="off"
                maxLength={120}
                placeholder={extractText(t('search_placeholder'))}
                bidi={this.query}
                onkeydown={(e: KeyboardEvent) => {
                  // Enter searches; it must not submit (save) the form.
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    this.search();
                  }
                }}
              />
              <Button
                className="Button"
                type="button"
                loading={this.searching}
                disabled={this.query().trim().length < 2}
                onclick={() => this.search()}
              >
                {t('search')}
              </Button>
            </div>
            {this.results && (
              <ul className="MemberMapLocationModal-results">
                {this.results.length ? (
                  this.results.map((place) => (
                    <li>
                      <button
                        type="button"
                        className={'MemberMapLocationModal-result' + (this.pointName === place.label ? ' active' : '')}
                        onclick={() => this.choose(place.lat, place.lng, place.label)}
                      >
                        {place.label}
                      </button>
                    </li>
                  ))
                ) : (
                  <li className="helpText">{t('no_results')}</li>
                )}
              </ul>
            )}
          </div>

          <div className="Form-group">
            <Button
              className="Button Button--link MemberMapLocationModal-pinToggle"
              type="button"
              icon="fas fa-map-pin"
              onclick={() => this.togglePin()}
            >
              {t('or_pin')}
            </Button>
            {this.pinOpen && this.leaflet && (
              <div className="MemberMapLocationModal-pin">
                <p className="helpText">{t('pin_help')}</p>
                <div
                  className="MemberMap MemberMapLocationModal-map"
                  oncreate={(vnode: Mithril.VnodeDOM) => this.createMap(vnode.dom as HTMLElement)}
                />
              </div>
            )}
          </div>

          <fieldset className="Form-group MemberMapLocationModal-precision">
            <legend>{t('precision_label')}</legend>
            {(['city', 'region', 'country'] as Precision[]).map((precision) => (
              <label className="checkbox">
                <input
                  type="radio"
                  name="member-map-precision"
                  value={precision}
                  checked={this.precision === precision}
                  onchange={() => this.setPrecision(precision)}
                />
                {t(`precision.${precision}`)}
              </label>
            ))}
          </fieldset>

          {(this.previewing || this.preview) && (
            <p className="MemberMapLocationModal-preview" aria-live="polite">
              {this.previewing ? (
                <>
                  <LoadingIndicator display="inline" size="small" /> {t('preview_loading')}
                </>
              ) : (
                this.preview && t('preview', { label: <strong>{this.preview}</strong> })
              )}
            </p>
          )}

          {showProfile && (
            <div className="Form-group">
              <label className="checkbox">
                <input type="checkbox" checked={this.profile()} onchange={(e: Event) => this.profile((e.target as HTMLInputElement).checked)} />
                {t('profile')}
              </label>
            </div>
          )}

          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading} disabled={!this.point && !this.own}>
              {t('save')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  /**
   * The point to look up: the one just chosen, or else the saved place's
   * centre — but only to be shown LESS exactly: a country's centre says
   * nothing about which city.
   */
  basis(): { lat: number; lng: number } | null {
    if (this.point) return this.point;
    if (this.own && RANK[this.precision] >= RANK[this.own.precision]) return { lat: this.own.lat, lng: this.own.lng };
    return null;
  }

  search() {
    const q = this.query().trim();
    if (q.length < 2 || this.searching) return;
    this.searching = true;
    call<{ places: Place[] }>('GET', '/geocode', { q })
      .then((data) => (this.results = data.places))
      .catch(() => {})
      .finally(() => {
        this.searching = false;
        m.redraw();
      });
  }

  choose(lat: number, lng: number, name = '') {
    this.point = { lat, lng };
    this.pointName = name;
    if (this.map) this.placePin(lat, lng, true);
    this.lookup();
  }

  setPrecision(precision: Precision) {
    this.precision = precision;
    this.lookup();
  }

  /** What the saved label would be: the server does the same lookup on save, so the answer is cached. */
  lookup() {
    const point = this.basis();
    if (!point || (!this.point && this.own?.precision === this.precision)) {
      this.preview = null;
      this.previewFor = '';
      this.previewing = false;
      return;
    }
    const key = `${point.lat},${point.lng},${this.precision}`;
    this.previewFor = key;
    this.previewing = true;
    this.preview = null;
    call<{ label: string }>('GET', '/geocode', { lat: String(point.lat), lng: String(point.lng), precision: this.precision })
      .then((data) => {
        if (this.previewFor === key) this.preview = data.label;
      })
      .catch(() => {})
      .finally(() => {
        if (this.previewFor === key) this.previewing = false;
        m.redraw();
      });
  }

  /** Leaflet is its own chunk: fetched the first time the pin map opens. */
  togglePin() {
    this.pinOpen = !this.pinOpen;
    if (!this.pinOpen) {
      this.stopTiles?.();
      this.map?.remove();
      this.map = this.pin = null;
      return;
    }
    if (!this.leaflet) {
      import('../map/leaflet').then((module) => {
        this.leaflet = module;
        m.redraw();
      });
    }
  }

  createMap(element: HTMLElement) {
    const L = this.leaflet.default;
    this.leaflet.ensureCss();
    const start = this.point ?? this.own;
    this.map = L.map(element, {
      center: start ? [start.lat, start.lng] : [20, 0],
      zoom: start ? 5 : 1,
      minZoom: 1,
      maxZoom: 12,
      worldCopyJump: true,
    });
    this.stopTiles = this.leaflet.addTiles(this.map);
    if (this.point) this.placePin(this.point.lat, this.point.lng, false);
    this.map.on('click', (e: any) => {
      this.choose(e.latlng.lat, L.Util.wrapNum(e.latlng.lng, [-180, 180], true));
      m.redraw();
    });
  }

  placePin(lat: number, lng: number, pan: boolean) {
    if (!this.map) return;
    const L = this.leaflet.default;
    const icon = L.divIcon({ className: 'MemberMapLocationModal-marker', iconSize: L.point(18, 18) });
    if (this.pin) this.pin.setLatLng([lat, lng]);
    else this.pin = L.marker([lat, lng], { icon, keyboard: false, interactive: false }).addTo(this.map);
    if (pan) this.map.setView([lat, lng], Math.max(this.map.getZoom(), 5));
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    const point = this.basis();
    if (!point && (!this.own || this.own.precision !== this.precision)) {
      app.alerts.show({ type: 'error' }, t(this.own ? 'finer' : 'pick_first'));
      return;
    }

    this.loading = true;
    const body: Record<string, any> = { profile: this.profile() };
    // Same place, same precision: only the profile switch changed, so nothing is looked up again.
    if (point && (this.point || this.own?.precision !== this.precision)) Object.assign(body, point, { precision: this.precision });

    call<{ me: Own }>('POST', '/location', body)
      .then(({ me }) => {
        remember(me);
        this.attrs.onsave?.(me);
        app.alerts.show({ type: 'success' }, t('saved'));
        this.hide();
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
