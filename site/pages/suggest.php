<?php
/**
 * LIVE SUGGEST — /suggest?q=...  (command-palette की live खोज)
 * JSON लौटाता है (HTML नहीं)। हल्का + तेज़: top 8 titles, posters सहित।
 * noindex; page-cache इसे छोड़ देता है (cache.php की exclusion सूची)।
 * index.php से मिलता है: $PDO, helpers (web.php से)।
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$q = trim((string) ($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 60, 'UTF-8');

$items = [];
if (mb_strlen($q) >= 2) {
    $esc  = str_replace(['%', '_'], ['\\%', '\\_'], $q);
    $like = '%' . $esc . '%';
    $pre  = $esc . '%';   // शुरुआत से मिलान → ऊपर
    try {
        $rows = all($PDO, "
            SELECT t.slug, t.title, t.release_year, t.poster_path, t.media_type, t.vote_average,
                   MAX(a.id IS NOT NULL) AS avail
              FROM titles t
              LEFT JOIN availability a ON a.title_id = t.id AND a.is_current = 1
             WHERE t.title LIKE ? OR t.original_title LIKE ?
             GROUP BY t.id
             ORDER BY (t.title LIKE ?) DESC, avail DESC, t.popularity DESC
             LIMIT 8",
            [$like, $like, $pre]);
        foreach ($rows as $t) {
            $va = (float) ($t['vote_average'] ?? 0);
            $items[] = [
                'url'   => title_url($t),
                'title' => (string) $t['title'],
                'meta'  => trim((string) ($t['release_year'] ?? '')) . ' · ' . media_label($t['media_type']),
                'img'   => tmdb_img($t['poster_path'], 'w92'),
                'rate'  => $va > 0 ? number_format($va, 1) : null,
            ];
        }
    } catch (Throwable $e) {
        // कुछ गड़बड़ हो तो ख़ाली सूची — UI साफ़ गिरता है
    }
}

echo json_encode(['q' => $q, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
