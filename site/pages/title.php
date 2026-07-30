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

// ---- अतिरिक्त TMDB मेटाडेटा (genre, cast/crew, trailer, certification) --------
// नई tables से; अगर सर्वर पर अभी migrate नहीं चला (tables नहीं बनीं) तो पन्ना
// बिना इनके भी पूरा चलता है — इसलिए try/catch में। sync भरने के बाद अपने-आप दिखेंगे।
$tid_i  = (int) $title['id'];
$genres = $cast = $crew = $videos = [];
$meta   = null;
try {
    $genres = all($PDO, "SELECT g.name_en, g.slug FROM title_genres tg
                          JOIN genres g ON g.id = tg.genre_id
                         WHERE tg.title_id = ? ORDER BY g.name_en", [$tid_i]);
    $cast   = all($PDO, "SELECT p.name, p.profile_path, tc.role FROM title_credits tc
                          JOIN people p ON p.id = tc.person_id
                         WHERE tc.title_id = ? AND tc.credit_kind = 'cast'
                         ORDER BY tc.ord", [$tid_i]);
    $crew   = all($PDO, "SELECT p.name, tc.role FROM title_credits tc
                          JOIN people p ON p.id = tc.person_id
                         WHERE tc.title_id = ? AND tc.credit_kind = 'crew'
                         ORDER BY tc.ord", [$tid_i]);
    $videos = all($PDO, "SELECT yt_key, name, kind FROM title_videos
                         WHERE title_id = ? ORDER BY ord", [$tid_i]);
    $meta   = one($PDO, "SELECT certification, tagline, digital_date FROM title_meta
                         WHERE title_id = ?", [$tid_i]);
} catch (Throwable $e) {
    // नई tables अभी मौजूद नहीं — कोई बात नहीं, बाक़ी पन्ना ज्यों का त्यों
}
$directors = array_values(array_filter($crew, fn ($c) => $c['role'] === 'Director'));
$writers   = array_values(array_filter($crew,
    fn ($c) => in_array($c['role'], ['Writer', 'Screenplay', 'Story', 'Creator'], true)));
$trailer   = $videos[0] ?? null;
$cert      = nz((string) ($meta['certification'] ?? ''));

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

$node = [
    '@type' => $is_tv ? 'TVSeries' : 'Movie',
    'name'  => $title['title'],
    'url'   => 'https://ottguru.in' . title_url($title),
];
if (nz($title['original_title'] ?? null) !== null && $title['original_title'] !== $title['title']) {
    $node['alternateName'] = $title['original_title'];
}
if (nz($title['overview'] ?? null) !== null) {
    $node['description'] = $title['overview'];
}
if (nz($title['release_date'] ?? null) !== null) {
    $node['datePublished'] = $title['release_date'];
}
if (tmdb_img($title['poster_path'], 'w500') !== null) {
    $node['image'] = tmdb_img($title['poster_path'], 'w500');
}
if ((float) $title['vote_average'] > 0 && (int) $title['vote_count'] >= 10) {
    $node['aggregateRating'] = [
        '@type' => 'AggregateRating', 'ratingValue' => (float) $title['vote_average'],
        'bestRating' => 10, 'ratingCount' => (int) $title['vote_count'],
    ];
}
// नया मेटाडेटा — schema को असली डेटा से और मज़बूत करता है
if ($genres !== []) {
    $node['genre'] = array_map(fn ($g) => $g['name_en'], $genres);
}
if ($cast !== []) {
    $node['actor'] = array_map(fn ($c) => ['@type' => 'Person', 'name' => $c['name']],
        array_slice($cast, 0, 10));
}
if ($directors !== []) {
    $node['director'] = array_map(fn ($c) => ['@type' => 'Person', 'name' => $c['name']], $directors);
}
if ($cert !== null) {
    $node['contentRating'] = $cert;
}
if ($trailer !== null) {
    $thumb = tmdb_img($title['backdrop_path'] ?? null, 'w780') ?? tmdb_img($title['poster_path'], 'w500');
    $node['trailer'] = array_filter([
        '@type'        => 'VideoObject',
        'name'         => nz((string) ($trailer['name'] ?? '')) ?? ($title['title'] . ' Trailer'),
        'description'  => $title['title'],
        'embedUrl'     => 'https://www.youtube.com/embed/' . $trailer['yt_key'],
        'thumbnailUrl' => $thumb,
    ]);
}

// ---- FAQ — सिर्फ़ हमारे डेटा से (visible section + FAQPage schema, दोनों एक स्रोत) ----
$paisaNames = array_values(array_unique(array_map(fn ($o) => $o['name'], $paisa)));
$faqs = [];
$faqs[] = [
    'q' => tf('%s कहाँ देखें?', $title['title']),
    'a' => $now_on !== []
        ? tf('%s अभी %s पर सब्सक्रिप्शन में उपलब्ध है।', $title['title'], implode(', ', $now_on))
          . ($paisaNames !== [] ? ' ' . tf('किराये/ख़रीद पर भी: %s।', implode(', ', $paisaNames)) : '')
        : tf('%s अभी भारत में किसी OTT सब्सक्रिप्शन पर उपलब्ध नहीं है।', $title['title']),
];
if ($now_on !== []) {
    $faqs[] = ['q' => tf('क्या %s %s पर है?', $title['title'], $now_on[0]),
               'a' => tf('हाँ, %s अभी %s पर देखी जा सकती है।', $title['title'], $now_on[0])];
}
if (nz($title['release_date'] ?? null) !== null) {
    $faqs[] = ['q' => tf('%s कब रिलीज़ हुई?', $title['title']),
               'a' => tf('%s %s को रिलीज़ हुई थी।', $title['title'], hindi_date($title['release_date']))];
}
if (!$is_tv && (int) ($title['runtime'] ?? 0) > 0) {
    $faqs[] = ['q' => tf('%s की अवधि कितनी है?', $title['title']),
               'a' => tf('%s की अवधि लगभग %d मिनट है।', $title['title'], (int) $title['runtime'])];
}
if ($langs !== []) {
    $faqs[] = ['q' => tf('%s किन भाषाओं में है?', $title['title']),
               'a' => tf('%s इन भाषाओं में है: %s।', $title['title'],
                        implode(', ', array_map(fn ($l) => lang_label($l['lang_code']), $langs)))];
}

// ---- schema @graph: Movie/TVSeries + Breadcrumb + FAQ + Organization ----
$graph = [$node];
$graph[] = [
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => OTT_LANG === 'hi' ? 'होम' : 'Home', 'item' => 'https://ottguru.in/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $title['title'], 'item' => 'https://ottguru.in' . title_url($title)],
    ],
];
if ($faqs !== []) {
    $graph[] = [
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn ($f) => [
            '@type' => 'Question', 'name' => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
        ], $faqs),
    ];
}
$graph[] = ['@type' => 'Organization', 'name' => 'OTTGuru', 'url' => 'https://ottguru.in/'];

page_header([
    'title'       => tf('%s कहाँ देखें', $h1),
    'description' => $desc,
    'canonical'   => title_url($title),
    'image'       => tmdb_img($title['poster_path'], 'w500'),
    'jsonld'      => ['@context' => 'https://schema.org', '@graph' => $graph],
]);
?>
<?php $bd = tmdb_img($title['backdrop_path'] ?? null, 'w1280'); ?>
<?php if ($bd !== null): ?><div class="t-backdrop" style="background-image:url('<?= h($bd) ?>')"></div><?php endif; ?>

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
      <?php if ($cert !== null): ?>
        · <span class="cert" title="<?= h(t('भारत सेंसर रेटिंग')) ?>"><?= h($cert) ?></span>
      <?php endif; ?>
    </p>

    <?php if ($genres !== []): ?>
    <div class="chips">
      <?php foreach ($genres as $g): ?><span class="chip"><?= h($g['name_en']) ?></span><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ((float) $title['vote_average'] > 0):
      $pct = (int) round((float) $title['vote_average'] / 10 * 100);
      $dash = round(157.08 * $pct / 100, 1);
      $col = $pct >= 70 ? 'var(--good)' : ($pct >= 50 ? 'var(--warn)' : 'var(--pink)'); ?>
    <div class="scorebox">
      <div class="score">
        <svg width="58" height="58" viewBox="0 0 58 58">
          <circle cx="29" cy="29" r="25" fill="none" stroke="rgba(255,255,255,.09)" stroke-width="4"></circle>
          <circle cx="29" cy="29" r="25" fill="none" stroke="<?= $col ?>" stroke-width="4" stroke-linecap="round" stroke-dasharray="<?= $dash ?> 157.08"></circle>
        </svg>
        <span class="num"><?= number_format((float) $title['vote_average'], 1) ?><s>/10</s></span>
      </div>
      <div class="meta">
        <div><b>TMDB</b> <?= OTT_LANG === 'hi' ? 'यूज़र स्कोर' : 'user score' ?></div>
        <?php if ((int) $title['vote_count'] > 0): ?><div><?= number_format((int) $title['vote_count']) ?> <?= OTT_LANG === 'hi' ? 'वोट' : 'votes' ?></div><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

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
          <div class="o-since"><?= h(tf('%s से यहाँ है', hindi_month($o['first_seen']))) ?></div>
          <?php $wl = watch_url($o, $title); ?>
          <?php if ($wl !== null): ?>
            <a class="watchbtn" href="<?= h($wl) ?>" rel="nofollow noopener" target="_blank"><?= h(t('अभी देखें')) ?> <span aria-hidden="true">↗</span></a>
          <?php endif; ?>
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
          <?php $wl = watch_url($o, $title); ?>
          <?php if ($wl !== null): ?>
            <a class="watchbtn" href="<?= h($wl) ?>" rel="nofollow noopener" target="_blank"><?= h(t('अभी देखें')) ?> <span aria-hidden="true">↗</span></a>
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
<?php if (nz($meta['tagline'] ?? null) !== null): ?><p class="tagline">“<?= h($meta['tagline']) ?>”</p><?php endif; ?>
<p><?= h($title['overview']) ?></p>
<?php endif; ?>

<?php if ($trailer !== null):
  $tthumb = tmdb_img($title['backdrop_path'] ?? null, 'w780') ?? tmdb_img($title['poster_path'], 'w500'); ?>
<h2><?= h(t('ट्रेलर')) ?></h2>
<div class="trailer" data-yt="<?= h($trailer['yt_key']) ?>" role="button" tabindex="0"
     aria-label="<?= h(tf('%s का ट्रेलर चलाएँ', $title['title'])) ?>">
  <?php if ($tthumb !== null): ?><img src="<?= h($tthumb) ?>" alt="<?= h(tf('%s ट्रेलर', $title['title'])) ?>" loading="lazy"><?php endif; ?>
  <span class="play" aria-hidden="true"></span>
</div>
<?php endif; ?>

<?php if ($cast !== []): ?>
<h2><?= h(t('कलाकार')) ?></h2>
<div class="castrow">
  <?php foreach ($cast as $c): ?>
  <div class="castcard">
    <?php $pf = tmdb_img($c['profile_path'] ?? null, 'w185'); ?>
    <div class="ph"><?php if ($pf !== null): ?><img src="<?= h($pf) ?>" alt="<?= h($c['name']) ?>" loading="lazy"><?php else: ?><span class="noimg"><?= h(mb_substr($c['name'], 0, 1)) ?></span><?php endif; ?></div>
    <div class="nm"><?= h($c['name']) ?></div>
    <?php if (nz($c['role'] ?? null) !== null): ?><div class="rl"><?= h($c['role']) ?></div><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
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

<h2><?= h(t('फिल्म के तथ्य')) ?></h2>
<div class="facts">
  <?php if ($directors !== []): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? ($is_tv ? 'क्रिएटर' : 'निर्देशक') : ($is_tv ? 'Creator' : 'Director') ?></div><div class="v" style="font-size:14px"><?= h(implode(', ', array_map(fn ($c) => $c['name'], $directors))) ?></div></div><?php endif; ?>
  <?php if ($writers !== []): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'लेखक' : 'Writer' ?></div><div class="v" style="font-size:14px"><?= h(implode(', ', array_map(fn ($c) => $c['name'], array_slice($writers, 0, 3)))) ?></div></div><?php endif; ?>
  <?php if ($year !== null): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'रिलीज़ वर्ष' : 'Year' ?></div><div class="v"><?= h($year) ?></div></div><?php endif; ?>
  <div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'किस्म' : 'Type' ?></div><div class="v"><?= h(media_label($title['media_type'])) ?></div></div>
  <?php if ($cert !== null): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'सेंसर रेटिंग' : 'Certification' ?></div><div class="v"><?= h($cert) ?></div></div><?php endif; ?>
  <?php if (!$is_tv && (int) ($title['runtime'] ?? 0) > 0): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'अवधि' : 'Runtime' ?></div><div class="v"><?= (int) $title['runtime'] ?> <?= OTT_LANG === 'hi' ? 'मिनट' : 'min' ?></div></div><?php endif; ?>
  <?php if ($original !== []): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'मूल भाषा' : 'Original language' ?></div><div class="v"><?= h(lang_label($original[0])) ?></div></div><?php endif; ?>
  <?php if (nz($title['release_date'] ?? null) !== null): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'रिलीज़ तारीख़' : 'Release date' ?></div><div class="v" style="font-size:14px"><?= h(hindi_date($title['release_date'])) ?></div></div><?php endif; ?>
  <?php if (nz($meta['digital_date'] ?? null) !== null): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'OTT/डिजिटल रिलीज़' : 'OTT/digital release' ?></div><div class="v" style="font-size:14px"><?= h(hindi_date($meta['digital_date'])) ?></div></div><?php endif; ?>
  <?php if (nz($title['status'] ?? null) !== null): ?><div class="fact"><div class="k"><?= OTT_LANG === 'hi' ? 'स्थिति' : 'Status' ?></div><div class="v" style="font-size:14px"><?= h($title['status']) ?></div></div><?php endif; ?>
</div>

<?php if ($faqs !== []): ?>
<h2><?= h(t('अक्सर पूछे सवाल')) ?></h2>
<div class="faq">
  <?php foreach ($faqs as $i => $f): ?>
  <details<?= $i === 0 ? ' open' : '' ?>><summary><?= h($f['q']) ?></summary><div class="a"><?= h($f['a']) ?></div></details>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$shareUrl = 'https://ottguru.in' . title_url($title);
$u = rawurlencode($shareUrl);
$txt = rawurlencode($title['title'] . ' — OTTGuru');
?>
<h2><?= h(t('शेयर करें')) ?></h2>
<div class="share" data-url="<?= h($shareUrl) ?>">
  <a href="https://wa.me/?text=<?= $txt ?>%20<?= $u ?>" target="_blank" rel="noopener"><span class="ic" style="background:#25D366"></span>WhatsApp</a>
  <a href="https://t.me/share/url?url=<?= $u ?>&text=<?= $txt ?>" target="_blank" rel="noopener"><span class="ic" style="background:#2AABEE"></span>Telegram</a>
  <a href="https://twitter.com/intent/tweet?url=<?= $u ?>&text=<?= $txt ?>" target="_blank" rel="noopener"><span class="ic" style="background:#111"></span>X</a>
  <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $u ?>" target="_blank" rel="noopener"><span class="ic" style="background:#1877F2"></span>Facebook</a>
  <button type="button" class="s-copy"><span class="ic" style="background:var(--grad)"></span><?= h(t('लिंक कॉपी')) ?></button>
</div>

<script>
(function(){
  // timeline reveal
  var els = document.querySelectorAll('[data-reveal]');
  if (matchMedia('(prefers-reduced-motion:reduce)').matches){
    els.forEach(function(e){e.classList.add('in');});
  } else {
    var io = new IntersectionObserver(function(es,o){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');o.unobserve(e.target);}});},{threshold:.2});
    els.forEach(function(e){io.observe(e);});
  }
  // trailer — क्लिक पर ही YouTube iframe लोड (तब तक कोई third-party नहीं)
  var tr = document.querySelector('.trailer[data-yt]');
  if (tr){
    var load = function(){
      var k = tr.getAttribute('data-yt');
      var f = document.createElement('iframe');
      f.src = 'https://www.youtube-nocookie.com/embed/' + k + '?autoplay=1&rel=0';
      f.title = 'Trailer'; f.allow = 'accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture';
      f.allowFullscreen = true; f.loading = 'lazy';
      tr.innerHTML = ''; tr.classList.add('on'); tr.removeAttribute('role'); tr.appendChild(f);
    };
    tr.addEventListener('click', load);
    tr.addEventListener('keydown', function(e){ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); load(); } });
  }
  // share — copy link
  var sc = document.querySelector('.share .s-copy'), box = document.querySelector('.share');
  if (sc && box){ sc.addEventListener('click', function(){
    var url = box.getAttribute('data-url'), old = sc.lastChild.textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject())
      .then(function(){ sc.lastChild.textContent = <?= json_encode(t('कॉपी हो गया ✓'), JSON_UNESCAPED_UNICODE) ?>; })
      .catch(function(){ prompt(<?= json_encode(t('यह लिंक कॉपी कीजिए:'), JSON_UNESCAPED_UNICODE) ?>, url); })
      .finally(function(){ setTimeout(function(){ sc.lastChild.textContent = old; }, 1800); });
  }); }
})();
</script>
<?php page_footer(); ?>
