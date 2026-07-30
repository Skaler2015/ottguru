<?php
/**
 * ============================================================================
 *  sync_catalog.php — नए titles खोजना
 *
 *  पूरा TMDB कैटलॉग नहीं खींचता। सिर्फ़ वो titles जो India में किसी OTT पर
 *  उपलब्ध हैं — इसलिए DB छोटी और काम की रहती है।
 *
 *  हर दौड़ थोड़े पेज करती है और कर्सर सहेज देती है, इसलिए PHP का
 *  max_execution_time कभी नहीं मारता।
 *
 *  cron:  हफ़्ते में 2 बार  →  0 3 * * 1,4
 * ============================================================================
 */
require dirname(__DIR__) . '/lib/boot.php';

$lock = lock_acquire('catalog');
if ($lock === false) {
    logline('पिछली catalog दौड़ अभी चल रही है — यह दौड़ छोड़ी गई।');
    exit(0);
}

run_reap_stale($PDO);
$runId = run_start($PDO, 'catalog');
$t0    = ms_now();
$maxS  = (int) $CFG['batch']['max_seconds'];
$pages = (int) $CFG['batch']['catalog_pages_per_run'];

$MT = ['movie', 'tv'];

// भारत-पहले: इन भाषाओं का कंटेंट सीधे भाषा से खोजा जाता है (provider इंतज़ार बिना)
$LANGS = ['hi', 'ta', 'te', 'ml', 'kn', 'bn', 'mr', 'pa', 'gu'];

$provIds = array_map('intval', array_column(all(
    $PDO,
    'SELECT tmdb_provider_id FROM providers
      WHERE is_active = 1 AND tmdb_provider_id IS NOT NULL
      ORDER BY display_priority, id'
), 'tmdb_provider_id'));

if ($provIds === []) {
    run_finish($PDO, $runId, 'failed', [], 'providers टेबल ख़ाली है — पहले install.php चलाइए');
    lock_release($lock);
    fail('providers टेबल ख़ाली है। पहले bin/install.php चलाइए।');
}

// कर्सर में दो अलग उप-कर्सर: 'lang' (भारतीय भाषा) और 'prov' (provider sweep)।
// phase तय करता है अभी कौन चल रहा है। पुराना सपाट कर्सर अपने-आप 'prov' में मिल जाता है
// (Netflix की प्रगति नहीं टूटती), और नया 'lang' phase शून्य से शुरू होकर पहले चलता है।
$cur   = state_get($PDO, 'catalog_cursor', []);
$phase = ($cur['phase'] ?? 'lang') === 'prov' ? 'prov' : 'lang';
$langC = is_array($cur['lang'] ?? null) ? $cur['lang'] : ['mt' => 0, 'li' => 0, 'page' => 1];
$provC = is_array($cur['prov'] ?? null) ? $cur['prov']
       : ['mt' => (int) ($cur['mt'] ?? 0), 'pi' => (int) ($cur['pi'] ?? 0), 'page' => (int) ($cur['page'] ?? 1)];
// सीमा के अंदर रखिए (list बदल जाए तो भी सुरक्षित)
$langC['mt'] = (int) $langC['mt'] % count($MT);
$langC['li'] = (int) $langC['li'] % count($LANGS);
$langC['page'] = max(1, (int) $langC['page']);
$provC['mt'] = (int) $provC['mt'] % count($MT);
$provC['pi'] = count($provIds) > 0 ? (int) $provC['pi'] % count($provIds) : 0;
$provC['page'] = max(1, (int) $provC['page']);

$seen = 0;
$new  = 0;
$status = 'done';
$note = null;

$selExist = $PDO->prepare('SELECT id FROM titles WHERE media_type = ? AND tmdb_id = ?');
$selSlug  = $PDO->prepare('SELECT 1 FROM titles WHERE slug = ?');

$insTitle = $PDO->prepare(
    'INSERT INTO titles
       (tmdb_id, media_type, slug, title, original_title, original_language, overview,
        release_date, release_year, poster_path, backdrop_path, popularity,
        vote_average, vote_count, is_adult, tier)
     VALUES
       (:tmdb, :mt, :slug, :title, :otitle, :lang, :ov,
        :rd, :ry, :poster, :backdrop, :pop, :va, :vc, :adult, :tier)'
);

// slug कभी नहीं बदलता — SEO के लिए यह ज़रूरी है
$updTitle = $PDO->prepare(
    'UPDATE titles SET title = :title, original_title = :otitle, original_language = :lang,
            overview = COALESCE(NULLIF(:ov, ""), overview),
            release_date = COALESCE(:rd, release_date),
            release_year = COALESCE(:ry, release_year),
            poster_path = COALESCE(:poster, poster_path),
            backdrop_path = COALESCE(:backdrop, backdrop_path),
            popularity = :pop, vote_average = :va, vote_count = :vc, tier = :tier
      WHERE id = :id'
);

