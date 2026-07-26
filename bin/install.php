<?php
/**
 * ============================================================================
 *  install.php — एक बार चलाइए
 *  1. टेबलें बनाता है (schema.sql से)
 *  2. TMDB से India के providers की सूची लाकर providers टेबल भरता है
 *  3. गंदे नामों के alias अपने-आप बनाता है
 *
 *  CLI :  php bin/install.php
 *  Web :  bin/install.php?k=आपका-run-token
 * ============================================================================
 */
require dirname(__DIR__) . '/lib/boot.php';

$t0 = ms_now();

/* ------------------------------------------------------------------ 1. जाँच */
logline('PHP ' . PHP_VERSION);
foreach (['curl', 'pdo_mysql', 'mbstring', 'json'] as $ext) {
    if (!extension_loaded($ext)) {
        fail("PHP एक्सटेंशन '$ext' चालू नहीं है — hPanel में चालू कीजिए।");
    }
}
logline('ज़रूरी एक्सटेंशन: ठीक');

if (trim((string) $CFG['tmdb_key']) === '' || str_contains((string) $CFG['tmdb_key'], 'यहाँ')) {
    fail('config.php में tmdb_key नहीं डाली गई।');
}

/* ------------------------------------------------------------------ 2. टेबलें */
$sqlFile = OTT_ROOT . '/schema.sql';
if (!is_readable($sqlFile)) {
    fail('schema.sql नहीं मिली।');
}
$sql = (string) file_get_contents($sqlFile);

// टिप्पणियाँ हटाकर ; पर बाँटना
$sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
$made = 0;
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    if ($stmt === '') {
        continue;
    }
    $PDO->exec($stmt);
    $made++;
}
logline("schema लागू ($made कथन)");

$tables = array_column(all($PDO, 'SHOW TABLES'), array_keys(all($PDO, 'SHOW TABLES')[0] ?? ['x'])[0] ?? 0);
logline('टेबलें: ' . implode(', ', $tables));

/* ------------------------------------------------------------------ 3. providers */
// TMDB जिन नामों से भेजता है वो कई बार गलत स्पेलिंग वाले होते हैं।
// साइट पर सही नाम दिखे, इसलिए यह सुधार सूची:
$prettyName = [
    'sonyliv'            => 'SonyLIV',
    'zee5'               => 'ZEE5',
    'jiohotstar'         => 'JioHotstar',
    'jiocinema'          => 'JioCinema',
    'mxplayer'           => 'MX Player',
    'sunnxt'             => 'Sun NXT',
    'etvwin'             => 'ETV Win',
    'aha'                => 'aha',
    'hoichoi'            => 'hoichoi',
    'manoramamax'        => 'ManoramaMAX',
    'vimoviesandtv'      => 'Vi Movies and TV',
    'amazonprimevideo'   => 'Amazon Prime Video',
    'amazonvideo'        => 'Amazon Video (किराया/ख़रीद)',
    'appletv'            => 'Apple TV (किराया/ख़रीद)',
    'appletvplus'        => 'Apple TV+',
    'googleplaymovies'   => 'Google Play Movies',
    'lionsgateplay'      => 'Lionsgate Play',
    'chaupal'            => 'Chaupal',
    'planetmarathi'      => 'Planet Marathi',
    'stage'              => 'STAGE',
];

$raw = [];   // tmdb_provider_id => ['name'=>..,'logo'=>..,'prio'=>..]
foreach (['movie', 'tv'] as $mt) {
    $r = tmdb_provider_list($mt);
    if (!$r['ok']) {
        fail("TMDB से $mt providers की सूची नहीं आई: " . ($r['error'] ?? '?'));
    }
    foreach (($r['data']['results'] ?? []) as $p) {
        $id = (int) ($p['provider_id'] ?? 0);
        if ($id === 0) {
            continue;
        }
        $prio = $p['display_priorities'][strtoupper($CFG['country'])]
            ?? ($p['display_priority'] ?? 100);
        if (!isset($raw[$id]) || (int) $prio < (int) $raw[$id]['prio']) {
            $raw[$id] = [
                'name' => (string) ($p['provider_name'] ?? ('provider-' . $id)),
                'logo' => nz((string) ($p['logo_path'] ?? '')),
                'prio' => (int) $prio,
            ];
        }
    }
}
logline('TMDB से ' . count($raw) . ' provider entries मिलीं (India)');

// एक ही असली सेवा के सब रूप एक जगह इकट्ठा कीजिए
$groups = [];   // normBase => ['name','logo','prio','members'=>[id=>rawName]]
foreach ($raw as $id => $info) {
    $base  = base_service_name($info['name']);
    $key   = norm_name($base);
    if ($key === '') {
        continue;
    }
    if (!isset($groups[$key])) {
        $groups[$key] = ['name' => $base, 'logo' => $info['logo'], 'prio' => $info['prio'], 'members' => []];
    }
    $groups[$key]['members'][$id] = $info['name'];
    // सबसे ऊँची प्राथमिकता और उपलब्ध logo रखिए
    if ($info['prio'] < $groups[$key]['prio']) {
        $groups[$key]['prio'] = $info['prio'];
    }
    if ($groups[$key]['logo'] === null && $info['logo'] !== null) {
        $groups[$key]['logo'] = $info['logo'];
    }
    // बिना 'with Ads' वाला नाम मूल माना जाएगा
    if (!name_implies_ads($info['name']) && mb_strlen($info['name']) <= mb_strlen($groups[$key]['name'])) {
        $groups[$key]['name'] = base_service_name($info['name']);
    }
}
logline(count($groups) . ' असली सेवाओं में समेटा गया (पहला दौर)');

