<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  Streaming Availability (RapidAPI · movieofthenight) का client।
 *  यही असली per-OTT deep link + "इस OTT पर कौन सी ऑडियो" देता है।
 *
 *  ⚠️ यह API अभी live नहीं परखी गई (CLAUDE.md §10) — key खाली हो तो कुछ नहीं होता।
 *  endpoint/field नाम v4 docs से हैं; असली key से पहली दौड़ में पुष्टि कर लीजिए।
 * ============================================================================
 */

/** SA चालू है? (key भरी है?) */
function sa_enabled(): bool
{
    global $CFG;
    return trim((string) ($CFG['sa']['key'] ?? '')) !== '';
}

/**
 * एक show — TMDB id से। v4: GET /shows/{movie|tv}/{tmdbId}?country=in
 * लौटाता है http_get_json जैसा ['ok','status','data',...]।
 */
function sa_show(string $mediaType, int $tmdbId): array
{
    global $CFG;
    $host = (string) ($CFG['sa']['host'] ?? 'streaming-availability.p.rapidapi.com');
    $key  = (string) ($CFG['sa']['key'] ?? '');
    $seg  = $mediaType === 'tv' ? 'tv' : 'movie';
    $url  = 'https://' . $host . '/shows/' . $seg . '/' . $tmdbId
          . '?' . http_build_query([
              'country'         => strtolower((string) ($CFG['country'] ?? 'IN')),
              'output_language' => 'en',
          ]);
    $headers = ['X-RapidAPI-Key: ' . $key, 'X-RapidAPI-Host: ' . $host];

    $r = http_get_json($url, $headers, $CFG['http']);
    $ms = (int) ($CFG['batch']['sleep_ms'] ?? 120);
    if ($ms > 0) {
        usleep($ms * 1000);
    }
    return $r;
}

/** 3-अक्षर (ISO 639-2/3) ऑडियो कोड → हमारा 2-अक्षर कोड; न पहचानें तो null */
function sa_lang2(string $c): ?string
{
    static $m = [
        'hin' => 'hi', 'eng' => 'en', 'tam' => 'ta', 'tel' => 'te', 'mal' => 'ml',
        'kan' => 'kn', 'ben' => 'bn', 'mar' => 'mr', 'pan' => 'pa', 'guj' => 'gu',
        'urd' => 'ur', 'ori' => 'or', 'asm' => 'as', 'nep' => 'ne', 'san' => 'sa',
        'kor' => 'ko', 'jpn' => 'ja', 'fra' => 'fr', 'fre' => 'fr', 'spa' => 'es',
        'deu' => 'de', 'ger' => 'de', 'zho' => 'zh', 'chi' => 'zh', 'ita' => 'it',
        'rus' => 'ru', 'tha' => 'th', 'tur' => 'tr', 'ind' => 'id', 'ara' => 'ar',
        'por' => 'pt',
    ];
    $c = strtolower(trim($c));
    if ($c === '') {
        return null;
    }
    if (isset($m[$c])) {
        return $m[$c];
    }
    return strlen($c) === 2 ? $c : null;   // पहले से 2-अक्षर हो तो वही
}

/**
 * SA के जवाब से देश (country) के streaming options निकालना।
 * हर entry: ['name','id','offer','link','audios'=>[2-अक्षर कोड]]।
 * offer = हमारा offer_type (flatrate/free/rent/buy)।
 */
function sa_extract(array $show, string $country): array
{
    $cc   = strtolower($country);
    $opts = $show['streamingOptions'][$cc]
        ?? $show['streamingOptions'][strtoupper($country)]
        ?? $show['streamingOptions'][$country]
        ?? [];
    if (!is_array($opts)) {
        return [];
    }

    static $typeMap = [
        'subscription' => 'flatrate', 'free' => 'free',
        'rent' => 'rent', 'buy' => 'buy', 'addon' => 'flatrate',
    ];

    $out = [];
    foreach ($opts as $o) {
        if (!is_array($o)) {
            continue;
        }
        $svc  = $o['service'] ?? [];
        $name = trim((string) ($svc['name'] ?? $svc['id'] ?? ''));
        $link = trim((string) ($o['link'] ?? ''));
        $type = $typeMap[strtolower((string) ($o['type'] ?? ''))] ?? null;
        if ($name === '' || $link === '' || $type === null) {
            continue;
        }
        $auds = [];
        foreach (($o['audios'] ?? []) as $a) {
            $code = is_array($a) ? (string) ($a['language'] ?? '') : (string) $a;
            $l = sa_lang2($code);
            if ($l !== null) {
                $auds[$l] = true;
            }
        }
        $out[] = [
            'name'   => $name,
            'id'     => (string) ($svc['id'] ?? ''),
            'offer'  => $type,
            'link'   => mb_substr($link, 0, 500),
            'audios' => array_keys($auds),
        ];
    }
    return $out;
}
