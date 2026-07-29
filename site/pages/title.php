<?php
/**
 * TITLE पन्ना — "यह फिल्म कहाँ देखें" + उपलब्धता का पूरा इतिहास
 * रास्ता: /movie/{slug} या /series/{slug}
 * queries.sql की query 1 (अभी कहाँ है) और 6 (इतिहास) यहीं चलती हैं।
 *
 * index.php से मिलता है: $want_slug, $want_type
 */
declare(strict_types=1);

$title = one($PDO, 'SELECT * FROM titles WHERE slug = ?', [$want_slug]);
if ($title === null) {
    not_found();
}

// slug सही पर media_type का prefix गलत (movie की जगह series) → canonical पर भेजो।
// slug कभी बदलता नहीं, इसलिए यह redirect हमेशा सही रहेगा।
if ($title['media_type'] !== $want_type) {
    header('Location: ' . title_url($title), true, 301);
    exit;
}

$country = $CFG['country'] ?? 'IN';

// ---- query 1: अभी कहाँ है --------------------------------------------------
$offers = all($PDO, "
    SELECT p.slug, p.name, p.logo_path, a.offer_type, a.watch_link, a.first_seen
      FROM availability a
      JOIN providers p ON p.id = a.provider_id
     WHERE a.title_id = ?
       AND a.country = ?
       AND a.is_current = 1
     ORDER BY FIELD(a.offer_type, 'flatrate','ads','free','rent','buy'),
              p.display_priority",
    [(int) $title['id'], $country]);

$stream = array_values(array_filter($offers, fn ($o) => in_array($o['offer_type'], ['flatrate','ads','free'], true)));
$paisa  = array_values(array_filter($offers, fn ($o) => in_array($o['offer_type'], ['rent','buy'], true)));

// ---- query 6: इतिहास (spells + घटनाएँ) --------------------------------------
$spells = all($PDO, "
    SELECT p.name, p.slug AS pslug, a.offer_type, a.first_seen, a.last_seen, a.is_current
      FROM availability a
      JOIN providers p ON p.id = a.provider_id
     WHERE a.title_id = ? AND a.country = ?
     ORDER BY a.is_current DESC, a.first_seen",
    [(int) $title['id'], $country]);

$events = all($PDO, "
    SELECT c.changed_on, c.change_type, p.name, c.offer_type
      FROM availability_changes c
      JOIN providers p ON p.id = c.provider_id
     WHERE c.title_id = ? AND c.country = ?
     ORDER BY c.changed_on DESC, c.id DESC",
    [(int) $title['id'], $country]);

// ---- फिल्म की भाषाएँ (यह dub नहीं है — CLAUDE.md §5) -------------------------
// जो भाषा original भी है और spoken भी, वो एक ही बार दिखे — (मूल) के साथ
$langs    = all($PDO, "
    SELECT lang_code, kind FROM title_languages
     WHERE title_id = ? ORDER BY kind = 'original' DESC, lang_code",
    [(int) $title['id']]);
$original = array_column(array_filter($langs, fn ($l) => $l['kind'] === 'original'), 'lang_code');
$langs    = array_values(array_filter($langs,
    fn ($l) => $l['kind'] === 'original' || !in_array($l['lang_code'], $original, true)));

// ---- meta / schema.org -------------------------------------------------------
$is_tv    = $title['media_type'] === 'tv';
$year     = nz((string) ($title['release_year'] ?? '')) ;
$h1       = $title['title'] . ($year !== null ? ' (' . $year . ')' : '');
$now_on   = array_values(array_unique(array_map(fn ($o) => $o['name'], $stream)));

if ($now_on !== []) {
    $desc = tf(
        '%s अभी %s पर देखी जा सकती है। कब आई, कहाँ-कहाँ रही — पूरा इतिहास OTT गुरु पर।',
        $title['title'],
        implode(', ', array_slice($now_on, 0, 3))
    );
} else {
    $desc = tf('%s अभी भारत में किसी OTT के सब्सक्रिप्शन में नहीं है। यह पहले कहाँ थी और कब हटी — पूरा इतिहास OTT गुरु पर।', $title['title']);
}

