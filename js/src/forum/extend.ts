import Extend from 'flarum/common/extenders';
import TripNearbyNotification from './components/TripNearbyNotification';
import MemberMapLocation from './models/MemberMapLocation';
import MemberMapMeetup from './models/MemberMapMeetup';
import MemberMapStats from './models/MemberMapStats';
import MemberMapTrip from './models/MemberMapTrip';

export default [
  // The page is its own chunk; the core parts it draws in are loaded through the registry first.
  new Extend.Routes().add('member-map', '/map', () => import('./components/MapPage').then(async (page) => (await page.loadCore(), page))),

  new Extend.Notification().add('memberMapTripNearby', TripNearbyNotification),

  new Extend.Store()
    .add('member-map-locations', MemberMapLocation)
    .add('member-map-trips', MemberMapTrip)
    .add('member-map-meetups', MemberMapMeetup)
    .add('member-map-stats', MemberMapStats),
];
