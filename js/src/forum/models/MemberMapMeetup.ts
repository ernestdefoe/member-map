import Model from 'flarum/common/Model';

/** `member-map-meetups`: a place, a local time and a discussion. */
export default class MemberMapMeetup extends Model {
  title = Model.attribute<string>('title');
  label = Model.attribute<string>('label');
  discussionId = Model.attribute<number>('discussionId');
  discussionSlug = Model.attribute<string>('discussionSlug');
}