$jsonld = [
    '@context'      => 'https://schema.org',
    '@type'         => $is_tv ? 'TVSeries' : 'Movie',
    'name'          => $title['title'],
    'url'           => 'https://ottguru.in' . title_url($title),
];
if (nz($title['original_title'] ?? null) !== null && $title['original_title'] !== $title['title']) {
    $jsonld['alternateName'] = $title['original_title'];
}
if (nz($title['overview'] ?? null) !== null) {
    $jsonld['description'] = $title['overview'];
}
if (nz($title['release_date'] ?? null) !== null) {
    $jsonld['datePublished'] = $title['release_date'];
}
if (tmdb_img($title['poster_path'], 'w500') !== null) {
    $jsonld['image'] = tmdb_img($title['poster_path'], 'w500');
}
if ((float) $title['vote_average'] > 0 && (int) $title['vote_count'] >= 10) {
    $jsonld['aggregateRating'] = [
        '@type'       => 'AggregateRating',
        'ratingValue' => (float) $title['vote_average'],
        'bestRating'  => 10,
        'ratingCount' => (int) $title['vote_count'],
    ];
}

page_header([
    'title'       => tf('%s कहाँ देखें', $h1),
    'description' => $desc,
    'canonical'   => title_url($title),
    'image'       => tmdb_img($title['poster_path'], 'w500'),
    'jsonld'      => $jsonld,
]);
?>

<div class="t-head">
  <?php $poster = tmdb_img($title['poster_path'], 'w342'); ?>
  <?php if ($poster !== null): ?>
  <div class="t-poster"><img src="<?= h($poster) ?>" alt="<?= h(tf('%s का poster', $title['title'])) ?>"></div>
  <?php endif; ?>

  <div class="t-meta">
    <h1><?= h($title['title']) ?><?= $year !== null ? ' <span class="dim">(' . h($year) . ')</span>' : '' ?></h1>
    <p class="t-sub">
      <?= h(media_label($title['media_type'])) ?>
      <?php if (nz($title['original_title'] ?? null) !== null && $title['original_title'] !== $title['title']): ?>
        · <?= h(t('मूल नाम:')) ?> <b><?= h($title['original_title']) ?></b>
      <?php endif; ?>
      <?php if (!$is_tv && (int) ($title['runtime'] ?? 0) > 0): ?>
        · <?= h(tf('%d मिनट', (int) $title['runtime'])) ?>
      <?php endif; ?>
      <?php if ((float) $title['vote_average'] > 0): ?>
        · <span class="rating">★ <?= number_format((float) $title['vote_average'], 1) ?></span><span class="dim">/10</span>
      <?php endif; ?>
    </p>

    <?php if ($langs !== []): ?>
    <div class="badges">
      <?php foreach ($langs as $l): ?>
        <span class="badge"><?= h(lang_label($l['lang_code'])) ?><?= $l['kind'] === 'original' ? ' ' . h(t('(मूल)')) : '' ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2><?= h(t('अभी कहाँ देखें')) ?></h2>
    <?php if ($stream === [] && $paisa === []): ?>
      <div class="offer-none">
        <?= h(tf('यह %s अभी भारत में किसी OTT पर नहीं दिख रही।', media_label($title['media_type']))) ?>
        <?php if ($spells !== []): ?><?= h(t('नीचे इतिहास में देखिए यह पहले कहाँ थी।')) ?><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="offers">
        <?php foreach ($stream as $o): ?>
        <div class="offer">
          <?php $logo = tmdb_img($o['logo_path'], 'w92'); ?>
          <?php if ($logo !== null): ?><img src="<?= h($logo) ?>" alt="<?= h($o['name']) ?>"><?php endif; ?>
          <div>
            <div class="o-name"><a href="/platform/<?= h(rawurlencode($o['slug'])) ?>"><?= h($o['name']) ?></a></div>
            <div class="o-type"><?= h(offer_label($o['offer_type'])) ?></div>
          </div>
          <div class="o-since"><?= h(tf('%s से यहाँ है', hindi_month($o['first_seen']))) ?>
            <?php if (nz($o['watch_link'] ?? null) !== null): ?>
              <br><a class="o-link" href="<?= h($o['watch_link']) ?>" rel="nofollow noopener" target="_blank"><?= h(t('देखें ↗')) ?></a>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($paisa !== []): ?>
      <h2><?= h(t('किराये / ख़रीद पर')) ?></h2>
      <div class="offers">
        <?php foreach ($paisa as $o): ?>
        <div class="offer">
          <?php $logo = tmdb_img($o['logo_path'], 'w92'); ?>
          <?php if ($logo !== null): ?><img src="<?= h($logo) ?>" alt="<?= h($o['name']) ?>"><?php endif; ?>
          <div>
            <div class="o-name"><a href="/platform/<?= h(rawurlencode($o['slug'])) ?>"><?= h($o['name']) ?></a></div>
            <div class="o-type"><?= h(offer_label($o['offer_type'])) ?></div>
          </div>
          <?php if (nz($o['watch_link'] ?? null) !== null): ?>
            <div class="o-since"><a class="o-link" href="<?= h($o['watch_link']) ?>" rel="nofollow noopener" target="_blank"><?= h(t('देखें ↗')) ?></a></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (nz($title['overview'] ?? null) !== null): ?>
