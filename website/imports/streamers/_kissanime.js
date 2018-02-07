import Cheerio from 'cheerio';
import ScrapingHelpers from "./scrapingHelpers";
import {Shows} from '../api/shows/shows';

function cleanName(name) {
  return name.replace(/ \(Dub\)$/, '').replace(/ \(Sub\)$/, '');
}

function getTypeFromName(name) {
  return name.endsWith(' (Dub)') ? 'dub' : 'sub';
}

const validTypes = ['OVA', 'Movie', 'Special', 'ONA'];

export let kissanime = {
  // General data
  id: 'kissanime',
  name: 'KissAnime',
  homepage: 'http://kissanime.ru',
  minimalPageTypes: ['sub', 'dub'],

  // Search page data
  search: {
    createUrl: function(search) {
      if (search.query) {
        let filler = search.query.length < 2 ? search.query : '';
        return kissanime.homepage + '/Search/Anime?keyword=' + encodeURIComponentReplaceSpaces(search.query + filler, '+');
      }

      // TODO: Advanced anime searching
      // else if (search.includeTypes && search.types && search.types.length === 1 && validTypes.includes(search.types[0])) {
      //   return kissanime.homepage + '/Genre/' + search.types[0];
      // }
      //
      // else if (search.includeGenres && search.genres && search.genres.length === 1 && search.genres[0] !== 'Unknown') {
      //   return kissanime.homepage + '/Genre/' + search.genres[0].replace(/\s/g, '-');
      // }

      else {
        return kissanime.homepage + '/AnimeList';
      }
    },
    rowSelector: 'table.listing tbody tr',
    rowSkips: 2,

    // Search page attribute data
    attributes: {
      streamerUrls: function(partial, full) {
        return [{
          type: getTypeFromName(partial.find('td:first-of-type a').text()),
          url: kissanime.homepage + partial.find('td:first-of-type a').attr('href')
        }];
      },
      name: function(partial, full) {
        return cleanName(partial.find('td:first-of-type a').text());
      },
      description: function(partial, full) {
        return ScrapingHelpers.replaceDescriptionCutoff(Cheerio.load(partial.find('td:first-of-type').attr('title'))('div p').text(), /\s\.\.\.\n\s*/);
      },
    },

    // Search page thumbnail data
    thumbnails: {
      rowSelector: 'td:first-of-type',
      getUrl: function (partial, full) {
        return Cheerio.load(partial.attr('title'))('img').attr('src');
      },
    },
  },

  // Show page data
  show: {
    checkIfPage: function(page) {
      return page('title').text().cleanWhitespace().match(/^.* anime \| Watch .* anime online in high quality$/);
    },

    // Show page attribute data
    attributes: {
      streamerUrls: function(partial, full) {
        return [{
          type: getTypeFromName(partial.find('a.bigChar').text()),
          url: kissanime.homepage + partial.find('a.bigChar').attr('href')
        }];
      },
      name: function(partial, full) {
        return cleanName(partial.find('a.bigChar').text());
      },
      altNames: function(partial, full) {
        return partial.find('div.bigBarContainer div.barContent div:nth-of-type(2) p:has(span:contains("Other name:")) a').map((index, element) => {
          return partial.find(element).text();
        }).get();
      },
      description: function(partial, full) {
        return partial.find('div.bigBarContainer div.barContent div:nth-of-type(2) p:nth-last-of-type(2)').html();
      },
      type: function(partial, full) {
        let genres = partial.find('div.bigBarContainer div.barContent div:nth-of-type(2) p:has(span:contains("Genres:")) a').map((index, element) => {
          return partial.find(element).text();
        }).get();
        return Shows.validTypes.find((type) => {
          return genres.includes(type);
        });
      },
      genres: function(partial, full) {
        return partial.find('div.bigBarContainer div.barContent div:nth-of-type(2) p:has(span:contains("Genres:")) a').map((index, element) => {
          return partial.find(element).text();
        }).get().filter((genre) => {
          return !Shows.validTypes.includes(genre) && genre !== 'Dub';
        });
      },
    },

    // Show page thumbnail data
    thumbnails: {
      rowSelector: 'div#rightside div.barContent div[style="text-align: center"] img',
      getUrl: function (partial, full) {
        return partial.attr('src');
      },
    },
  },

  // Related shows data
  showRelated: {
    rowSelector: 'div#rightside div:nth-of-type(3) div.barContent div:nth-of-type(2) a',
    rowIgnore: function(partial) {
      return partial.attr('href').count('/') > 2;
    },

    // Related shows attribute data
    attributes: {
      streamerUrls: function(partial, full) {
        return [{
          type: getTypeFromName(partial.text()),
          url: kissanime.homepage + partial.attr('href')
        }];
      },
      name: function(partial, full) {
        return cleanName(partial.text());
      },
    },
  },

  // Episode list data
  showEpisodes: {
    rowSelector: 'table.listing tbody tr',
    rowSkips: 2,
    cannotCount: true,

    // Episode list attribute data
    attributes: {
      episodeNumStart: function(partial, full) {
        let words = partial.find('td:first-of-type a').text().cleanWhitespace().split(' ');
        let lastWord = words.pop();

        if (isNumeric(lastWord)) {
          if (words.pop() === '-') {
            return words.pop();
          }
        }

        return lastWord;
      },
      episodeNumEnd: function(partial, full) {
        return partial.find('td:first-of-type a').text().cleanWhitespace().split(' ').pop()
      },
      translationType: function(partial, full) {
        return getTypeFromName(full.find('a.bigChar').text());
      },
      sourceUrl: function(partial, full) {
        return kissanime.homepage + partial.find('td:first-of-type a').attr('href');
      },
      sources: function(partial, full) {
        let sourceUrl = kissanime.homepage + partial.find('td:first-of-type a').attr('href');
        return [{
          name: 'Openload',
          url: sourceUrl + '&s=openload',
          flags: ['cloudflare', 'mixed-content']
        }, {
          name: 'RapidVideo',
          url: sourceUrl + '&s=rapidvideo',
          flags: ['cloudflare', 'mixed-content']
        }, {
          name: 'Streamango',
          url: sourceUrl + '&s=streamango',
          flags: ['cloudflare', 'mixed-content']
        }, {
          name: 'Beta Server',
          url: sourceUrl + '&s=beta',
          flags: ['cloudflare', 'mixed-content']
        }];
      },
    },
  },
};