/* Amazon जिन सेवाओं को अपने नाम के आगे लगाकर बेचता है उन्हें जोड़िए —
   'Amazon MX Player' और 'MX Player' एक ही चीज़ हैं।
   नियम: 'amazon' हटाने पर अगर कोई मौजूदा सेवा मिल जाए, तो उसी में मिला दीजिए।
   'Amazon Prime Video' → 'primevideo' कहीं नहीं है, इसलिए वो अलग ही रहेगा (जो सही है)। */
foreach (array_keys($groups) as $key) {
    if (!str_starts_with($key, 'amazon') || $key === 'amazon') {
        continue;
    }
    $stripped = substr($key, 6);
    if ($stripped === '' || !isset($groups[$stripped]) || $stripped === $key) {
        continue;
    }
    foreach ($groups[$key]['members'] as $id => $nm) {
        $groups[$stripped]['members'][$id] = $nm;
    }
    if ($groups[$key]['prio'] < $groups[$stripped]['prio']) {
        $groups[$stripped]['prio'] = $groups[$key]['prio'];
    }
    logline("  मिलाया: '{$groups[$key]['name']}' → '{$groups[$stripped]['name']}'");
    unset($groups[$key]);
}
logline(count($groups) . ' असली सेवाएँ (मिलाने के बाद)');

$insP = $PDO->prepare(
    'INSERT INTO providers (tmdb_provider_id, slug, name, logo_path, display_priority)
     VALUES (:tid, :slug, :name, :logo, :prio)
     ON DUPLICATE KEY UPDATE name = VALUES(name), logo_path = VALUES(logo_path),
                             display_priority = VALUES(display_priority)'
);
$insA = $PDO->prepare(
    'INSERT INTO provider_aliases (provider_id, alias_norm, alias_raw, tmdb_provider_id, source, implies_ads)
     VALUES (:pid, :an, :ar, :tid, "tmdb", :ads)
     ON DUPLICATE KEY UPDATE provider_id = VALUES(provider_id), alias_raw = VALUES(alias_raw),
                             implies_ads = VALUES(implies_ads)'
);

$nProv = 0;
$nAlias = 0;
foreach ($groups as $key => $g) {
    // discovery के लिए मूल (non-ads) tmdb id चुनिए
    $primary = null;
    foreach ($g['members'] as $id => $nm) {
        if (!name_implies_ads($nm)) {
            $primary = $id;
            break;
        }
    }
    if ($primary === null) {
        $primary = (int) array_key_first($g['members']);
    }

    $name = $prettyName[$key] ?? $g['name'];
    $slug = slugify($name);

    $insP->execute([
        ':tid'  => $primary,
        ':slug' => $slug,
        ':name' => $name,
        ':logo' => $g['logo'],
        ':prio' => $g['prio'],
    ]);
    $pid = (int) scalar($PDO, 'SELECT id FROM providers WHERE slug = ?', [$slug]);
    if ($pid === 0) {
        continue;
    }
    $nProv++;

    foreach ($g['members'] as $id => $nm) {
        $insA->execute([
            ':pid' => $pid,
            ':an'  => norm_name($nm),
            ':ar'  => $nm,
            ':tid' => $id,
            ':ads' => name_implies_ads($nm) ? 1 : 0,
        ]);
        $nAlias++;
    }
    // सही नाम भी alias बन जाए, ताकि दूसरी API के नाम भी मिल जाएँ
    if (norm_name($name) !== '' && !isset($g['members'][$primary])) {
        $insA->execute([':pid' => $pid, ':an' => norm_name($name), ':ar' => $name, ':tid' => null, ':ads' => 0]);
    }
}
logline("providers: $nProv सेवाएँ, $nAlias alias");

/* ----------------------------------------------- 4. हाथ से जोड़े गए alias
   ये नाम चरण 0 के असली टेस्ट में मिले थे। दूसरी API या पुराने डेटा में
   ये रूप आएँ तो भी सही जगह मिल जाएँ। */
$manual = [
    'netflix'          => ['netflix', 'netflixbasicwithads', 'netflixstandardwithads', 'netflixkids'],
    'amazon-prime-video' => ['prime', 'primevideo', 'amazonprime'],
    'jiohotstar'       => ['hotstar', 'disneyhotstar', 'disneyplushotstar', 'jiocinema'],
    'zee5'             => ['zee5amazonchannel'],
    'sonyliv'          => ['sonylivamazonchannel'],
    'mx-player'        => ['amazonmxplayer'],
];
$nMan = 0;
foreach ($manual as $slug => $aliases) {
    $pid = scalar($PDO, 'SELECT id FROM providers WHERE slug = ?', [$slug]);
    if ($pid === null) {
        continue;
    }
    foreach ($aliases as $a) {
        q(
            $PDO,
            'INSERT INTO provider_aliases (provider_id, alias_norm, alias_raw, source)
             VALUES (?, ?, ?, "manual")
             ON DUPLICATE KEY UPDATE provider_id = VALUES(provider_id)',
            [(int) $pid, norm_name($a), $a]
        );
        $nMan++;
    }
}
logline("हाथ से जोड़े गए alias: $nMan");

/* ------------------------------------------------------------------ हो गया */
$top = all($PDO, 'SELECT slug, name, tmdb_provider_id FROM providers
                  WHERE is_active = 1 ORDER BY display_priority, id LIMIT 15');
logline('--- पहले 15 providers ---');
foreach ($top as $p) {
    logline('  ' . str_pad($p['slug'], 24) . $p['name'] . '  (tmdb #' . $p['tmdb_provider_id'] . ')');
}

logline('इंस्टॉल पूरा — ' . fmt_secs(ms_now() - $t0));
logline('अगला कदम: bin/sync_catalog.php चलाइए');
