<?php
/**
 * PROVIDER पन्ना — "Netflix India पर उपलब्ध सब कुछ"
 * रास्ता: /platform/{slug}   (?type=movie|tv, ?page=N)
 * queries.sql की query 2 (सूची) और 4 (इस हफ़्ते नया) यहीं चलती हैं।
 *
 * index.php से मिलता है: $want_slug
 */
declare(strict_types=1);

$prov = one($PDO, 'SELECT * FROM providers WHERE slug = ? AND is_active = 1', [$want_slug]);
if ($prov === null) {
    not_found();
}

$country  = $CFG['country'] ?? 'IN';
$per_page = 40;

// ---- फ़िल्टर: सब / फिल्में / सीरीज़ ------------------------------------------
$type = $_GET['type'] ?? '';
if ($type !== 'movie' && $type !== 'tv') {
    $type = '';
}

$page = max(1, (int) ($_GET['page'] ?? 1));

$type_sql  = $type !== '' ? ' AND t.media_type = ? ' : '';
$type_args = $type !== '' ? [$type] : [];

// ---- गिनती (pager के लिए) ----------------------------------------------------
$total = (int) scalar($PDO, "
    SELECT COUNT(DISTINCT t.id)
      FROM availability a
      JOIN titles t ON t.id = a.title_id
     WHERE a.provider_id = ?
       AND a.country = ?
       AND a.is_current = 1
       AND a.offer_type IN ('flatrate','ads','free')
       $type_sql",
    array_merge([(int) $prov['id'], $country], $type_args));

$pages = max(1, (int) ceil($total / $per_page));
$page  = min($page, $pages);

// ---- query 2: सूची ------------------------------------------------------------
$titles = all($PDO, "
    SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type,
           t.vote_average, t.popularity
      FROM availability a
      JOIN titles t ON t.id = a.title_id
     WHERE a.provider_id = ?
       AND a.country = ?
       AND a.is_current = 1
       AND a.offer_type IN ('flatrate','ads','free')
       $type_sql
     ORDER BY t.popularity DESC
     LIMIT $per_page OFFSET " . (($page - 1) * $per_page),
    array_merge([(int) $prov['id'], $country], $type_args));

// ---- query 4: इस हफ़्ते क्या नया आया ------------------------------------------
$naya = all($PDO, "
    SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type,
           c.changed_on
      FROM availability_changes c
      JOIN titles t ON t.id = c.title_id
     WHERE c.provider_id = ?
       AND c.country = ?
       AND c.change_type = 'added'
       AND c.offer_type IN ('flatrate','ads','free')
       AND c.changed_on >= (CURDATE() - INTERVAL 7 DAY)
     ORDER BY c.changed_on DESC
     LIMIT 12",
    [(int) $prov['id'], $country]);

// ---- भाषा के हिसाब से लिंक — सिर्फ़ वे जोड़ जिन पर सच में titles हैं --------------
// (5 से कम वाले पेज noindex हैं, पर यूज़र के लिए लिंक 1 से ही दिखा देते हैं)
$bhasha_links = [];
foreach (all($PDO, "
    SELECT l.lang_code, t.media_type, COUNT(DISTINCT t.id) AS kitne
      FROM availability a
      JOIN titles t          ON t.id = a.title_id
      JOIN title_languages l ON l.title_id = t.id
     WHERE a.provider_id = ?
       AND a.country = ?
       AND a.is_current = 1
       AND a.offer_type IN ('flatrate','ads','free')
     GROUP BY l.lang_code, t.media_type
     ORDER BY kitne DESC",
    [(int) $prov['id'], $country]) as $r) {
    $ls = lang_page_slug($r['lang_code']);
    if ($ls === null) {
        continue;
    }
    $bhasha_links[] = [
        'url'   => provider_url($prov) . '/' . $ls . '-' . ($r['media_type'] === 'tv' ? 'series' : 'movies'),
        'label' => $r['media_type'] === 'tv'
                 ? tf('%s सीरीज़ (%d)', lang_label($r['lang_code']), (int) $r['kitne'])
                 : tf('%s फिल्में (%d)', lang_label($r['lang_code']), (int) $r['kitne']),
    ];
}
$bhasha_links = array_slice($bhasha_links, 0, 12);

