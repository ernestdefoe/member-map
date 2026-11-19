import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../../api';
import type MapPage from '../MapPage';

type Chip = 'online' | 'following' | 'travelers' | 'meetups';

/** Find a place, the filters, and map or list. */
export function toolbar(page: MapPage): Mithril.Children {
  return (
    <div className="MemberMapPage-toolbar">
      {app.session.user && searchForm(page)}
      <div className="MemberMapPage-chips" role="group" aria-label={extractText(t('filters.label'))}>
        {chips(page)}
        {page.supported && viewToggle(page)}
      </div>
    </div>
  );
}

/** Searches only on the button or Enter: never as you type (Nominatim's usage policy). */
function searchForm(page: MapPage): Mithril.Children {
  return (
    <form
      className="MemberMapSearch"
      role="search"
      onsubmit={(e: SubmitEvent) => {
        e.preventDefault();
        page.search();
      }}
    >
      <input
        className="FormControl"
        type="search"
        aria-label={extractText(t('search.label'))}
        placeholder={extractText(t('search.placeholder'))}
        bidi={page.query}
      />
      <Button
        className="Button Button--icon"
        type="submit"
        icon="fas fa-magnifying-glass"
        loading={page.searching}
        aria-label={extractText(t('search.button'))}
      />
      {page.results && (
        <ul className="MemberMapSearch-results">
          {page.results.length ? (
            page.results.map((result) => (
              <li>
                <button type="button" onclick={() => page.flyToResult(result)}>
                  {result.label}
                </button>
              </li>
            ))
          ) : (
            <li className="helpText">{t('search.none')}</li>
          )}
        </ul>
      )}
    </form>
  );
}

function chip(page: MapPage, key: Chip, iconName: string, label: string, onchange?: () => void): Mithril.Children {
  return (
    <button
      type="button"
      className={'MemberMapChip' + (page.filters[key] ? ' is-on' : '')}
      aria-pressed={page.filters[key] ? 'true' : 'false'}
      onclick={() => {
        page.filters[key] = !page.filters[key];
        onchange ? onchange() : page.refreshMap();
      }}
    >
      <Icon name={iconName} /> {label}
    </button>
  );
}

function chips(page: MapPage): Mithril.Children {
  const groups = app.store.all<any>('groups').filter((group) => !['1', '2', '3'].includes(String(group.id())) && !group.isHidden?.());
  const following = !!app.session.user && 'ianm-follow-users' in (flarum.extensions || {});

  return [
    chip(page, 'online', 'fas fa-circle', extractText(t('filters.online'))),
    following && chip(page, 'following', 'fas fa-user-check', extractText(t('filters.following')), () => page.loadFilter()),
    groups.length > 0 && (
      <select
        className="FormControl MemberMapChip-select"
        aria-label={extractText(t('filters.group'))}
        value={page.filters.group}
        onchange={(e: Event) => {
          page.filters.group = (e.target as HTMLSelectElement).value;
          page.loadFilter();
        }}
      >
        <option value="">{t('filters.any_group')}</option>
        {groups.map((group) => (
          <option value={group.id()}>{group.namePlural()}</option>
        ))}
      </select>
    ),
    page.data.trips.length > 0 && chip(page, 'travelers', 'fas fa-suitcase-rolling', extractText(t('filters.travelers'))),
    page.data.meetups.length > 0 && chip(page, 'meetups', 'fas fa-calendar-day', extractText(t('filters.meetups'))),
  ];
}

function viewToggle(page: MapPage): Mithril.Children {
  return (
    <div className="MemberMapToggle" role="group" aria-label={extractText(t('view.label'))}>
      <button type="button" aria-pressed={page.mode === 'map' ? 'true' : 'false'} onclick={() => page.setMode('map')}>
        <Icon name="fas fa-earth-americas" /> {t('view.map')}
      </button>
      <button type="button" aria-pressed={page.mode === 'list' ? 'true' : 'false'} onclick={() => page.setMode('list')}>
        <Icon name="fas fa-list" /> {t('view.list')}
      </button>
    </div>
  );
}
