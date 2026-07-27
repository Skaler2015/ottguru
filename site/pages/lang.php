<?php
/**
 * भाषा पेज — "Netflix पर हिंदी फिल्में" (queries.sql #3)
 * रास्ता: /platform/{provider}/{bhasha}-movies या .../{bhasha}-series
 *
 * ध्यान (CLAUDE.md §5): यह **फिल्म की भाषा** है, dub नहीं। "इस OTT पर हिंदी
 * ऑडियो मिलेगी या नहीं" अलग सवाल है — वो Streaming Availability जुड़ने के
 * बाद आएगा। दोनों को मिलाकर कभी नहीं दिखाना।
 *
 * thin-content से बचाव: 0 titles → 404; 5 से कम → noindex.
 *
 * index.php से मिलता है: $want_slug, $want_lang ('hi'), $lang_slug ('hindi'), $want_type
 */
declare(strict_types=1);

$prov = one($PDO, 'SELECT * FROM providers WHERE slug = ? AND is_active = 1', [$want_slug]);
if ($prov === null) {
    not_found();
}

$country  = $CFG['country'] ?? 'IN';
$per_page = 40;
$page     = max(1, (int) ($_GET['page'] ?? 1));

$total = (int) scalar($PDO, "
    SELECT COUNT(DISTINCT t.id)
      FROM availability a
      JOIN titles t          ON t.id = a.title_id
      JOIN title_languages l ON l.title_id = t.id
     WHERE a.provider_id = ?
       AND a.country = ?
       AND a.is_current = 1
       AND a.offer_type IN ('flatrate','ads','free')
       AND l.lang_code = ?
       AND t.media_type = ?",
    [(int) $prov['id'], $country, $want_lang, $want_type]);

// जिस जोड़ पर कुछ है ही नहीं, वो पन्ना है ही नहीं — Google के लिए भी, यूज़र के लिए भी
if ($total === 0) {
    not_found();
}

$pages = max(1, (int) ceil($total / $per_page));
$page  = min($page, $pages);

$titles = all($PDO, "
    SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type,
           t.popularity
      FROM availability a
      JOIN titles t          ON t.id = a.title_id
      JOIN title_languages l ON l.title_id = t.id
     WHERE a.provider_id = ?
       AND a.country = ?
       AND a.is_current = 1
       AND a.offer_type IN ('flatrate','ads','free')
       AND l.lang_code = ?
       AND t.media_type = ?
     ORDER BY t.popularity DESC
     LIMIT $per_page OFFSET " . (($page - 1) * $per_page),
    [(int) $prov['id'], $country, $want_lang, $want_type]);

// ---- meta ---------------------------------------------------------------------
$bhasha     = lang_label($want_lang);
$kya        = $want_type === 'tv' ? 'वेब सीरीज़' : 'फिल्में';
$h1         = $prov['name'] . ' पर ' . $bhasha . ' ' . $kya;
$self       = provider_url($prov) . '/' . $lang_slug . '-' . ($want_type === 'tv' ? 'series' : 'movies');
$dusra_type = $want_type === 'tv' ? 'movies' : 'series';

// दूसरी तरफ़ (फिल्में ↔ सीरीज़) कुछ है तो ही उसका लिंक दिखाना
$dusra_total = (int) scalar($PDO, "
    SELECT COUNT(DISTINCT t.id)
      FROM availability a
      JOIN titles t          ON t.id = a.title_id
      JOIN title_languages l ON l.title_id = t.id
     WHERE a.provider_id = ?
       AND a.country = ?
       AND a.is_current = 1
       AND a.offer_type IN ('flatrate','ads','free')
       AND l.lang_code = ?
       AND t.media_type = ?",
    [(int) $prov['id'], $country, $want_lang, $want_type === 'tv' ? 'movie' : 'tv']);

page_header([
    'title'       => $h1 . ' (' . $total . ')',
    'description' => $prov['name'] . ' India पर अभी ' . $total . ' ' . $bhasha . ' ' . $kya
                   . ' सब्सक्रिप्शन में हैं — पूरी सूची, रोज़ अपडेट। OTT गुरु पर।',
    'canonical'   => $self,
    'image'       => tmdb_img($prov['logo_path'], 'w154'),
    'noindex'     => $total < 5 || $page > 1,   // बहुत छोटा या paginated पन्ना index न हो
    'jsonld'      => [
        '@context' => 'https://schema.org',
        '@type'    => 'CollectionPage',
        'name'     => $h1,
        'url'      => 'https://ottguru.in' . $self,
    ],
]);
?>

<div class="p-head">
  <?php $logo = tmdb_img($prov['logo_path'], 'w154'); ?>
  <?php if ($logo !== null): ?><img src="<?= h($logo) ?>" alt="<?= h($prov['name']) ?> का logo"><?php endif; ?>
  <div>
    <h1><?= h($h1) ?></h1>
    <p class="dim" style="margin:0">अभी <b><?= $total ?></b> · रोज़ अपडेट ·
      <a href="<?= h(provider_url($prov)) ?>"><?= h($prov['name']) ?> की पूरी सूची →</a></p>
  </div>
</div>

<div class="tabs">
  <span class="tabs-label dim small"><?= h($bhasha) ?>:</span>
  <a class="<?= $want_type === 'movie' ? 'on' : '' ?>"
     href="<?= h(provider_url($prov) . '/' . $lang_slug . '-movies') ?>">फिल्में</a>
  <?php if ($dusra_total > 0 || $want_type === 'tv'): ?>
  <a class="<?= $want_type === 'tv' ? 'on' : '' ?>"
     href="<?= h(provider_url($prov) . '/' . $lang_slug . '-series') ?>">वेब सीरीज़</a>
  <?php endif; ?>
</div>

<?php render_title_grid($titles); ?>

<?php if ($pages > 1): ?>
<div class="pager">
  <?php if ($page > 1): ?><a href="<?= h($self . ($page - 1 > 1 ? '?page=' . ($page - 1) : '')) ?>">← पिछला</a><?php endif; ?>
  <span class="here">पन्ना <?= $page ?> / <?= $pages ?></span>
  <?php if ($page < $pages): ?><a href="<?= h($self . '?page=' . ($page + 1)) ?>">अगला →</a><?php endif; ?>
</div>
<?php endif; ?>

<div class="note">
  यह सूची <?= h($bhasha) ?> में <b>बनी</b> <?= h($kya) ?> दिखाती है (मूल या बोली गई भाषा)।
  "<?= h($prov['name']) ?> पर <?= h($bhasha) ?> ऑडियो (dub) मिलेगी या नहीं" — वह जानकारी
  अलग है और जल्द जुड़ेगी।
</div>

<?php page_footer(); ?>
