<?php

namespace App;

use App\Scopes\CacheShowScope;
use Carbon\Carbon;
use App\AnimeSentinel\Actions\ShowManager;

class Show extends BaseModel
{
  protected static $cachesUpdated = 0;

  /**
   * The attributes that are mass assignable.
   *
   * @var array
   */
  protected $fillable = [
    'mal_id', 'thumbnail_id', 'title', 'alts', 'description', 'type', 'genres', 'episode_amount', 'episode_duration', 'airing_start', 'airing_end', 'season', 'hits',
  ];

  /**
   * The attributes that should be casted to native types.
   *
   * @var array
   */
  protected $casts = [
    'alts' => 'array',
    'genres' => 'array',
  ];

  /**
   * Ensure both cache handling and decoding occurs.
   */
  public function getAltsAttribute($value) {
    $this->handleCaching();
    return (array) json_decode($value);
  }
  public function getGenresAttribute($value) {
    $this->handleCaching();
    return (array) json_decode($value);
  }

  /**
   * The attributes that should be mutated to dates.
   *
   * @var array
   */
  protected $dates = ['airing_start', 'airing_end', 'cache_updated_at', 'created_at', 'updated_at'];

  /**
  * Get all videos related to this show.
  *
  * @return \Illuminate\Database\Eloquent\Relations\Relation
  */
  public function videos() {
    return $this->hasMany(Video::class);
  }

  /**
  * Get all streamers for this show.
  *
  * @return \Illuminate\Database\Eloquent\Relations\Relation
  */
  public function streamers() {
    return $this->belongsToMany(Streamer::class, 'videos')->distinct();
  }

  /**
  * Get the show flag for this show.
  *
  * @return \Illuminate\Database\Eloquent\Relations\Relation
  */
  public function show_flag() {
    return $this->hasOne(ShowFlag::class, 'mal_id', 'mal_id');
  }

  /**
  * Functions to print information in a fancy way.
  */
  public function printAlts() {
    return implode(', ', $this->alts);
  }
  public function printType() {
    return isset($this->type) ? ucwords($this->type) : 'Unknown';
  }
  public function printGenres() {
    if (count($this->genres) > 0) {
      $string = '';
      foreach ($this->genres as $index => $genre) {
        $string .= ucwords($genre);
        $string .= $index < count($this->genres) - 1 ? ', ' : '';
      }
      return $string;
    }
    else {
      return 'Unknown';
    }
  }
  public function printTotalEpisodes() {
    return isset($this->episode_amount) ? $this->episode_amount : 'Unknown';
  }
  public function printExpectedDuration($withEp = true) {
    if (isset($this->episode_duration)) {
      return fancyDuration($this->episode_duration * 60, false) . ($withEp ? ' per ep.' : '');
    }
    elseif (empty($this->mal) && $this->videos()->avg('duration') !== null) {
      return fancyDuration($this->videos()->avg('duration'), false) . ($withEp ? ' per ep.' : '');
    }
    else {
      return 'Unknown';
    }
  }
  public function printAvarageDuration($withEp = true) {
    if (empty($this->mal) && $this->videos()->avg('duration') !== null) {
      return fancyDuration($this->videos()->avg('duration')) . ($withEp ? ' per ep.' : '');
    }
    else {
      return 'NA';
    }
  }
  public function printExpectedAiring() {
    if (empty($this->airing_start) && empty($this->airing_end)) {
      return 'Unknown';
    }
    else {
      $string = !empty($this->airing_start) ? $this->airing_start->toFormattedDateString() : '?';
      $string .= ' to ';
      $string .= !empty($this->airing_end) ? $this->airing_end->toFormattedDateString() : '?';
      return $string;
    }
  }
  public function printSeason() {
    return isset($this->season) ? ucwords($this->season) : 'Unknown';
  }
  public function printStatusSub() {
    if (empty($this->mal)) {
      if ($this->isAiring('sub')) {
        return 'Currently Airing';
      }
      elseif ($this->finishedAiring('sub')) {
        return 'Completed';
      }
      else {
        return 'Upcoming';
      }
    }
    else {
      if (!isset($this->airing_start) || Carbon::now()->endOfDay()->lt($this->airing_start)) {
        return 'Upcoming';
      }
      elseif (!isset($this->airing_end) || Carbon::now()->startOfDay()->lte($this->airing_end)) {
        return 'Currently Airing';
      }
      else {
        return 'Completed';
      }
    }
  }
  public function printStatusDub() {
    if (empty($this->mal)) {
      if ($this->isAiring('dub')) {
        return 'Currently Airing';
      }
      elseif ($this->finishedAiring('dub')) {
        return 'Completed';
      }
      else {
        return 'Upcoming';
      }
    }
    else {
      return 'Unknown';
    }
  }
  public function printLatestSub() {
    if (!isset($this->latest_sub)) {
      if (!$this->videos_initialised) {
        return 'Searching for Episodes ...';
      }
      else {
        return 'No Episodes Available';
      }
    }
    else {
      return 'Episode ' . $this->latest_sub->episode_num;
    }
  }
  public function printLatestDub() {
    if (!isset($this->latest_dub)) {
      if (!$this->videos_initialised) {
        return 'Searching for Episodes ...';
      }
      else {
        return 'No Episodes Available';
      }
    }
    else {
      return 'Episode ' . $this->latest_dub->episode_num;
    }
  }

