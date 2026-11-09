import app from 'flarum/forum/app';
import L, { previous } from './global';
import 'leaflet.markercluster';
import css from './leafletCss';

(window as any).L = previous;

let injected = false;

/** Leaflet's own CSS, added once, when a map first opens. */
export function ensureCss(): void {
  if (injected) return;
  injected = true;
  const style = document.createElement('style');
  style.id = 'member-map-leaflet-css';
  style.textContent = css;
  document.head.prepend(style);
}

/** Whether the page is dark right now, read from what is drawn, so themes with their own dark schemes count too. */
export function isDark(): boolean {
  const rgb = getComputedStyle(document.body).backgroundColor.match(/\d+(\.\d+)?/g);
  if (!rgb) return false;
  const [r, g, b] = rgb.map(Number);
  return (0.299 * r + 0.587 * g + 0.114 * b) / 255 < 0.5;
}

/**
 * The tile layer, following the forum's light and dark mode as it changes:
 * the admin's dark tiles if set, otherwise the light tiles darkened by CSS
 * (`.MemberMap--dark`). Returns a function that stops following.
 */
export function addTiles(map: any): () => void {
  const light = app.forum.attribute<string>('memberMapTiles');
  const dark = app.forum.attribute<string | null>('memberMapTilesDark');
  const container = map.getContainer();

  const layer = L.tileLayer(light, {
    maxZoom: 19,
    attribution: app.forum.attribute<string>('memberMapAttribution'),
  }).addTo(map);

  const apply = () => {
    const isDarkNow = isDark();
    if (dark) layer.setUrl(isDarkNow ? dark : light, false);
    container.classList.toggle('MemberMap--dark', isDarkNow && !dark);
  };
  apply();

  const observer = new MutationObserver(apply);
  observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class', 'style'] });
  observer.observe(document.body, { attributes: true, attributeFilter: ['class', 'style'] });
  const media = window.matchMedia('(prefers-color-scheme: dark)');
  media.addEventListener('change', apply);

  return () => {
    observer.disconnect();
    media.removeEventListener('change', apply);
  };
}

export default L;
