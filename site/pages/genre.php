<?php
/**
 * GENRE पन्ना — "Action फिल्में/सीरीज़ जो अभी OTT पर हैं"  (Content Hub · Feature 1)
 * रास्ता: /genre/{slug}   (?type=movie|tv, ?page=N)
 * provider.php का ही सलीक़ा — असली डेटा, SEO, pager। index.php से: $want_slug
 */
declare(strict_types=1);

try {
    $genre = one($PDO, 'SELECT * FROM genres WHERE slug = ?', [$want_slug]);
} catch (Throwable $e) {
    $genre = null;   // genres table अभी नहीं बनी (सर्वर पर migrate/sync बाक़ी)
}
if ($genre === null) {
    not_found();
}
$gid      = (int) $genre['id'];
$gname    = (string) $genre['name_en'];
$country  = $CFG['country'] ?? 'IN';
$per_page = 40;

$type = $_GET['type'] ?? '';
if ($type !== 'movie' && $type !== 'tv') {
    $type = '';
}
$page      = max(1, (int) ($_GET['page'] ?? 1));
$type_sql  = $type !== '' ? ' AND t.media_type = ? ' : '';
$type_args = $type !== '' ? [$type] : [];

// सिर्फ़ वही titles जो अभी भारत में सब्सक्रिप्शन/मुफ़्त पर उपलब्ध हैं (thin से बचाव)
$AV = "JOIN availability a ON a.title_id = t.id AND a.is_current = 1
       AND a.country = ? AND a.offer_type IN ('flatrate','ads','free')";

$total = (int) scalar($PDO, "
    SELECT COUNT(DISTINCT t.id) FROM title_genres tg
      JOIN titles t ON t.id = tg.title_id $AV
     WHERE tg.genre_id = ? $type_sql",
    array_merge([$country, $gid], $type_args));

$pages = max(1, (int) ceil($total / $per_page));
$page  = min($page, $pages);

$titles = all($PDO, "
    SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type,
           t.vote_average, t.popularity
      FROM title_genres tg
      JOIN titles t ON t.id = tg.title_id $AV
     WHERE tg.genre_id = ? $type_sql
     ORDER BY t.popularity DESC
     LIMIT $per_page OFFSET " . (($page - 1) * $per_page),
    array_merge([$country, $gid], $type_args));

// फिल्म/सीरीज़ गिनती (stat bar + tabs)
$by = [];
foreach (all($PDO, "
    SELECT t.media_type, COUNT(DISTINCT t.id) n FROM title_genres tg
      JOIN titles t ON t.id = tg.title_id $AV
     WHERE tg.genre_id = ? GROUP BY t.media_type",
    [$country, $gid]) as $r) {
    $by[$r['media_type']] = (int) $r['n'];
}
$n_movies = $by['movie'] ?? 0;
$n_series = $by['tv'] ?? 0;

// बाक़ी genres — internal linking (SEO + discovery)
$others = all($PDO, 'SELECT name_en, slug FROM genres WHERE id <> ? ORDER BY name_en', [$gid]);

// ---- meta ---------------------------------------------------------------------
$base_url = '/genre/' . rawurlencode($genre['slug']);
$desc = tf('%s की %d फिल्में और वेब सीरीज़ जो अभी भारत में OTT पर उपलब्ध हैं — Netflix, Prime, ZEE5, JioHotstar आदि। कहाँ देखें और कब से है — रोज़ अपडेट, OTT गुरु पर।',
    $gname, $total);

page_header([
    'title'       => tf('%s फिल्में और सीरीज़ — अभी OTT पर (%d)', $gname, $total),
    'description' => $desc,
    'canonical'   => $base_url,
    'noindex'     => $page > 1 || $total < 3,   // paginated/thin पन्ने index न हों
    'jsonld'      => [
        '@context' => 'https://schema.org',
        '@type'    => 'CollectionPage',
        'name'     => tf('%s — अभी OTT पर', $gname),
        'url'      => 'https://ottguru.in' . $base_url,
        'about'    => ['@type' => 'Thing', 'name' => $gname],
    ],
]);
?>

<div class="phead">
  <div class="phead-top">
    <span class="lg" style="background:var(--grad)"><?= h(mb_strtoupper(mb_substr($gname, 0, 2, 'UTF-8'))) ?></span>
    <div>
      <h1><?= h($gname) ?> <span class="dim" style="font-weight:600"><?= OTT_LANG === 'hi' ? '— अभी OTT पर' : '— on OTT now' ?></span></h1>
      <div class="sub"><?= OTT_LANG === 'hi' ? 'भारत · रोज़ अपडेट होता है' : 'India · updated daily' ?></div>
    </div>
  </div>
  <div class="pstats">
    <div><div class="v"><?= $nf = $n_movies + $n_series ?></div><div class="k">Titles</div></div>
    <div><div class="v"><?= $n_movies ?></div><div class="k"><?= h(t('फिल्में (tab)')) ?></div></div>
    <div><div class="v"><?= $n_series ?></div><div class="k"><?= h(t('वेब सीरीज़ (tab)')) ?></div></div>
  </div>
  <?php if ($others !== []): ?>
  <div class="sublinks">
    <?php foreach (array_slice($others, 0, 14) as $o): ?>
      <a href="/genre/<?= h(rawurlencode($o['slug'])) ?>"><?= h($o['name_en']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<h2><?= h(tf('%s की पूरी सूची', $gname)) ?></h2>
<div class="tabs">
  <a class="<?= $type === ''      ? 'on' : '' ?>" href="<?= h($base_url) ?>"><?= h(t('सब')) ?></a>
  <a class="<?= $type === 'movie' ? 'on' : '' ?>" href="<?= h($base_url) ?>?type=movie"><?= h(t('फिल्में (tab)')) ?></a>
  <a class="<?= $type === 'tv'    ? 'on' : '' ?>" href="<?= h($base_url) ?>?type=tv"><?= h(t('वेब सीरीज़ (tab)')) ?></a>
</div>

<?php if ($titles === []): ?>
  <div class="offer-none"><?= h(t('इस चुनाव में अभी कुछ नहीं मिला।')) ?></div>
<?php else: ?>
  <?php render_title_grid($titles); ?>
<?php endif; ?>

<?php if ($pages > 1): ?>
<div class="pager">
  <?php
  $qs = static function (int $p) use ($base_url, $type): string {
      $q = http_build_query(array_filter(['type' => $type, 'page' => $p > 1 ? $p : null]));
      return $base_url . ($q !== '' ? '?' . $q : '');
  };
  ?>
  <?php if ($page > 1): ?><a href="<?= h($qs($page - 1)) ?>"><?= h(t('← पिछला')) ?></a><?php endif; ?>
  <span class="here"><?= h(tf('पन्ना %d / %d', $page, $pages)) ?></span>
  <?php if ($page < $pages): ?><a href="<?= h($qs($page + 1)) ?>"><?= h(t('अगला →')) ?></a><?php endif; ?>
</div>
<?php endif; ?>

<?php page_footer(); ?>
