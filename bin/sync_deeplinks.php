<?php
/**
 * ============================================================================
 *  sync_deeplinks.php — Streaming Availability से deep link + dub ऑडियो भरना
 *
 *  यह TMDB वाले sync को छूता नहीं। सिर्फ़ enrich करता है:
 *    • availability.watch_link  ← असली per-OTT deep link (सीधे उस मूवी पर)
 *    • provider_audio           ← "इस OTT पर कौन सी ऑडियो/dub"
 *
 *  नियम बरक़रार: यह availability की मौजूदगी या availability_changes (ख़ज़ाना)
 *  को कभी नहीं लिखता — सिर्फ़ मौजूदा (TMDB-पुष्ट) offers पर deep link चढ़ाता है।
 *
 *  key खाली हो तो चुपचाप बाहर (फ़ीचर बंद)। rate-limit के लिए per_run छोटा रखिए।
 *  cron:  रात में, अलग समय  →  30 5 * * *   (SA free tier कम है — धीरे भरिए)
 * ============================================================================
 */
require dirname(__DIR__) . '/lib/boot.php';
require OTT_ROOT . '/lib/sa.php';

if (!sa_enabled()) {
    logline('SA key सेट नहीं — deep-link फ़ीचर बंद। (config.php का sa.key भरिए)');
    exit(0);
}

$lock = lock_acquire('deeplinks');
if ($lock === false) {
    logline('पिछली deeplinks दौड़ अभी चल रही है — यह दौड़ छोड़ी गई।');
    exit(0);
}

/* provider_audio न हो तो schema.sql से खुद बना लें (self-heal, transaction से पहले) */
$hasPA = (int) scalar($PDO, "SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'provider_audio'") > 0;
if (!$hasPA) {
    logline('provider_audio नहीं मिली — schema.sql से बना रहे हैं…');
    try {
        $schema = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(OTT_ROOT . '/schema.sql')) ?? '';
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $st) {
            if ($st !== '') {
                $PDO->exec($st);
            }
        }
    } catch (Throwable $e) {
        logline('!! provider_audio नहीं बनी: ' . $e->getMessage());
    }
}

run_reap_stale($PDO);
$runId   = run_start($PDO, 'deeplinks');
$t0      = ms_now();
$maxS    = (int) $CFG['batch']['max_seconds'];
$limit   = (int) ($CFG['sa']['per_run'] ?? 40);
$country = strtoupper((string) $CFG['country']);

/* alias नक़्शा — SA का service नाम → हमारा provider_id */
$byNorm = [];
foreach (all($PDO, 'SELECT provider_id, alias_norm FROM provider_aliases') as $a) {
    $byNorm[$a['alias_norm']] = (int) $a['provider_id'];
}
$resolve = static function (string $name) use ($byNorm): ?int {
    $n = norm_name($name);
    if ($n !== '' && isset($byNorm[$n])) {
        return $byNorm[$n];
    }
    $b = norm_name(base_service_name($name));
    return $b !== '' && isset($byNorm[$b]) ? $byNorm[$b] : null;
};

/* कर्सर — id-चक्र। सिर्फ़ उन titles को जिन पर अभी कुछ उपलब्ध है (enrich करने लायक़) */
$cursor = (int) state_get($PDO, 'deeplink_cursor', 0);
$due = all($PDO, "
    SELECT DISTINCT t.id, t.tmdb_id, t.media_type
      FROM titles t
      JOIN availability a ON a.title_id = t.id AND a.is_current = 1
     WHERE t.id > ?
     ORDER BY t.id
     LIMIT $limit", [$cursor]);

if ($due === []) {
    state_set($PDO, 'deeplink_cursor', 0);   // चक्र पूरा → फिर शुरू से (ताज़ा deep links)
    logline('सारे titles एक चक्र में देख लिए — कर्सर रीसेट।');
    run_finish($PDO, $runId, 'done', ['titles' => 0], 'चक्र पूरा');
    lock_release($lock);
    exit(0);
}

$updLink = $PDO->prepare('UPDATE availability SET watch_link = ?
                           WHERE title_id = ? AND provider_id = ? AND is_current = 1');
$delPA   = $PDO->prepare('DELETE FROM provider_audio WHERE title_id = ?');
$insPA   = $PDO->prepare('INSERT IGNORE INTO provider_audio (title_id, provider_id, lang_code) VALUES (?, ?, ?)');

$seen = $linksSet = $audioRows = $unmatched = 0;
$stopMsg = null;

foreach ($due as $t) {
    if (ms_now() - $t0 > $maxS) {
        $stopMsg = 'समय सीमा — बाक़ी अगली दौड़';
        logline($stopMsg);
        break;
    }

    $tid = (int) $t['id'];
    $r   = sa_show((string) $t['media_type'], (int) $t['tmdb_id']);

    // key/subscription की गड़बड़ → रुक जाओ (हर title पर वही गलती दोहराना बेकार)
    if (in_array((int) $r['status'], [401, 403], true)) {
        $stopMsg = 'SA key/subscription गड़बड़ (HTTP ' . $r['status'] . '): ' . ($r['error'] ?? '');
        logline('!! ' . $stopMsg);
        alert($CFG, 'SA deep-link रुका', $stopMsg . "\nRapidAPI पर subscription/की जाँचिए।");
        break;
    }
    if ((int) $r['status'] === 429) {
        $stopMsg = 'SA rate-limit (429) — बाक़ी अगली दौड़';
        logline($stopMsg);
        break;   // इस title का कर्सर आगे नहीं बढ़ाते
    }

    $cursor = $tid;   // इसे देख लिया (सफल या 404) — आगे बढ़ो
    $seen++;

    if (!$r['ok']) {
        continue;   // 404 वग़ैरह — इस title पर SA के पास कुछ नहीं, कोई बात नहीं
    }

    $opts = sa_extract((array) $r['data'], $country);

    // provider_id → deep link, और → audio भाषाएँ
    $byPid = [];   // pid => ['link'=>?, 'aud'=>[lang=>1]]
    foreach ($opts as $o) {
        $pid = $resolve($o['name']);
        if ($pid === null) {
            $unmatched++;
            continue;
        }
        if (!isset($byPid[$pid])) {
            $byPid[$pid] = ['link' => null, 'aud' => []];
        }
        // flatrate/free के deep link को rent/buy पर प्राथमिकता (देखने का लिंक पहले)
        if ($byPid[$pid]['link'] === null || in_array($o['offer'], ['flatrate', 'free'], true)) {
            $byPid[$pid]['link'] = $o['link'];
        }
        foreach ($o['audios'] as $l) {
            $byPid[$pid]['aud'][$l] = 1;
        }
    }

    $delPA->execute([$tid]);   // इस title का audio ताज़ा
    foreach ($byPid as $pid => $info) {
        if ($info['link'] !== null) {
            $updLink->execute([$info['link'], $tid, $pid]);   // सिर्फ़ मौजूदा offer पर चढ़ेगा
            $linksSet += $updLink->rowCount();
        }
        foreach (array_keys($info['aud']) as $l) {
            $insPA->execute([$tid, $pid, $l]);
            $audioRows++;
        }
    }
}

state_set($PDO, 'deeplink_cursor', $cursor);

logline(sprintf(
    'देखे %d · deep-link चढ़े %d · audio पंक्तियाँ %d · अनजान service %d  ·  %s',
    $seen, $linksSet, $audioRows, $unmatched, fmt_secs(ms_now() - $t0)
));

run_finish($PDO, $runId, 'done', ['titles' => $seen], $stopMsg);
lock_release($lock);
