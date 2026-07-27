<?php
/**
 * ============================================================================
 *  OTT गुरु — वेबसाइट का front controller
 *  सारे रास्ते यहीं से बँटते हैं:
 *    /                     होमपेज
 *    /movie/{slug}         फिल्म का पन्ना
 *    /series/{slug}        वेब सीरीज़ का पन्ना
 *    /platform/{slug}      OTT platform का पन्ना (?type=movie|tv, ?page=N)
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

not_found();
