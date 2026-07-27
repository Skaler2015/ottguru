<?php
/**
 * UI की भाषा — default अंग्रेज़ी, ?lang=hi से हिंदी। पसंद cookie में याद रहती है।
 * स्रोत कोड में strings हिंदी में हैं (वही t() की key), अंग्रेज़ी अनुवाद इस नक़्शे में।
 * key न मिले तो हिंदी ही छपती है — यानी अनुवाद छूटना घातक नहीं, बस दिखेगा।
 */
declare(strict_types=1);

$GLOBALS['__EN'] = [
    // ---- साझा / layout ----
    'OTT Guru — कौन सी फिल्म किस OTT पर है'
        => 'OTT Guru — which OTT has that movie or show',
    'कौन सी फिल्म या वेब सीरीज़ किस OTT platform पर है — Netflix, Prime Video, Hotstar, ZEE5, SonyLIV। platform बदलने का पूरा इतिहास सिर्फ़ यहाँ।'
        => 'Find which OTT platform has any movie or web series in India — Netflix, Prime Video, JioHotstar, ZEE5, SonyLIV. Full platform-change history, only here.',
    'होम'          => 'Home',
    'नया आया'      => 'New arrivals',
    'क्या हटा'      => 'Removed',
    '%s का poster' => 'Poster of %s',
    'OTT गुरु — कौन सी फिल्म किस platform पर है, और कब से कब तक थी। उपलब्धता रोज़ जाँची जाती है, फिर भी देखने से पहले app में पुष्टि कर लें।'
        => 'OTT Guru — which platform has each movie, and from when to when it was there. Availability is checked daily; still, confirm in the app before watching.',
    'फिल्मों-सीरीज़ का डेटा और posters'
        => 'Movie & series data and posters from',
    'से।' => '.',

    // ---- होमपेज ----
    'कौन सी फिल्म किस OTT पर है?' => 'Which OTT has that movie or show?',
    'Netflix, Prime Video, JioHotstar, ZEE5, SonyLIV — सब एक जगह। और सिर्फ़ "कहाँ है" नहीं: <b>कब आई, कहाँ-कहाँ रही, कब हटी</b> — पूरा इतिहास।'
        => 'Netflix, Prime Video, JioHotstar, ZEE5, SonyLIV — all in one place. And not just "where": <b>when it arrived, where it has been, when it left</b> — the full history.',
    'फिल्में और सीरीज़'  => 'Movies & shows',
    'अभी उपलब्ध'        => 'Available now',
    'इस हफ़्ते नई आईं'    => 'New this week',
    'Platform चुनिए'    => 'Pick a platform',
    'इस हफ़्ते OTT पर नया आया' => 'New on OTT this week',
    'सब देखिए →'        => 'see all →',
    '%s पर'             => 'On %s',
    '%d platforms पर'   => 'On %d platforms',
    'अभी चर्चा में'       => 'Trending now',

    // ---- title पन्ना ----
    '%s कहाँ देखें'     => 'Where to watch %s',
    'फिल्म'            => 'Movie',
    'वेब सीरीज़'        => 'Web series',
    'मूल नाम:'          => 'Original title:',
    '%d मिनट'          => '%d min',
    '(मूल)'            => '(original)',
    'अभी कहाँ देखें'     => 'Where to watch now',
    'यह %s अभी भारत में किसी OTT पर नहीं दिख रही।'
        => 'This %s is not currently showing on any OTT in India.',
    'नीचे इतिहास में देखिए यह पहले कहाँ थी।'
        => 'See the history below for where it used to be.',
    '%s से यहाँ है'     => 'Here since %s',
    'देखें ↗'           => 'Watch ↗',
    'किराये / ख़रीद पर'  => 'Rent or buy',
    'कहानी'            => 'Story',
    'उपलब्धता का इतिहास' => 'Availability history',
    'यह जानकारी सिर्फ़ OTT गुरु पर है — हम रोज़ जाँचते हैं कि कौन सी चीज़ किस platform पर आई और कब हटी।'
        => 'This data lives only on OTT Guru — we check every day what arrived on which platform and when it left.',
    'कैसे'             => 'How',
    'कब से'            => 'From',
    'कब तक'            => 'Until',
    'अभी भी है'         => 'Still here',
    '%s तक'            => 'until %s',
    'क्या-क्या बदला'     => 'What changed, when',
    '%s पर आई (%s)'    => 'Added on %s (%s)',
    '%s से हटी'         => 'Removed from %s',
    'ऊपर लिखी भाषाएँ इस %s की मूल/बोली गई भाषाएँ हैं। किस platform पर कौन सी ऑडियो (dub) मिलेगी — यह जानकारी जल्द जुड़ेगी।'
        => 'The languages above are this %s\'s original/spoken languages. Which audio (dub) each platform offers — that info is coming soon.',
    '%s अभी %s पर देखी जा सकती है। कब आई, कहाँ-कहाँ रही — पूरा इतिहास OTT गुरु पर।'
        => '%s is streaming on %s right now. When it arrived and where it has been — full history on OTT Guru.',
    '%s अभी भारत में किसी OTT के सब्सक्रिप्शन में नहीं है। यह पहले कहाँ थी और कब हटी — पूरा इतिहास OTT गुरु पर।'
        => '%s is not on any OTT subscription in India right now. Where it used to be and when it left — full history on OTT Guru.',

    // ---- offer के प्रकार ----
    'सब्सक्रिप्शन में'    => 'On subscription',
    'विज्ञापन के साथ'    => 'With ads',
    'मुफ़्त'             => 'Free',
    'किराये पर'         => 'For rent',
    'ख़रीदकर'           => 'To buy',

    // ---- provider पन्ना ----
    '%s पर क्या-क्या है' => "What's on %s",
    '%s पर क्या-क्या है (%d titles)' => "What's on %s (%d titles)",
    'भारत में अभी %s %s सब्सक्रिप्शन/मुफ़्त में · रोज़ अपडेट होता है'
        => '%s %s on subscription/free in India right now · updated daily',
    'फिल्में'            => 'movies',
    'फिल्में और वेब सीरीज़' => 'movies & web series',
    'वेब सीरीज़ (सूची)'   => 'web series',
    'इस हफ़्ते नया आया'   => 'New this week',
    'इस हफ़्ते नया आया →' => 'New this week →',
    'हाल में क्या हटा →'   => 'Recently removed →',
    '%s फिल्में (%d)'    => '%s movies (%d)',
    '%s सीरीज़ (%d)'     => '%s series (%d)',
    '%s को आई'          => 'Added %s',
    'पूरी सूची'           => 'Full list',
    'सब'                => 'All',
    'फिल्में (tab)'       => 'Movies',
    'वेब सीरीज़ (tab)'    => 'Series',
    'इस चुनाव में अभी कुछ नहीं मिला।' => 'Nothing here for this filter yet.',
    'पन्ना %d / %d'      => 'Page %d / %d',
    '← पिछला'           => '← Prev',
    'अगला →'            => 'Next →',
    '%s India पर अभी %d %s सब्सक्रिप्शन में उपलब्ध हैं। इस हफ़्ते क्या नया आया और क्या हटा — रोज़ अपडेट, OTT गुरु पर।'
        => '%2$d %3$s are available on %1$s India right now. What arrived this week and what left — updated daily on OTT Guru.',

    // ---- भाषा पन्ना ----
    '%1$s पर %2$s %3$s' => '%2$s %3$s on %1$s',
    'अभी %s · रोज़ अपडेट ·' => '%s right now · updated daily ·',
    '%s की पूरी सूची →'  => 'Full %s list →',
    'यह सूची %1$s में बनी %2$s दिखाती है (मूल या बोली गई भाषा)। "%3$s पर %1$s ऑडियो (dub) मिलेगी या नहीं" — वह जानकारी अलग है और जल्द जुड़ेगी।'
        => 'This list shows %2$s made in %1$s (original or spoken language). Whether %3$s offers %1$s audio (dub) is a separate question — that info is coming soon.',
    '%1$s India पर अभी %2$d %3$s %4$s सब्सक्रिप्शन में हैं — पूरी सूची, रोज़ अपडेट। OTT गुरु पर।'
        => '%2$d %3$s %4$s on %1$s India right now, on subscription — full list, updated daily. On OTT Guru.',

    // ---- changes पन्ने ----
    '%s पर इस हफ़्ते नया आया'  => 'New on %s this week',
    'OTT से हाल में क्या हटा'   => 'Recently removed from OTT',
    '%s से हाल में क्या हटा'    => 'Recently removed from %s',
    'पिछले %d दिन'            => 'Last %d days',
    'उपलब्धता रोज़ जाँची जाती है' => 'availability is checked daily',
    '%s पर पूरी सूची →'        => 'Full list on %s →',
    'पिछले %d दिनों में यहाँ कोई बदलाव दर्ज नहीं हुआ।'
        => 'No changes recorded here in the last %d days.',
    'होमपेज पर चलिए'           => 'Go to the homepage',
    '%s पर आई'                => 'Added on %s',
    '%d platforms'            => '%d platforms',
    'अब %s पर'                => 'Now on %s',
    'अभी कहीं और नहीं'          => 'Not streaming elsewhere yet',
    'एक platform से दूसरे पर गईं' => 'Moved from one platform to another',
    'एक ही दिन एक जगह से हटीं और दूसरी पर आ गईं — plan बदलने से पहले यह देख लीजिए।'
        => 'Left one platform and arrived on another the same day — check this before switching plans.',
    '%1$s से %2$s पर'          => 'from %1$s to %2$s',
    'किसी एक platform का हिसाब चाहिए? platform के पन्ने पर "नया आया / हटा" के लिंक हैं —'
        => 'Want this for one platform? Every platform page has "new / removed" links —',
    'होमपेज से platform चुनिए'   => 'pick a platform from the homepage',
    '%s पर पिछले %d दिनों में कौन सी फिल्में और वेब सीरीज़ नई आईं — रोज़ अपडेट, OTT गुरु पर।'
        => 'Which movies and web series arrived on %s in the last %d days — updated daily on OTT Guru.',
    '%s से पिछले %d दिनों में कौन सी फिल्में हटीं और अब कहाँ देख सकते हैं — OTT गुरु पर।'
        => 'What left %s in the last %d days and where to watch it now — on OTT Guru.',

    // ---- 404 ----
    'पन्ना नहीं मिला'  => 'Page not found',
    'यह पन्ना नहीं मिला' => "This page doesn't exist",
    'हो सकता है लिंक पुराना हो या टाइप में चूक हुई हो।'
        => 'The link may be old, or there may be a typo.',
    '← होमपेज पर चलिए' => '← Back to the homepage',
];

/**
 * कुछ keys एक जैसी हिंदी पर अलग अंग्रेज़ी चाहती हैं (जैसे tab का 'Movies' बनाम
 * वाक्य का 'movies') — उनके लिए key में पहचान-पूँछ लगी है। हिंदी में वह पूँछ
 * नहीं दिखनी चाहिए, इसलिए यह नक़्शा।
 */
$GLOBALS['__HI'] = [
    'फिल्में (tab)'     => 'फिल्में',
    'वेब सीरीज़ (tab)'  => 'वेब सीरीज़',
    'वेब सीरीज़ (सूची)' => 'वेब सीरीज़',
];

/** हिंदी string → चालू भाषा की string */
function t(string $s): string
{
    if (OTT_LANG === 'hi') {
        return $GLOBALS['__HI'][$s] ?? $s;
    }
    return $GLOBALS['__EN'][$s] ?? $s;
}

/** t() + sprintf एक साथ */
function tf(string $s, ...$a): string
{
    return vsprintf(t($s), $a);
}

/** भाषा बदलने का URL — मौजूदा पन्ना, सिर्फ़ lang बदले */
function lang_switch_url(string $to): string
{
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $q);
    $q['lang'] = $to;
    return $path . '?' . http_build_query($q);
}
