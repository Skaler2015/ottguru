<?php
/**
 * ============================================================================
 *  sync_providers.php — दिल
 *
 *  हर title के लिए एक ही API कॉल में मेटाडेटा + India के providers लाता है
 *  (append_to_response), पुराने से तुलना करता है, और फ़र्क़
 *  availability_changes में दर्ज करता है।
 *
 *  तीन नियम जो इसमें गुँथे हैं:
 *   1. कॉल विफल = "पता नहीं"।  कभी "डेटा नहीं है" नहीं।
 *      विफल title का providers_last_success नहीं बदलता, कोई diff नहीं लिखा जाता।
 *   2. diff सिर्फ़ सफल कॉल पर।
 *   3. सुरक्षा ब्रेक — एक ही दौड़ में बहुत ज़्यादा "हटना" दिखे तो
 *      कुछ भी लिखे बिना दौड़ रोक दी जाती है और मेल आता है।
 *
 *  cron:  रोज़ कई बार  →  15 1,2,3,4 * * *
 * ============================================================================
 */
require dirname(__DIR__) . '/lib/boot.php';

$lock = lock_acquire('providers');
if ($lock === false) {
    logline('पिछली providers दौड़ अभी चल रही है — यह दौड़ छोड़ी गई।');
    exit(0);
}

run_reap_stale($PDO);
$runId   = run_start($PDO, 'providers');
$t0      = ms_now();
$maxS    = (int) $CFG['batch']['max_seconds'];
$limit   = (int) $CFG['batch']['provider_titles_per_run'];
$country = strtoupper((string) $CFG['country']);

/* ------------------------------------------------------------------
   1. alias नक़्शा — 'Amazon Prime Video with Ads' → prime, ads tier
------------------------------------------------------------------ */
$byTmdb = [];
$byNorm = [];
foreach (all($PDO, 'SELECT provider_id, alias_norm, tmdb_provider_id, implies_ads FROM provider_aliases') as $a) {
    $row = ['pid' => (int) $a['provider_id'], 'ads' => (int) $a['implies_ads'] === 1];
    if ($a['tmdb_provider_id'] !== null) {
        $byTmdb[(int) $a['tmdb_provider_id']] = $row;
    }
    $byNorm[$a['alias_norm']] = $row;
}
if ($byNorm === []) {
    run_finish($PDO, $runId, 'failed', [], 'alias टेबल ख़ाली — पहले install.php चलाइए');
    lock_release($lock);
    fail('provider_aliases ख़ाली है। पहले bin/install.php चलाइए।');
}
logline(count($byNorm) . ' alias लोड हुए');

$unknown = [];   // जो providers पहचाने नहीं गए — बाद में alias जोड़ने के लिए

function resolve_provider(array $p, array $byTmdb, array $byNorm, array &$unknown): ?array
{
    $tid  = (int) ($p['provider_id'] ?? 0);
    $name = (string) ($p['provider_name'] ?? '');
    if ($tid !== 0 && isset($byTmdb[$tid])) {
        return $byTmdb[$tid];
    }
    $n = norm_name($name);
    if ($n !== '' && isset($byNorm[$n])) {
        return $byNorm[$n];
    }
    // 'X with Ads' अनजाना हो तो मूल 'X' पर गिराने की कोशिश
    $b = norm_name(base_service_name($name));
    if ($b !== '' && isset($byNorm[$b])) {
        $r = $byNorm[$b];
        $r['ads'] = $r['ads'] || name_implies_ads($name);
        return $r;
    }
    if ($name !== '') {
        $unknown[$name] = ($unknown[$name] ?? 0) + 1;
    }
    return null;
}

/* ------------------------------------------------------------------
   2. कौन से titles जाँचने हैं — tier के हिसाब से
------------------------------------------------------------------ */
$h1 = (int) $CFG['poll_hours'][1];
$h2 = (int) $CFG['poll_hours'][2];
$h3 = (int) $CFG['poll_hours'][3];

