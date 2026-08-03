<?php
/**
 * हर स्क्रिप्ट की पहली लाइन। config लोड करता है, पहुँच जाँचता है,
 * DB जोड़ता है और लॉगिंग तैयार करता है।
 */
declare(strict_types=1);

define('OTT_ROOT', dirname(__DIR__));
define('OTT_IS_CLI', PHP_SAPI === 'cli');

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Kolkata');
set_time_limit(0);           // CLI पर असर करता है; web पर होस्ट की सीमा चलेगी
ignore_user_abort(true);     // ब्राउज़र बंद हो जाए तो भी दौड़ पूरी हो

$CFG = require OTT_ROOT . '/config.php';

// ---------------------------------------------------------------- लॉग
if (!is_dir($CFG['log_dir'])) {
    @mkdir($CFG['log_dir'], 0750, true);
}

function logline(string $msg): void
{
    global $CFG;
    $line = date('Y-m-d H:i:s') . '  ' . $msg;
    @file_put_contents(
        rtrim($CFG['log_dir'], '/') . '/sync-' . date('Y-m') . '.log',
        $line . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    if (OTT_IS_CLI) {
        echo $line . PHP_EOL;
    } else {
        echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "<br>\n";
        @ob_flush();
        @flush();
    }
}

function fail(string $msg, int $code = 500): never
{
    if (!OTT_IS_CLI) {
        http_response_code($code);
    }
    logline('FATAL: ' . $msg);
    exit(1);
}

// ---------------------------------------------------------------- पहुँच
// CLI (cron) खुला है। ब्राउज़र से चलाने पर टोकन ज़रूरी।
if (!OTT_IS_CLI) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    // status.php जैसे पन्ने अपना पूरा HTML खुद छापते हैं — उनके लिए यह शेल नहीं
    if (empty($GLOBALS['__RAW_OUTPUT'])) {
        echo "<!doctype html><meta charset=utf-8><title>OTT Guru sync</title>"
           . "<style>body{font:13px/1.7 ui-monospace,monospace;background:#0e1a21;color:#cfe3ee;padding:18px}</style>\n";
    }

    $token = (string) ($CFG['run_token'] ?? '');
    if ($token === '' || $token === 'बदल-कर-कुछ-लंबा-लिखिए') {
        fail('config.php में run_token सेट नहीं है — ब्राउज़र से चलाना बंद है।', 403);
    }
    if (!isset($_GET['k']) || !hash_equals($token, (string) $_GET['k'])) {
        fail('गलत या गुम टोकन।', 403);
    }
}

// ---------------------------------------------------------------- PHP त्रुटियाँ
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

require OTT_ROOT . '/lib/util.php';
require OTT_ROOT . '/lib/db.php';
require OTT_ROOT . '/lib/http.php';
require OTT_ROOT . '/lib/tmdb.php';
require OTT_ROOT . '/lib/run.php';
require OTT_ROOT . '/lib/cache.php';   // sync के अंत में cache_clear_all() के लिए

$PDO = db_connect($CFG['db']);
