<?php
declare(strict_types=1);

/** दौड़ भर की गिनती — sync_runs में दर्ज होती है */
$GLOBALS['TMDB_CALLS']    = 0;
$GLOBALS['TMDB_FAILURES'] = 0;

function tmdb_get(string $path, array $query = []): array
{
    global $CFG;

    $key     = (string) $CFG['tmdb_key'];
    $headers = [];
    if (substr($key, 0, 3) === 'eyJ') {
        $headers[] = 'Authorization: Bearer ' . $key;   // v4 token
    } else {
        $query['api_key'] = $key;                        // v3 key
    }

    $url = rtrim((string) ($CFG['tmdb_base'] ?? 'https://api.themoviedb.org/3'), '/') . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $r = http_get_json($url, $headers, $CFG['http']);

    $GLOBALS['TMDB_CALLS']++;
    if (!$r['ok']) {
        $GLOBALS['TMDB_FAILURES']++;
    }

    $ms = (int) ($CFG['batch']['sleep_ms'] ?? 120);
    if ($ms > 0) {
        usleep($ms * 1000);
    }
    return $r;
}

/**
 * एक ही कॉल में मेटाडेटा + India के providers।
 * append_to_response की वजह से API कॉल आधी हो जाती हैं।
 */
function tmdb_title_bundle(string $mediaType, int $tmdbId): array
{
    global $CFG;
    // credits=cast/crew, videos=trailer, release_dates=भारत की certification (movie),
    // content_ratings=certification (tv)। जो append उस media_type पर लागू नहीं, TMDB
    // उसे चुपचाप छोड़ देता है — कॉल की संख्या नहीं बढ़ती (सब एक ही request में)।
    return tmdb_get('/' . $mediaType . '/' . $tmdbId, [
        'language'           => $CFG['language'],
        'append_to_response' => 'watch/providers,external_ids,credits,videos,release_dates,content_ratings',
    ]);
}

/** India में उपलब्ध titles खोजना — पूरा TMDB कैटलॉग खींचने की ज़रूरत नहीं */
function tmdb_discover(string $mediaType, int $providerTmdbId, int $page): array
{
    global $CFG;
    return tmdb_get('/discover/' . $mediaType, [
        'watch_region'              => $CFG['country'],
        'with_watch_providers'      => $providerTmdbId,
        'watch_monetization_types'  => 'flatrate|free|ads',
        'sort_by'                   => 'popularity.desc',
        'include_adult'             => 'false',
        'page'                      => $page,
        'language'                  => $CFG['language'],
    ]);
}

/**
 * भारतीय भाषा से खोज — India में उपलब्ध वो titles जिनकी मूल भाषा दी गई है।
 * provider-दर-provider इंतज़ार किए बिना हिंदी/regional कंटेंट सीधे लाता है।
 * watch_region + monetization_types मिलकर "किसी भी Indian OTT पर उपलब्ध" पक्का करते हैं।
 */
function tmdb_discover_lang(string $mediaType, string $lang, int $page): array
{
    global $CFG;
    return tmdb_get('/discover/' . $mediaType, [
        'watch_region'                  => $CFG['country'],
        'with_original_language'        => $lang,
        'with_watch_monetization_types' => 'flatrate|free|ads',
        'sort_by'                       => 'popularity.desc',
        'include_adult'                 => 'false',
        'page'                          => $page,
        'language'                      => $CFG['language'],
    ]);
}

/**
 * TMDB bundle से अतिरिक्त मेटाडेटा निकालना — genre, cast/crew, trailer,
 * certification, tagline, digital रिलीज़ डेट। सिर्फ़ पढ़ता है, DB नहीं छूता।
 * limits जान-बूझकर बँधे हैं ताकि हर title पर लिखना हल्का रहे।
 * लौटाता है: ['genres'=>[id=>name], 'cast'=>[], 'crew'=>[], 'videos'=>[],
 *             'cert'=>?, 'digital'=>?, 'tagline'=>?]
 */
