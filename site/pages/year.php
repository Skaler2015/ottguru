<?php
/**
 * YEAR पेज — /year/{yyyy}  ("2024 की फिल्में/सीरीज़ जो अभी OTT पर हैं")
 * पूरी तरह auto (DB से)। index.php से: $want_year (4-अंकों का, route में जँचा)
 */
declare(strict_types=1);

$country  = $CFG['country'] ?? 'IN';
$per_page = 40;
$L        = OTT_LANG === 'hi';
$yr       = (int) $want_year;
if ($yr < 1930 || $yr > ((int) date('Y') + 2)) {
    not_found();
}

$AV = "JOIN availability a ON a.title_id = t.id AND a.is_current = 1
       AND a.country = ? AND a.offer_type IN ('flatrate','ads','free')";

$page = max(1, (int) ($_GET['page'] ?? 1));
$base = '/year/' . $yr;

$total = (int) scalar($PDO, "SELECT COUNT(DISTINCT t.id) FROM titles t $AV WHERE t.release_year = ?", [$country, $yr]);
$pages = max(1, (int) ceil($total / $per_page));
$page  = min($page, $pages);

$titles = all($PDO, "
    SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type, t.vote_average
      FROM titles t $AV
     WHERE t.release_year = ?
     ORDER BY t.popularity DESC
     LIMIT $per_page OFFSET " . (($page - 1) * $per_page), [$country, $yr]);

// आस-पास के साल — internal linking
$years = [];
for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 12; $y--) {
    $years[] = $y;
}

$h1 = $L ? "$yr की फिल्में और सीरीज़ — अभी OTT पर" : "$yr movies & shows — on OTT now";
$crumbs = [
    ['name' => $L ? 'होम' : 'Home', 'url' => '/'],
    ['name' => $L ? 'ब्राउज़' : 'Browse', 'url' => '/browse'],
    ['name' => (string) $yr, 'url' => $base],
];
page_header([
    'title'       => $h1 . " ($total)",
    'description' => $L ? "$yr में रिलीज़ हुई वे फिल्में और वेब सीरीज़ जो अभी भारत में OTT (Netflix, Prime, ZEE5, JioHotstar आदि) पर उपलब्ध हैं — कहाँ देखें, रोज़ अपडेट।"
                        : "Movies and web series from $yr that are available on OTT in India right now — where to watch, updated daily.",
    'canonical'   => $base,
    'noindex'     => $page > 1 || $total < 3,
    'breadcrumb'  => $crumbs,
    'jsonld'      => ['@context' => 'https://schema.org', '@type' => 'CollectionPage',
                      'name' => $h1, 'url' => 'https://ottguru.in' . $base],
]);
crumbs($crumbs);
?>

<div class="phead" style="padding-bottom:16px">
  <span class="eyebrow"><?= $L ? 'साल' : 'Year' ?></span>
  <h1 style="margin:4px 0 6px"><?= h($h1) ?> <span class="dim" style="font-weight:600;font-size:.55em">(<?= $total ?>)</span></h1>
</div>

<div class="chips" style="margin-bottom:16px">
  <?php foreach ($years as $y): ?><a class="chip <?= $y === $yr ? 'on' : '' ?>" href="/year/<?= $y ?>"><?= $y ?></a><?php endforeach; ?>
</div>

<?php if ($titles === []): ?>
  <div class="offer-none"><?= h(tf('%d के लिए अभी कोई उपलब्ध title नहीं।', $yr)) ?></div>
<?php else: ?>
  <?php render_title_grid($titles); ?>
<?php endif; ?>

<?php if ($pages > 1): ?>
<div class="pager">
  <?php $qs = fn (int $p) => $base . ($p > 1 ? '?page=' . $p : ''); ?>
  <?php if ($page > 1): ?><a href="<?= h($qs($page - 1)) ?>"><?= h(t('← पिछला')) ?></a><?php endif; ?>
  <span class="pageno"><?= $page ?> / <?= $pages ?></span>
  <?php if ($page < $pages): ?><a href="<?= h($qs($page + 1)) ?>"><?= h(t('अगला →')) ?></a><?php endif; ?>
</div>
<?php endif; ?>

<?php page_footer(); ?>
