<?php
/**
 * खोज — /search?q=...
 * titles के नाम/मूल-नाम पर सादा LIKE खोज, popularity से क्रम।
 * नतीजे noindex (thin/duplicate crawl से बचाव)। असली, काम करती खोज।
 */
declare(strict_types=1);

$country = $CFG['country'] ?? 'IN';
$q = trim((string) ($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 80, 'UTF-8');

$results = [];
if ($q !== '') {
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $results = all($PDO, "
        SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type
          FROM titles t
          LEFT JOIN availability a ON a.title_id = t.id AND a.is_current = 1
         WHERE t.title LIKE ? OR t.original_title LIKE ?
         ORDER BY (a.id IS NOT NULL) DESC, t.popularity DESC
         LIMIT 60",
        [$like, $like]);
}

page_header([
    'title'   => $q !== '' ? tf('"%s" की खोज', $q) : t('खोजें'),
    'noindex' => true,
]);
?>

<h1><?= h(t('खोजें')) ?></h1>
<form class="hsearch" action="/search" method="get" role="search" style="margin:18px 0 8px">
  <div class="inner">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
    <input name="q" value="<?= h($q) ?>" placeholder="<?= h(t('फिल्म, सीरीज़ या platform खोजिए…')) ?>" aria-label="<?= h(t('खोजें')) ?>" autofocus autocomplete="off">
    <button type="submit"><?= h(t('खोजें')) ?></button>
  </div>
</form>

<?php if ($q === ''): ?>
  <p class="dim" style="margin-top:16px"><?= h(t('कोई फिल्म या वेब सीरीज़ का नाम लिखिए।')) ?></p>
<?php elseif ($results === []): ?>
  <div class="offer-none" style="margin-top:16px"><?= h(tf('"%s" के लिए कुछ नहीं मिला। नाम की वर्तनी जाँचिए या अंग्रेज़ी में लिखकर देखिए।', $q)) ?></div>
<?php else: ?>
  <p class="dim" style="margin:14px 0 4px"><?= h(tf('%d नतीजे', count($results))) ?></p>
  <?php render_title_grid($results); ?>
<?php endif; ?>

<?php page_footer(); ?>
