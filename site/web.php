<?php
/**
 * वेबसाइट का bootstrap — lib/boot.php से जान-बूझकर अलग।
 * boot.php sync स्क्रिप्टों के लिए है (token-जाँच, log-shell);
 * ये public पन्ने हैं — यहाँ वो सब नहीं चाहिए।
 */
declare(strict_types=1);

define('OTT_ROOT', dirname(__DIR__));

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Kolkata');

$CFG = require OTT_ROOT . '/config.php';

/** DB न मिले तो पूरा stack-trace यूज़र को नहीं दिखाना — सादा 500 */
function fail(string $msg, int $code = 500): never
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>OTT Guru</title>'
       . '<p style="font-family:sans-serif;padding:40px">साइट में अभी कुछ गड़बड़ है — थोड़ी देर बाद आइए।</p>';
    error_log('ottguru web: ' . $msg);
    exit(1);
}

// ---------------------------------------------------------------- UI की भाषा
// default अंग्रेज़ी; ?lang=hi से हिंदी। पसंद cookie में साल भर याद रहती है।
$__lang = $_GET['lang'] ?? ($_COOKIE['ottg_lang'] ?? 'en');
$__lang = $__lang === 'hi' ? 'hi' : 'en';
define('OTT_LANG', $__lang);
if (isset($_GET['lang'])) {
    @setcookie('ottg_lang', $__lang, time() + 31536000, '/');
}

require OTT_ROOT . '/lib/util.php';
require OTT_ROOT . '/lib/db.php';
require OTT_ROOT . '/site/i18n.php';
require OTT_ROOT . '/site/helpers.php';

$PDO = db_connect($CFG['db']);
