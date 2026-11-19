import Extend from 'flarum/common/extenders';
import TripNearbyNotification from './components/TripNearbyNotification';

export default [
  // The page is its own chunk; the core parts it draws in are loaded through the registry first.
  new Extend.Routes().add('member-map', '/map', () => import('./components/MapPage').then(async (page) => (await page.loadCore(), page))),

  new Extend.Notification().add('memberMapTripNearby', TripNearbyNotification),
];
