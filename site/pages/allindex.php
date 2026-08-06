<?php
/**
 * A–Z INDEX — /all?l=A&page=N  (सारी titles का browsable crawl-index)
 * मक़सद: Google को हर title पेज तक अंदरूनी link मिले (deep crawl)।
 * यहाँ वही titles जो कभी किसी OTT पर रहीं (sitemap जैसा = index-योग्य, thin नहीं)।
 */
declare(strict_types=1);

$country  = $CFG['country'] ?? 'IN';
$per_page = 300;
$L        = OTT_LANG === 'hi';

$letters = array_merge(range('A', 'Z'), ['#']);
$l = strtoupper(trim((string) ($_GET['l'] ?? 'A')));
if (!in_array($l, $letters, true)) {
    $l = 'A';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$base = '/all';

// कोई भी availability (is_current 0/1) = हमारे पास इतिहास = index-योग्य
$AV = "JOIN availability a ON a.title_id = t.id AND a.country = ?";
$cond = $l === '#' ? "t.title NOT REGEXP '^[A-Za-z]'" : "t.title LIKE ?";
$args = $l === '#' ? [$country] : [$country, $l . '%'];

$total = (int) scalar($PDO, "SELECT COUNT(DISTINCT t.id) FROM titles t $AV WHERE $cond", $args);
$pages = max(1, (int) ceil($total / $per_page));
$page  = min($page, $pages);

$titles = all($PDO, "
    SELECT DISTINCT t.slug, t.title, t.release_year, t.media_type
      FROM titles t $AV
     WHERE $cond
     ORDER BY t.title
     LIMIT $per_page OFFSET " . (($page - 1) * $per_page), $args);

$crumbs = [
    ['name' => $L ? 'होम' : 'Home', 'url' => '/'],
    ['name' => $L ? 'सारी titles (A–Z)' : 'All titles (A–Z)', 'url' => $base],
];
page_header([
    'title'       => ($L ? 'सारी फिल्में और सीरीज़ — A से Z' : 'All movies & series — A to Z') . " · $l",
    'description' => $L ? 'OTT गुरु पर मौजूद सारी फिल्में और वेब सीरीज़, वर्णमाला क्रम में — कहाँ देखें और कब से कब तक कहाँ रहीं।'
                        : 'Every movie and web series on OTT Guru, listed A–Z — where to watch and their full availability history.',
    'canonical'   => $base . '?l=' . rawurlencode($l),
    'noindex'     => $page > 1,   // पहला letter-पेज index; pages गहरे noindex
    'breadcrumb'  => $crumbs,
]);
crumbs($crumbs);
?>

<div class="phead" style="padding-bottom:14px">
  <span class="eyebrow"><?= $L ? 'सूचकांक' : 'Index' ?></span>
  <h1 style="margin:4px 0 4px"><?= $L ? 'सारी फिल्में और सीरीज़ (A–Z)' : 'All movies & series (A–Z)' ?></h1>
  <p class="dim" style="margin:0"><?= $L ? 'अक्षर चुनिए — हर title के पेज पर उसका पूरा OTT-इतिहास है।' : 'Pick a letter — each title page has its full OTT history.' ?></p>
</div>

<div class="chips" style="margin-bottom:16px">
  <?php foreach ($letters as $x): ?><a class="chip <?= $x === $l ? 'on' : '' ?>" href="/all?l=<?= rawurlencode($x) ?>"><?= h($x) ?></a><?php endforeach; ?>
</div>

<?php if ($titles === []): ?>
  <div class="offer-none"><?= h(tf('“%s” से शुरू होने वाली कोई title नहीं।', $l)) ?></div>
<?php else: ?>
<div class="azlist">
  <?php foreach ($titles as $t): ?>
  <a href="<?= h(title_url($t)) ?>"><?= h($t['title']) ?><?php if (!empty($t['release_year'])): ?> <span class="dim">(<?= h((string) $t['release_year']) ?>)</span><?php endif; ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($pages > 1): ?>
<div class="pager">
  <?php $qs = fn (int $p) => $base . '?l=' . rawurlencode($l) . ($p > 1 ? '&page=' . $p : ''); ?>
  <?php if ($page > 1): ?><a href="<?= h($qs($page - 1)) ?>"><?= h(t('← पिछला')) ?></a><?php endif; ?>
  <span class="pageno"><?= $page ?> / <?= $pages ?></span>
  <?php if ($page < $pages): ?><a href="<?= h($qs($page + 1)) ?>"><?= h(t('अगला →')) ?></a><?php endif; ?>
</div>
<?php endif; ?>

<?php page_footer(); ?>
