<?php
/**
 * SITEMAP — /sitemap.xml (queries.sql #9)
 * नियम एक ही है: सिर्फ़ वही पन्ने जिन पर असल में कुछ है। ख़ाली पन्ने
 * sitemap में डालना Google की नज़र में thin content है (CLAUDE.md §8)।
 *
 * अभी सब कुछ एक ही फाइल में है। 40,000 URLs के पास पहुँचने पर इसे
 * sitemap-index + टुकड़ों में बाँटना होगा (सीमा 50,000 की है)।
 */
declare(strict_types=1);

$country = $CFG['country'] ?? 'IN';
$base    = 'https://ottguru.in';

header('Content-Type: application/xml; charset=utf-8');

$urls = [];   // हर entry: [loc, lastmod|null, image|null]

// ---- होमपेज + changes पेज ------------------------------------------------------
$aaj = date('Y-m-d');
$urls[] = [$base . '/', $aaj];
$urls[] = [$base . '/browse', $aaj];
$urls[] = [$base . '/naya', $aaj];
$urls[] = [$base . '/hata', $aaj];

// ---- provider पेज — जिन पर अभी कुछ है, उनके naya/hata समेत ---------------------
$provs = all($PDO, "
    SELECT p.slug, MAX(a.last_seen) AS lastmod
      FROM providers p
      JOIN availability a ON a.provider_id = p.id
                         AND a.is_current = 1
                         AND a.country = ?
                         AND a.offer_type IN ('flatrate','ads','free')
     WHERE p.is_active = 1
     GROUP BY p.id",
    [$country]);
foreach ($provs as $p) {
    $urls[] = [$base . '/platform/' . rawurlencode($p['slug']), $p['lastmod']];
    $urls[] = [$base . '/naya/' . rawurlencode($p['slug']), $aaj];
    $urls[] = [$base . '/hata/' . rawurlencode($p['slug']), $aaj];
}

// ---- भाषा पेज — सिर्फ़ वे जोड़ जिन पर कम से कम 5 titles हैं ----------------------
$combos = all($PDO, "
    SELECT p.slug AS pslug, l.lang_code, t.media_type,
           COUNT(DISTINCT t.id) AS kitne, MAX(a.last_seen) AS lastmod
      FROM availability a
      JOIN providers p       ON p.id = a.provider_id AND p.is_active = 1
      JOIN titles t          ON t.id = a.title_id
      JOIN title_languages l ON l.title_id = t.id
     WHERE a.country = ?
       AND a.is_current = 1
       AND a.offer_type IN ('flatrate','ads','free')
     GROUP BY p.id, l.lang_code, t.media_type
    HAVING kitne >= 5",
    [$country]);
foreach ($combos as $c) {
    $ls = lang_page_slug($c['lang_code']);
    if ($ls === null) {
        continue;   // जिस भाषा का पेज ही नहीं बनता, वो sitemap में भी नहीं
    }
    $urls[] = [
        $base . '/platform/' . rawurlencode($c['pslug']) . '/' . $ls
              . '-' . ($c['media_type'] === 'tv' ? 'series' : 'movies'),
        $c['lastmod'],
    ];
}

// ---- genre पेज — सिर्फ़ जिन पर कम से कम 3 उपलब्ध titles हैं (thin नहीं) ----------
try {
    foreach (all($PDO, "
        SELECT g.slug, COUNT(DISTINCT t.id) AS n
          FROM title_genres tg
          JOIN genres g ON g.id = tg.genre_id
          JOIN titles t ON t.id = tg.title_id
          JOIN availability a ON a.title_id = t.id AND a.is_current = 1
                             AND a.country = ? AND a.offer_type IN ('flatrate','ads','free')
         GROUP BY g.id
        HAVING n >= 3", [$country]) as $g) {
        $urls[] = [$base . '/genre/' . rawurlencode($g['slug']), $aaj];
    }
} catch (Throwable $e) {
    // genres table अभी नहीं — कोई बात नहीं
}

// ---- person पेज — सिर्फ़ ≥3 उपलब्ध titles वाले (चर्चित लोग; thin/बहुत-ज़्यादा से बचाव) --
try {
    foreach (all($PDO, "
        SELECT p.id, p.name, COUNT(DISTINCT t.id) AS n
          FROM title_credits tc
          JOIN people p ON p.id = tc.person_id
          JOIN titles t ON t.id = tc.title_id
          JOIN availability a ON a.title_id = t.id AND a.is_current = 1
                             AND a.country = ? AND a.offer_type IN ('flatrate','ads','free')
         GROUP BY p.id
        HAVING n >= 3", [$country]) as $p) {
        $urls[] = [$base . person_url($p), $aaj];
    }
} catch (Throwable $e) {
    // people/title_credits table अभी नहीं
}

// ---- query 9: title पेज — हर वो title जो कभी किसी OTT पर रही (current या हटी) ------
// पहले सिर्फ़ is_current=1 था — इससे "अब हटी पर इतिहास वाली" movies (हमारा असली unique
// content) और वे जिनकी दोबारा-availability बाद में आई, Google को sitemap से पता ही नहीं
// चलती थीं। अब कोई भी availability (is_current 0/1) = हमारे पास उसका इतिहास है = index
// होने लायक़ असली पेज (§8 सुरक्षित; सिर्फ़ TMDB overview वाले पतले पेज इसमें नहीं आते
// क्योंकि उनके पास कोई availability row नहीं)। poster भी → image sitemap।
// सुरक्षा: 50,000 URL की sitemap सीमा से पहले (popularity क्रम में) 49,000 पर रोकते हैं;
// इससे ज़्यादा होने पर sitemap-index में बाँटना अगला कदम है।
$titles = all($PDO, "
    SELECT t.slug, t.media_type, t.title, t.poster_path,
           MAX(a.is_current) AS is_live,
           GREATEST(t.updated_at, COALESCE(MAX(a.last_seen), t.updated_at)) AS lastmod
      FROM titles t
      JOIN availability a ON a.title_id = t.id AND a.country = ?
     GROUP BY t.id
     ORDER BY t.popularity DESC
     LIMIT 49000",
    [$country]);
foreach ($titles as $t) {
    // अभी live titles को थोड़ी ऊँची priority (Google इसे संकेत भर मानता है)
    $urls[] = [$base . title_url($t), substr((string) $t['lastmod'], 0, 10),
               tmdb_img($t['poster_path'], 'w500'), (int) $t['is_live'] === 1 ? '0.7' : '0.5'];
}

// ---- XML ------------------------------------------------------------------------
// image namespace → हर title URL के साथ उसका poster (जहाँ है)। Google Images इसे पढ़ता है।
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
echo '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
foreach ($urls as $u) {
    [$loc, $lastmod] = $u;
    $img = $u[2] ?? null;
    echo "  <url><loc>" . h($loc) . "</loc>";
    if (nz((string) $lastmod) !== null) {
        echo "<lastmod>" . h(substr((string) $lastmod, 0, 10)) . "</lastmod>";
    }
    if ($img !== null) {
        echo "<image:image><image:loc>" . h($img) . "</image:loc></image:image>";
    }
    $priority = $u[3] ?? null;
    if ($priority !== null) {
        echo "<priority>" . h($priority) . "</priority>";
    }
    echo "</url>\n";
}
echo "</urlset>\n";
