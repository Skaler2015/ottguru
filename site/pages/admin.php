<?php
/**
 * ADMIN dashboard — password login के पीछे (read-only, noindex, 100% असली डेटा)।
 *   • खुलता है  /admin  या गुप्त  /<admin_path>  (config.php का admin_path, जैसे /skaler2015)
 *   • password  = config.php का admin_pass (न हो तो run_token ही password)
 *   • login session में याद रहता है; ऊपर "लॉगआउट" से बाहर
 *   • /admin?k=<run_token> — बिना फ़ॉर्म सीधा (bookmark/backward compat)
 * sync/health पर नज़र रखने के लिए — status.php का premium web रूप।
 */
declare(strict_types=1);

// ---- session शुरू (किसी भी output से पहले) ----
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('ottg_adm');
    @session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
    @session_start();
}
header('X-Robots-Tag: noindex, nofollow');

// ---- logout ----
if (isset($_GET['logout'])) {
    $_SESSION = [];
    @session_destroy();
    header('Location: ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?'));
    exit;
}

$authed = !empty($_SESSION['ott_admin']);

// ?k=<run_token> से आया हो तो सीधा भीतर (पुराना bookmark टूटे नहीं)
if (!$authed) {
    $rt = (string) ($CFG['run_token'] ?? '');
    if ($rt !== '' && $rt !== 'बदल-कर-कुछ-लंबा-लिखिए'
        && isset($_GET['k']) && hash_equals($rt, (string) $_GET['k'])) {
        $authed = true;
    }
}

// password — config का admin_pass; सेट न हो तो run_token ही चलेगा
$adminPass = (string) ($CFG['admin_pass'] ?? '');
if ($adminPass === '') {
    $adminPass = (string) ($CFG['run_token'] ?? '');
}

// login फ़ॉर्म का POST
$loginErr = false;
if (!$authed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['pass'])) {
    if ($adminPass !== '' && $adminPass !== 'बदल-कर-कुछ-लंबा-लिखिए'
        && hash_equals($adminPass, (string) $_POST['pass'])) {
        session_regenerate_id(true);
        $_SESSION['ott_admin'] = true;
        header('Location: ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?'));
        exit;
    }
    $loginErr = true;
    sleep(1);   // ग़लत कोशिश को सुस्त करना (brute-force रोक)
}