$due = all($PDO, "
    SELECT id, tmdb_id, media_type, tier, providers_last_success
      FROM titles
     WHERE (tier = 1 AND (providers_last_success IS NULL OR providers_last_success < NOW() - INTERVAL $h1 HOUR))
        OR (tier = 2 AND (providers_last_success IS NULL OR providers_last_success < NOW() - INTERVAL $h2 HOUR))
        OR (tier = 3 AND (providers_last_success IS NULL OR providers_last_success < NOW() - INTERVAL $h3 HOUR))
     -- ORDER BY जान-बूझकर सिर्फ़ इन दो कॉलम पर है: यही ix_poll(tier, providers_last_success)
     -- का क्रम है, इसलिए MySQL filesort नहीं करता. MySQL में NULL अपने-आप पहले आते हैं,
     -- यानी 'कभी नहीं जाँचे गए' titles को प्राथमिकता मिल जाती है.
     -- popularity यहाँ मत जोड़िए — उससे index टूट जाएगा.
     ORDER BY tier ASC, providers_last_success ASC
     LIMIT $limit
");

if ($due === []) {
    logline('कोई title बाक़ी नहीं — सब अपने समय पर जाँचे जा चुके हैं।');
    run_finish($PDO, $runId, 'done', ['titles' => 0], 'कुछ due नहीं था');
    lock_release($lock);
    exit(0);
}
logline(count($due) . ' titles जाँचने हैं');

/* ------------------------------------------------------------------
   3. सब कुछ पहले मेमोरी में जमा कीजिए — लिखना बाद में, जाँच के बाद
------------------------------------------------------------------ */
$OFFERS = ['flatrate', 'ads', 'free', 'rent', 'buy'];

$pending      = [];   // titleId => ['add'=>[], 'keep'=>[], 'remove'=>[], 'detail'=>[], 'link'=>?]
$attempted    = [];   // हर title जिसकी कोशिश हुई (सफल या विफल)
$failed       = [];
$titlesOk     = 0;
$titlesLosing = 0;
$addCount     = 0;
$remCount     = 0;
$stoppedEarly = false;

$selCurrent = $PDO->prepare(
    'SELECT provider_id, offer_type FROM availability
      WHERE title_id = ? AND country = ? AND is_current = 1'
);

foreach ($due as $t) {
    if (ms_now() - $t0 > $maxS) {
        $stoppedEarly = true;
        logline('समय सीमा — बाक़ी titles अगली दौड़ में');
        break;
    }

    $tid        = (int) $t['id'];
    $attempted[] = $tid;

    $r = tmdb_title_bundle((string) $t['media_type'], (int) $t['tmdb_id']);

    if (!$r['ok']) {
        // नियम 1 — यह "डेटा नहीं है" नहीं है। कुछ मत बदलो।
        $failed[$tid] = $r['error'] ?? '?';
        logline("  विफल #$tid (tmdb {$t['tmdb_id']}): {$failed[$tid]}");
        continue;
    }

    $d        = $r['data'];
    $titlesOk++;

    /* ---- मेटाडेटा ---- */
    $rd = ymd((string) ($d['release_date'] ?? $d['first_air_date'] ?? ''));
    $runtime = null;
    if (isset($d['runtime'])) {
        $runtime = (int) $d['runtime'];
    } elseif (!empty($d['episode_run_time'][0])) {
        $runtime = (int) $d['episode_run_time'][0];
    }
    $langs = [];
    foreach (($d['spoken_languages'] ?? []) as $sl) {
        $c = nz((string) ($sl['iso_639_1'] ?? ''));
        if ($c !== null) {
            $langs[] = $c;
        }
    }
    $pending[$tid]['detail'] = [
        'title'    => mb_substr((string) ($d['title'] ?? $d['name'] ?? ''), 0, 255),
        'otitle'   => mb_substr((string) ($d['original_title'] ?? $d['original_name'] ?? ''), 0, 255) ?: null,
        'lang'     => nz((string) ($d['original_language'] ?? '')),
        'overview' => (string) ($d['overview'] ?? ''),
        'rd'       => $rd,
        'ry'       => $rd !== null ? (int) substr($rd, 0, 4) : null,
        'runtime'  => $runtime,
        'status'   => nz((string) ($d['status'] ?? '')),
        'poster'   => nz((string) ($d['poster_path'] ?? '')),
        'backdrop' => nz((string) ($d['backdrop_path'] ?? '')),
        'pop'      => (float) ($d['popularity'] ?? 0),
        'va'       => (float) ($d['vote_average'] ?? 0),
        'vc'       => (int) ($d['vote_count'] ?? 0),
        'imdb'     => nz((string) ($d['external_ids']['imdb_id'] ?? '')),
        'langs'    => array_values(array_unique($langs)),
        'origlang' => nz((string) ($d['original_language'] ?? '')),
    ];

    /* ---- providers ---- */
    $block = $d['watch/providers']['results'][$country] ?? null;
    $pending[$tid]['link'] = $block['link'] ?? null;

    $desired = [];   // "pid|offer" => raw name
    if (is_array($block)) {
        foreach ($OFFERS as $offer) {
            foreach (($block[$offer] ?? []) as $p) {
                $res = resolve_provider($p, $byTmdb, $byNorm, $unknown);
                if ($res === null) {
                    continue;
                }
                // ad-supported tier flatrate में आया हो तो उसे ads मानिए
                $eff = ($offer === 'flatrate' && $res['ads']) ? 'ads' : $offer;
                $desired[$res['pid'] . '|' . $eff] = (string) ($p['provider_name'] ?? '');
            }
        }
    }

    /* ---- तुलना ---- */
    $selCurrent->execute([$tid, $country]);
    $current = [];
    foreach ($selCurrent->fetchAll() as $c) {
        $current[$c['provider_id'] . '|' . $c['offer_type']] = true;
    }

    $add    = array_diff_key($desired, $current);
    $remove = array_diff_key($current, $desired);

    $pending[$tid]['desired'] = $desired;
    $pending[$tid]['add']     = $add;
    $pending[$tid]['remove']  = array_keys($remove);

    $addCount += count($add);
    $remCount += count($remove);
    if ($remove !== []) {
        $titlesLosing++;
    }
}

logline(sprintf(
    'कोशिश %d · सफल %d · विफल %d · जुड़ेंगे %d · हटेंगे %d (%d titles से)',
    count($attempted),
    $titlesOk,
    count($failed),
    $addCount,
    $remCount,
    $titlesLosing
));

/* ------------------------------------------------------------------
   4. सुरक्षा ब्रेक — नियम 3
------------------------------------------------------------------ */
$halt = safety_check($CFG, $titlesOk, $titlesLosing);
if ($halt !== null) {
    logline('!! ' . $halt);

    // कोशिश दर्ज कीजिए, पर last_success और diff कुछ नहीं
    if ($attempted !== []) {
        $in = implode(',', array_fill(0, count($attempted), '?'));
        q($PDO, "UPDATE titles SET providers_last_checked = NOW() WHERE id IN ($in)", $attempted);
    }

    run_finish($PDO, $runId, 'halted', ['titles' => count($attempted)], $halt);
    alert($CFG, 'sync रोका गया', $halt . "\n\nrun #$runId\n"
        . "जाँचिए: TMDB key, region सेटिंग, और status.php\n"
        . "जब तक ठीक न हो, ये titles दोबारा जाँचे जाते रहेंगे और कोई बदलाव दर्ज नहीं होगा।");
    lock_release($lock);
    exit(2);
}

/* ------------------------------------------------------------------
   5. अब लिखिए — एक transaction में
------------------------------------------------------------------ */
$upAvail = $PDO->prepare(
    'INSERT INTO availability
       (title_id, provider_id, offer_type, country, raw_provider_name, watch_link,
        first_seen, last_seen, is_current)
     VALUES (:tid, :pid, :offer, :country, :raw, :link, CURDATE(), CURDATE(), 1)
     ON DUPLICATE KEY UPDATE
        raw_provider_name = VALUES(raw_provider_name),
        watch_link        = VALUES(watch_link),
        last_seen         = CURDATE(),
        first_seen        = IF(is_current = 0, CURDATE(), first_seen),
        is_current        = 1'
);
// ध्यान: ऊपर first_seen की पंक्ति is_current से पहले है — क्रम मायने रखता है

$offAvail = $PDO->prepare(
    'UPDATE availability SET is_current = 0
      WHERE title_id = ? AND provider_id = ? AND offer_type = ? AND country = ?'
);

$insChange = $PDO->prepare(
    'INSERT INTO availability_changes
       (title_id, provider_id, offer_type, country, change_type, changed_on, run_id)
     VALUES (:tid, :pid, :offer, :country, :ct, CURDATE(), :run)'
);

$updDetail = $PDO->prepare(
    'UPDATE titles SET
        title = :title,
        original_title = COALESCE(:otitle, original_title),
        original_language = COALESCE(:lang, original_language),
        overview = COALESCE(NULLIF(:ov, ""), overview),
        release_date = COALESCE(:rd, release_date),
        release_year = COALESCE(:ry, release_year),
        runtime = COALESCE(:runtime, runtime),
        status = COALESCE(:status, status),
        poster_path = COALESCE(:poster, poster_path),
        backdrop_path = COALESCE(:backdrop, backdrop_path),
        popularity = :pop, vote_average = :va, vote_count = :vc,
        imdb_id = COALESCE(:imdb, imdb_id),
        tier = :tier,
        detail_last_success = NOW(),
        providers_last_checked = NOW(),
        providers_last_success = NOW(),
        providers_fail_streak = 0
      WHERE id = :id'
);

$insLang = $PDO->prepare(
    'INSERT IGNORE INTO title_languages (title_id, lang_code, kind) VALUES (?, ?, ?)'
);

$PDO->beginTransaction();
try {
    foreach ($pending as $tid => $p) {

        // (क) जो हैं/नए हैं — सबका last_seen ताज़ा कीजिए
        foreach (($p['desired'] ?? []) as $keyStr => $rawName) {
            [$pid, $offer] = explode('|', $keyStr);
            $upAvail->execute([
                ':tid'     => $tid,
                ':pid'     => (int) $pid,
                ':offer'   => $offer,
                ':country' => $country,
                ':raw'     => $rawName !== '' ? mb_substr($rawName, 0, 160) : null,
                ':link'    => $p['link'] !== null ? mb_substr((string) $p['link'], 0, 500) : null,
            ]);
        }

        // (ख) नए — इतिहास में दर्ज
        foreach (array_keys($p['add'] ?? []) as $keyStr) {
            [$pid, $offer] = explode('|', $keyStr);
            $insChange->execute([
                ':tid' => $tid, ':pid' => (int) $pid, ':offer' => $offer,
                ':country' => $country, ':ct' => 'added', ':run' => $runId,
            ]);
        }

        // (ग) हटे — is_current बंद + इतिहास में दर्ज
        foreach (($p['remove'] ?? []) as $keyStr) {
            [$pid, $offer] = explode('|', $keyStr);
            $offAvail->execute([$tid, (int) $pid, $offer, $country]);
            $insChange->execute([
                ':tid' => $tid, ':pid' => (int) $pid, ':offer' => $offer,
                ':country' => $country, ':ct' => 'removed', ':run' => $runId,
            ]);
        }

        // (घ) मेटाडेटा + "सफल जाँच" की मुहर
        $de = $p['detail'];
        $updDetail->execute([
            ':title' => $de['title'], ':otitle' => $de['otitle'], ':lang' => $de['lang'],
            ':ov' => $de['overview'], ':rd' => $de['rd'], ':ry' => $de['ry'],
            ':runtime' => $de['runtime'], ':status' => $de['status'],
            ':poster' => $de['poster'], ':backdrop' => $de['backdrop'],
            ':pop' => $de['pop'], ':va' => $de['va'], ':vc' => $de['vc'],
            ':imdb' => $de['imdb'],
            ':tier' => compute_tier((float) $de['pop'], $de['rd']),
            ':id' => $tid,
        ]);

        foreach ($de['langs'] as $lc) {
            $insLang->execute([$tid, $lc, 'spoken']);
        }
        if ($de['origlang'] !== null) {
            $insLang->execute([$tid, $de['origlang'], 'original']);
        }
    }

    // (ङ) विफल titles — कोशिश दर्ज, सफलता नहीं
    foreach ($failed as $tid => $err) {
        q(
            $PDO,
            'UPDATE titles SET providers_last_checked = NOW(),
                    providers_fail_streak = providers_fail_streak + 1
              WHERE id = ?',
            [$tid]
        );
    }

    $PDO->commit();
} catch (Throwable $e) {
    $PDO->rollBack();
    run_finish($PDO, $runId, 'failed', ['titles' => count($attempted)], 'लिखते समय गड़बड़: ' . $e->getMessage());
    lock_release($lock);
    fail('DB में लिखते समय गड़बड़ (कुछ भी आधा-अधूरा नहीं लिखा गया): ' . $e->getMessage());
}

/* ------------------------------------------------------------------
   6. अनजाने providers — खुद बता देता है कि alias कहाँ जोड़ना है
------------------------------------------------------------------ */
if ($unknown !== []) {
    arsort($unknown);
    $old = state_get($PDO, 'unknown_providers', []);
    if (!is_array($old)) {
        $old = [];
    }
    foreach ($unknown as $nm => $cnt) {
        $old[$nm] = ($old[$nm] ?? 0) + $cnt;
    }
    state_set($PDO, 'unknown_providers', $old);
    logline('अनजाने providers (alias जोड़िए): ' . implode(', ', array_slice(array_keys($unknown), 0, 10)));
}

/* ------------------------------------------------------------------ रिपोर्ट */
$note = $stoppedEarly ? 'समय सीमा पर रुका' : null;
if ($failed !== []) {
    $note = trim(($note ?? '') . ' · विफल ' . count($failed) . ' titles');
}

logline(sprintf(
    'लिखा गया — जुड़े %d, हटे %d  ·  %s',
    $addCount,
    $remCount,
    fmt_secs(ms_now() - $t0)
));

run_finish($PDO, $runId, 'done', [
    'titles'  => count($attempted),
    'added'   => $addCount,
    'removed' => $remCount,
], $note);

lock_release($lock);
