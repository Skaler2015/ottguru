# OTT Guru — sync engine (चरण 1)

यह वेबसाइट नहीं है। यह वो इंजन है जो डेटा जमा करता है और **इतिहास बनाता है।**
वेबसाइट चरण 2 है — पर sync आज से चलना ज़रूरी है, क्योंकि जो दिन बीत गया उसका
इतिहास कभी वापस नहीं आएगा।

---

## तीन नियम जो पूरे कोड में गुँथे हैं

चरण 0 के टेस्ट में जो सबक़ मिला था, वही यहाँ नियम बन गया है:

1. **हर API कॉल पर retry** — 429 / 500 / timeout पर रुककर दोबारा (`lib/http.php`)
2. **विफल कॉल = "पता नहीं", कभी "डेटा नहीं"** — विफल title का
   `providers_last_success` नहीं बदलता और उसका कोई बदलाव दर्ज नहीं होता
3. **सुरक्षा ब्रेक** — एक ही दौड़ में 40% से ज़्यादा titles से provider "हटते"
   दिखें तो **कुछ भी लिखे बिना** दौड़ रुक जाती है और मेल आता है

पहले नियम के बिना पहले टेस्ट में 30 में से 8 titles चुपचाप गायब हो गए थे।
दूसरे नियम के बिना वही 8 titles `availability_changes` में **झूठे "हट गया"**
बन जाते — और वही टेबल आपकी सबसे कीमती संपत्ति है।

---

## फाइलें

```
config.php              ← सिर्फ़ यही बदलनी है
schema.sql              डेटाबेस का ढाँचा
queries.sql             वेबसाइट के लिए तैयार queries (चरण 2 में काम आएँगी)
lib/
  boot.php              config + पहुँच जाँच + DB
  db.php                PDO और कर्सर (sync_state)
  http.php              retry वाला HTTP  ← नियम 1
  tmdb.php              TMDB client + कॉल की गिनती
  util.php              slug, provider नाम की सफ़ाई, tier
  run.php               दौड़ का हिसाब + सुरक्षा ब्रेक  ← नियम 3
bin/
  install.php           एक बार — टेबलें + providers + alias
  sync_catalog.php      नए titles खोजना
  sync_providers.php    providers जाँचना + बदलाव पकड़ना  ← नियम 2
  status.php            सेहत का पन्ना
public/                 ← वेबसाइट का web root (चरण 2) — सिर्फ़ यही public_html में जाता है
  index.php             front controller — सारे रास्ते यहीं से
  .htaccess             सब रास्ते index.php की ओर
  assets/site.css       एक ही stylesheet
site/                   वेबसाइट का कोड (public_html के बाहर रहता है)
  web.php               वेबसाइट का bootstrap (boot.php से अलग — बिना token/log-shell)
  helpers.php           escaping, TMDB images, हिंदी तारीख़ें, लेबल
  layout.php            header/footer + posters की grid
  pages/
    home.php            होमपेज — आँकड़े, platforms, इस हफ़्ते नया
    title.php           /movie/{slug}, /series/{slug} — कहाँ देखें + पूरा इतिहास
    provider.php        /platform/{slug} — सूची, filter, भाषा-लिंक, इस हफ़्ते नया
    lang.php            /platform/{slug}/hindi-movies — भाषा पेज (0 पर 404, <5 पर noindex)
    changes.php         /naya[/{slug}], /hata[/{slug}] — क्या आया, क्या हटा + अब कहाँ है
    sitemap.php         /sitemap.xml — सिर्फ़ वही पन्ने जिन पर असल में कुछ है
    404.php
```

---

## लगाने का तरीक़ा

### 1. जगह — यह ज़रूरी है

फोल्डर को **`public_html` के बाहर** रखिए:

```
/home/uXXXXXX/ottguru-sync/        ← यहाँ
/home/uXXXXXX/domains/ottguru.in/public_html/   ← वेबसाइट यहाँ (बाद में)
```

वजह — `config.php` में DB का पासवर्ड है। बाहर रखने पर वो किसी हालत में
ब्राउज़र से नहीं खुल सकता। cron (CLI) से sync चलाने के लिए वेब पहुँच की
ज़रूरत नहीं है।

अगर मजबूरी में `public_html` के अंदर रखना पड़े, तो साथ आई `.htaccess`
फाइलें रहने दीजिए और `status.php` को टोकन से ही खोलिए।

### 2. डेटाबेस बनाइए

hPanel → Databases → MySQL → नया database + user.
**एक बात नोट कर लीजिए:** वहीं "Database size limit" भी दिख जाएगा — वही
आपकी असली सीमा है, 100 GB डिस्क नहीं।

### 3. config.php भरिए

- `db` — hPanel से मिले नाम/user/password
- `tmdb_key` — वही जो चरण 0 में इस्तेमाल की (v3 key या v4 token, दोनों चलेंगे)
- `run_token` — कुछ लंबा और अटपटा, जैसे `ott-8k3mz9-x7q2`
- `safety.alert_email` — अपना ईमेल, ताकि ब्रेक लगने पर पता चले

