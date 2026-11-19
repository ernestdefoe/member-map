import Model from 'flarum/common/Model';
import type { Place } from '../api';

/** `member-map-locations`: the viewer's own place, keyed by their user id. */
export default class MemberMapLocation extends Model {
  lat = Model.attribute<number>('lat');
  lng = Model.attribute<number>('lng');
  label = Model.attribute<string>('label');
  precision = Model.attribute<Place['precision']>('precision');
  profile = Model.attribute<boolean>('profile');
  membersOnly = Model.attribute<boolean>('membersOnly');
  listed = Model.attribute<boolean>('listed');

  place(): Place {
    return {
      lat: this.lat(),
      lng: this.lng(),
      label: this.label(),
      precision: this.precision(),
      profile: this.profile(),
      membersOnly: this.membersOnly(),
      listed: this.listed(),
    };
  }
}
