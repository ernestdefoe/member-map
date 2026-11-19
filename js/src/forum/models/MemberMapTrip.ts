import Model from 'flarum/common/Model';

/** `member-map-trips`: "visiting Chicago, Dec 3–7". */
export default class MemberMapTrip extends Model {
  label = Model.attribute<string>('label');
  starts = Model.attribute<string>('starts');
  ends = Model.attribute<string>('ends');
}
