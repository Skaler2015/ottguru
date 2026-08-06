<?php
/**
 * INFO पेज — /about · /privacy · /terms  (स्थिर content; भरोसा + AdSense के लिए ज़रूरी)
 * index.php से: $want_slug ∈ {about, privacy, terms}
 */
declare(strict_types=1);

$L    = OTT_LANG === 'hi';
$slug = $want_slug ?? '';
$mail = trim((string) ($CFG['safety']['alert_email'] ?? '')) ?: 'contact@ottguru.in';

$valid = ['about', 'privacy', 'terms'];
if (!in_array($slug, $valid, true)) {
    not_found();
}

$titles = [
    'about'   => $L ? 'हमारे बारे में'      : 'About OTT Guru',
    'privacy' => $L ? 'निजता नीति'          : 'Privacy Policy',
    'terms'   => $L ? 'नियम और शर्तें'       : 'Terms of Use',
];
$h1 = $titles[$slug];

$crumbs = [
    ['name' => $L ? 'होम' : 'Home', 'url' => '/'],
    ['name' => $h1, 'url' => '/' . $slug],
];
page_header([
    'title'       => $h1,
    'description' => $L ? "OTT गुरु — $h1। भारत के लिए OTT availability और इतिहास।" : "OTT Guru — $h1.",
    'canonical'   => '/' . $slug,
    'breadcrumb'  => $crumbs,
]);
crumbs($crumbs);
?>
<article class="prose">
  <h1><?= h($h1) ?></h1>

  <?php if ($slug === 'about'): ?>
    <?php if ($L): ?>
    <p><b>OTT गुरु (ottguru.in)</b> भारत के लिए बनी एक OTT availability साइट है — कौन सी फिल्म या वेब सीरीज़ किस platform (Netflix, Prime Video, JioHotstar, ZEE5, SonyLIV आदि) पर है, किस भाषा में, और platform बदलने पर <b>अपने-आप अपडेट</b>।</p>
    <p>हमारा फ़र्क़ सिर्फ़ "कहाँ है" बताने में नहीं — बल्कि <b>उपलब्धता का पूरा इतिहास</b> ("यह फिल्म जनवरी 2024 से मार्च 2026 तक Netflix पर थी, अब ZEE5 पर"), <b>plan tier की सच्चाई</b> ("₹149 Mobile plan TV पर चलेगा?") और <b>telecom बंडल</b> ("Jio ₹399 में क्या मुफ़्त है") में है — यह जानकारी और कहीं आसानी से नहीं मिलती।</p>
    <p>यह साइट <b>सीकर, राजस्थान</b> से बनाई और चलाई जाती है। फिल्मों-सीरीज़ का मेटाडेटा और posters <a href="https://www.themoviedb.org/" rel="noopener" target="_blank">TMDB</a> से आते हैं (TMDB द्वारा प्रमाणित नहीं)।</p>
    <p>सुझाव या सुधार के लिए <a href="/contact">संपर्क कीजिए</a>।</p>
    <?php else: ?>
    <p><b>OTT Guru (ottguru.in)</b> is an OTT availability site for India — which movie or web series is on which platform (Netflix, Prime Video, JioHotstar, ZEE5, SonyLIV and more), in which language, and it <b>updates automatically</b> when a platform changes.</p>
    <p>What sets us apart isn't just "where is it" — it's the <b>full availability history</b> ("this film was on Netflix Jan 2024–Mar 2026, now on ZEE5"), the <b>plan-tier truth</b> ("will the ₹149 Mobile plan play on a TV?"), and <b>telecom bundles</b> ("what's free with a Jio ₹399 recharge") — data you won't easily find elsewhere.</p>
    <p>The site is built and run from <b>Sikar, Rajasthan</b>. Movie/series metadata and posters come from <a href="https://www.themoviedb.org/" rel="noopener" target="_blank">TMDB</a> (not endorsed or certified by TMDB).</p>
    <p>For suggestions, please <a href="/contact">contact us</a>.</p>
    <?php endif; ?>

  <?php elseif ($slug === 'privacy'): ?>
    <?php if ($L): ?>
    <p>हम आपकी निजता का सम्मान करते हैं। यह नीति बताती है कि OTT गुरु क्या जानकारी रखता है।</p>
    <h2>1. कोई login/खाता नहीं</h2>
    <p>साइट चलाने के लिए हमें आपका नाम, ईमेल या फ़ोन नहीं चाहिए। आपकी <b>वॉचलिस्ट और "हाल में देखी"</b> सिर्फ़ आपके ब्राउज़र में (localStorage में) रहती है — हमारे सर्वर पर नहीं जाती।</p>
    <h2>2. कुकीज़ और तीसरे पक्ष</h2>
    <p>पसंद (भाषा) याद रखने के लिए एक साधारण कुकी इस्तेमाल होती है। Posters <a href="https://www.themoviedb.org/" rel="noopener" target="_blank">TMDB</a> के सर्वर से आते हैं। अगर हम आगे विज्ञापन (जैसे Google AdSense) या analytics जोड़ते हैं, तो वे तीसरे पक्ष अपनी कुकीज़ इस्तेमाल कर सकते हैं; उनका उपयोग उनकी अपनी नीति के अधीन होगा।</p>
    <h2>3. संपर्क</h2>
    <p>सवाल हो तो <a href="/contact">संपर्क</a> कीजिए या <?= h($mail) ?> पर लिखिए।</p>
    <?php else: ?>
    <p>We respect your privacy. This policy explains what OTT Guru stores.</p>
    <h2>1. No login / account</h2>
    <p>We don't need your name, email or phone to use the site. Your <b>wishlist and "recently viewed"</b> stay only in your browser (localStorage) — they never reach our servers.</p>
    <h2>2. Cookies & third parties</h2>
    <p>A simple cookie remembers your language preference. Posters are served from <a href="https://www.themoviedb.org/" rel="noopener" target="_blank">TMDB</a>. If we later add ads (e.g. Google AdSense) or analytics, those third parties may set their own cookies, governed by their own policies.</p>
    <h2>3. Contact</h2>
    <p>Questions? <a href="/contact">Contact us</a> or write to <?= h($mail) ?>.</p>
    <?php endif; ?>

  <?php else: /* terms */ ?>
    <?php if ($L): ?>
    <p>OTT गुरु का उपयोग करके आप इन शर्तों से सहमत होते हैं।</p>
    <h2>1. सिर्फ़ जानकारी के लिए</h2>
    <p>यहाँ दी गई availability, कीमतें और भाषा-जानकारी बदल सकती हैं। हम रोज़ जाँचते हैं, फिर भी <b>देखने से पहले असली OTT ऐप में पुष्टि कर लें</b>। किसी गलती के लिए हम ज़िम्मेदार नहीं।</p>
    <h2>2. कोई पायरेसी नहीं</h2>
    <p>हम सिर्फ़ यह बताते हैं कि चीज़ कहाँ <b>क़ानूनी रूप से</b> उपलब्ध है। कोई अवैध/पायरेटेड लिंक यहाँ नहीं है।</p>
    <h2>3. सामग्री का स्रोत</h2>
    <p>मेटाडेटा और posters <a href="https://www.themoviedb.org/" rel="noopener" target="_blank">TMDB</a> से हैं (TMDB द्वारा प्रमाणित नहीं)। सारे ट्रेडमार्क/लोगो उनके मालिकों के हैं।</p>
    <?php else: ?>
    <p>By using OTT Guru you agree to these terms.</p>
    <h2>1. Information only</h2>
    <p>Availability, prices and language info shown here can change. We check daily, but <b>please confirm in the actual OTT app before watching</b>. We're not liable for any error.</p>
    <h2>2. No piracy</h2>
    <p>We only show where a title is <b>legally</b> available. No illegal/pirated links are hosted here.</p>
    <h2>3. Source of content</h2>
    <p>Metadata and posters are from <a href="https://www.themoviedb.org/" rel="noopener" target="_blank">TMDB</a> (not endorsed or certified by TMDB). All trademarks/logos belong to their owners.</p>
    <?php endif; ?>
  <?php endif; ?>

  <p class="dim small" style="margin-top:24px"><?= $L ? 'आख़िरी अपडेट:' : 'Last updated:' ?> <?= h(date('F Y')) ?></p>
</article>
<?php page_footer(); ?>
