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
    return tmdb_get('/' . $mediaType . '/' . $tmdbId, [
        'language'           => $CFG['language'],
        'append_to_response' => 'watch/providers,external_ids',
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

/** providers की मास्टर सूची — install.php इससे providers टेबल भरता है */
function tmdb_provider_list(string $mediaType): array
{
    global $CFG;
    return tmdb_get('/watch/providers/' . $mediaType, [
        'watch_region' => $CFG['country'],
        'language'     => $CFG['language'],
    ]);
}
