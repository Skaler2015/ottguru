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

// गुप्त admin path — config.php में admin_path सेट हो (जैसे 'skaler2015') तो
// सीधे /skaler2015 से admin खुलता है, लंबा ?k=token लिखे बिना। path खुद ही चाबी है,
// इसलिए वह config.php (git से बाहर) में रहता है — कोड में कभी नहीं।
$adminPath = trim((string) ($CFG['admin_path'] ?? ''));
if ($adminPath !== '' && count($seg) === 1 && hash_equals($adminPath, $seg[0])) {
    $GLOBALS['ADMIN_AUTHED'] = true;
    require OTT_ROOT . '/site/pages/admin.php';
    exit;
}

// admin dashboard — /admin?k=<run_token>  (token से सुरक्षित, noindex)
if (count($seg) === 1 && $seg[0] === 'admin') {
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

not_found();
