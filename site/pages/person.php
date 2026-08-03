<?php
/**
 * PERSON पन्ना — कलाकार / निर्देशक  (Content Hub · Feature 2)
 * रास्ता: /person/{id}[/{slug}]  — id (TMDB person id) पक्का, slug सिर्फ़ SEO के लिए।
 * index.php से: $want_pid (int), $want_pslug (?string)
 */
declare(strict_types=1);

try {
    $person = one($PDO, 'SELECT * FROM people WHERE id = ?', [$want_pid]);
} catch (Throwable $e) {
    $person = null;   // people table अभी नहीं बनी
}
if ($person === null) {
    not_found();
}

$country = $CFG['country'] ?? 'IN';
$pname   = (string) $person['name'];

// slug कभी बदले तो canonical पर 301 (SEO — एक ही पता index हो)
if (($want_pslug ?? '') !== slugify($pname)) {
    header('Location: ' . person_url($person), true, 301);
    exit;
}

// सिर्फ़ वही titles जो अभी भारत में OTT पर उपलब्ध हैं
$AV = "EXISTS (SELECT 1 FROM availability a WHERE a.title_id = t.id AND a.is_current = 1
        AND a.country = ? AND a.offer_type IN ('flatrate','ads','free'))";

$asActor = all($PDO, "
    SELECT DISTINCT t.slug, t.title, t.release_year, t.poster_path, t.media_type, t.vote_average, t.popularity
      FROM title_credits tc
      JOIN titles t ON t.id = tc.title_id
     WHERE tc.person_id = ? AND tc.credit_kind = 'cast' AND $AV
     ORDER BY t.popularity DESC LIMIT 60", [$want_pid, $country]);

$asCrew = all($PDO, "
    SELECT t.slug, t.title, t.release_year, t.poster_path, t.media_type, t.vote_average, t.popularity,
           MIN(tc.role) AS role
      FROM title_credits tc
      JOIN titles t ON t.id = tc.title_id
     WHERE tc.person_id = ? AND tc.credit_kind = 'crew' AND $AV
     GROUP BY t.id ORDER BY t.popularity DESC LIMIT 60", [$want_pid, $country]);

$total = count($asActor) + count($asCrew);
if ($total === 0) {
    not_found();   // इस व्यक्ति की कोई भी title अभी OTT पर नहीं — thin पेज नहीं बनाते
}

// भूमिका सारांश (Actor · Director) — असली डेटा से
$crewRoles = array_values(array_unique(array_map(fn ($r) => (string) $r['role'], $asCrew)));
$roleBits  = [];
if ($asActor !== []) {
    $roleBits[] = OTT_LANG === 'hi' ? 'अभिनेता' : 'Actor';
}
foreach (['Director' => ['निर्देशक', 'Director'], 'Writer' => ['लेखक', 'Writer'],
          'Creator' => ['क्रिएटर', 'Creator']] as $en => $lbl) {
    if (in_array($en, $crewRoles, true)) {
        $roleBits[] = OTT_LANG === 'hi' ? $lbl[0] : $lbl[1];
    }
}
$roleStr = implode(' · ', array_values(array_unique($roleBits)));

$photo   = tmdb_img($person['profile_path'] ?? null, 'w185');
$canon   = person_url($person);

$desc = tf('%s की वे फिल्में और वेब सीरीज़ जो अभी भारत में OTT पर उपलब्ध हैं — कहाँ देखें, किस platform पर। रोज़ अपडेट, OTT गुरु पर।', $pname);

$crumbs = [
    ['name' => OTT_LANG === 'hi' ? 'होम' : 'Home', 'url' => '/'],
    ['name' => $pname, 'url' => $canon],
];

page_header([
    'title'       => tf('%s — फिल्में और सीरीज़ जो अभी OTT पर हैं', $pname),
    'description' => $desc,
    'canonical'   => $canon,
    'image'       => $photo,
    'noindex'     => $total < 2,   // बहुत पतला हो तो index नहीं
    'breadcrumb'  => $crumbs,
    'jsonld'      => array_filter([
        '@context' => 'https://schema.org',
        '@type'    => 'Person',
        'name'     => $pname,
        'url'      => 'https://ottguru.in' . $canon,
        'image'    => $photo,
        'jobTitle' => $roleStr !== '' ? $roleStr : null,
    ]),
]);
crumbs($crumbs);
?>

<div class="phead">
  <div class="phead-top">
    <?php if ($photo !== null): ?>
      <img class="pface" src="<?= h($photo) ?>" alt="<?= h($pname) ?>">
    <?php else: ?>
      <span class="lg pface" style="background:var(--grad)"><?= h(mb_strtoupper(mb_substr($pname, 0, 1, 'UTF-8'))) ?></span>
    <?php endif; ?>
    <div>
      <h1><?= h($pname) ?></h1>
      <div class="sub"><?= $roleStr !== '' ? h($roleStr) . ' · ' : '' ?><?= h(tf('%d अभी OTT पर', $total)) ?></div>
    </div>
  </div>
</div>

<?php if ($asActor !== []): ?>
<h2><?= h(OTT_LANG === 'hi' ? 'बतौर अभिनेता — अभी OTT पर' : 'As actor — on OTT now') ?></h2>
<?php render_title_grid($asActor); ?>
<?php endif; ?>

<?php if ($asCrew !== []): ?>
<h2 style="margin-top:40px"><?= h(OTT_LANG === 'hi' ? 'बतौर निर्देशक / लेखक — अभी OTT पर' : 'As director / writer — on OTT now') ?></h2>
<?php render_title_grid($asCrew); ?>
<?php endif; ?>

<?php page_footer(); ?>
