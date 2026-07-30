<?php
/**
 * ============================================================================
 *  migrate.php — schema.sql की सारी टेबलें सुरक्षित रूप से बनाता है
 *
 *  हर कथन "CREATE TABLE IF NOT EXISTS" है, इसलिए यह स्क्रिप्ट:
 *    • पहले से मौजूद टेबलों को छूती नहीं (कोई डेटा नहीं मिटता)
 *    • सिर्फ़ नई टेबलें जोड़ती है (genres, title_credits, title_videos … वग़ैरह)
 *    • जितनी बार भी चलाइए, कोई नुक़सान नहीं (idempotent)
 *
 *  install.php से हल्की है — यह TMDB को कॉल नहीं करती, providers नहीं छूती।
 *  नया मेटाडेटा फ़ीचर लगाने के बाद एक बार चलाइए, बस।
 *
 *  CLI :  php bin/migrate.php
 *  Web :  bin/migrate.php?k=आपका-run-token
 * ============================================================================
 */
require dirname(__DIR__) . '/lib/boot.php';

$t0 = ms_now();

$sqlFile = OTT_ROOT . '/schema.sql';
if (!is_readable($sqlFile)) {
    fail('schema.sql नहीं मिली।');
}

// पहले से कौन सी टेबलें हैं, गिन लीजिए
$before = array_map('current', all($PDO, 'SHOW TABLES'));

$sql = (string) file_get_contents($sqlFile);
$sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;   // टिप्पणियाँ हटाइए

$made = 0;
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    if ($stmt === '') {
        continue;
    }
    $PDO->exec($stmt);
    $made++;
}

$after = array_map('current', all($PDO, 'SHOW TABLES'));
$newTables = array_values(array_diff($after, $before));

logline("schema लागू ($made कथन)");
logline('कुल टेबलें अब: ' . count($after));
if ($newTables !== []) {
    logline('नई बनीं: ' . implode(', ', $newTables));
} else {
    logline('कोई नई टेबल नहीं बनी — सब पहले से मौजूद थीं।');
}
logline('migrate पूरा — ' . fmt_secs(ms_now() - $t0));