function tmdb_extract_extra(array $d, string $mediaType): array
{
    // ---- genres (base response में आते हैं) ----
    $genres = [];
    foreach (($d['genres'] ?? []) as $g) {
        $gid = (int) ($g['id'] ?? 0);
        $gn  = trim((string) ($g['name'] ?? ''));
        if ($gid !== 0 && $gn !== '') {
            $genres[$gid] = mb_substr($gn, 0, 60);
        }
    }

    // ---- cast (order के हिसाब से top 18) ----
    $cast    = [];
    $castRaw = $d['credits']['cast'] ?? [];
    usort($castRaw, fn ($a, $b) => ((int) ($a['order'] ?? 999)) <=> ((int) ($b['order'] ?? 999)));
    foreach (array_slice($castRaw, 0, 18) as $i => $c) {
        $pid = (int) ($c['id'] ?? 0);
        if ($pid === 0) {
            continue;
        }
        $cast[] = [
            'pid'     => $pid,
            'name'    => mb_substr((string) ($c['name'] ?? ''), 0, 160),
            'profile' => nz((string) ($c['profile_path'] ?? '')),
            'role'    => nz(mb_substr((string) ($c['character'] ?? ''), 0, 200)),
            'ord'     => $i,
        ];
    }

    // ---- crew (सिर्फ़ मुख्य jobs) ----
    $keep = ['Director' => 1, 'Writer' => 2, 'Screenplay' => 3, 'Story' => 4, 'Creator' => 5];
    $crew = [];
    $seen = [];
    foreach (($d['credits']['crew'] ?? []) as $c) {
        $job = trim((string) ($c['job'] ?? ''));
        $pid = (int) ($c['id'] ?? 0);
        if ($pid === 0 || !isset($keep[$job]) || isset($seen[$pid . '|' . $job])) {
            continue;
        }
        $seen[$pid . '|' . $job] = true;
        $crew[] = [
            'pid' => $pid, 'name' => mb_substr((string) ($c['name'] ?? ''), 0, 160),
            'profile' => nz((string) ($c['profile_path'] ?? '')), 'role' => $job, 'sort' => $keep[$job],
        ];
    }
    // सीरीज़ के creators अलग top-level field में आते हैं
    foreach (($d['created_by'] ?? []) as $c) {
        $pid = (int) ($c['id'] ?? 0);
        if ($pid === 0 || isset($seen[$pid . '|Creator'])) {
            continue;
        }
        $seen[$pid . '|Creator'] = true;
        $crew[] = [
            'pid' => $pid, 'name' => mb_substr((string) ($c['name'] ?? ''), 0, 160),
            'profile' => nz((string) ($c['profile_path'] ?? '')), 'role' => 'Creator', 'sort' => 5,
        ];
    }
    usort($crew, fn ($a, $b) => $a['sort'] <=> $b['sort']);
    foreach ($crew as $i => &$cc) {
        $cc['ord'] = $i;
        unset($cc['sort']);
    }
    unset($cc);

    // ---- videos — सिर्फ़ YouTube trailer/teaser/clip (official पहले, फिर नया) ----
    $typePrio = ['Trailer' => 1, 'Teaser' => 2, 'Clip' => 3];
    $vraw     = [];
    foreach (($d['videos']['results'] ?? []) as $v) {
        if (strtolower((string) ($v['site'] ?? '')) !== 'youtube') {
            continue;
        }
        $type = (string) ($v['type'] ?? '');
        $key  = trim((string) ($v['key'] ?? ''));
        if ($key === '' || !isset($typePrio[$type])) {
            continue;
        }
        $vraw[] = [
            'key'      => mb_substr($key, 0, 24),
            'name'     => nz(mb_substr((string) ($v['name'] ?? ''), 0, 200)),
            'kind'     => $type,
            'official' => empty($v['official']) ? 1 : 0,   // 0 = official → पहले
            'prio'     => $typePrio[$type],
            'pub'      => (string) ($v['published_at'] ?? ''),
        ];
    }
    usort($vraw, fn ($a, $b) =>
        [$a['official'], $a['prio'], $b['pub']] <=> [$b['official'], $b['prio'], $a['pub']]);
    $vids  = [];
    $vseen = [];
    foreach ($vraw as $v) {
        if (isset($vseen[$v['key']])) {
            continue;
        }
        $vseen[$v['key']] = true;
        $vids[] = ['key' => $v['key'], 'name' => $v['name'], 'kind' => $v['kind'], 'ord' => count($vids)];
        if (count($vids) >= 6) {
            break;
        }
    }

    // ---- certification (भारत) + digital रिलीज़ डेट ----
    $cert    = null;
    $digital = null;
    if ($mediaType === 'movie') {
        foreach (($d['release_dates']['results'] ?? []) as $c) {
            if (strtoupper((string) ($c['iso_3166_1'] ?? '')) !== 'IN') {
                continue;
            }
            foreach (($c['release_dates'] ?? []) as $rd) {
                $cc = trim((string) ($rd['certification'] ?? ''));
                if ($cc !== '') {
                    $cert = mb_substr($cc, 0, 16);
                }
                if ((int) ($rd['type'] ?? 0) === 4) {   // 4 = Digital
                    $dd = ymd(substr((string) ($rd['release_date'] ?? ''), 0, 10));
                    if ($dd !== null) {
                        $digital = $dd;
                    }
                }
            }
        }
    } else {
        foreach (($d['content_ratings']['results'] ?? []) as $c) {
            if (strtoupper((string) ($c['iso_3166_1'] ?? '')) === 'IN') {
                $rr = trim((string) ($c['rating'] ?? ''));
                if ($rr !== '') {
                    $cert = mb_substr($rr, 0, 16);
                }
            }
        }
    }

    // ---- collection/franchise (movie base response में; null भी हो सकता है) ----
    $collection = null;
    $bc = $d['belongs_to_collection'] ?? null;
    if (is_array($bc) && (int) ($bc['id'] ?? 0) > 0 && trim((string) ($bc['name'] ?? '')) !== '') {
        $collection = [
            'id'     => (int) $bc['id'],
            'name'   => mb_substr(trim((string) $bc['name']), 0, 160),
            'poster' => nz((string) ($bc['poster_path'] ?? '')),
        ];
    }

    return [
        'genres'     => $genres,   // [id => name]
        'cast'       => $cast,
        'crew'       => $crew,
        'videos'     => $vids,
        'cert'       => $cert,
        'digital'    => $digital,
        'tagline'    => nz(mb_substr((string) ($d['tagline'] ?? ''), 0, 300)),
        'collection' => $collection,
    ];
}

/** providers की मास्टर सूची — install.php इससे providers टेबल भरता है */
function tmdb_provider_list(string $mediaType): array
{
    global $CFG;
    return tmdb_get('/watch/providers/' . $mediaType, [
        'watch_region' => $CFG['country'],
        'language'     => $CFG['language'],
    ]);
}