// login नहीं हुआ → login पन्ना दिखाकर रुक जाओ
if (!$authed) {
    http_response_code($loginErr ? 401 : 200);
    $L = OTT_LANG === 'hi';
    ?><!doctype html>
<html lang="<?= OTT_LANG ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>OTTGuru · Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css">
<style>
  .lg{min-height:100dvh;display:grid;place-items:center;padding:20px}
  .lgcard{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:32px 26px;width:100%;max-width:340px}
  .lgcard h1{font-size:21px;margin:14px 0 4px}
  .lgcard .sub{color:var(--ink3);font-size:13px;margin:0 0 18px}
  .lgcard input{width:100%;box-sizing:border-box;background:var(--bg2);border:1px solid var(--line2);border-radius:11px;padding:12px 14px;color:var(--ink);font-size:15px;font-family:var(--body);outline:0;margin-bottom:12px}
  .lgcard input:focus{border-color:var(--blue)}
  .lgcard button{width:100%;padding:12px;border:0;border-radius:11px;background:var(--grad);color:#fff;font-weight:600;font-size:15px;cursor:pointer;font-family:var(--body)}
  .lgerr{background:rgba(255,77,109,.12);border:1px solid rgba(255,77,109,.3);color:#ff9db0;border-radius:10px;padding:9px 12px;font-size:13px;margin-bottom:12px}
</style>
</head>
<body>
<div class="lg">
  <form class="lgcard" method="post" action="">
    <a class="logo" href="/" style="font-size:22px">OTT<span>Guru</span></a>
    <h1><?= $L ? 'एडमिन लॉगिन' : 'Admin login' ?></h1>
    <div class="sub"><?= $L ? 'आगे बढ़ने के लिए पासवर्ड डालिए' : 'Enter your password to continue' ?></div>
    <?php if ($loginErr): ?><div class="lgerr"><?= $L ? 'ग़लत पासवर्ड — फिर कोशिश कीजिए' : 'Wrong password — try again' ?></div><?php endif; ?>
    <input type="password" name="pass" placeholder="<?= $L ? 'पासवर्ड' : 'Password' ?>" autofocus autocomplete="current-password" required>
    <button type="submit"><?= $L ? 'लॉगिन' : 'Log in' ?></button>
  </form>
</div>
</body>
</html>
<?php
    exit;
}

$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$nf = fn (int $n) => number_format($n);

// ============================================================================
//  नियंत्रण (control) — सिर्फ़ owner, session-login के पीछे।
//  ⚠️ ये writes जान-बूझकर सुरक्षित हैं: सिर्फ़ sync-नियंत्रण fields (tier,
//  providers_last_success) और provider_aliases छूते हैं — availability या
//  availability_changes (ख़ज़ाना) को कभी हाथ नहीं लगाते। तीनों नियम बरक़रार।
// ============================================================================
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF     = (string) $_SESSION['csrf'];
$selfPath = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/admin'), '?');

/**
 * URLs की एक साथ (parallel) live जाँच का एक दौर।
 * $resolveIp दिया हो तो connection उसी IP पर मुड़ता है (Host/SNI वही रहते हैं) —
 * इससे "सर्वर अपने ही domain को बाहर से नहीं पढ़ पाता" (NAT) वाली दिक़्क़त हल होती है।
 * हर पेज का सिर्फ़ ~9KB खींचता है (head में robots/meta आ जाता है)।
 */
function admin_live_pass(array $urls, ?string $resolveIp): array
{
    $mh = curl_multi_init();
    $hs = $buf = [];
    foreach ($urls as $k => $u) {
        $buf[$k] = '';
        $ch   = curl_init($u);
        $opts = [
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,   // अपने ही सर्वर की loopback जाँच — cert मायने नहीं रखता
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'OTTGuru-health/1.0',
            CURLOPT_WRITEFUNCTION  => function ($c, $data) use (&$buf, $k) {
                $buf[$k] .= $data;
                return strlen($buf[$k]) > 9000 ? 0 : strlen($data);   // 0 = बस, इतना काफ़ी
            },
        ];
        if ($resolveIp !== null) {
            $pu   = parse_url($u);
            $host = (string) ($pu['host'] ?? '');
            $port = (int) ($pu['port'] ?? (($pu['scheme'] ?? 'https') === 'https' ? 443 : 80));
            if ($host !== '') {
                $opts[CURLOPT_RESOLVE] = ["$host:$port:$resolveIp"];
            }
        }
        curl_setopt_array($ch, $opts);
        curl_multi_add_handle($mh, $ch);
        $hs[$k] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 0.4);
        }
    } while ($running > 0);
    $out = [];
    foreach ($hs as $k => $ch) {
        $out[$k] = [
            'code'    => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'ms'      => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
            'noindex' => stripos($buf[$k], 'noindex') !== false,
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * पहले सीधे जाँचो; जो पेज न खुलें (code 0) उन्हें loopback (127.0.0.1) से दोबारा —
 * यही Hostinger पर "self-check fail" (सब HTTP 0) की असली मार का इलाज है।
 */
function admin_live_check(array $urls): array
{
    if (!function_exists('curl_multi_init')) {
        return array_map(fn () => ['code' => 0, 'ms' => 0, 'noindex' => false], $urls);
    }
    $res   = admin_live_pass($urls, null);
    $retry = array_filter($urls, fn ($u, $k) => (int) ($res[$k]['code'] ?? 0) === 0, ARRAY_FILTER_USE_BOTH);
    if ($retry !== []) {
        foreach (admin_live_pass($retry, '127.0.0.1') as $k => $v) {
            if ((int) $v['code'] !== 0) {
                $res[$k] = $v;
            }
        }
    }
    return $res;
}

/**
 * IndexNow — URLs को Bing/Yandex आदि को तुरंत "index कर लो" कहना (मुफ़्त, आधिकारिक)।
 * एक POST में 10,000 तक URL। लौटाता है ['code'=>HTTP, 'n'=>कितने भेजे]।
 * (Google IndexNow अभी आधिकारिक तौर पर नहीं लेता — उसके लिए GSC/sitemap ही रास्ता।)
 */
function indexnow_submit(string $host, string $key, array $urls): array
{
    $urls = array_values(array_unique(array_filter(array_map('trim', $urls))));
    if ($host === '' || $key === '' || $urls === [] || !function_exists('curl_init')) {
        return ['code' => 0, 'n' => 0];
    }
    $payload = json_encode([
        'host'        => $host,
        'key'         => $key,
        'keyLocation' => 'https://' . $host . '/' . $key . '.txt',
        'urlList'     => array_slice($urls, 0, 10000),
    ]);
    $ch = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'n' => count($urls)];
}

// साइट का असली host (बिना port) + पूरा base — canonical से मेल खाता
$siteHost = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ottguru.in'));
$siteBase = 'https://' . $siteHost;

// ---- POST क्रियाएँ (PRG: लिखो → redirect → दिखाओ) ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['do'])) {
    if (!hash_equals($CSRF, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('bad token');
    }
    $do = (string) $_POST['do'];
    $id = (int) ($_POST['id'] ?? 0);

    if ($do === 'recheck' && $id > 0) {
        // दोबारा जाँच के लिए कतार में — अगली providers दौड़ इसे सबसे पहले देखेगी।
        // कोई availability/इतिहास नहीं छेड़ा (नियम 2 सुरक्षित)।
        q($PDO, 'UPDATE titles SET providers_last_success = NULL, providers_fail_streak = 0 WHERE id = ?', [$id]);
        header('Location: ' . $selfPath . '?view=title&id=' . $id . '&ok=recheck');
        exit;
    }
    if ($do === 'tier' && $id > 0) {
        $t = max(1, min(3, (int) ($_POST['tier'] ?? 3)));
        q($PDO, 'UPDATE titles SET tier = ? WHERE id = ?', [$t, $id]);
        header('Location: ' . $selfPath . '?view=title&id=' . $id . '&ok=tier');
        exit;
    }
    if ($do === 'alias') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $pid  = (int) ($_POST['pid'] ?? 0);
        if ($name !== '' && $pid > 0 && norm_name($name) !== '') {
            q($PDO, 'INSERT INTO provider_aliases (provider_id, alias_norm, alias_raw, source)
                     VALUES (?, ?, ?, "manual")
                     ON DUPLICATE KEY UPDATE provider_id = VALUES(provider_id)',
                [$pid, norm_name($name), $name]);
            $unk = state_get($PDO, 'unknown_providers', []);   // पहचानी सूची से हटाएँ
            if (is_array($unk)) {
                unset($unk[$name]);
                state_set($PDO, 'unknown_providers', $unk);
            }
        }
        header('Location: ' . $selfPath . '?ok=alias');
        exit;
    }

    // IndexNow चालू — एक random key बनाकर sync_state में सहेजो (config नहीं छूना पड़ता)
    if ($do === 'indexnow_on') {
        if ((string) state_get($PDO, 'indexnow_key', '') === '') {
            state_set($PDO, 'indexnow_key', bin2hex(random_bytes(16)));
        }
        header('Location: ' . $selfPath . '?view=pages&ok=inon');
        exit;
    }
    // आज जुड़े/बदले titles के URL IndexNow को भेजो
    if ($do === 'indexnow_recent') {
        $key = (string) state_get($PDO, 'indexnow_key', '');
        $rows = all($PDO, "SELECT DISTINCT t.slug, t.media_type FROM availability_changes c
                           JOIN titles t ON t.id=c.title_id
                          WHERE c.changed_on >= (CURDATE() - INTERVAL 2 DAY)
                          ORDER BY c.id DESC LIMIT 500");
        $urls = array_map(fn ($t) => $siteBase . title_url($t), $rows);
        $urls[] = $siteBase . '/';
        $r = indexnow_submit($siteHost, $key, $urls);
        state_set($PDO, 'indexnow_last', ['at' => date('Y-m-d H:i'), 'code' => $r['code'], 'n' => $r['n']]);
        header('Location: ' . $selfPath . '?view=pages&ok=insent');
        exit;
    }
    // किसी एक title का URL भेजो (inspector से)
    if ($do === 'indexnow_one' && $id > 0) {
        $key = (string) state_get($PDO, 'indexnow_key', '');
        $t   = one($PDO, 'SELECT slug, media_type FROM titles WHERE id = ?', [$id]);
        if ($t !== null && $key !== '') {
            $r = indexnow_submit($siteHost, $key, [$siteBase . title_url($t)]);
            state_set($PDO, 'indexnow_last', ['at' => date('Y-m-d H:i'), 'code' => $r['code'], 'n' => $r['n']]);
        }
        header('Location: ' . $selfPath . '?view=title&id=' . $id . '&ok=insent');
        exit;
    }

    // page-cache मैन्युअल साफ़ (कभी sync के बाहर कुछ बदला हो तो)
    if ($do === 'cache_clear') {
        $n = function_exists('cache_clear_all') ? cache_clear_all() : 0;
        header('Location: ' . $selfPath . '?ok=cc' . $n);
        exit;
    }

    // ---- मैन्युअल डेटा: OTT plan tier + telecom बंडल (§1 का असली भेद) ----
    if (str_starts_with($do, 'plan_') || str_starts_with($do, 'bundle_')) {
        admin_ensure_manual_tables($PDO);
        // बदलाव तुरंत दिखे — page-cache साफ़ कर दो (write अभी नीचे होगा, अगली
        // public request ताज़ा बना लेगी; इसलिए यहीं clear करना सुरक्षित)।
        if (function_exists('cache_clear_all')) {
            cache_clear_all();
        }
    }
    if ($do === 'plan_add') {
        $pid = (int) ($_POST['provider_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $price = max(0, (int) ($_POST['price_inr'] ?? 0));
        if ($pid > 0 && $name !== '') {
            q($PDO, "INSERT INTO provider_plans
                     (provider_id,name,price_inr,period,max_quality,screens,tv_allowed,has_ads,devices,sort_order)
                     VALUES (?,?,?,?,?,?,?,?,?,?)", [
                $pid, $name, $price,
                in_array($_POST['period'] ?? 'month', ['month', 'quarter', 'year'], true) ? $_POST['period'] : 'month',
                nz(trim((string) ($_POST['max_quality'] ?? ''))),
                ($sc = (int) ($_POST['screens'] ?? 0)) > 0 ? $sc : null,
                isset($_POST['tv_allowed']) ? 1 : 0,
                isset($_POST['has_ads']) ? 1 : 0,
                nz(trim((string) ($_POST['devices'] ?? ''))),
                (int) ($_POST['sort_order'] ?? 0),
            ]);
        }
        header('Location: ' . $selfPath . '?view=manual&ok=plan');
        exit;
    }
    if ($do === 'plan_del' && $id > 0) {
        q($PDO, 'DELETE FROM provider_plans WHERE id = ?', [$id]);
        header('Location: ' . $selfPath . '?view=manual&ok=pdel');
        exit;
    }
    if ($do === 'bundle_add') {
        $pid = (int) ($_POST['provider_id'] ?? 0);
        $op  = trim((string) ($_POST['operator'] ?? ''));
        $price = max(0, (int) ($_POST['plan_price'] ?? 0));
        if ($pid > 0 && $op !== '') {
            q($PDO, "INSERT INTO telecom_bundles
                     (operator,plan_price,plan_label,provider_id,ott_tier,validity_days)
                     VALUES (?,?,?,?,?,?)", [
                $op, $price,
                nz(trim((string) ($_POST['plan_label'] ?? ''))),
                $pid,
                nz(trim((string) ($_POST['ott_tier'] ?? ''))),
                ($vd = (int) ($_POST['validity_days'] ?? 0)) > 0 ? $vd : null,
            ]);
        }
        header('Location: ' . $selfPath . '?view=manual&ok=bundle');
        exit;
    }
    if ($do === 'bundle_del' && $id > 0) {
        q($PDO, 'DELETE FROM telecom_bundles WHERE id = ?', [$id]);
        header('Location: ' . $selfPath . '?view=manual&ok=bdel');
        exit;
    }

    header('Location: ' . $selfPath);
    exit;
}
$flash = (string) ($_GET['ok'] ?? '');

/**
 * मैन्युअल-डेटा tables पक्का बनाएँ — admin पहली बार खुले (sync से पहले) तब भी चले।
 * schema.sql की CREATE TABLE IF NOT EXISTS ही चलाते हैं (idempotent, सुरक्षित)।
 */
function admin_ensure_manual_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $sql = (string) @file_get_contents(OTT_ROOT . '/schema.sql');
        if (preg_match_all('/CREATE TABLE IF NOT EXISTS (provider_plans|telecom_bundles).*?;/s', $sql, $m)) {
            foreach ($m[0] as $stmt) {
                $pdo->exec($stmt);
            }
        }
    } catch (Throwable $e) {
        // न बन पाएँ तो view/डेटा चुपचाप छूट जाएगा — बाक़ी admin ज्यों का त्यों
    }
}