  /**
   * Include show's where the requested title matches any alt.
   *
   * @return \Illuminate\Database\Eloquent\Builder
   */
  public function scopeWithTitle($query, $title, $allowPartial = false) {
    // fuzz title
    $title = str_fuzz($title, false);
    // allow matching of ' and ', ' to ', ' und ' and '&' to each other
    // allow matching of ': ' to ' '
    $title = str_replace(': ', '% ', str_replace('&', '%', $title));
    // allow case insensitive matching of greek characters
    $title = preg_replace('/[α-ωΑ-Ω]/u', '\\u03__', $title);
    // encode to json, then escape all unescaped \'s
    $title = preg_replace('/([^\\\\])\\\\([^\\\\])/u', '$1\\\\\\\\$2', json_encode($title));
    // create final query title
    if ($allowPartial) {
      $title = '%"%'.str_replace_last('"', '', str_replace_first('"', '', $title)).'%"%';
    } else {
      $title = '%'.$title.'%';
    }
    // return query
    return $query->whereLike('alts', $title);
  }

  /**
   * Searches for shows matching the requested query, ordered by relevance.
   *
   * @return array
   */
  public static function search($search, $types, $genres, $start = 0, $amount = null, $fill = false) {
    $results = [];
    $query = Self::skip($start)->take($amount)->orderBy('title');

    // searching by types
    $query->whereIn('type', $types);

    // searching by genres
    $query->where(function ($query) use ($genres) {
      $query->where(\DB::raw('1'), \DB::raw('0'));
      foreach ($genres as $genre) {
        $query->orWhere(function ($query) use ($genre) {
          $query->whereLike('genres', '%'.json_encode($genre).'%');
        });
      }
    });

    // searching by title
    if ($search !== '') {
      $query->where(function ($query) use ($search, $fill) {

        // match with full titles
        $query->where(function ($query) use ($search) {
          $query->withTitle($search, false);
        });

        // match with partial titles
        $query->orWhere(function ($query) use ($search) {
          $query->withTitle($search, true);
        });

        // match with partial titles, with non-alphanumeric characters ignored
        $thisSearch = str_to_url($search, '%', '/[^a-zA-Z0-9]/u');
        if (strlen(str_replace('%', '', $thisSearch)) >= 1) {
          $query->orWhere(function ($query) use ($thisSearch) {
            $query->withTitle($thisSearch, true);
          });
        }

        // match with partial titles, with non-alphabetic characters ignored
        $thisSearch = str_to_url($search, '%', '/[^a-zA-Z]/u');
        if (strlen(str_replace('%', '', $thisSearch)) >= 1) {
          $query->orWhere(function ($query) use ($thisSearch) {
            $query->withTitle($thisSearch, true);
          });
        }

        if ($fill) {
          // match any titles with the same letters in the same order, at any location
          $thisSearch = str_to_url($search, '%', '/[^a-zA-Z]/u');
          if (strlen(str_replace('%', '', $thisSearch)) >= 1) {
            $thisSearch = '%'.str_to_url($thisSearch, '$1%', '/([a-zA-Z])/u');
            $query->orWhere(function ($query) use ($thisSearch) {
              $query->withTitle($thisSearch, true);
            });
          }
        }

      });
    }

    // complete and return results
    $results = $query->get();

    if ($search !== '') {
      foreach ($results as $result) {
        $result->matchScore = 0;
        foreach ($result->alts as $alt) {
          similar_text(mb_strtolower($alt), $search, $matchScore);
          if ($matchScore > $result->matchScore) {
            $result->matchScore = $matchScore;
          }
        }
      }
      $results = $results->sortByDesc('matchScore');
    }

    return $results;
  }

  /**
   * Find the show with the given URL data.
   * Return null if no show was found.
   *
   * @return \App\Show
   */
  public static function getShowFromUrl($show_id, $title) {
    if (is_numeric($show_id)) {
      $show = Show::find($show_id);
    }

    if (isset($show)) {
      if ($title === slugify($show->title) || empty($title)) {
        return $show;
      }
      else {
        foreach ($show->alts as $alt) {
          if ($title === slugify($alt)) {
            return $show;
          }
        }
      }
    }

    if (!empty($title)) {
      $replace = [
        '⧸' => '/',
        '⧹' => '\\',
      ];
      foreach ($replace as $from => $to) {
        $title = str_replace($from, $to, $title);
      }
      $title = str_replace('‑', ' ', $title);
      $show = Show::withTitle($title)->first();
      if (isset($show)) {
        return $show;
      } else {
        $show = ShowManager::addShowWithTitle($title, false, 'default');
        return $show;
      }
    }

    return null;
  }

