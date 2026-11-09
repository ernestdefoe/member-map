import L from 'leaflet';

/*
 * leaflet.markercluster extends a GLOBAL `L` while it loads. Lend it ours for
 * that moment only (leaflet.ts puts back whatever was there), so another
 * extension's Leaflet is never replaced.
 */
const w = window as any;
export const previous = w.L;
w.L = L;

export default L;