// ---- header के लिए stats: फिल्म/सीरीज़ गिनती + इस हफ़्ते जुड़ीं/हटीं ----------------
$by_type = [];
foreach (all($PDO, "
    SELECT t.media_type, COUNT(DISTINCT t.id) AS n
      FROM availability a JOIN titles t ON t.id = a.title_id
     WHERE a.provider_id = ? AND a.country = ? AND a.is_current = 1
       AND a.offer_type IN ('flatrate','ads','free')
     GROUP BY t.media_type", [(int) $prov['id'], $country]) as $r) {
    $by_type[$r['media_type']] = (int) $r['n'];
}
$n_movies = $by_type['movie'] ?? 0;
$n_series = $by_type['tv'] ?? 0;
$grand    = $n_movies + $n_series;

$chg7 = one($PDO, "
    SELECT
      (SELECT COUNT(DISTINCT title_id) FROM availability_changes
        WHERE provider_id = ? AND country = ? AND change_type = 'added'
          AND changed_on >= (CURDATE() - INTERVAL 7 DAY)) AS added,
      (SELECT COUNT(DISTINCT title_id) FROM availability_changes
        WHERE provider_id = ? AND country = ? AND change_type = 'removed'
          AND changed_on >= (CURDATE() - INTERVAL 7 DAY)) AS removed",
    [(int) $prov['id'], $country, (int) $prov['id'], $country]) ?? ['added' => 0, 'removed' => 0];

// ---- meta ---------------------------------------------------------------------
$type_label = $type === 'movie' ? t('फिल्में') : ($type === 'tv' ? t('वेब सीरीज़ (सूची)') : t('फिल्में और वेब सीरीज़'));
$desc = tf('%s India पर अभी %d %s सब्सक्रिप्शन में उपलब्ध हैं। इस हफ़्ते क्या नया आया और क्या हटा — रोज़ अपडेट, OTT गुरु पर।',
    $prov['name'], $total, $type_label);

$self = provider_url($prov) . ($type !== '' ? '?type=' . $type : '');

page_header([
    'title'       => tf('%s पर क्या-क्या है (%d titles)', $prov['name'], $total),
    'description' => $desc,
    'canonical'   => provider_url($prov),
    'image'       => tmdb_img($prov['logo_path'], 'w154'),
    'noindex'     => $page > 1,   // paginated पन्ने index न हों — पहला ही काफ़ी है
    'jsonld'      => [
        '@context' => 'https://schema.org',
        '@type'    => 'CollectionPage',
        'name'     => tf('%s पर क्या-क्या है', $prov['name']),
        'url'      => 'https://ottguru.in' . provider_url($prov),
    ],
]);
?>

<div class="phead">
  <div class="phead-top">
    <?php $logo = tmdb_img($prov['logo_path'], 'w154'); ?>
    <?php if ($logo !== null): ?><img src="<?= h($logo) ?>" alt="<?= h($prov['name']) ?> logo"><?php else: ?><span class="lg"><?= h(mb_substr($prov['name'], 0, 2, 'UTF-8')) ?></span><?php endif; ?>
    <div>
      <h1><?= h($prov['name']) ?></h1>
      <div class="sub"><?= OTT_LANG === 'hi' ? 'भारत · रोज़ अपडेट होता है' : 'India · updated daily' ?></div>
    </div>
  </div>
  <div class="pstats">
    <div><div class="v"><?= $grand ?></div><div class="k">Titles</div></div>
    <div><div class="v"><?= $n_movies ?></div><div class="k"><?= h(t('फिल्में (tab)')) ?></div></div>
    <div><div class="v"><?= $n_series ?></div><div class="k"><?= h(t('वेब सीरीज़ (tab)')) ?></div></div>
    <div><div class="v <?= (int) $chg7['added'] > 0 ? 'up' : '' ?>"><?= (int) $chg7['added'] > 0 ? '+' . (int) $chg7['added'] : '0' ?></div><div class="k"><?= h(t('इस हफ़्ते नई आईं')) ?></div></div>
    <div><div class="v <?= (int) $chg7['removed'] > 0 ? 'down' : '' ?>"><?= (int) $chg7['removed'] > 0 ? '−' . (int) $chg7['removed'] : '0' ?></div><div class="k"><?= h(t('इस हफ़्ते हटीं')) ?></div></div>
  </div>
  <div class="sublinks">
    <a href="/naya/<?= h(rawurlencode($prov['slug'])) ?>"><?= h(t('इस हफ़्ते नया आया →')) ?></a>
    <a href="/hata/<?= h(rawurlencode($prov['slug'])) ?>"><?= h(t('हाल में क्या हटा →')) ?></a>
    <?php foreach ($bhasha_links as $bl): ?>
      <a href="<?= h($bl['url']) ?>"><?= h($bl['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($naya !== []): ?>
<section>
  <div class="head"><div><span class="eyebrow"><?= OTT_LANG === 'hi' ? 'पिछले 7 दिन' : 'Last 7 days' ?></span><h2><?= h(t('इस हफ़्ते नया आया')) ?></h2></div>
    <a class="link" href="/naya/<?= h(rawurlencode($prov['slug'])) ?>"><?= h(t('सब देखिए →')) ?></a></div>
  <div class="rail">
    <?php foreach ($naya as $t): $img = tmdb_img($t['poster_path'], 'w342'); ?>
    <a class="pcard" href="<?= h(title_url($t)) ?>">
      <?php if ($img !== null): ?><img loading="lazy" src="<?= h($img) ?>" alt="<?= h(tf('%s का poster', $t['title'])) ?>"><?php else: ?><span class="noposter"><?= h(mb_substr($t['title'], 0, 40, 'UTF-8')) ?></span><?php endif; ?>
      <span class="ov"><span class="t"><?= h($t['title']) ?></span><span class="tag g"><?= h(tf('%s को आई', hindi_date($t['changed_on']))) ?></span></span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<h2><?= h(t('पूरी सूची')) ?></h2>
<div class="tabs">
  <a class="<?= $type === ''      ? 'on' : '' ?>" href="<?= h(provider_url($prov)) ?>"><?= h(t('सब')) ?></a>
  <a class="<?= $type === 'movie' ? 'on' : '' ?>" href="<?= h(provider_url($prov)) ?>?type=movie"><?= h(t('फिल्में (tab)')) ?></a>
  <a class="<?= $type === 'tv'    ? 'on' : '' ?>" href="<?= h(provider_url($prov)) ?>?type=tv"><?= h(t('वेब सीरीज़ (tab)')) ?></a>
</div>

<?php if ($titles === []): ?>
  <div class="offer-none"><?= h(t('इस चुनाव में अभी कुछ नहीं मिला।')) ?></div>
<?php else: ?>
  <?php render_title_grid($titles); ?>
<?php endif; ?>

<?php if ($pages > 1): ?>
<div class="pager">
  <?php
  $qs = static function (int $p) use ($prov, $type): string {
      $q = http_build_query(array_filter(['type' => $type, 'page' => $p > 1 ? $p : null]));
      return provider_url($prov) . ($q !== '' ? '?' . $q : '');
  };
  ?>
  <?php if ($page > 1): ?><a href="<?= h($qs($page - 1)) ?>"><?= h(t('← पिछला')) ?></a><?php endif; ?>
  <span class="here"><?= h(tf('पन्ना %d / %d', $page, $pages)) ?></span>
  <?php if ($page < $pages): ?><a href="<?= h($qs($page + 1)) ?>"><?= h(t('अगला →')) ?></a><?php endif; ?>
</div>
<?php endif; ?>

<?php page_footer(); ?>