  /**
  * Get whether the show is currently airing for the requested translation type.
  *
  * @return boolean
  */
  public function isAiring($translation_type) {
    $latest = 'latest_'.$translation_type;
    $latest = $this->$latest;
    return $latest !== null && (!isset($this->episode_amount) || $latest->episode_num < $this->episode_amount);
  }

  /**
  * Get whether the show has finished airing for the requested translation type.
  *
  * @return boolean
  */
  public function finishedAiring($translation_type) {
    $latest = 'latest_'.$translation_type;
    $latest = $this->$latest;
    return $latest !== null && isset($this->episode_amount) && $latest->episode_num >= $this->episode_amount;
  }

  /**
  * Get the latest subbed episode for this show.
  *
  * @return integer
  */
  public function getLatestSubAttribute() {
    return $this->videos()
                ->where('translation_type', 'sub')
                ->orderBy('episode_num', 'desc')
                ->orderBy('uploadtime', 'asc')
                ->orderBy('id', 'asc')
                ->first();
  }

  /**
  * Get the latest dubbed episode for this show.
  *
  * @return integer
  */
  public function getLatestDubAttribute() {
    return $this->videos()
                ->where('translation_type', 'dub')
                ->orderBy('episode_num', 'desc')
                ->orderBy('uploadtime', 'asc')
                ->orderBy('id', 'asc')
                ->first();
  }

  /**
  * Get the first uploaded video.
  *
  * @return Video
  */
  public function getFirstVideoAttribute() {
    return $this->videos()->orderBy('uploadtime', 'asc')->orderBy('id', 'asc')->first();
  }

  /**
  * Get the last uploaded video.
  *
  * @return Video
  */
  public function getLastVideoAttribute() {
    return $this->videos()->orderBy('uploadtime', 'desc')->orderBy('id', 'desc')->first();
  }


  /**
  * Get a list of episodes of the requested type.
  *
  * @return array
  */
  public function episodes($translation_type) {
    return $this->videos()
                ->where('translation_type', $translation_type)
                ->distinctOn('episode_num', 'uploadtime')
                ->orderBy('episode_num', 'desc')
                ->orderBy('uploadtime', 'asc')
                ->orderBy('id', 'asc')
                ->get();
  }

  /**
  * Get the full url for this show's details page.
  *
  * @return string
  */
  public function getDetailsUrlAttribute() {
    if (empty($this->mal)) {
      return fullUrl('/anime/'.$this->id.'/'.slugify($this->title));
    } else {
      return $this->attributes['details_url'];
    }
  }

  /**
  * Get a static url for this show's details page.
  *
  * @return string
  */
  public function getDetailsUrlStaticAttribute() {
    if (empty($this->mal)) {
      return fullUrl('/anime/-/'.slugify($this->title), true);
    } else {
      return $this->attributes['details_url'];
    }
  }

  /**
  * Get the url to this show's MAL page.
  *
  * @return string
  */
  public function getMalUrlAttribute() {
    if (isset($this->mal_id)) {
      return 'https://myanimelist.net/anime/'.$this->mal_id;
    } else {
      return null;
    }
  }

  /**
  * Get the url to this show's local thumbnail.
  *
  * @return string
  */
  public function getThumbnailUrlAttribute() {
    if (empty($this->mal)) {
      return fullUrl('/media/thumbnails/'.$this->thumbnail_id);
    } else {
      return $this->attributes['thumbnail_url'];
    }
  }

  /**
  * Update this show's cached infomation when needed.
  */
  public function handleCaching() {
    // TODO: smarter cache time
    if (!$this->mal && Self::$cachesUpdated < 1 && $this->cache_updated_at->diffInHours(Carbon::now()) >= rand(168, 336)) {
      Self::$cachesUpdated++;
      ShowManager::updateShowCache($this->id);
    }
  }

  /**
  * Handle caching calls.
  */
  public function getTitleAttribute($value) {
    $this->handleCaching();
    return $value;
  }
  public function getDescriptionAttribute($value) {
    $this->handleCaching();
    return $value;
  }
  public function getTypeAttribute($value) {
    $this->handleCaching();
    return $value;
  }
  public function getEpisodeAmountAttribute($value) {
    $this->handleCaching();
    return $value;
  }
  public function getEpisodeDurationAttribute($value) {
    $this->handleCaching();
    return $value;
  }
}
