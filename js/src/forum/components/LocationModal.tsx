import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import { browserZone, call, remember, t, type Place } from '../api';
import PlaceSearch from './PlaceSearch';
import PinMap from './PinMap';

/**
 * Where a member is on the map: search for a place, or drop a pin; choose how
 * exactly to be shown and who sees it; see the label before saving.
 *
 * 🚨 The pin and the search hit are only ever sent to our server, which saves
 * the centre of the place around them (see Geocoder::place), not the point.
 */

declare const m: Mithril.Static;

type Precision = Place['precision'];

interface LocationModalAttrs extends IFormModalAttrs {
  onsave?: (place: Place | null) => void;
}

const RANK: Record<Precision, number> = { city: 0, region: 1, country: 2 };
const tl = (key: string, params: Record<string, any> = {}) => t(`location.${key}`, params);

export default class LocationModal extends FormModal<LocationModalAttrs> {
  own: Place | null = null;
  point: { lat: number; lng: number } | null = null;
  precision: Precision = 'city';
  profile = Stream(true);
  membersOnly = Stream(false);

  preview: string | null = null;
  previewing = false;
  private previewFor = '';
  pinOpen = false;

  oninit(vnode: Mithril.Vnode<LocationModalAttrs, this>) {
    super.oninit(vnode);
    this.own = app.session.user?.attribute<Place | null>('memberMapOwn') || null;
    if (this.own) {
      this.precision = this.own.precision;
      this.profile(this.own.profile);
      this.membersOnly(!!this.own.membersOnly);
    }
  }

  className() {
    return 'MemberMapLocationModal Modal--small';
  }

  title() {
    return tl('title');
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Form">
          {this.own && <p className="helpText">{t('settings.shown_as', { label: this.own.label })}</p>}

          <PlaceSearch id="member-map-search" label={tl('search_label')} onpick={(place) => this.choose(place.lat, place.lng)} />

          <div className="Form-group">
            <Button
              className="Button Button--link MemberMapLocationModal-pinToggle"
              type="button"
              icon="fas fa-map-pin"
              onclick={() => (this.pinOpen = !this.pinOpen)}
            >
              {tl('or_pin')}
            </Button>
            {this.pinOpen && (
              <div className="MemberMapLocationModal-pin">
                <p className="helpText">{tl('pin_help')}</p>
                <PinMap point={this.point} start={this.own} onpick={(lat: number, lng: number) => this.choose(lat, lng)} />
              </div>
            )}
          </div>

          <fieldset className="Form-group MemberMapLocationModal-precision">
            <legend>{tl('precision_label')}</legend>
            {(['city', 'region', 'country'] as Precision[]).map((precision) => (
              <label className="checkbox">
                <input
                  type="radio"
                  name="member-map-precision"
                  value={precision}
                  checked={this.precision === precision}
                  onchange={() => this.setPrecision(precision)}
                />
                {tl(`precision.${precision}`)}
              </label>
            ))}
          </fieldset>

          {(this.previewing || this.preview) && (
            <p className="MemberMapLocationModal-preview" aria-live="polite">
              {this.previewing ? (
                <>
                  <LoadingIndicator display="inline" size="small" /> {tl('preview_loading')}
                </>
              ) : (
                tl('preview', { label: <strong>{this.preview}</strong> })
              )}
            </p>
          )}

          <fieldset className="Form-group MemberMapLocationModal-who">
            <legend>{tl('who_label')}</legend>
            {app.forum.attribute('memberMapProfile') && (
              <label className="checkbox">
                <input type="checkbox" checked={this.profile()} onchange={(e: Event) => this.profile((e.target as HTMLInputElement).checked)} />
                {tl('profile')}
              </label>
            )}
            <label className="checkbox">
              <input type="checkbox" checked={this.membersOnly()} onchange={(e: Event) => this.membersOnly((e.target as HTMLInputElement).checked)} />
              {tl('members_only')}
            </label>
          </fieldset>

          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading} disabled={!this.point && !this.own}>
              {tl('save')}
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

  choose(lat: number, lng: number) {
    this.point = { lat, lng };
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

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    const point = this.basis();
    if (!point && (!this.own || this.own.precision !== this.precision)) {
      app.alerts.show({ type: 'error' }, tl(this.own ? 'finer' : 'pick_first'));
      return;
    }

    this.loading = true;
    const body: Record<string, any> = { profile: this.profile(), membersOnly: this.membersOnly() };
    // Same place, same precision: only who sees it changed, so nothing is looked up again.
    if (point && (this.point || this.own?.precision !== this.precision))
      Object.assign(body, point, { precision: this.precision, timezone: browserZone() });

    call<{ place: Place }>('POST', '/location', body)
      .then(({ place }) => {
        remember(place);
        this.attrs.onsave?.(place);
        app.alerts.show({ type: 'success' }, tl('saved'));
        this.hide();
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
