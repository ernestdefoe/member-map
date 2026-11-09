import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

export type Member = [id: number, lat: number, lng: number, name: string, slug: string, avatar: string | null];

export interface Own {
  lat: number;
  lng: number;
  label: string;
  precision: 'city' | 'region' | 'country';
  profile: boolean;
  listed: boolean;
}

/** The sentence for an error answer from our endpoints. */
export function errorText(error: any): string {
  const code = error?.response?.errors?.[0]?.code;
  const key = `ernestdefoe-member-map.forum.errors.${code}`;
  return code && app.translator.trans(key) !== key
    ? extractText(app.translator.trans(key))
    : extractText(app.translator.trans('ernestdefoe-member-map.forum.page.load_error'));
}

export function call<T>(method: 'GET' | 'POST' | 'DELETE', path: string, data?: Record<string, any>): Promise<T> {
  const url = app.forum.attribute<string>('apiUrl') + '/member-map' + path;
  return app.request<T>({
    method,
    url: method === 'GET' && data ? url + '?' + new URLSearchParams(data).toString() : url,
    body: method === 'GET' ? undefined : data,
    errorHandler: (error: any) => {
      app.alerts.show({ type: 'error' }, errorText(error));
      throw error;
    },
  });
}

/** After saving or removing: the session user's own copy, so profile, card and settings update without a reload. */
export function remember(me: Own | null): void {
  const user = app.session.user;
  if (!user) return;
  user.pushAttributes({
    memberMapOwn: me,
    memberMapLocation: me && me.profile && app.forum.attribute('memberMapProfile') ? me.label : null,
  });
}

export function openLocationForm(attrs: { onsave?: (me: Own | null) => void } = {}): void {
  app.modal.show(() => import('./components/LocationModal'), attrs);
}

export function removeLocation(): Promise<void> {
  return call<void>('DELETE', '/location').then(() => {
    remember(null);
    app.alerts.show({ type: 'success' }, app.translator.trans('ernestdefoe-member-map.forum.location.removed'));
  });
}
