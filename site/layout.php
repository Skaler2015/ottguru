<?php
/**
 * हर पन्ने का साझा ढाँचा। पन्ने page_header() से शुरू और
 * page_footer() पर ख़त्म होते हैं — बीच में सिर्फ़ अपना content छापते हैं।
 */
declare(strict_types=1);

/**
 * $opt:
 *   title       — <title> (साइट का नाम अपने-आप जुड़ जाता है)
 *   description — meta description
 *   canonical   — canonical path, जैसे '/movie/jawan-2023'
 *   image       — og:image का पूरा URL
 *   jsonld      — schema.org array (जैसा है वैसा छप जाएगा)
 *   noindex     — true = robots को मना करना (ख़ाली/paginated पन्नों के लिए)
 */
function page_header(array $opt = []): void
{
    $site  = 'OTT Guru';
    $title = isset($opt['title']) ? $opt['title'] . ' — ' . $site : $site . ' — कौन सी फिल्म किस OTT पर है';
    $desc  = $opt['description'] ?? 'कौन सी फिल्म या वेब सीरीज़ किस OTT platform पर है — Netflix, Prime Video, Hotstar, ZEE5, SonyLIV। platform बदलने का पूरा इतिहास सिर्फ़ यहाँ।';
    ?>
<!doctype html>
<html lang="hi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<meta name="description" content="<?= h(mb_substr($desc, 0, 300, 'UTF-8')) ?>">
<?php if (!empty($opt['noindex'])): ?>
<meta name="robots" content="noindex, follow">
<?php endif; ?>
<?php if (!empty($opt['canonical'])): ?>
<link rel="canonical" href="https://ottguru.in<?= h($opt['canonical']) ?>">
<?php endif; ?>
<meta property="og:title" content="<?= h($title) ?>">
<meta property="og:description" content="<?= h(mb_substr($desc, 0, 200, 'UTF-8')) ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="hi_IN">
<?php if (!empty($opt['image'])): ?>
<meta property="og:image" content="<?= h($opt['image']) ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/site.css">
<?php if (!empty($opt['jsonld'])): ?>
<script type="application/ld+json"><?= json_encode($opt['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
</head>
<body>
<header class="top">
  <div class="wrap top-in">
    <a class="logo" href="/">OTT<span> गुरु</span></a>
    <nav class="topnav">
      <a href="/">होम</a>
    </nav>
  </div>
</header>
<main class="wrap">
<?php
}

function page_footer(): void
{
    ?>
</main>
<footer class="foot">
  <div class="wrap">
    <p><strong>OTT गुरु</strong> — कौन सी फिल्म किस platform पर है, और <em>कब से कब तक थी</em>।
       उपलब्धता रोज़ जाँची जाती है, फिर भी देखने से पहले app में पुष्टि कर लें।</p>
    <p class="tmdb">फिल्मों-सीरीज़ का डेटा और posters
       <a href="https://www.themoviedb.org/" rel="noopener" target="_blank">TMDB</a> से।
       This product uses the TMDB API but is not endorsed or certified by TMDB.</p>
  </div>
</footer>
</body>
</html>
<?php
}

/** posters की grid — provider पन्ने और होमपेज दोनों इस्तेमाल करते हैं */
function render_title_grid(array $titles): void
{
    echo '<div class="grid">';
    foreach ($titles as $t) {
        $img = tmdb_img($t['poster_path'] ?? null, 'w342');
        echo '<a class="card" href="' . h(title_url($t)) . '">';
        if ($img !== null) {
            echo '<img loading="lazy" src="' . h($img) . '" alt="' . h($t['title']) . ' का poster">';
        } else {
            echo '<span class="noposter">' . h(mb_substr($t['title'], 0, 40, 'UTF-8')) . '</span>';
        }
        echo '<span class="card-t">' . h($t['title']) . '</span>';
        echo '<span class="card-y">' . h((string) ($t['release_year'] ?? '')) ;
        if (isset($t['media_type'])) {
            echo ' · ' . h(media_label($t['media_type']));
        }
        echo '</span></a>';
    }
    echo '</div>';
}
