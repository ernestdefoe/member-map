import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import type Mithril from 'mithril';
import { browserZone, call, t, type SearchResult } from '../api';
import PlaceSearch from './PlaceSearch';

/**
 * Plan a meetup: a title, a place, a time (local to the place) and a few
 * words. It starts a discussion to talk it through, shows as a pin, and goes
 * on the calendar when the Calendar extension is installed.
 */

declare const m: Mithril.Static;

const tm = (key: string, params: Record<string, any> = {}) => t(`meetup.${key}`, params);

export default class MeetupModal extends FormModal<IFormModalAttrs & { onsave?: () => void }> {
  name = Stream('');
  place: SearchResult | null = null;
  when = Stream('');
  details = Stream('');

  className() {
    return 'MemberMapMeetupModal Modal--small';
  }

  title() {
    return tm('title');
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label for="member-map-meetup-title">{tm('name')}</label>
            <input
              id="member-map-meetup-title"
              className="FormControl"
              maxLength={80}
              bidi={this.name}
              placeholder={tm('name_placeholder')}
              required
            />
          </div>
          <PlaceSearch id="member-map-meetup-place" label={tm('where')} onpick={(place) => (this.place = place)} />
          <div className="Form-group">
            <label for="member-map-meetup-when">{tm('when')}</label>
            <input id="member-map-meetup-when" className="FormControl" type="datetime-local" bidi={this.when} required />
            <p className="helpText">{tm('when_help')}</p>
          </div>
          <div className="Form-group">
            <label for="member-map-meetup-details">{tm('details')}</label>
            <textarea
              id="member-map-meetup-details"
              className="FormControl"
              rows={4}
              maxLength={2000}
              bidi={this.details}
              placeholder={tm('details_placeholder')}
            />
          </div>
          <div className="Form-group">
            <Button
              className="Button Button--primary Button--block"
              type="submit"
              loading={this.loading}
              disabled={!this.place || this.name().trim().length < 3 || !this.when()}
            >
              {tm('save')}
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
    call<{ meetup: any[] }>('POST', '/meetups', {
      title: this.name().trim(),
      details: this.details().trim(),
      lat: this.place.lat,
      lng: this.place.lng,
      startsAt: this.when().slice(0, 16),
      timezone: browserZone(),
    })
      .then(({ meetup }) => {
        app.alerts.show({ type: 'success' }, tm('saved'));
        this.attrs.onsave?.();
        this.hide();
        m.route.set(app.route('discussion', { id: `${meetup[7]}-${meetup[8]}` }));
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
