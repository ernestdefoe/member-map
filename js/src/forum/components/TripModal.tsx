import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import { call, t, type SearchResult } from '../api';
import PlaceSearch from './PlaceSearch';

/**
 * "I'm visiting Chicago Dec 3-7": a travel pin that ends by itself. Members
 * who live nearby get a notification, so they can meet up. The server saves
 * the city's centre, like a home place.
 */

declare const m: Mithril.Static;

const tt = (key: string, params: Record<string, any> = {}) => t(`trip.${key}`, params);

function iso(date: Date): string {
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

export default class TripModal extends FormModal<IFormModalAttrs & { onsave?: () => void }> {
  place: SearchResult | null = null;
  starts = Stream(iso(new Date()));
  ends = Stream(iso(new Date(Date.now() + 3 * 86400000)));

  className() {
    return 'MemberMapTripModal Modal--small';
  }

  title() {
    return tt('title');
  }

  content() {
    const today = iso(new Date());
    return (
      <div className="Modal-body">
        <div className="Form">
          <p className="helpText">{tt('help')}</p>
          <PlaceSearch id="member-map-trip-place" label={tt('where')} onpick={(place) => (this.place = place)} />
          <div className="Form-group MemberMapDates">
            <label>
              {tt('from')}
              <input className="FormControl" type="date" min={today} bidi={this.starts} required />
            </label>
            <label>
              {tt('to')}
              <input className="FormControl" type="date" min={this.starts() || today} bidi={this.ends} required />
            </label>
          </div>
          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading} disabled={!this.place}>
              {tt('save')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    if (!this.place) return;
    this.loading = true;
    call('POST', '/trips', { lat: this.place.lat, lng: this.place.lng, starts: this.starts(), ends: this.ends() })
      .then(() => {
        app.alerts.show({ type: 'success' }, tt('saved'));
        this.attrs.onsave?.();
        this.hide();
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
