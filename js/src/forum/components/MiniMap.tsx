import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import { t } from '../api';
import { isDark } from '../theme';

interface Tile {
  src: string;
  dx: number;
  dy: number;
}

/** The four tiles around a point at a zoom, and where the point falls on them (in pixels). */
function tilesAround(lat: number, lng: number, zoom: number): { tiles: Tile[]; offsetX: number; offsetY: number } {
  const n = 2 ** zoom;
  const px = ((lng + 180) / 360) * n * 256;
  const rad = (lat * Math.PI) / 180;
  const py = ((1 - Math.log(Math.tan(rad) + 1 / Math.cos(rad)) / Math.PI) / 2) * n * 256;
  const tx = Math.floor(px / 256 - 0.5);
  const ty = Math.floor(py / 256 - 0.5);
  const template = app.forum.attribute<string>('memberMapTiles');
  const tiles = [0, 1].flatMap((dy) =>
    [0, 1].map((dx) => {
      const x = (((tx + dx) % n) + n) % n;
      const y = Math.min(Math.max(ty + dy, 0), n - 1);
      return {
        src: template.replace('{z}', String(zoom)).replace('{x}', String(x)).replace('{y}', String(y)).replace('{s}', 'a').replace('{r}', ''),
        dx,
        dy,
      };
    })
  );

  return { tiles, offsetX: px - tx * 256, offsetY: py - ty * 256 };
}

/**
 * A static map of the member's place: four tiles around the rounded centre,
 * no engine. 🚨 The images send the forum's origin as Referer: Flarum's
 * same-origin policy sends none, and OpenStreetMap blocks tiles without one.
 */
export default class MiniMap extends Component<{ point: [number, number, string]; label: string; userId: string }> {
  view() {
    const attrs = this.attrs;
    const [lat, lng, precision] = attrs.point;
    const zoom = precision === 'city' ? 8 : precision === 'region' ? 5 : 3;
    const { tiles, offsetX, offsetY } = tilesAround(lat, lng, zoom);

    return (
      <Link
        href={app.route('member-map') + '?member=' + attrs.userId}
        className={'MemberMapMini' + (isDark() ? ' is-dark' : '')}
        aria-label={extractText(t('profile.mini_label', { place: attrs.label }))}
      >
        <span className="MemberMapMini-tiles" style={{ marginLeft: `${-offsetX}px`, marginTop: `${-offsetY}px` }}>
          {tiles.map((tile) => (
            <img
              src={tile.src}
              alt=""
              loading="lazy"
              referrerpolicy="strict-origin-when-cross-origin"
              decoding="async"
              width="256"
              height="256"
              style={{ left: `${tile.dx * 256}px`, top: `${tile.dy * 256}px` }}
            />
          ))}
        </span>
        <span className="MemberMapMini-dot" />
        <span className="MemberMapMini-label">
          <Icon name="fas fa-location-dot" /> {attrs.label}
        </span>
      </Link>
    );
  }
}
