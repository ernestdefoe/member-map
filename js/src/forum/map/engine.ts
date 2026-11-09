import app from 'flarum/forum/app';
import * as maplibregl from 'maplibre-gl';
import css from './mapCss';
import { isDark } from '../theme';

/*
 * The map engine: MapLibre GL with OpenStreetMap-based vector tiles
 * (OpenFreeMap by default, no key), on a globe. This module is its own chunk
 * (forum/map/engine), imported dynamically by the map page and the location
 * forms: no other page downloads it.
 *
 * If the vector style can't load, the map falls back to the raster tiles the
 * admin set (OpenStreetMap's by default), drawn by the same engine.
 */

let injected = false;
let worker: Promise<void> | null = null;

/**
 * MapLibre's worker, started from a same-origin blob of the published file:
 * a worker can't be loaded straight from another origin, and forums often
 * serve assets from a CDN. Call (and wait for) this before the first map.
 */
export function prepare(): Promise<void> {
  worker ||= fetch(app.forum.attribute<string>('assetsBaseUrl') + '/js/ernestdefoe-member-map/forum/map/maplibre-worker.js')
    .then((response) => {
      if (!response.ok) throw new Error('Map worker ' + response.status);
      return response.text();
    })
    .then((code) => maplibregl.setWorkerUrl(URL.createObjectURL(new Blob([code], { type: 'text/javascript' }))));
  return worker;
}

function ensureCss(): void {
  if (injected) return;
  injected = true;
  const style = document.createElement('style');
  style.id = 'member-map-maplibre-css';
  style.textContent = css;
  document.head.prepend(style);
}

/** Whether this browser can draw the map at all. Without WebGL the page shows its list instead. */
export function supported(): boolean {
  try {
    const canvas = document.createElement('canvas');
    return !!(canvas.getContext('webgl2') || canvas.getContext('webgl'));
  } catch {
    return false;
  }
}

function rasterStyle(): maplibregl.StyleSpecification {
  const url = app.forum.attribute<string>('memberMapTiles');
  return {
    version: 8,
    sources: {
      raster: { type: 'raster', tiles: [url], tileSize: 256, maxzoom: 19, attribution: app.forum.attribute<string>('memberMapAttribution') },
    },
    layers: [{ id: 'raster', type: 'raster', source: 'raster' }],
  };
}

function styleUrl(dark: boolean): string | null {
  const light = app.forum.attribute<string | null>('memberMapStyle');
  return (dark && app.forum.attribute<string | null>('memberMapStyleDark')) || light;
}

/** Place names in the forum's language where the map has them, else in Latin letters, else as written locally. */
function localizeLabels(map: maplibregl.Map): void {
  const locale = (app.translator.getLocale() || 'en').split('-')[0];
  const name = ['coalesce', ['get', `name:${locale}`], ['get', 'name:latin'], ['get', 'name']];
  for (const layer of map.getStyle().layers || []) {
    if (layer.type !== 'symbol') continue;
    const field = JSON.stringify(map.getLayoutProperty(layer.id, 'text-field') ?? '');
    if (field.includes('name')) map.setLayoutProperty(layer.id, 'text-field', name as any);
  }
}

export interface MapOptions {
  center?: [number, number];
  zoom?: number;
  globe?: boolean;
  /** Called after every style load (first load and each light/dark switch): add sources and layers here. */
  onStyle?: (map: maplibregl.Map) => void;
}

export interface MapHandle {
  map: maplibregl.Map;
  destroy: () => void;
}

export function createMap(container: HTMLElement, options: MapOptions = {}): MapHandle {
  ensureCss();

  let dark = isDark();
  let raster = !styleUrl(dark);

  const map = new maplibregl.Map({
    container,
    style: raster ? rasterStyle() : (styleUrl(dark) as string),
    center: options.center ?? [0, 20],
    zoom: options.zoom ?? 1.4,
    minZoom: 0.5,
    // Places are city centres: closer than this shows nothing more.
    maxZoom: 13,
    dragRotate: false,
    pitchWithRotate: false,
    touchPitch: false,
    attributionControl: { compact: true },
    // 🚨 Flarum sends `Referrer-Policy: same-origin`, so tile requests would carry no Referer, and
    // OpenStreetMap answers those with an "Access blocked" picture. Send the forum's origin, nothing more.
    transformRequest: (url: string) => ({ url, referrerPolicy: 'strict-origin-when-cross-origin' as ReferrerPolicy }),
    cooperativeGestures: false,
  });
  map.touchZoomRotate.disableRotation();
  map.keyboard.disableRotation();
  map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');

  container.classList.toggle('MemberMap--rasterDark', raster && dark);

  // MapLibre opens its compact credit expanded; on a phone that covers the map's foot, links and all.
  map.once('load', () => container.querySelector('.maplibregl-ctrl-attrib')?.classList.remove('maplibregl-compact-show'));

  map.on('style.load', () => {
    if (options.globe !== false) map.setProjection({ type: 'globe' });
    localizeLabels(map);
    options.onStyle?.(map);
  });

  // The vector style or its tiles are unreachable (anything failing before the
  // first full load, or repeated tile failures after): draw the raster tiles instead.
  let loaded = false;
  let failures = 0;
  map.once('load', () => (loaded = true));
  map.on('error', () => {
    if (raster || (loaded && ++failures < 4)) return;
    raster = true;
    container.classList.toggle('MemberMap--rasterDark', dark);
    map.setStyle(rasterStyle());
  });

  // Follow the forum's light and dark mode as it changes.
  const follow = () => {
    const now = isDark();
    if (now === dark) return;
    dark = now;
    container.classList.toggle('MemberMap--rasterDark', raster && dark);
    if (!raster && styleUrl(dark) !== styleUrl(!dark)) map.setStyle(styleUrl(dark) as string);
  };
  const observer = new MutationObserver(follow);
  observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class', 'style'] });
  const media = window.matchMedia('(prefers-color-scheme: dark)');
  media.addEventListener('change', follow);

  return {
    map,
    destroy: () => {
      observer.disconnect();
      media.removeEventListener('change', follow);
      map.remove();
    },
  };
}

export function reducedMotion(): boolean {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export default maplibregl;