for ($n = 0; $n < $pages; $n++) {

    if (ms_now() - $t0 > $maxS) {
        $note = 'समय सीमा पर रुका — कर्सर सहेज लिया';
        logline($note);
        break;
    }

    if ($phase === 'lang') {
        $mt   = $MT[$langC['mt']];
        $lang = $LANGS[$langC['li']];
        $page = $langC['page'];
        $r    = tmdb_discover_lang($mt, $lang, $page);
        $srcLabel = "भाषा $lang";
    } else {
        $mt   = $MT[$provC['mt']];
        $pid  = $provIds[$provC['pi']];
        $page = $provC['page'];
        $r    = tmdb_discover($mt, $pid, $page);
        $srcLabel = "provider #$pid";
    }

    if (!$r['ok']) {
        // कर्सर आगे नहीं बढ़ाते — अगली दौड़ यहीं से दोबारा कोशिश करेगी
        $status = 'failed';
        $note = "discover विफल ($mt, $srcLabel, पेज $page): " . ($r['error'] ?? '?');
        logline($note);
        break;
    }

    $results = $r['data']['results'] ?? [];
    $total   = min((int) ($r['data']['total_pages'] ?? 1), 500);   // TMDB 500 पेज से आगे नहीं देता

    foreach ($results as $it) {
        $tmdbId = (int) ($it['id'] ?? 0);
        if ($tmdbId === 0) {
            continue;
        }
        $seen++;

        $title  = (string) ($it['title'] ?? $it['name'] ?? '');
        $otitle = (string) ($it['original_title'] ?? $it['original_name'] ?? '');
        if ($title === '') {
            continue;
        }
        $rd   = ymd((string) ($it['release_date'] ?? $it['first_air_date'] ?? ''));
        $ry   = $rd !== null ? (int) substr($rd, 0, 4) : null;
        $pop  = (float) ($it['popularity'] ?? 0);
        $tier = compute_tier($pop, $rd);

        $selExist->execute([$mt, $tmdbId]);
        $existingId = $selExist->fetchColumn();

        $common = [
            ':title'    => mb_substr($title, 0, 255),
            ':otitle'   => mb_substr($otitle, 0, 255) ?: null,
            ':lang'     => nz((string) ($it['original_language'] ?? '')),
            ':ov'       => (string) ($it['overview'] ?? ''),
            ':rd'       => $rd,
            ':ry'       => $ry,
            ':poster'   => nz((string) ($it['poster_path'] ?? '')),
            ':backdrop' => nz((string) ($it['backdrop_path'] ?? '')),
            ':pop'      => $pop,
            ':va'       => (float) ($it['vote_average'] ?? 0),
            ':vc'       => (int) ($it['vote_count'] ?? 0),
            ':tier'     => $tier,
        ];

        if ($existingId !== false) {
            $updTitle->execute($common + [':id' => (int) $existingId]);
            continue;
        }

        // नया title — अनोखा slug बनाइए
        $base = slugify($title) . ($ry !== null ? '-' . $ry : '');
        $slug = $base;
        $selSlug->execute([$slug]);
        if ($selSlug->fetchColumn() !== false) {
            $slug = mb_substr($base, 0, 200) . '-' . $tmdbId;
        }

        try {
            $insTitle->execute($common + [
                ':tmdb'  => $tmdbId,
                ':mt'    => $mt,
                ':slug'  => $slug,
                ':adult' => !empty($it['adult']) ? 1 : 0,
            ]);
            $new++;
        } catch (PDOException $e) {
            // दो समांतर दौड़ों की टकराहट — छोड़कर आगे बढ़िए
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    logline(sprintf(
        '%s · %s · पेज %d/%d · %d नतीजे (नए %d)',
        $mt,
        $srcLabel,
        $page,
        $total,
        count($results),
        $new
    ));

    /* ---------------- कर्सर आगे ----------------
       lang phase पहले पूरा होता है (हिंदी/regional सबसे पहले), फिर prov sweep,
       फिर वापस lang (नया कंटेंट ताज़ा रखने के लिए)। दोनों की प्रगति अलग सहेजी जाती है।
       क्रम: page → mt → li — यानी हर भाषा में पहले फिल्में फिर सीरीज़, फिर अगली भाषा।
       (पहले सारी भाषाओं की फिल्में होती थीं, सीरीज़ बहुत देर से — भारत में web series
        OTT की जान हैं, इसलिए अब हर भाषा में movie+tv दोनों साथ-साथ आते हैं।) */
    if ($phase === 'lang') {
        $langC['page']++;
        if ($langC['page'] > max(1, $total)) {
            $langC['page'] = 1;
            $langC['mt']++;                     // उसी भाषा का अगला media type (movie → tv)
            if ($langC['mt'] >= count($MT)) {
                $langC['mt'] = 0;
                $langC['li']++;                 // दोनों हो गए → अगली भाषा
                if ($langC['li'] >= count($LANGS)) {
                    $langC['li'] = 0;
                    $phase = 'prov';        // भारतीय भाषाएँ पूरी → अब provider sweep
                    logline('भारतीय-भाषा चक्र पूरा → provider sweep शुरू');
                }
            }
        }
    } else {
        $provC['page']++;
        if ($provC['page'] > max(1, $total)) {
            $provC['page'] = 1;
            $provC['pi']++;
            if ($provC['pi'] >= count($provIds)) {
                $provC['pi'] = 0;
                $provC['mt']++;
                if ($provC['mt'] >= count($MT)) {
                    $provC['mt'] = 0;
                    $phase = 'lang';        // पूरा चक्र ख़त्म → फिर भारतीय भाषाएँ ताज़ा करो
                    logline('provider sweep पूरा → भारतीय-भाषा चक्र दोबारा');
                }
            }
        }
    }
    state_set($PDO, 'catalog_cursor', ['phase' => $phase, 'lang' => $langC, 'prov' => $provC]);
}

state_set($PDO, 'catalog_cursor', ['phase' => $phase, 'lang' => $langC, 'prov' => $provC]);

$totalTitles = (int) scalar($PDO, 'SELECT COUNT(*) FROM titles');
logline("DB में कुल titles: $totalTitles  ·  इस दौड़ में नए: $new  ·  " . fmt_secs(ms_now() - $t0));

run_finish($PDO, $runId, $status, ['titles' => $seen], $note);
lock_release($lock);