// ============================================================================
//  Title inspector — /admin?view=title&id=N  (एक फिल्म की पूरी कुंडली + क्रियाएँ)
// ============================================================================
if (($_GET['view'] ?? '') === 'title') {
    $tid = (int) ($_GET['id'] ?? 0);
    $t   = $tid > 0 ? one($PDO, 'SELECT * FROM titles WHERE id = ?', [$tid]) : null;

    $iOffers = $iSpells = $iEvents = $iLangs = $iGenres = $iCast = [];
    if ($t !== null) {
        $iOffers = all($PDO, "SELECT p.name, a.offer_type, a.first_seen, a.is_current
              FROM availability a JOIN providers p ON p.id=a.provider_id
             WHERE a.title_id=? ORDER BY a.is_current DESC,
                   FIELD(a.offer_type,'flatrate','ads','free','rent','buy'), p.display_priority", [$tid]);
        $iEvents = all($PDO, "SELECT c.changed_on, c.change_type, p.name, c.offer_type
              FROM availability_changes c JOIN providers p ON p.id=c.provider_id
             WHERE c.title_id=? ORDER BY c.changed_on DESC, c.id DESC LIMIT 40", [$tid]);
        $iLangs  = all($PDO, "SELECT lang_code, kind FROM title_languages WHERE title_id=? ORDER BY kind='original' DESC", [$tid]);
        try {
            $iGenres = all($PDO, "SELECT g.name_en FROM title_genres tg JOIN genres g ON g.id=tg.genre_id WHERE tg.title_id=?", [$tid]);
            $iCast   = all($PDO, "SELECT p.name, tc.role, tc.credit_kind FROM title_credits tc JOIN people p ON p.id=tc.person_id WHERE tc.title_id=? ORDER BY tc.credit_kind, tc.ord LIMIT 12", [$tid]);
        } catch (Throwable $ex) { /* मेटाडेटा tables न हों तो कोई बात नहीं */ }
    }
    $inKey = (string) state_get($PDO, 'indexnow_key', '');   // IndexNow चालू है?
    require OTT_ROOT . '/site/pages/admin_title.php';
    exit;
}

// ============================================================================
//  Pages & index — /admin?view=pages
//  हर page-type + गिनती + index नीति, और ज़रूरी पेजों की असली live जाँच।
//  (असली "Google ने index किया या नहीं" सिर्फ़ Search Console बताता है —
//   यहाँ हम "live है? + index होने लायक़ है?" दिखाते हैं।)
// ============================================================================
if (($_GET['view'] ?? '') === 'pages') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base   = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'ottguru.in');
    $cc     = $CFG['country'] ?? 'IN';

    // page-type गिनती — sitemap के नियमों से मेल खाती (सिर्फ़ जिन पर कुछ है)
    $nMovie = (int) scalar($PDO, "SELECT COUNT(DISTINCT t.id) FROM titles t
        JOIN availability a ON a.title_id=t.id AND a.is_current=1 WHERE t.media_type='movie'");
    $nSeries = (int) scalar($PDO, "SELECT COUNT(DISTINCT t.id) FROM titles t
        JOIN availability a ON a.title_id=t.id AND a.is_current=1 WHERE t.media_type='tv'");
    $nProvLive = (int) scalar($PDO, "SELECT COUNT(DISTINCT p.id) FROM providers p
        JOIN availability a ON a.provider_id=p.id AND a.is_current=1 AND a.country=?
        AND a.offer_type IN ('flatrate','ads','free') WHERE p.is_active=1", [$cc]);
    $nLang = (int) scalar($PDO, "SELECT COUNT(*) FROM (
        SELECT 1 FROM availability a
          JOIN providers p ON p.id=a.provider_id AND p.is_active=1
          JOIN titles t ON t.id=a.title_id
          JOIN title_languages l ON l.title_id=t.id
         WHERE a.country=? AND a.is_current=1 AND a.offer_type IN ('flatrate','ads','free')
         GROUP BY p.id, l.lang_code, t.media_type HAVING COUNT(DISTINCT t.id)>=5) x", [$cc]);

    $ptypes = [
        ['होमपेज',              '/',                          1,                    true],
        ['फिल्म पेज',           '/movie/…',                   $nMovie,              true],
        ['सीरीज़ पेज',           '/series/…',                  $nSeries,             true],
        ['platform पेज',        '/platform/…',                $nProvLive,           true],
        ['भाषा पेज',            '/platform/…/hindi-movies',   $nLang,               true],
        ['changes पेज',         '/naya · /hata (+platform)',  2 + 2 * $nProvLive,   true],
        ['खोज',                 '/search',                    null,                 false],
        ['admin',               '/admin',                     null,                 false],
    ];
    $totalIndex = 1 + $nMovie + $nSeries + $nProvLive + $nLang + (2 + 2 * $nProvLive);

    // ज़रूरी पेजों की असली live जाँच के लिए URLs (नमूने DB से)
    $sM = one($PDO, "SELECT t.slug, t.media_type FROM titles t
        JOIN availability a ON a.title_id=t.id AND a.is_current=1
        WHERE t.media_type='movie' ORDER BY t.popularity DESC LIMIT 1");
    $sP = one($PDO, "SELECT p.slug FROM providers p
        JOIN availability a ON a.provider_id=p.id AND a.is_current=1
        WHERE p.is_active=1 GROUP BY p.id ORDER BY COUNT(*) DESC LIMIT 1");
    $check = ['होमपेज' => $base . '/', 'sitemap.xml' => $base . '/sitemap.xml', 'robots.txt' => $base . '/robots.txt'];
    if ($sM) { $check['नमूना फिल्म पेज'] = $base . '/movie/' . rawurlencode($sM['slug']); }
    if ($sP) { $check['नमूना platform पेज'] = $base . '/platform/' . rawurlencode($sP['slug']); }
    $check['/naya'] = $base . '/naya';
    $check['/hata'] = $base . '/hata';
    $check['/search'] = $base . '/search?q=test';

    // IndexNow — key + पिछली सबमिट। key set हो तो उसकी verify-फ़ाइल भी live-check में।
    $inKey  = (string) state_get($PDO, 'indexnow_key', '');
    $inLast = state_get($PDO, 'indexnow_last', null);
    if ($inKey !== '') {
        $check['IndexNow key फ़ाइल'] = $base . '/' . $inKey . '.txt';
    }
    $todayNew = (int) scalar($PDO, "SELECT COUNT(DISTINCT title_id) FROM availability_changes
        WHERE changed_on >= (CURDATE() - INTERVAL 2 DAY)");

    $live = admin_live_check($check);

    require OTT_ROOT . '/site/pages/admin_pages.php';
    exit;
}

// ============================================================================
//  मैन्युअल डेटा — /admin?view=manual  (OTT plan tier + telecom बंडल)
//  यही JustWatch से असली फ़र्क़ (§1) — कोई API नहीं देता, हाथ से भरना है।
// ============================================================================
if (($_GET['view'] ?? '') === 'manual') {
    admin_ensure_manual_tables($PDO);
    $mProvs  = all($PDO, 'SELECT id, name FROM providers WHERE is_active = 1 ORDER BY display_priority, name');
    $mPlans  = $mBundles = [];
    try {
        $mPlans = all($PDO, "SELECT pp.*, p.name AS pname FROM provider_plans pp
                              JOIN providers p ON p.id = pp.provider_id
                             ORDER BY p.display_priority, p.name, pp.sort_order, pp.price_inr");
        $mBundles = all($PDO, "SELECT tb.*, p.name AS pname FROM telecom_bundles tb
                                JOIN providers p ON p.id = tb.provider_id
                               ORDER BY tb.operator, tb.plan_price, p.name");
    } catch (Throwable $e) { /* tables न बनें तो ख़ाली */ }
    require OTT_ROOT . '/site/pages/admin_manual.php';
    exit;
}

// ---- खोज (dashboard पर) — ?q= ----
$q       = trim((string) ($_GET['q'] ?? ''));
$results = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $results = all($PDO, "SELECT id, title, media_type, release_year, slug, tier,
                                 providers_last_success
            FROM titles WHERE title LIKE ? OR original_title LIKE ? OR slug LIKE ?
           ORDER BY popularity DESC LIMIT 25", [$like, $like, $like]);
}

// ---- thin-page audit — कमज़ोर पेज (SEO जोखिम): न availability, न overview ----
$thinCount = (int) scalar($PDO, "SELECT COUNT(*) FROM titles t
     WHERE NOT EXISTS (SELECT 1 FROM availability a WHERE a.title_id=t.id AND a.is_current=1)
       AND (t.overview IS NULL OR t.overview='')");
$thin = all($PDO, "SELECT t.id, t.title, t.media_type, t.release_year FROM titles t
     WHERE NOT EXISTS (SELECT 1 FROM availability a WHERE a.title_id=t.id AND a.is_current=1)
       AND (t.overview IS NULL OR t.overview='')
     ORDER BY t.popularity DESC LIMIT 10");

// providers की सूची (alias mapper के dropdown के लिए)
$provList = all($PDO, 'SELECT id, name FROM providers WHERE is_active=1 ORDER BY display_priority, name');

// ---- overview ----------------------------------------------------------------
$c = [
    'titles'   => (int) scalar($PDO, 'SELECT COUNT(*) FROM titles'),
    'movies'   => (int) scalar($PDO, "SELECT COUNT(*) FROM titles WHERE media_type='movie'"),
    'series'   => (int) scalar($PDO, "SELECT COUNT(*) FROM titles WHERE media_type='tv'"),
    'prov'     => (int) scalar($PDO, 'SELECT COUNT(*) FROM providers WHERE is_active = 1'),
    'live'     => (int) scalar($PDO, 'SELECT COUNT(*) FROM availability WHERE is_current = 1'),
    'past'     => (int) scalar($PDO, 'SELECT COUNT(*) FROM availability WHERE is_current = 0'),
    'changes'  => (int) scalar($PDO, 'SELECT COUNT(*) FROM availability_changes'),
    'never'    => (int) scalar($PDO, 'SELECT COUNT(*) FROM titles WHERE providers_last_success IS NULL'),
    'failing'  => (int) scalar($PDO, 'SELECT COUNT(*) FROM titles WHERE providers_fail_streak >= 3'),
];

// DB size (MB)
$dbmb = (float) (scalar($PDO, 'SELECT ROUND(SUM(data_length+index_length)/1048576,1)
                                 FROM information_schema.tables WHERE table_schema = DATABASE()') ?? 0);

// आज
$today = one($PDO, "SELECT
    COALESCE(SUM(change_type='added'),0)   AS a,
    COALESCE(SUM(change_type='removed'),0) AS r
  FROM availability_changes WHERE changed_on = CURDATE()") ?? ['a' => 0, 'r' => 0];
$todayRuns = (int) scalar($PDO, 'SELECT COUNT(*) FROM sync_runs WHERE DATE(started_at) = CURDATE()');

// पिछले 14 दिन की गतिविधि — रोज़ जुड़े/हटे (chart के लिए)
$actRaw = [];
foreach (all($PDO, "SELECT changed_on d,
        COALESCE(SUM(change_type='added'),0)   a,
        COALESCE(SUM(change_type='removed'),0) r
      FROM availability_changes
     WHERE changed_on >= (CURDATE() - INTERVAL 13 DAY)
     GROUP BY changed_on") as $row) {
    $actRaw[substr((string) $row['d'], 0, 10)] = ['a' => (int) $row['a'], 'r' => (int) $row['r']];
}
$activity = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $activity[] = ['d' => $d, 'a' => $actRaw[$d]['a'] ?? 0, 'r' => $actRaw[$d]['r'] ?? 0];
}
$actMax = max(1, max(array_map(fn ($x) => max($x['a'], $x['r']), $activity)));

// coverage
$pcov = one($PDO, "SELECT COUNT(*) tot, COALESCE(SUM(poster_path IS NOT NULL AND poster_path<>''),0) wp FROM titles")
      ?? ['tot' => 0, 'wp' => 0];
$langTitles = (int) scalar($PDO, 'SELECT COUNT(DISTINCT title_id) FROM title_languages');
$posterPct = (int) $pcov['tot'] > 0 ? round((int) $pcov['wp'] / (int) $pcov['tot'] * 100) : 0;
$langPct   = $c['titles'] > 0 ? round($langTitles / $c['titles'] * 100) : 0;

// last run per job + recent runs
$lastCat  = one($PDO, "SELECT * FROM sync_runs WHERE job='catalog'   ORDER BY id DESC LIMIT 1");
$lastProv = one($PDO, "SELECT * FROM sync_runs WHERE job='providers' ORDER BY id DESC LIMIT 1");
$runs = all($PDO, 'SELECT * FROM sync_runs ORDER BY id DESC LIMIT 12');
$halted = array_values(array_filter($runs, fn ($r) => $r['status'] === 'halted'));

// health पिल — providers की आख़िरी दौड़ से
$health = ['warn', 'कोई providers दौड़ नहीं'];
if ($halted !== []) {
    $health = ['bad', 'सुरक्षा ब्रेक (halted)'];
} elseif ($lastProv !== null) {
    $ageH = $lastProv['finished_at'] ? (time() - strtotime($lastProv['finished_at'])) / 3600 : 999;
    if ($lastProv['status'] === 'done' && $ageH <= 30)      $health = ['ok',   'सेहत ठीक'];
    elseif ($lastProv['status'] === 'done' && $ageH > 30)   $health = ['warn', 'cron देर से चला'];
    elseif ($lastProv['status'] === 'failed')               $health = ['bad',  'आख़िरी दौड़ विफल'];
    else                                                    $health = ['warn', $lastProv['status']];
}

// growth — पिछले 14 दिन के बदलाव
$growth = [];
foreach (all($PDO, "SELECT changed_on d,
    COALESCE(SUM(change_type='added'),0) a, COALESCE(SUM(change_type='removed'),0) r
  FROM availability_changes WHERE changed_on >= (CURDATE() - INTERVAL 13 DAY)
  GROUP BY changed_on") as $g) {
    $growth[$g['d']] = ['a' => (int) $g['a'], 'r' => (int) $g['r']];
}

// tables by size
$tables = all($PDO, 'SELECT table_name tn, table_rows tr, ROUND((data_length+index_length)/1048576,2) mb
    FROM information_schema.tables WHERE table_schema = DATABASE()
    ORDER BY (data_length+index_length) DESC');

// top providers + languages
$topProv = all($PDO, "SELECT p.name, COUNT(DISTINCT a.title_id) c
    FROM availability a JOIN providers p ON p.id = a.provider_id
   WHERE a.is_current = 1 AND a.offer_type IN ('flatrate','ads','free')
   GROUP BY p.id ORDER BY c DESC LIMIT 8");
$langs = all($PDO, "SELECT l.lang_code, COUNT(DISTINCT t.id) c
    FROM availability a JOIN titles t ON t.id=a.title_id JOIN title_languages l ON l.title_id=t.id
   WHERE a.is_current=1 AND a.offer_type IN ('flatrate','ads','free')
   GROUP BY l.lang_code ORDER BY c DESC LIMIT 8");

$recent = all($PDO, "SELECT c.change_type, c.changed_on, t.title, p.name prov, c.offer_type
    FROM availability_changes c JOIN titles t ON t.id=c.title_id JOIN providers p ON p.id=c.provider_id
   ORDER BY c.id DESC LIMIT 12");

$unknown = state_get($PDO, 'unknown_providers', []);
$cursor  = state_get($PDO, 'catalog_cursor', []);
$cacheOn = function_exists('cache_enabled') && cache_enabled();
$cacheSt = function_exists('cache_stats') ? cache_stats() : ['files' => 0, 'bytes' => 0];
$now     = date('d M Y, H:i');

$runcell = function (?array $r) use ($e): string {
    if ($r === null) return '<span class="dim">कभी नहीं</span>';
    $when = $e(substr((string) $r['started_at'], 5, 11));
    return '<span class="stt ' . $e($r['status']) . '">' . $e($r['status']) . '</span> · '
         . '<span class="dim">' . $when . ' · +' . (int) $r['changes_added'] . ' −' . (int) $r['changes_removed'] . '</span>';
};

// ---- premium dark shell (public nav/footer नहीं) -----------------------------
?><!doctype html>
<html lang="<?= OTT_LANG ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>OTTGuru · Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css">
</head>
<body>

<div class="abar"><div class="wrap abar-in">
  <a class="logo" href="/">OTT<span>Guru</span></a>
  <span class="tag">Admin</span>
  <span class="hpill <?= $health[0] ?>"><span class="d"></span><?= $e($health[1]) ?></span>
  <span class="when"><?= $e($now) ?></span>
  <a class="alogout" href="?view=manual"><?= OTT_LANG === 'hi' ? 'plan + बंडल' : 'Plans + bundles' ?></a>
  <a class="alogout" href="?view=pages"><?= OTT_LANG === 'hi' ? 'पेज + index' : 'Pages' ?></a>
  <a class="alogout" href="?logout=1"><?= OTT_LANG === 'hi' ? 'लॉगआउट ↩' : 'Log out ↩' ?></a>
</div></div>

<main class="wrap" style="padding-top:22px">

<?php if ($halted !== []): ?>
<div class="alarm"><b>सुरक्षा ब्रेक लगा था।</b> <?= $e($halted[0]['note'] ?? '') ?><br>
  <span class="dim small">वजह ठीक होने तक कोई बदलाव दर्ज नहीं होगा — इतिहास सुरक्षित है।</span></div>
<?php endif; ?>

<!-- खोज — किसी title की पूरी कुंडली + क्रियाओं तक जाने का रास्ता -->
<form class="asearch" method="get" action="<?= $e($selfPath) ?>">
  <input type="search" name="q" value="<?= $e($q) ?>" placeholder="<?= OTT_LANG === 'hi' ? 'कोई फिल्म/सीरीज़ खोजें… (नाम या slug)' : 'Search a title…' ?>" autocomplete="off" autofocus>
  <button type="submit"><?= OTT_LANG === 'hi' ? 'खोजें' : 'Search' ?></button>
</form>
<?php if ($q !== ''): ?>
<div class="panel" style="margin-bottom:16px">
  <div class="ph"><h3><?= OTT_LANG === 'hi' ? 'खोज नतीजे' : 'Results' ?></h3><span class="t"><?= count($results) ?> · “<?= $e($q) ?>”</span></div>
  <div style="overflow-x:auto"><table class="atable">
    <tr><th>title</th><th>tier</th><th><?= OTT_LANG === 'hi' ? 'जाँचा' : 'checked' ?></th><th></th></tr>
    <?php foreach ($results as $r): ?>
    <tr>
      <td><a href="<?= $e($selfPath) ?>?view=title&id=<?= (int) $r['id'] ?>"><?= $e($r['title']) ?><?= $r['release_year'] ? ' <span class="dim">(' . $e($r['release_year']) . ')</span>' : '' ?></a>
        <span class="dim small"><?= $r['media_type'] === 'tv' ? '· सीरीज़' : '· फिल्म' ?></span></td>
      <td class="n"><?= (int) $r['tier'] ?></td>
      <td class="n"><?= $r['providers_last_success'] ? $e(substr((string) $r['providers_last_success'], 0, 10)) : '<span style="color:var(--warn)">' . (OTT_LANG === 'hi' ? 'कभी नहीं' : 'never') . '</span>' ?></td>
      <td class="n"><a href="<?= $e($selfPath) ?>?view=title&id=<?= (int) $r['id'] ?>"><?= OTT_LANG === 'hi' ? 'खोलें →' : 'open →' ?></a></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($results === []): ?><tr><td colspan="4" class="dim"><?= OTT_LANG === 'hi' ? 'कुछ नहीं मिला।' : 'Nothing found.' ?></td></tr><?php endif; ?>
  </table></div>
</div>
<?php endif; ?>

<div class="acards">
  <div class="acard"><div class="v"><?= $nf($c['titles']) ?></div><div class="k">titles (<?= $nf($c['movies']) ?> फिल्में · <?= $nf($c['series']) ?> सीरीज़)</div></div>
  <div class="acard"><div class="v"><?= $nf($c['prov']) ?></div><div class="k">active platforms</div></div>
  <div class="acard ok"><div class="v"><?= $nf($c['live']) ?></div><div class="k">अभी उपलब्ध</div></div>
  <div class="acard"><div class="v"><?= $nf($c['past']) ?></div><div class="k">बीत चुके spells</div></div>
  <div class="acard ok"><div class="v"><?= $nf($c['changes']) ?></div><div class="k">इतिहास records</div></div>
  <div class="acard"><div class="v"><?= number_format($dbmb, 1) ?> <span style="font-size:14px">MB</span></div><div class="k">DB size</div></div>
</div>

<div class="acards" style="grid-template-columns:repeat(3,1fr)">
  <div class="acard ok"><div class="v">+<?= $nf((int) $today['a']) ?></div><div class="k">आज जुड़े</div></div>
  <div class="acard <?= (int) $today['r'] > 0 ? 'bad' : '' ?>"><div class="v">−<?= $nf((int) $today['r']) ?></div><div class="k">आज हटे</div></div>
  <div class="acard"><div class="v"><?= $nf($todayRuns) ?></div><div class="k">आज की sync दौड़ें</div></div>
</div>

<!-- गतिविधि — पिछले 14 दिन (जुड़े हरे ऊपर, हटे गुलाबी नीचे) -->
<div class="panel" style="margin-bottom:16px">
  <div class="ph"><h3><?= OTT_LANG === 'hi' ? 'गतिविधि — पिछले 14 दिन' : 'Activity — last 14 days' ?></h3>
    <span class="t"><span style="color:var(--good)">■</span> <?= OTT_LANG === 'hi' ? 'जुड़े' : 'added' ?>
      · <span style="color:var(--pink)">■</span> <?= OTT_LANG === 'hi' ? 'हटे' : 'removed' ?></span></div>
  <div class="actchart">
    <?php foreach ($activity as $day): ?>
    <div class="actcol" title="<?= $e(substr($day['d'], 5)) ?> · +<?= (int) $day['a'] ?> / −<?= (int) $day['r'] ?>">
      <div class="actbars">
        <span class="actadd" style="height:<?= (int) round($day['a'] / $actMax * 100) ?>%"></span>
        <span class="actrem" style="height:<?= (int) round($day['r'] / $actMax * 100) ?>%"></span>
      </div>
      <span class="actday"><?= $e((int) substr($day['d'], 8, 2)) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="agrid">
  <!-- sync health -->
  <div class="panel">
    <div class="ph"><h3>Sync health</h3><span class="t">cron · दो jobs</span></div>
    <table class="atable">
      <tr><th>job</th><th>आख़िरी दौड़</th></tr>
      <tr><td>catalog</td><td><?= $runcell($lastCat) ?></td></tr>
      <tr><td>providers</td><td><?= $runcell($lastProv) ?></td></tr>
    </table>
    <div style="overflow-x:auto;margin-top:14px">
    <table class="atable">
      <tr><th>job</th><th>हालत</th><th>कॉल</th><th>विफल</th><th>+</th><th>−</th><th>कब</th></tr>
      <?php foreach ($runs as $r): ?>
      <tr><td><?= $e($r['job']) ?></td>
        <td class="stt <?= $e($r['status']) ?>"><?= $e($r['status']) ?></td>
        <td class="n"><?= (int) $r['api_calls'] ?></td>
        <td class="n" <?= (int) $r['api_failures'] > 0 ? 'style="color:var(--warn)"' : '' ?>><?= (int) $r['api_failures'] ?></td>
        <td class="n"><?= (int) $r['changes_added'] ?></td>
        <td class="n"><?= (int) $r['changes_removed'] ?></td>
        <td class="n"><?= $e(substr((string) $r['started_at'], 5, 11)) ?></td></tr>
      <?php endforeach; ?>
    </table>
    </div>
  </div>

  <!-- coverage + queue -->
  <div class="panel">
    <div class="ph"><h3>Coverage &amp; queue</h3><span class="t">डेटा की गुणवत्ता</span></div>
    <div class="cov">
      <div>
        <div class="lbl"><span>Poster coverage</span><b><?= $posterPct ?>%</b></div>
        <div class="covbar"><i style="width:<?= $posterPct ?>%"></i></div>
      </div>
      <div>
        <div class="lbl"><span>भाषा coverage</span><b><?= $langPct ?>%</b></div>
        <div class="covbar"><i style="width:<?= $langPct ?>%"></i></div>
      </div>
    </div>
    <table class="atable" style="margin-top:18px">
      <tr><td>कभी नहीं जाँचे titles</td><td class="n" <?= $c['never'] > 0 ? 'style="color:var(--warn)"' : '' ?>><?= $nf($c['never']) ?></td></tr>
      <tr><td>लगातार विफल (fail-streak ≥ 3)</td><td class="n" <?= $c['failing'] > 0 ? 'style="color:var(--pink)"' : '' ?>><?= $nf($c['failing']) ?></td></tr>
      <tr><td>बिना poster titles</td><td class="n"><?= $nf((int) $pcov['tot'] - (int) $pcov['wp']) ?></td></tr>
    </table>
  </div>
</div>

<!-- growth chart -->
<div class="panel" style="margin-top:16px">
  <div class="ph"><h3>पिछले 14 दिन · बदलाव</h3>
    <span class="t"><span style="color:var(--good)">■</span> जुड़े &nbsp; <span style="color:var(--pink)">■</span> हटे</span></div>
  <?php
  $days = [];
  for ($i = 13; $i >= 0; $i--) $days[] = date('Y-m-d', strtotime("-$i day"));
  $maxv = 1;
  foreach ($days as $d) { $maxv = max($maxv, $growth[$d]['a'] ?? 0, $growth[$d]['r'] ?? 0); }
  $W = 720; $H = 150; $pad = 18; $bw = ($W - 2 * $pad) / count($days);
  ?>
  <svg viewBox="0 0 <?= $W ?> <?= $H ?>" width="100%" height="170" preserveAspectRatio="xMidYMid meet" role="img" aria-label="14-day changes">
    <line x1="<?= $pad ?>" y1="<?= $H - $pad ?>" x2="<?= $W - $pad ?>" y2="<?= $H - $pad ?>" stroke="rgba(255,255,255,.1)" stroke-width="1"/>
    <?php foreach ($days as $i => $d):
      $a = $growth[$d]['a'] ?? 0; $r = $growth[$d]['r'] ?? 0;
      $x = $pad + $i * $bw; $ah = ($H - 2 * $pad) * $a / $maxv; $rh = ($H - 2 * $pad) * $r / $maxv;
      $w = $bw / 2 - 3; ?>
      <rect x="<?= round($x + 3, 1) ?>" y="<?= round($H - $pad - $ah, 1) ?>" width="<?= round($w, 1) ?>" height="<?= round($ah, 1) ?>" rx="2" fill="#00D26A"/>
      <rect x="<?= round($x + 3 + $w + 1, 1) ?>" y="<?= round($H - $pad - $rh, 1) ?>" width="<?= round($w, 1) ?>" height="<?= round($rh, 1) ?>" rx="2" fill="#FF4D6D"/>
      <?php if ($i % 2 === 0): ?><text x="<?= round($x + $bw / 2, 1) ?>" y="<?= $H - 4 ?>" text-anchor="middle" font-family="IBM Plex Mono,monospace" font-size="9" fill="#5E6C86"><?= (int) date('d', strtotime($d)) ?></text><?php endif; ?>
    <?php endforeach; ?>
  </svg>
</div>

<div class="agrid" style="margin-top:16px">
  <div class="panel">
    <div class="ph"><h3>Top platforms</h3><span class="t">अभी उपलब्ध titles</span></div>
    <?php $pmax = $topProv ? max(array_map(fn ($p) => (int) $p['c'], $topProv)) : 1; ?>
    <div class="bars">
      <?php foreach ($topProv as $p): ?>
      <div class="barrow"><span class="nm"><?= $e($p['name']) ?></span>
        <span class="track"><span class="fill" style="width:<?= (int) round((int) $p['c'] / $pmax * 100) ?>%"></span></span>
        <span class="val"><?= $nf((int) $p['c']) ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="panel">
    <div class="ph"><h3>भाषा distribution</h3><span class="t">अभी उपलब्ध titles</span></div>
    <?php $lmax = $langs ? max(array_map(fn ($l) => (int) $l['c'], $langs)) : 1; ?>
    <div class="bars">
      <?php foreach ($langs as $l): ?>
      <div class="barrow"><span class="nm"><?= $e(lang_label($l['lang_code'])) ?></span>
        <span class="track"><span class="fill" style="width:<?= (int) round((int) $l['c'] / $lmax * 100) ?>%"></span></span>
        <span class="val"><?= $nf((int) $l['c']) ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="agrid" style="margin-top:16px">
  <div class="panel">
    <div class="ph"><h3>Database tables</h3><span class="t"><?= number_format($dbmb, 1) ?> MB कुल</span></div>
    <div style="overflow-x:auto"><table class="atable">
      <tr><th>table</th><th>rows</th><th>MB</th></tr>
      <?php foreach ($tables as $t): ?>
      <tr><td><?= $e($t['tn']) ?></td><td class="n"><?= $nf((int) $t['tr']) ?></td><td class="n"><?= number_format((float) $t['mb'], 2) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  </div>
  <div class="panel">
    <div class="ph"><h3>ताज़ा बदलाव</h3><span class="t">आख़िरी 12</span></div>
    <div style="overflow-x:auto"><table class="atable">
      <tr><th></th><th>title</th><th>platform</th><th>कब</th></tr>
      <?php foreach ($recent as $r): ?>
      <tr><td class="n" style="color:<?= $r['change_type'] === 'added' ? 'var(--good)' : 'var(--pink)' ?>"><?= $r['change_type'] === 'added' ? '+' : '−' ?></td>
        <td><?= $e(mb_substr($r['title'], 0, 30, 'UTF-8')) ?></td>
        <td><?= $e($r['prov']) ?></td>
        <td class="n"><?= $e(substr((string) $r['changed_on'], 5)) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($recent === []): ?><tr><td colspan="4" class="dim">अभी कुछ नहीं</td></tr><?php endif; ?>
    </table></div>
  </div>
</div>

<!-- page-cache — रफ़्तार -->
<div class="panel" style="margin-top:16px">
  <div class="ph"><h3><?= OTT_LANG === 'hi' ? 'पेज-कैश' : 'Page cache' ?></h3>
    <span class="t"><?= $cacheOn ? ($nf($cacheSt['files']) . ' ' . (OTT_LANG === 'hi' ? 'फ़ाइलें' : 'files') . ' · ' . number_format($cacheSt['bytes'] / 1048576, 1) . ' MB') : (OTT_LANG === 'hi' ? 'बंद' : 'off') ?></span></div>
  <?php if (preg_match('/^cc(\d+)$/', $flash, $ccm)): ?><div class="okline">✓ <?= OTT_LANG === 'hi' ? 'कैश साफ़ — ' : 'Cleared — ' ?><?= $nf((int) $ccm[1]) ?> <?= OTT_LANG === 'hi' ? 'फ़ाइलें हटीं।' : 'files removed.' ?></div><?php endif; ?>
  <p class="dim small" style="margin:0 0 10px">
    <?= OTT_LANG === 'hi'
        ? 'दिन भर पेज इसी कैश से तेज़ी से मिलते हैं (कोई DB query नहीं)। हर रात sync अपने-आप साफ़ कर देता है। कुछ मैन्युअल बदला हो तो नीचे से साफ़ करें।'
        : 'Pages are served fast from this cache all day (no DB query). The nightly sync clears it automatically. Clear it below if you changed something by hand.' ?>
  </p>
  <form method="post" action="<?= $e($selfPath) ?>" style="display:inline">
    <input type="hidden" name="csrf" value="<?= $e($CSRF) ?>">
    <input type="hidden" name="do" value="cache_clear">
    <button type="submit" class="abtn"><?= OTT_LANG === 'hi' ? 'कैश साफ़ करें' : 'Clear cache' ?></button>
  </form>
</div>

<!-- thin-page audit — SEO जोखिम -->
<div class="panel" style="margin-top:16px">
  <div class="ph"><h3><?= OTT_LANG === 'hi' ? 'कमज़ोर (thin) पेज' : 'Thin pages' ?></h3><span class="t"><?= $nf($thinCount) ?> · SEO जोखिम</span></div>
  <p class="dim small" style="margin:0 0 10px">
    <?= OTT_LANG === 'hi' ? 'इन पर न कोई OTT है, न कहानी — Google इन्हें deindex कर सकता है। providers दौड़ भरने पर अपने-आप घटेंगे। किसी को खोलकर “अभी दोबारा जाँचें” दबा सकते हैं।'
       : 'No OTT and no overview — deindex risk. They shrink as the providers run fills them. Open one to “Re-check now”.' ?>
  </p>
  <div style="overflow-x:auto"><table class="atable">
    <tr><th>title</th><th></th></tr>
    <?php foreach ($thin as $r): ?>
    <tr><td><a href="<?= $e($selfPath) ?>?view=title&id=<?= (int) $r['id'] ?>"><?= $e($r['title']) ?><?= $r['release_year'] ? ' (' . $e($r['release_year']) . ')' : '' ?></a></td>
      <td class="n dim small"><?= $r['media_type'] === 'tv' ? 'सीरीज़' : 'फिल्म' ?></td></tr>
    <?php endforeach; ?>
    <?php if ($thin === []): ?><tr><td colspan="2" class="dim"><?= OTT_LANG === 'hi' ? 'कोई नहीं — बढ़िया!' : 'None — great!' ?></td></tr><?php endif; ?>
  </table></div>
</div>

<?php if (is_array($unknown) && $unknown !== []): ?>
<div class="panel" style="margin-top:16px">
  <div class="ph"><h3><?= OTT_LANG === 'hi' ? 'अनजाने provider नाम' : 'Unknown provider names' ?></h3><span class="t"><?= OTT_LANG === 'hi' ? 'सही OTT से जोड़िए' : 'map to a provider' ?></span></div>
  <?php if ($flash === 'alias'): ?><div class="okline">✓ <?= OTT_LANG === 'hi' ? 'जुड़ गया — अगली दौड़ से यह नाम सही जगह गिना जाएगा।' : 'Mapped — counted correctly from the next run.' ?></div><?php endif; ?>
  <div style="overflow-x:auto"><table class="atable">
    <tr><th><?= OTT_LANG === 'hi' ? 'नाम' : 'name' ?></th><th><?= OTT_LANG === 'hi' ? 'बार' : 'seen' ?></th><th><?= OTT_LANG === 'hi' ? 'किस OTT से जोड़ें?' : 'map to' ?></th></tr>
    <?php arsort($unknown); foreach (array_slice($unknown, 0, 15, true) as $nm => $cnt): ?>
    <tr>
      <td><?= $e($nm) ?></td><td class="n"><?= (int) $cnt ?></td>
      <td>
        <form method="post" action="<?= $e($selfPath) ?>" style="display:flex;gap:8px;align-items:center">
          <input type="hidden" name="csrf" value="<?= $e($CSRF) ?>">
          <input type="hidden" name="do" value="alias">
          <input type="hidden" name="name" value="<?= $e($nm) ?>">
          <select name="pid" class="asel" required>
            <option value=""><?= OTT_LANG === 'hi' ? '— चुनिए —' : '— choose —' ?></option>
            <?php foreach ($provList as $p): ?><option value="<?= (int) $p['id'] ?>"><?= $e($p['name']) ?></option><?php endforeach; ?>
          </select>
          <button type="submit" class="abtn"><?= OTT_LANG === 'hi' ? 'जोड़ें' : 'Map' ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>

<p class="dim small" style="margin:22px 0 40px;font-family:var(--mono)">
  catalog cursor: <?= $e(json_encode($cursor, JSON_UNESCAPED_UNICODE)) ?>
</p>

</main>
</body>
</html>
