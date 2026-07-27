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

$urls = [];   // हर entry: [loc, lastmod|null]

// ---- होमपेज + changes पेज ------------------------------------------------------
$aaj = date('Y-m-d');
$urls[] = [$base . '/', $aaj];
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

// ---- query 9: title पेज — सिर्फ़ वे जिन पर अभी कुछ उपलब्ध है --------------------
$titles = all($PDO, "
    SELECT t.slug, t.media_type,
           GREATEST(t.updated_at, COALESCE(MAX(a.last_seen), t.updated_at)) AS lastmod
      FROM titles t
      JOIN availability a ON a.title_id = t.id AND a.is_current = 1
     WHERE a.country = ?
     GROUP BY t.id
     ORDER BY t.popularity DESC",
    [$country]);
foreach ($titles as $t) {
    $urls[] = [$base . title_url($t), substr((string) $t['lastmod'], 0, 10)];
}

// ---- XML ------------------------------------------------------------------------
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$loc, $lastmod]) {
    echo "  <url><loc>" . h($loc) . "</loc>";
    if (nz((string) $lastmod) !== null) {
        echo "<lastmod>" . h(substr((string) $lastmod, 0, 10)) . "</lastmod>";
    }
    echo "</url>\n";
}
echo "</urlset>\n";
