import Model from 'flarum/common/Model';

/** `member-map-stats`: the "Where we are" widget's counts. */
export default class MemberMapStats extends Model {
  members = Model.attribute<number>('members');
  countries = Model.attribute<[string, number][]>('countries');
}
