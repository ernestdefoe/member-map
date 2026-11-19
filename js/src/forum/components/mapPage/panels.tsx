import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { countryName, dateRange, deleteRecord, flag, localTime, ONLINE, shown, t, type Member } from '../../api';
import { CONTINENTS, continentOf } from '../../continents';
import { distanceKm, roughDistance } from '../../units';
import { Avatar, meetupWhen, NEAR_MAX, profileUrl } from './shared';
import type MapPage from '../MapPage';

/** "92 members · 28 countries · 3 continents to go · 4 online now" */
export function stats(page: MapPage): Mithril.Children {
  const members = page.data.members;
  if (!members.length) return null;
  const countries = new Set(members.map((row) => row[6]).filter(Boolean) as string[]);
  const continents = new Set([...countries].map((cc) => continentOf(cc)).filter(Boolean));
  const left = CONTINENTS - continents.size;
  const online = page.online().length;

  return (
    <p className="MemberMapStats">
      {t('stats.members', { count: members.length })}
      {countries.size > 0 && [' · ', t('stats.countries', { count: countries.size })]}
      {countries.size > 0 && [' · ', left > 0 ? t('stats.to_go', { count: left }) : t('stats.everywhere')]}
      {online > 0 && [
        ' · ',
        <span className="MemberMapStats-online">
          <span className="MemberMap-onlineDot" /> {t('stats.online', { count: online })}
        </span>,
      ]}
    </p>
  );
}

/** The crosshairs button beside a member in a list: switches to the map and opens their card. */
function showOnMap(page: MapPage, row: Member, className = ''): Mithril.Children {
  if (!page.supported) return null;
  return (
    <Button
      className={'Button Button--icon Button--link ' + className}
      icon="fas fa-location-crosshairs"
      aria-label={extractText(t('near.show', { name: row[3] }))}
      onclick={() => {
        page.setMode('map');
        setTimeout(() => page.focusMember(row[0]), 50);
      }}
    />
  );
}

function memberLink(row: Member, className: string): Mithril.Children {
  return (
    <Link href={profileUrl(row[4])} className={className + '-member'}>
      <Avatar name={row[3]} url={row[5]} size="Avatar--size24" />
      <span className={className + '-name'}>{row[3]}</span>
      {row[9] & ONLINE ? <span className="MemberMap-onlineDot" title={extractText(t('card.online'))} /> : null}
    </Link>
  );
}

/** The list view: the same people, grouped by country, for keyboards and screen readers. */
export function list(page: MapPage): Mithril.Children {
  const groups = new Map<string, Member[]>();
  for (const row of page.visible()) {
    const key = row[6] || '';
    groups.set(key, [...(groups.get(key) || []), row]);
  }
  const sorted = [...groups.entries()].sort((a, b) => b[1].length - a[1].length || countryName(a[0]).localeCompare(countryName(b[0])));

  if (!sorted.length) return <div className="MemberMapPage-message">{page.loading ? <LoadingIndicator /> : t('page.empty')}</div>;

  return <div className="MemberMapList">{sorted.map(([cc, rows]) => country(page, cc, rows))}</div>;
}

function country(page: MapPage, cc: string, rows: Member[]): Mithril.Children {
  const time = (row: Member) => localTime(row[7]);
  return (
    <section className="MemberMapList-country" aria-labelledby={`member-map-cc-${cc || 'none'}`}>
      <h3 id={`member-map-cc-${cc || 'none'}`}>
        <span aria-hidden="true">{flag(cc)}</span> {cc ? countryName(cc) : t('list.elsewhere')}{' '}
        <span className="MemberMapList-count">{rows.length}</span>
      </h3>
      <ul>
        {rows
          .slice()
          .sort((a, b) => a[3].localeCompare(b[3]))
          .map((row) => (
            <li key={row[0]}>
              {memberLink(row, 'MemberMapList')}
              <span className="MemberMapList-meta">
                {row[8]}
                {time(row) && [' · ', t('card.local_time', { time: time(row) })]}
              </span>
              {showOnMap(page, row)}
            </li>
          ))}
      </ul>
    </section>
  );
}

/** Near you: the members nearest the viewer's own place, with rough distances. */
export function near(page: MapPage): Mithril.Children {
  if (!app.session.user) return null;
  const place = page.data.me?.place;

  return (
    <section className="MemberMapNear">
      <h3 className="MemberMapSection-heading">{t('near.heading')}</h3>
      {place ? nearest(page, place.lat, place.lng) : <p className="helpText">{t('near.add_prompt')}</p>}
    </section>
  );
}

function nearest(page: MapPage, lat: number, lng: number): Mithril.Children {
  const myId = Number(app.session.user!.id());
  const rows = page.data.members
    .filter((row) => row[0] !== myId)
    .map((row) => ({ row, km: distanceKm(lat, lng, row[1], row[2]) }))
    .sort((a, b) => a.km - b.km)
    .slice(0, NEAR_MAX);

  if (!rows.length) return <p className="helpText">{t('near.none')}</p>;

  return (
    <ul className="MemberMapNear-list">
      {rows.map(({ row, km }) => {
        const rough = roughDistance(km, page.miles);
        return (
          <li key={row[0]} className="MemberMapNear-item">
            {memberLink(row, 'MemberMapNear')}
            <span className="MemberMapNear-distance">
              {rough === null ? t('near.same_area') : t(page.miles ? 'near.mi' : 'near.km', { count: rough })}
            </span>
            {showOnMap(page, row, 'MemberMapNear-show')}
          </li>
        );
      })}
    </ul>
  );
}

export function meetupList(page: MapPage): Mithril.Children {
  if (!page.data.meetups.length) return null;
  return (
    <section className="MemberMapMeetups">
      <h3 className="MemberMapSection-heading">{t('meetup.heading')}</h3>
      <ul>
        {page.data.meetups.map((row) => (
          <li key={row[0]}>
            <Icon name="fas fa-calendar-day" />
            <div>
              <Link href={app.route('discussion', { id: `${row[7]}-${row[8]}` })} className="MemberMapMeetups-title">
                {row[6]}
              </Link>
              <div className="MemberMapMeetups-meta">
                {meetupWhen(row)} · {row[3]}
              </div>
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}

export function myTrips(page: MapPage): Mithril.Children {
  const trips = page.data.me?.trips || [];
  if (!trips.length) return null;
  return (
    <section className="MemberMapTrips">
      <h3 className="MemberMapSection-heading">{t('trip.mine')}</h3>
      <ul>
        {trips.map((trip) => (
          <li key={trip.id}>
            <Icon name="fas fa-suitcase-rolling" /> <span>{t('trip.visiting', { place: trip.label, dates: dateRange(trip.starts, trip.ends) })}</span>
            <Button
              className="Button Button--link"
              icon="fas fa-xmark"
              onclick={() =>
                deleteRecord('member-map-trips', String(trip.id))
                  .then(() => page.load())
                  .catch(shown)
              }
            >
              {t('trip.remove')}
            </Button>
          </li>
        ))}
      </ul>
    </section>
  );
}
