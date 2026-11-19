import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import { dateRange, flag, localTime, ONLINE, t, type Member } from '../../api';
import { Avatar, LIST_MAX, meetupWhen, profileUrl, type Selected } from './shared';
import type MapPage from '../MapPage';

/** A member, a cluster, a traveler or a meetup, for the popup (desktop) or the bottom sheet (phones). */
export function card(page: MapPage, selected: Selected): Mithril.Children {
  switch (selected.kind) {
    case 'member':
      return memberCard(page, selected.member);
    case 'cluster':
      return clusterCard(page, selected);
    case 'trip':
      return tripCard(page, selected.trip);
    default:
      return meetupCard(page, selected.meetup);
  }
}

function memberCard(page: MapPage, row: Member): Mithril.Children {
  const time = localTime(row[7]);
  const trips = page.data.trips.filter((trip) => trip[0] === row[0]);
  return (
    <div className="MemberMapCard">
      <div className="MemberMapCard-head">
        <Avatar name={row[3]} url={row[5]} size="Avatar--size48" />
        <div className="MemberMapCard-who">
          <Link href={profileUrl(row[4])} className="MemberMapCard-name" onclick={() => page.close()}>
            {row[3]}
          </Link>
          <div className="MemberMapCard-place">
            <span aria-hidden="true">{flag(row[6])}</span> {row[8]}
          </div>
        </div>
      </div>
      <ul className="MemberMapCard-facts">
        {row[9] & ONLINE ? (
          <li className="MemberMapCard-online">
            <span className="MemberMap-onlineDot" /> {t('card.online')}
          </li>
        ) : null}
        {time && (
          <li>
            <Icon name="far fa-clock" /> {t('card.local_time', { time })}
          </li>
        )}
        {trips.map((trip) => (
          <li>
            <Icon name="fas fa-suitcase-rolling" /> {t('trip.visiting', { place: trip[3], dates: dateRange(trip[4], trip[5]) })}
          </li>
        ))}
      </ul>
      <Link href={profileUrl(row[4])} className="Button Button--primary Button--block" onclick={() => page.close()}>
        {t('card.profile')}
      </Link>
    </div>
  );
}

function clusterCard(page: MapPage, selected: Extract<Selected, { kind: 'cluster' }>): Mithril.Children {
  const members = selected.members.slice().sort((a, b) => (b[9] & ONLINE) - (a[9] & ONLINE) || a[3].localeCompare(b[3]));
  const canZoom = page.map && page.map.getZoom() < page.map.getMaxZoom() - 0.5;
  return (
    <div className="MemberMapCard">
      <div className="MemberMapCard-count">{t('page.members', { count: members.length })}</div>
      <ul className="MemberMapCard-list">
        {members.slice(0, LIST_MAX).map((row) => (
          <li key={row[0]}>
            <button type="button" className="MemberMapCard-row" onclick={() => page.select({ kind: 'member', member: row }, [row[2], row[1]])}>
              <Avatar name={row[3]} url={row[5]} size="Avatar--size24" />
              <span className="MemberMapCard-rowName">{row[3]}</span>
              {row[9] & ONLINE ? <span className="MemberMap-onlineDot" /> : null}
            </button>
          </li>
        ))}
      </ul>
      {members.length > LIST_MAX && <div className="MemberMapCard-more">{t('page.more', { count: members.length - LIST_MAX })}</div>}
      {canZoom && (
        <Button className="Button Button--link MemberMapCard-zoom" icon="fas fa-magnifying-glass-plus" onclick={() => page.zoomCluster(selected)}>
          {t('page.zoom_in')}
        </Button>
      )}
    </div>
  );
}

function tripCard(page: MapPage, trip: Extract<Selected, { kind: 'trip' }>['trip']): Mithril.Children {
  return (
    <div className="MemberMapCard">
      <div className="MemberMapCard-head">
        <Avatar name={trip[6]} url={trip[8]} size="Avatar--size48" />
        <div className="MemberMapCard-who">
          <Link href={profileUrl(trip[7])} className="MemberMapCard-name" onclick={() => page.close()}>
            {trip[6]}
          </Link>
          <div className="MemberMapCard-place">
            <Icon name="fas fa-suitcase-rolling" /> {t('trip.visiting', { place: trip[3], dates: dateRange(trip[4], trip[5]) })}
          </div>
        </div>
      </div>
      <Link href={profileUrl(trip[7])} className="Button Button--block" onclick={() => page.close()}>
        {t('card.profile')}
      </Link>
    </div>
  );
}

function meetupCard(page: MapPage, meetup: Extract<Selected, { kind: 'meetup' }>['meetup']): Mithril.Children {
  return (
    <div className="MemberMapCard">
      <div className="MemberMapCard-meetupTitle">
        <Icon name="fas fa-calendar-day" /> {meetup[6]}
      </div>
      <ul className="MemberMapCard-facts">
        <li>
          <Icon name="far fa-clock" /> {meetupWhen(meetup)}
        </li>
        <li>
          <Icon name="fas fa-location-dot" /> {meetup[3]}
        </li>
      </ul>
      <Link
        href={app.route('discussion', { id: `${meetup[7]}-${meetup[8]}` })}
        className="Button Button--primary Button--block"
        onclick={() => page.close()}
      >
        {t('meetup.open')}
      </Link>
    </div>
  );
}
