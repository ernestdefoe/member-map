import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';
import { dateRange, t } from '../api';

/** "Dana is visiting near you", opening the map on their trip. */
export default class TripNearbyNotification extends Notification {
  icon() {
    return 'fas fa-suitcase-rolling';
  }

  href() {
    const notification: any = this.attrs.notification;
    return app.route('member-map') + '?traveler=' + notification.fromUser()?.id();
  }

  content(): Mithril.Children {
    const notification: any = this.attrs.notification;
    return t('notification.trip', { username: notification.fromUser()?.displayName() });
  }

  excerpt(): Mithril.Children {
    const data: any = (this.attrs.notification as any).content() || {};
    return data.label ? `${data.label} · ${dateRange(data.starts, data.ends)}` : null;
  }
}
