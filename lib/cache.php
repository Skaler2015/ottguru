<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  हल्का file-based full-page cache — Redis का PHP/shared-hosting विकल्प।
 *
 *  डेटा सिर्फ़ रात की sync में बदलता है, इसलिए दिन भर पेज static रहते हैं।
 *  HIT पर पूरी DB-query + rendering बच जाती है (सिर्फ़ फ़ाइल पढ़कर भेज देते हैं)।
 *  sync पूरा होने पर cache अपने-आप साफ़ (cache_clear_all)। TTL fallback भी।
 *
 *  cache फोल्डर public_html के बाहर रहता है (config का cache.dir)।
 *  admin/search/sitemap कभी cache नहीं होते।
 * ============================================================================
 */

function cache_enabled(): bool
{
    global $CFG;
    return !empty($CFG['cache']['enabled']);
}

function cache_dir(): string
{
    global $CFG;
    return rtrim((string) ($CFG['cache']['dir'] ?? (OTT_ROOT . '/cache')), '/');
}

/** इस request का cache key — या null अगर cache नहीं करना */
function cache_key_for_request(): ?string
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return null;
    }
    $path  = trim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');
    $first = $path === '' ? '' : (explode('/', $path)[0]);

    // ये कभी cache मत करो — बदलते/निजी/non-HTML
    if (in_array($first, ['admin', 'search', 'suggest', 'bin'], true) || $path === 'sitemap.xml') {
        return null;
    }

    $q = $_GET;
    unset($q['lang']);   // ?lang सिर्फ़ cookie सेट करता है — असली भाषा OTT_LANG में
    ksort($q);
    $lang = defined('OTT_LANG') ? OTT_LANG : 'en';
    return sha1($path . '?' . http_build_query($q) . '|' . $lang);
}

function cache_file(string $key): string
{
    return cache_dir() . '/' . substr($key, 0, 2) . '/' . $key . '.html';
}

/** ताज़ा cache मिले तो HTML लौटाओ, वरना null */
function cache_get(string $key): ?string
{
    global $CFG;
    if (!cache_enabled()) {
        return null;
    }
    $f = cache_file($key);
    if (!is_file($f)) {
        return null;
    }
    $ttl = (int) ($CFG['cache']['ttl'] ?? 7200);
    if ($ttl > 0 && (time() - (int) @filemtime($f)) > $ttl) {
        @unlink($f);
        return null;
    }
    $c = @file_get_contents($f);
    return $c === false ? null : $c;
}

function cache_put(string $key, string $html): void
{
    if (!cache_enabled()) {
        return;
    }
    $dir = dirname(cache_file($key));
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    @file_put_contents(cache_file($key), $html, LOCK_EX);
}

/** पूरा page-cache साफ़ — sync के बाद, या admin से। लौटाता है: कितनी फ़ाइलें हटीं */
function cache_clear_all(): int
{
    $n = 0;
    foreach (glob(cache_dir() . '/*/*.html') ?: [] as $f) {
        if (@unlink($f)) {
            $n++;
        }
    }
    return $n;
}

function cache_stats(): array
{
    $files = glob(cache_dir() . '/*/*.html') ?: [];
    $bytes = 0;
    foreach ($files as $f) {
        $bytes += (int) @filesize($f);
    }
    return ['files' => count($files), 'bytes' => $bytes];
}

/**
 * request की शुरुआत में: HIT हो तो सीधा भेजकर exit; वरना output बफ़र शुरू —
 * पेज बनने के बाद (200 + असली HTML) उसे cache में सहेज देता है।
 * index.php से routing/rendering से पहले कॉल होता है।
 */
function page_cache_serve_or_start(): void
{
    if (!cache_enabled()) {
        return;
    }
    $key = cache_key_for_request();
    if ($key === null) {
        return;
    }
    $hit = cache_get($key);
    if ($hit !== null) {
        header('X-Cache: HIT');
        echo $hit;
        exit;
    }
    header('X-Cache: MISS');
    ob_start(function (string $html) use ($key): string {
        // सिर्फ़ 200 + असली HTML सहेजो (301/404/खाली नहीं)
        if (http_response_code() === 200 && strlen($html) > 300) {
            cache_put($key, $html);
        }
        return $html;
    });
}