<h2><?= h(t('कहानी')) ?></h2>
<p><?= h($title['overview']) ?></p>
<?php endif; ?>

<?php
// ---- इतिहास को एक animated timeline में: रिलीज़ → हर बदलाव → अभी ----------------
$tl = [];
if (nz($title['release_date'] ?? null) !== null) {
    $tl[] = ['when' => $title['release_date'], 'kind' => 'rel', 'html' => '<b>' . h(t('रिलीज़ हुई')) . '</b>'];
}
foreach (array_reverse($events) as $e) {   // $events DESC है → ASC कर लेते हैं
    if ($e['change_type'] === 'added') {
        $tl[] = ['when' => $e['changed_on'], 'kind' => 'add',
                 'html' => tf('%s पर आई', '<b>' . h($e['name']) . '</b>'),
                 'tag'  => offer_label($e['offer_type'])];
    } else {
        $tl[] = ['when' => $e['changed_on'], 'kind' => 'rm',
                 'html' => tf('%s से हटी', '<b>' . h($e['name']) . '</b>')];
    }
}
$now_names = array_values(array_unique(array_map(fn ($o) => $o['name'], $stream)));
if ($now_names !== []) {
    $tl[] = ['when' => null, 'kind' => 'now',
             'html' => tf('अभी %s पर', '<b>' . h(implode(', ', $now_names)) . '</b>'),
             'tag'  => t('लाइव')];
}
?>
<?php if ($tl !== []): ?>
<h2><?= h(t('उपलब्धता का इतिहास')) ?></h2>
<p class="dim small"><?= h(t('यह जानकारी सिर्फ़ OTT गुरु पर है — हम रोज़ जाँचते हैं कि कौन सी चीज़ किस platform पर आई और कब हटी।')) ?></p>
<div class="tlwrap" data-reveal>
  <div class="timeline">
    <div class="tline"><i></i></div>
    <?php foreach ($tl as $n): ?>
    <div class="tev <?= h($n['kind'] === 'rel' ? '' : $n['kind']) ?>">
      <span class="node"></span>
      <div class="when"><?= $n['when'] !== null ? h(hindi_date($n['when'])) : h(t('आज')) ?></div>
      <div class="what"><?= $n['html'] ?><?php if (!empty($n['tag'])): ?><span class="tag"><?= h($n['tag']) ?></span><?php endif; ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($langs !== []): ?>
<div class="note">
  <?= h(tf('ऊपर लिखी भाषाएँ इस %s की मूल/बोली गई भाषाएँ हैं। किस platform पर कौन सी ऑडियो (dub) मिलेगी — यह जानकारी जल्द जुड़ेगी।', media_label($title['media_type']))) ?>
</div>
<?php endif; ?>

<script>
(function(){
  var els = document.querySelectorAll('[data-reveal]');
  if (matchMedia('(prefers-reduced-motion:reduce)').matches){els.forEach(function(e){e.classList.add('in');});return;}
  var io = new IntersectionObserver(function(es,o){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');o.unobserve(e.target);}});},{threshold:.2});
  els.forEach(function(e){io.observe(e);});
})();
</script>
<?php page_footer(); ?>
