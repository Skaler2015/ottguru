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
        'label' => lang_label($r['lang_code']) . ' ' . ($r['media_type'] === 'tv' ? 'सीरीज़' : 'फिल्में')
                 . ' (' . (int) $r['kitne'] . ')',
    ];
}
$bhasha_links = array_slice($bhasha_links, 0, 12);

// ---- meta ---------------------------------------------------------------------
$type_label = $type === 'movie' ? 'फिल्में' : ($type === 'tv' ? 'वेब सीरीज़' : 'फिल्में और वेब सीरीज़');
$desc = $prov['name'] . ' India पर अभी ' . $total . ' ' . $type_label
      . ' सब्सक्रिप्शन में उपलब्ध हैं। इस हफ़्ते क्या नया आया और क्या हटा — रोज़ अपडेट, OTT गुरु पर।';

$self = provider_url($prov) . ($type !== '' ? '?type=' . $type : '');

page_header([
    'title'       => $prov['name'] . ' पर क्या-क्या है (' . $total . ' titles)',
    'description' => $desc,
    'canonical'   => provider_url($prov),
    'image'       => tmdb_img($prov['logo_path'], 'w154'),
    'noindex'     => $page > 1,   // paginated पन्ने index न हों — पहला ही काफ़ी है
    'jsonld'      => [
        '@context' => 'https://schema.org',
        '@type'    => 'CollectionPage',
        'name'     => $prov['name'] . ' India पर उपलब्ध ' . $type_label,
        'url'      => 'https://ottguru.in' . provider_url($prov),
    ],
]);
?>

<div class="p-head">
  <?php $logo = tmdb_img($prov['logo_path'], 'w154'); ?>
  <?php if ($logo !== null): ?><img src="<?= h($logo) ?>" alt="<?= h($prov['name']) ?> का logo"><?php endif; ?>
  <div>
    <h1><?= h($prov['name']) ?> पर क्या-क्या है</h1>
    <p class="dim" style="margin:0">भारत में अभी <b><?= $total ?></b> <?= h($type_label) ?> सब्सक्रिप्शन/मुफ़्त में
       · रोज़ अपडेट होता है</p>
    <div class="sublinks">
      <a href="/naya/<?= h(rawurlencode($prov['slug'])) ?>">इस हफ़्ते नया आया →</a>
      <a href="/hata/<?= h(rawurlencode($prov['slug'])) ?>">हाल में क्या हटा →</a>
      <?php foreach ($bhasha_links as $bl): ?>
        <a href="<?= h($bl['url']) ?>"><?= h($bl['label']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($naya !== []): ?>
<h2>इस हफ़्ते नया आया</h2>
<div class="newrow">
  <?php foreach ($naya as $t): ?>
  <a class="card" href="<?= h(title_url($t)) ?>">
    <?php $img = tmdb_img($t['poster_path'], 'w342'); ?>
    <?php if ($img !== null): ?>
      <img loading="lazy" src="<?= h($img) ?>" alt="<?= h($t['title']) ?> का poster">
    <?php else: ?>
      <span class="noposter"><?= h(mb_substr($t['title'], 0, 40, 'UTF-8')) ?></span>
    <?php endif; ?>
    <span class="card-t"><?= h($t['title']) ?></span>
    <span class="newdate"><?= h(hindi_date($t['changed_on'])) ?> को आई</span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<h2>पूरी सूची</h2>
<div class="tabs">
  <a class="<?= $type === ''      ? 'on' : '' ?>" href="<?= h(provider_url($prov)) ?>">सब</a>
  <a class="<?= $type === 'movie' ? 'on' : '' ?>" href="<?= h(provider_url($prov)) ?>?type=movie">फिल्में</a>
  <a class="<?= $type === 'tv'    ? 'on' : '' ?>" href="<?= h(provider_url($prov)) ?>?type=tv">वेब सीरीज़</a>
</div>

<?php if ($titles === []): ?>
  <div class="offer-none">इस चुनाव में अभी कुछ नहीं मिला।</div>
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
  <?php if ($page > 1): ?><a href="<?= h($qs($page - 1)) ?>">← पिछला</a><?php endif; ?>
  <span class="here">पन्ना <?= $page ?> / <?= $pages ?></span>
  <?php if ($page < $pages): ?><a href="<?= h($qs($page + 1)) ?>">अगला →</a><?php endif; ?>
</div>
<?php endif; ?>

<?php page_footer(); ?>