### 4. install चलाइए (एक बार)

```bash
cd /home/uXXXXXX/ottguru-sync
/usr/bin/php bin/install.php
```

यह टेबलें बनाएगा और TMDB से India के providers की सूची लाकर भर देगा —
सही IDs और सही स्पेलिंग (`ZEE5`, `SonyLIV`) के साथ। साथ ही वो गंदे नाम
अपने-आप एक जगह मिल जाएँगे जो आपके CSV में मिले थे:

```
MX Player  ←  Amazon MX Player
Amazon Prime Video  ←  Amazon Prime Video with Ads   (ads tier के निशान के साथ)
```

### 5. पहली बार डेटा भरिए

```bash
/usr/bin/php bin/sync_catalog.php      # titles खोजेगा
/usr/bin/php bin/sync_providers.php    # providers जाँचेगा
/usr/bin/php bin/status.php            # देखिए क्या हुआ
```

पहली बार catalog को कई बार चलाना पड़ेगा (हर दौड़ थोड़े पेज करती है और
कर्सर सहेज देती है)। कोई जल्दी नहीं — cron अपने-आप करता रहेगा।

### 6. cron लगाइए

hPanel → Advanced → Cron Jobs

```cron
# providers — रोज़ 4 बार (रात में, जब साइट पर ट्रैफ़िक कम हो)
15 1,2,3,4 * * *  /usr/bin/php /home/uXXXXXX/ottguru-sync/bin/sync_providers.php >/dev/null 2>&1

# catalog — हफ़्ते में 2 बार
0 3 * * 1,4       /usr/bin/php /home/uXXXXXX/ottguru-sync/bin/sync_catalog.php >/dev/null 2>&1

# deep link + dub ऑडियो (Streaming Availability) — तभी चलता है जब config में sa.key भरी हो
# RapidAPI free tier कम है, इसलिए धीरे: रात में, per_run छोटा
30 5 * * *        /usr/bin/php /home/uXXXXXX/ottguru-sync/bin/sync_deeplinks.php >/dev/null 2>&1
```

दो दौड़ें एक साथ नहीं चलेंगी — file lock लगा हुआ है, इसलिए cron के
ओवरलैप से डरने की ज़रूरत नहीं।

अगर CLI cron न चले तो ब्राउज़र वाला तरीक़ा:

```cron
15 1,2,3,4 * * *  wget -q -O /dev/null "https://ottguru.in/sync/bin/sync_providers.php?k=आपका-token"
```

---

## वेबसाइट लगाने का तरीक़ा (चरण 2)

वेबसाइट के सारे पन्ने तैयार हैं — होमपेज, title पन्ना (`/movie/jawan-2023`,
`/series/mirzapur`), platform पन्ना (`/platform/netflix`), भाषा पेज
(`/platform/netflix/hindi-movies`), changes पेज (`/naya`, `/hata` —
per-platform भी), और `/sitemap.xml`। सब हिंदी में, schema.org और TMDB
attribution के साथ।

साइट live करने के बाद Google Search Console में `sitemap.xml` जमा कर
दीजिए — `robots.txt` में उसका पता पहले से लिखा है।

### सबसे साफ़ तरीक़ा — public/ को ही web root बनाइए

hPanel → Websites → ottguru.in → document root को इस फोल्डर के `public/`
पर ले जाइए (या `public_html` को हटाकर उसकी जगह symlink):

```bash
ln -s /home/uXXXXXX/ottguru-sync/public /home/uXXXXXX/domains/ottguru.in/public_html
```

इसमें `config.php`, `lib/`, `site/` अपने-आप web root के बाहर रहते हैं —
कुछ अलग से छिपाना नहीं पड़ता।

### दूसरा तरीक़ा — public/ की फाइलें public_html में डालिए

`public/` की चारों चीज़ें (`index.php`, `.htaccess`, `robots.txt`, `assets/`)
`public_html` में copy कीजिए, बाक़ी app फोल्डर बाहर ही रहे। फिर
`index.php` की **एक** लाइन बदलिए — ऊपर ही मिलेगी:

```php
require dirname(__DIR__) . '/site/web.php';   // ← app फोल्डर अलग जगह हो तो यह रास्ता बदलिए
// जैसे: require '/home/uXXXXXX/ottguru-sync/site/web.php';
```

### याद रखने की बातें

- posters/logos **TMDB CDN से** आते हैं — अपने सर्वर पर कुछ store नहीं होता
- title पन्ने पर भाषा वाली पट्टी **फिल्म की भाषा** दिखाती है, dub नहीं —
  dub की जानकारी Streaming Availability जुड़ने के बाद आएगी
- paginated पन्ने (`?page=2`) अपने-आप `noindex` हैं — thin content से बचाव
- वेबसाइट सिर्फ़ **पढ़ती** है — DB में लिखता केवल sync engine है

---

## रफ़्तार का हिसाब — यह समझ लीजिए

हर title पर **एक** API कॉल लगती है (`append_to_response` की वजह से मेटाडेटा
और providers एक साथ आ जाते हैं)।

रोज़ कितनी जाँच चाहिए:

