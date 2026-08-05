<?php
/**
 * ============================================================================
 *  OTT गुरु — वेबसाइट का front controller
 *  सारे रास्ते यहीं से बँटते हैं:
 *    /                                होमपेज
 *    /movie/{slug}                    फिल्म का पन्ना
 *    /series/{slug}                   वेब सीरीज़ का पन्ना
 *    /platform/{slug}                 OTT platform का पन्ना (?type=movie|tv, ?page=N)
 *    /platform/{slug}/hindi-movies    भाषा पेज (queries.sql #3) — -series भी
 *    /naya  /naya/{platform}          इस हफ़्ते क्या नया आया (#4, #7)
 *    /hata  /hata/{platform}          हाल में क्या हटा + अब कहाँ है (#5)
 *    /sitemap.xml                     सिर्फ़ वही पन्ने जिन पर असल में कुछ है (#9)
 *
 *  Hostinger पर लगाने के दो तरीक़े README.md में लिखे हैं। अगर आपने public/
 *  की फाइलें सीधे public_html में डाली हैं, तो नीचे सिर्फ़ यही एक रास्ता
 *  बदलना है — app फोल्डर (जहाँ config.php है) की ओर इशारा कीजिए।
 */
declare(strict_types=1);

require dirname(__DIR__) . '/site/web.php';   // ← app फोल्डर अलग जगह हो तो यह रास्ता बदलिए

require OTT_ROOT . '/site/layout.php';

// PHP के built-in टेस्ट सर्वर पर असली फाइलें (css वग़ैरह) सीधे मिलें
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($f)) {
        return false;
    }
}

// full-page cache — HIT हो तो यहीं से भेजकर exit (कोई DB query, कोई render नहीं);
// MISS पर output बफ़र शुरू, पेज बनने पर अपने-आप सहेज लेता है। admin/search/sitemap
// को cache_key_for_request() ख़ुद छोड़ देता है, इसलिए यहाँ शर्त नहीं चाहिए।
page_cache_serve_or_start();

$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
$path = rawurldecode($path);
$seg  = $path === '' ? [] : explode('/', $path);

// slug में सिर्फ़ यही अक्षर हो सकते हैं — बाक़ी सब सीधा 404
$slug_ok = static fn (string $s): bool =>
    $s !== '' && preg_match('/^[a-z0-9\p{Devanagari}-]{1,280}$/u', $s) === 1;

if ($seg === []) {
    require OTT_ROOT . '/site/pages/home.php';
    exit;
}

// खोज — /search?q=...
if (count($seg) === 1 && $seg[0] === 'search') {
    require OTT_ROOT . '/site/pages/search.php';
    exit;
}

// live suggest (JSON) — /suggest?q=...  (command-palette की खोज)
if (count($seg) === 1 && $seg[0] === 'suggest') {
    require OTT_ROOT . '/site/pages/suggest.php';
    exit;
}

// Browse + filters — /browse?type=&genre=&lang=&platform=&year=&offer=&sort=
if (count($seg) === 1 && $seg[0] === 'browse') {
    require OTT_ROOT . '/site/pages/browse.php';
    exit;
}

// admin dashboard — /admin  या गुप्त  /<admin_path>  (जैसे /skaler2015)।
// दोनों admin.php पर जाते हैं, जो password login माँगता है (session में याद रहता है)।
// गुप्त path config.php (git से बाहर) में रहता है — कोड में कभी नहीं। इस तरह
// सुरक्षा दो-परत: URL पता हो + password पता हो, तभी भीतर।
$adminPath = trim((string) ($CFG['admin_path'] ?? ''));
if ((count($seg) === 1 && $seg[0] === 'admin')
    || ($adminPath !== '' && count($seg) === 1 && hash_equals($adminPath, $seg[0]))) {
    require OTT_ROOT . '/site/pages/admin.php';
    exit;
}

if (count($seg) === 2 && ($seg[0] === 'movie' || $seg[0] === 'series') && $slug_ok($seg[1])) {
    $want_type = $seg[0] === 'series' ? 'tv' : 'movie';
    $want_slug = $seg[1];
    require OTT_ROOT . '/site/pages/title.php';
    exit;
}

if (count($seg) === 2 && $seg[0] === 'platform' && $slug_ok($seg[1])) {
    $want_slug = $seg[1];
    require OTT_ROOT . '/site/pages/provider.php';
    exit;
}

// genre hub पेज — /genre/{slug}  (Content Hub)
if (count($seg) === 2 && $seg[0] === 'genre' && $slug_ok($seg[1])) {
    $want_slug = $seg[1];
    require OTT_ROOT . '/site/pages/genre.php';
    exit;
}

// person hub पेज — /person/{id}[/{slug}]  (कलाकार/निर्देशक; id पक्का, slug SEO)
if (($seg[0] === 'person') && isset($seg[1]) && ctype_digit($seg[1])
    && (count($seg) === 2 || count($seg) === 3)) {
    $want_pid   = (int) $seg[1];
    $want_pslug = $seg[2] ?? null;
    require OTT_ROOT . '/site/pages/person.php';
    exit;
}

// भाषा पेज — /platform/netflix/hindi-movies या .../hindi-series
if (count($seg) === 3 && $seg[0] === 'platform' && $slug_ok($seg[1])
    && preg_match('/^([a-z]+)-(movies|series)$/', $seg[2], $m) === 1
    && isset(lang_slugs()[$m[1]])) {
    $want_slug = $seg[1];
    $want_lang = lang_slugs()[$m[1]];
    $lang_slug = $m[1];
    $want_type = $m[2] === 'series' ? 'tv' : 'movie';
    require OTT_ROOT . '/site/pages/lang.php';
    exit;
}

// changes पेज — /naya[/{platform}] और /hata[/{platform}]
if (($seg[0] === 'naya' || $seg[0] === 'hata')
    && (count($seg) === 1 || (count($seg) === 2 && $slug_ok($seg[1])))) {
    $want_mode = $seg[0] === 'naya' ? 'added' : 'removed';
    $want_slug = $seg[1] ?? null;
    require OTT_ROOT . '/site/pages/changes.php';
    exit;
}

if ($path === 'sitemap.xml') {
    require OTT_ROOT . '/site/pages/sitemap.php';
    exit;
}

// IndexNow की verify फ़ाइल — /<key>.txt  (key admin से बनकर sync_state में रहती है;
// Bing/Yandex इसे पढ़कर पक्का करते हैं कि सबमिट करने वाला साइट का असली मालिक है)
if (count($seg) === 1 && preg_match('/^[a-f0-9]{16,64}\.txt$/', $seg[0]) === 1) {
    $ink = (string) state_get($PDO, 'indexnow_key', '');
    if ($ink !== '' && hash_equals($ink . '.txt', $seg[0])) {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo $ink;
        exit;
    }
}

not_found();
