<?php

namespace App\AnimeSentinel;

use Carbon\Carbon;

class MyAnimeList
{
  /**
   * Does a search on MAL with the requested query.
   * Returns all results.
   *
   * @return SimpleXMLElement
   */
  private static function searchApiXml($query) {
    // Preform curl request
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, 'http://myanimelist.net/api/anime/search.xml?q='.str_replace(' ', '+', $query));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_USERNAME, config('animesentinel.mal_username'));
    curl_setopt($curl, CURLOPT_PASSWORD, config('animesentinel.mal_password'));
    $response = curl_exec($curl);
    curl_close($curl);
    // Convert to and return xml
    $xml = simplexml_load_string($response);
    return $xml;
  }

  /**
   * Does an API search on MAL with the requested query.
   * Converts the results to an array of stdClass.
   *
   * @return array
   */
  public static function searchApi($query) {
    $xml = Self::searchApiXml($query);
    if (empty($xml)) return [];
    $results = [];
    foreach ($xml as $entry) {
      $result = json_decode(json_encode($entry));
      if (!is_string($result->english)) $result->english = '';
      $json_synonyms = json_encode($result->synonyms);
      if ($json_synonyms !== '{}') {
        $result->synonyms = explode('; ', $json_synonyms);
      } else {
        $result->synonyms = [];
      }
      $result->mal = true;
      $results[] = $result;
    }
    return $results;
  }

  /**
   * Does a regular search on MAL with the requested query.
   *
   * @return array
   */
  public static function search($query) {
    $page = Downloaders::downloadPage('http://myanimelist.net/anime.php?q='.str_replace(' ', '+', $query).'&gx=1&genre[]=12');
    $shows = array_slice(Helpers::scrape_page(str_get_between($page, '</div>Search Results</div>', '</table>'), '</tr>', [
      'mal_id' => [true, 'http://myanimelist.net/anime/', '/'],
      'thumbnail_url' => [false, '/images/anime/', '?'],
      'title' => [false, '<strong>', '</strong>'],
    ]), 0, 64);

    $results = [];
    foreach ($shows as $show) {
      $result = new \stdClass();
      $result->mal = true;
      $result->mal_id = $show['mal_id'];
      $result->title = $show['title'];
      if (!empty($show['thumbnail_url'])) {
        $result->thumbnail_url = 'http://cdn.myanimelist.net/images/anime/'.$show['thumbnail_url'];
      } else {
        $result->thumbnail_url = '';
      }
      $result->details_url = 'http://myanimelist.net/anime/'.$show['mal_id'];
      $results[] = $result;
    }
    return $results;
  }

  /**
   * Tries to find the mal id for the requested title.
   * Returns null if it cannot be found.
   *
   * @return integer
   */
  public static function getMalIdForTitle($title) {
    // First try searching using the api
    $results = Self::searchApi($title);
    foreach ($results as $result) {
      // Create alts list
      $alts[] = $result->title;
      $alts[] = $result->english;
      $alts = Helpers::mergeFlagAlts(array_merge($alts, $result->synonyms), $result->id);
      // Check for a match
      foreach ($alts as $alt) {
        if (match_fuzzy($alt, $title)) {
          return $result->id;
        }
      }
    }

    // If that fails, try using the regular search
    $page = Downloaders::downloadPage('http://myanimelist.net/anime.php?q='.str_replace(' ', '+', $title).'&gx=1&genre[]=12');
    $shows = array_slice(Helpers::scrape_page(str_get_between($page, '</div>Search Results</div>', '</table>'), '</tr>', [
      'mal_id' => [true, 'http://myanimelist.net/anime/', '/'],
    ]), 0, 8);
    foreach ($shows as $show) {
      // Get MAL data
      $data = Self::getAnimeData($show['mal_id']);
      // Check if the title is an alt
      foreach ($data['alts'] as $alt) {
        if (match_fuzzy($alt, $title)) {
          return $show['mal_id'];
        }
      }
    }

    return null;
  }

  /**
   * Scrapes all important data from MAL for the anime with the requested id.
   *
   * @return array
   */
  public static function getAnimeData($mal_id) {
    $page = Downloaders::downloadPage('http://myanimelist.net/anime/'.$mal_id);

    $title = trim(str_get_between($page, '<span itemprop="name">', '</span>'));

    $alts[] = $title;
    $set = explode('</div>', str_get_between($page, '<h2>Alternative Titles</h2>', '<br />'));
    foreach ($set as $line) {
      if (trim($line) !== '' && str_contains($line, '<span class="dark_text">Japanese:</span>')) {
        $list = trim(str_get_between($line, '</span>'));
        $alts = array_merge($alts, explode(', ', $list));
      }
    }

    $amount = str_get_between($page, '<span class="dark_text">Episodes:</span>', '</div>');
    settype($amount, 'int');
    if ($amount === 0) $amount = null;

    $durations = explode('hr.', trim(str_get_between($page, '<span class="dark_text">Duration:</span>', '</div>')));
    $duration = 0;
    if (count($durations) === 1) {
      $duration += $durations[0];
    } else if (count($durations) === 2) {
      $duration += $durations[0] * 60;
      $duration += $durations[1];
    }
    if ($duration === 0) $duration = null;

    $genres = [];
    $set = explode('</a>', str_get_between($page, '<span class="dark_text">Genres:</span>', '</div>'));
    foreach ($set as $item) {
      if (trim($item) !== '') {
        $genres[] = strtolower(trim(str_get_between($item, '>')));
      }
    }

    $airing_start = null;
    $airing_end = null;
    $aired = trim(str_get_between($page, '<span class="dark_text">Aired:</span>', '</div>'));
    if ($aired !== 'Not available') {
      $aired = explode(' to ', $aired);
      if ($aired[0] !== '?') {
        $airing_start = Self::convertAiringToCarbon($aired[0]);
      }
      if ($aired[count($aired) - 1] !== '?') {
        $airing_end = Self::convertAiringToCarbon($aired[count($aired) - 1]);
      }
    }

    $thumbnail_id = str_replace('/', '-', str_get_between($page, 'src="http://cdn.myanimelist.net/images/anime/', '"'));
    if (empty($thumbnail_id)) {
      $thumbnail_id = null;
    }

    return [
      'mal_id' => $mal_id,
      'thumbnail_id' => $thumbnail_id,
      'title' => $title,
      'alts' => Helpers::mergeFlagAlts($alts, $mal_id),
      'description' => trim(str_get_between($page, '<span itemprop="description">', '</span>')),
      'type' => strtolower(trim(str_get_between(str_get_between($page, '<span class="dark_text">Type:</span>', '</a>'), '>'))),
      'genres' => $genres,
      'episode_amount' => $amount,
      'episode_duration' => $duration,
      'airing_start' => $airing_start,
      'airing_end' => $airing_end,
    ];
  }

  private static function convertAiringToCarbon($string) {
    if (count(explode(' ', $string)) === 3) {
      $carbon = Carbon::createFromFormat('M j, Y', $string);
      return $carbon;
    }
    if (count(explode(' ', $string)) === 2) {
      $carbon = Carbon::createFromFormat('M, Y', $string)->day(1);
      return $carbon;
    }
    if (count(explode(' ', $string)) === 1) {
      $carbon = Carbon::createFromFormat('Y', $string)->day(1)->month(1);
      return $carbon;
    }
    return null;
  }
}