```
tier 1 titles ÷ 1 दिन  =  रोज़ की ज़रूरत
```

मान लीजिए tier 1 में 4,000 titles हैं → रोज़ 4,000 जाँच चाहिए।
`provider_titles_per_run = 250` और दिन में 4 दौड़ = सिर्फ़ 1,000। कम पड़ेगा।

तो `config.php` में बढ़ाइए:

- `provider_titles_per_run` → 800–1000
- cron → हर घंटे (`15 * * * *`)

`sleep_ms = 120` पर लगभग 8 कॉल प्रति सेकंड होती हैं, यानी 1,000 titles में
~2 मिनट। `max_seconds = 240` इसे संभाल लेता है, और सीमा पर पहुँचने पर दौड़
अपने-आप रुककर कर्सर सहेज देती है — कुछ अधूरा नहीं छूटता।

**status.php पर "अवधि" देखकर तय कीजिए।** अगर वो `max_seconds` के पास पहुँच
रही है तो batch घटाइए और cron बढ़ाइए, उल्टा नहीं।

---

## रोज़ क्या देखना है

`status.php?k=आपका-token` — दिन में एक बार, बस।

| क्या दिखे | मतलब |
|---|---|
| **सुरक्षा ब्रेक की लाल पट्टी** | तुरंत देखिए — TMDB key, region, या कोई outage। इतिहास सुरक्षित है, कुछ नहीं लिखा गया |
| **"लगातार विफल" > 0** | कुछ titles TMDB से हट गए होंगे। `queries.sql` #12 चलाइए |
| **अवधि `max_seconds` के पास** | batch घटाइए, cron बढ़ाइए |
| **"अनजाने provider नाम"** | नया OTT आया है — alias जोड़िए, वरना वो गिना नहीं जाएगा (`queries.sql` #11) |
| **"कभी नहीं जाँचे" घटता जाए** | सब ठीक चल रहा है |

---

## TMDB तक पहुँच — भारत के सर्वर पर ख़ास बात

असली deployment (Hostinger Mumbai, 27 जुलाई 2026) में मिला: TMDB के मुख्य पते
`api.themoviedb.org` पर **रुक-रुककर connection reset** आता है — पहली कॉल चलती
है, अगली टूट जाती है। sync में यह ऐसे दिखता है:

```
discover विफल ... Recv failure: Connection reset by peer
```

**इलाज:** `config.php` में TMDB का दूसरा आधिकारिक पता रखिए —

```php
'tmdb_base' => 'https://api.tmdb.org/3',
```

दोनों पतों की जाँच SSH से एक लाइन में (401 = रास्ता साफ़, 000 = reset):

```bash
for i in 1 2 3 4 5; do curl -s -o /dev/null -w "%{http_code} " --max-time 10 "https://api.tmdb.org/3/configuration"; done; echo
```

उस दिन की असल पैमाइश: `api.themoviedb.org` → `401 000 401 000 401`,
`api.tmdb.org` → `401 401 401 401 401`। इसीलिए `config.example.php` का
default अब `api.tmdb.org` है।

---

## जो अभी नहीं है (जान-बूझकर)

- **ऑडियो भाषा / dub** — इसके लिए Streaming Availability चाहिए, और वो अभी
  RapidAPI पर subscribe नहीं हुई है। ढाँचा तैयार है: `title_languages` में
  फिल्म की भाषा आ रही है; provider-वार audio के लिए आगे एक टेबल जुड़ेगी
- **वेबसाइट** — चरण 2. `queries.sql` में हर पन्ने की query तैयार है
- **टेलीकॉम बंडल, plan tier, TV प्रीमियर** — ये मैन्युअल डेटा हैं, बाद में
- **`title_languages` में जो `hi` है वो फिल्म की भाषा है** — "Netflix पर हिंदी
  dub है या नहीं" वो अलग सवाल है। दोनों को मिलाइए मत, वरना यूज़र का भरोसा टूटेगा

---

## जाँच की स्थिति

यह पूरा इंजन नक़ली TMDB सर्वर पर चलाकर परखा गया है:

- install → providers और alias सही बने, `Amazon MX Player` का `MX Player`
  में मिलना भी
- catalog → titles, slug, tier सही
- providers पहली दौड़ → 38 `added` घटनाएँ दर्ज
- **platform बदलना** → Netflix हटा + ZEE5 जुड़ा, पुरानी पंक्ति का
  `last_seen` जमा रह गया (यानी "कब से कब तक" निकल आता है)
- **नियम 2** → 3 titles पर HTTP 500 दिया गया: उनका डेटा जैसा था वैसा रहा,
  कोई झूठा "हट गया" नहीं बना, `fail_streak` बढ़ा
- **नियम 3** → सारे providers ख़ाली भेजे गए (100% हटाव): दौड़ `halted`,
  `availability` और `availability_changes` में एक भी बदलाव नहीं
- outage ख़त्म होने पर सिस्टम अपने-आप सँभल गया
- `queries.sql` की सभी 13 queries चलकर सही नतीजे दीं
- poller की मुख्य query index से चलती है, filesort नहीं करती
