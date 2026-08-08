<?php
/**
 * ============================================================================
 *  sync_people.php — कलाकार/निर्देशक की bio TMDB /person API से भरना (Feature 8)
 *
 *  यह core sync (catalog/providers) को छूता नहीं — सिर्फ़ people table को enrich
 *  करता है: biography, birthday, deathday, place_of_birth, known_for।
 *  disposable डेटा (TMDB से; खोकर दोबारा भरा जा सकता है)।
 *
 *  resumable: सिर्फ़ वे people लेता है जिनका bio_checked अभी खाली है।
 *  throttled: हर कॉल के बीच sleep_ms; max_seconds के पास रुक जाता है।
 *  विफल कॉल → छोड़ देता है (bio_checked नहीं भरता, अगली दौड़ फिर कोशिश करेगी);
 *  404 (person नहीं) → bio_checked भरकर आगे बढ़ जाता है (दोबारा न अटके)।
 *
 *  cron (रात, अलग समय):  45 4 * * *   php /path/bin/sync_people.php
 * ============================================================================
 */
require dirname(__DIR__) . '/lib/boot.php';

$lock = lock_acquire('people');
if ($lock === false) {
    logline('पिछली people दौड़ अभी चल रही है — यह छोड़ी गई।');
    exit(0);
}

/* self-heal: people में bio columns न हों तो जोड़ दें (मौजूदा install के लिए) */
try {
    $have = array_map('strval', array_column(all($PDO,
        "SELECT COLUMN_NAME FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'people'"), 'COLUMN_NAME'));
    $have = array_map('strtolower', $have);
    $need = [
        'biography'      => 'TEXT NULL',
        'birthday'       => 'DATE NULL',
        'deathday'       => 'DATE NULL',
        'place_of_birth' => 'VARCHAR(200) NULL',
        'known_for'      => 'VARCHAR(60) NULL',
        'bio_checked'    => 'DATE NULL',
    ];
    foreach ($need as $col => $def) {
        if (!in_array($col, $have, true)) {
            $PDO->exec("ALTER TABLE people ADD COLUMN $col $def");
            logline("people.$col जोड़ा (self-heal)");
        }
    }
} catch (Throwable $e) {
    logline('!! people columns self-heal विफल: ' . $e->getMessage());
    lock_release($lock);
    exit(1);
}

$perRun   = (int) ($CFG['batch']['people_per_run'] ?? 150);
$sleepMs  = (int) ($CFG['batch']['sleep_ms'] ?? 120);
$maxSecs  = (int) ($CFG['batch']['max_seconds'] ?? 240);
$lang     = (string) ($CFG['language'] ?? 'en-US');
$t0       = ms_now();

// सिर्फ़ वे लोग जो किसी title के credit में हैं और जिनकी bio अभी नहीं भरी
$people = all($PDO, "
    SELECT p.id FROM people p
     WHERE p.bio_checked IS NULL
       AND EXISTS (SELECT 1 FROM title_credits tc WHERE tc.person_id = p.id)
     LIMIT $perRun");

if ($people === []) {
    logline('सब people की bio भर चुकी — कुछ due नहीं।');
    lock_release($lock);
    exit(0);
}

$ok = $fail = 0;
$upd = $PDO->prepare("UPDATE people SET biography=:b, birthday=:bd, deathday=:dd,
        place_of_birth=:pob, known_for=:kf, bio_checked=CURDATE() WHERE id=:id");

foreach ($people as $row) {
    if ((ms_now() - $t0) > ($maxSecs * 1000)) {
        logline('समय सीमा पास — रुक रहे हैं (बाक़ी अगली दौड़)।');
        break;
    }
    $id = (int) $row['id'];
    try {
        $d = tmdb_get('/person/' . $id, ['language' => $lang]);
    } catch (Throwable $e) {
        $fail++;
        usleep($sleepMs * 1000);
        continue;   // transient — bio_checked नहीं भरा, अगली दौड़ फिर कोशिश
    }
    // 404/खाली — मान्य person नहीं; दोबारा न अटके इसलिए bio_checked भर देते हैं
    $name = trim((string) ($d['name'] ?? ''));
    $upd->execute([
        ':b'   => nz(trim((string) ($d['biography'] ?? ''))),
        ':bd'  => nz((string) ($d['birthday'] ?? '')),
        ':dd'  => nz((string) ($d['deathday'] ?? '')),
        ':pob' => nz(mb_substr(trim((string) ($d['place_of_birth'] ?? '')), 0, 200, 'UTF-8')),
        ':kf'  => nz(mb_substr(trim((string) ($d['known_for_department'] ?? '')), 0, 60, 'UTF-8')),
        ':id'  => $id,
    ]);
    $name !== '' ? $ok++ : $fail++;
    usleep($sleepMs * 1000);
}

$remain = (int) scalar($PDO, "SELECT COUNT(*) FROM people p WHERE p.bio_checked IS NULL
    AND EXISTS (SELECT 1 FROM title_credits tc WHERE tc.person_id = p.id)");
logline("people bio: भरे $ok · विफल $fail · बाक़ी $remain · " . fmt_secs(ms_now() - $t0));

// bio भरी → person पेज बदले, page-cache साफ़ कर दो
if ($ok > 0 && function_exists('cache_clear_all')) {
    $cleared = cache_clear_all();
    logline("page-cache साफ़: {$cleared} फ़ाइलें");
}

lock_release($lock);
