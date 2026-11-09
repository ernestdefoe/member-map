import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, t, type SearchResult } from '../api';

declare const m: Mithril.Static;

/**
 * Search for a place, on the button or Enter only: Nominatim's usage policy
 * forbids search-as-you-type. Small enough to be copied into each form's chunk.
 */
export default class PlaceSearch extends Component<{ id: string; label: Mithril.Children; onpick: (place: SearchResult) => void }> {
  query = Stream('');
  searching = false;
  results: SearchResult[] | null = null;
  picked = '';

  view() {
    return (
      <div className="Form-group MemberMapPlaceSearch">
        <label for={this.attrs.id}>{this.attrs.label}</label>
        <div className="MemberMapPlaceSearch-row">
          <input
            id={this.attrs.id}
            className="FormControl"
            type="search"
            autocomplete="off"
            maxLength={120}
            placeholder={extractText(t('location.search_placeholder'))}
            bidi={this.query}
            onkeydown={(e: KeyboardEvent) => {
              // Enter searches; it must not submit the form around it.
              if (e.key === 'Enter') {
                e.preventDefault();
                this.search();
              }
            }}
          />
          <Button className="Button" type="button" loading={this.searching} disabled={this.query().trim().length < 2} onclick={() => this.search()}>
            {t('location.search')}
          </Button>
        </div>
        {this.results && (
          <ul className="MemberMapPlaceSearch-results">
            {this.results.length ? (
              this.results.map((place) => (
                <li>
                  <button
                    type="button"
                    className={'MemberMapPlaceSearch-result' + (this.picked === place.label ? ' active' : '')}
                    onclick={() => {
                      this.picked = place.label;
                      this.attrs.onpick(place);
                    }}
                  >
                    {place.label}
                  </button>
                </li>
              ))
            ) : (
              <li className="helpText">{t('location.no_results')}</li>
            )}
          </ul>
        )}
      </div>
    );
  }

  search() {
    const q = this.query().trim();
    if (q.length < 2 || this.searching) return;
    this.searching = true;
    call<{ places: SearchResult[] }>('GET', '/geocode', { q })
      .then((data) => (this.results = data.places))
      .catch(() => {})
      .finally(() => {
        this.searching = false;
        m.redraw();
      });
  }
}
