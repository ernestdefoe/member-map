import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

/**
 * A small map to tap where you are. The pin is only ever sent to the server,
 * which saves the place around it, never the pin. The engine is its own
 * chunk, fetched when this first opens.
 */
export default class PinMap extends Component<{
  point: { lat: number; lng: number } | null;
  start?: { lat: number; lng: number } | null;
  onpick: (lat: number, lng: number) => void;
}> {
  engine: any = null;
  handle: any = null;
  pin: any = null;
  private shown = '';

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    import('../map/engine').then(async (module) => {
      await module.prepare();
      this.engine = module;
      m.redraw();
    });
  }

  /** A place picked from the search moves the pin here too. */
  onupdate() {
    const point = this.attrs.point;
    if (!point || !this.handle || `${point.lat},${point.lng}` === this.shown) return;
    this.place(point.lat, point.lng, true);
  }

  onremove() {
    this.handle?.destroy();
  }

  view() {
    if (!this.engine) return <LoadingIndicator />;
    return <div className="MemberMap MemberMapPinMap" oncreate={(vnode: Mithril.VnodeDOM) => this.create(vnode.dom as HTMLElement)} />;
  }

  create(element: HTMLElement) {
    if (!this.engine.supported()) return;
    const start = this.attrs.point ?? this.attrs.start;
    this.handle = this.engine.createMap(element, { center: start ? [start.lng, start.lat] : [0, 20], zoom: start ? 5 : 0.8 });
    const map = this.handle.map;
    map.getCanvas().style.cursor = 'crosshair';
    if (this.attrs.point) map.once('load', () => this.place(this.attrs.point!.lat, this.attrs.point!.lng, false));
    map.on('click', (e: any) => {
      const lngLat = e.lngLat.wrap();
      this.place(lngLat.lat, lngLat.lng, false);
      this.attrs.onpick(lngLat.lat, lngLat.lng);
      this.shown = `${lngLat.lat},${lngLat.lng}`;
      m.redraw();
    });
  }

  place(lat: number, lng: number, pan: boolean) {
    this.shown = `${lat},${lng}`;
    const maplibre = this.engine.default;
    if (!this.pin) {
      const element = document.createElement('span');
      element.className = 'MemberMapPinMap-marker';
      this.pin = new maplibre.Marker({ element }).setLngLat([lng, lat]).addTo(this.handle.map);
    } else {
      this.pin.setLngLat([lng, lat]);
    }
    if (pan) this.handle.map.easeTo({ center: [lng, lat], zoom: Math.max(this.handle.map.getZoom(), 5) });
  }
}
