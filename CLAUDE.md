# OTT Guru — प्रोजेक्ट संदर्भ

> यह फाइल Claude Code हर सत्र की शुरुआत में पढ़ता है।
> इसमें वो फ़ैसले लिखे हैं जो चर्चा और असली टेस्टिंग से निकले हैं — इन्हें
> बदलने से पहले पूछिए, क्योंकि हर नियम के पीछे एक ठोस वजह है।

---

## 1. प्रोजेक्ट क्या है

**ottguru.in** — भारत के लिए OTT availability साइट (JustWatch जैसी, पर उससे अलग)।
कौन सी फिल्म/सीरीज़ किस platform पर है, किस भाषा में, और **platform बदलने पर
अपने-आप अपडेट**।

रिपॉज़िटरी: `Skaler2015/ottguru`
मालिक: Subhash — Noble Care Hospital, सीकर, राजस्थान। हिंदी/Hinglish में बात करते हैं।

### JustWatch की नकल नहीं करनी

उनके पास licensing deals, 140+ देश और 10 साल का SEO है। "यह फिल्म Netflix पर है"
बताना commodity है। हमारा फ़र्क़ इन चीज़ों में है, और यही प्राथमिकता है:

1. **उपलब्धता का इतिहास** — "यह फिल्म जनवरी 2024 से मार्च 2026 तक Netflix पर थी,
   अब ZEE5 पर है"। कोई इसे कॉपी नहीं कर सकता; नए प्रतियोगी को भी शुरू से महीनों
   इंतज़ार करना पड़ेगा। **यह हमारी सबसे बड़ी संपत्ति है।**
2. **टेलीकॉम बंडल** — "मेरे Jio ₹399 plan में यह पहले से मुफ़्त है"
3. **plan tier की सच्चाई** — "मेरा ₹149 Netflix Mobile plan इसे TV पर चलाएगा?"
4. **ऑडियो भाषा / dub** — भारत का असली सवाल, TMDB यह नहीं देता
5. **OTT रिलीज़ डेट का अनुमान** — थिएटर में लगी फिल्म कब OTT पर आएगी

बिंदु 2, 3, 5 का डेटा किसी API से नहीं मिलता — मैन्युअल है। **यह बोझ नहीं, यही
बचाव है।** जो चीज़ आसानी से scrape हो जाए उसकी कोई कीमत नहीं।

---

## 2. अभी कहाँ तक पहुँचे हैं

| चरण | हालत |
|---|---|
| 0 — डेटा सत्यापन | ✅ पूरा। TMDB 30 में से 29 सही (~97%) |
| 1 — sync engine | ✅ बना और टेस्ट हुआ (यही रिपॉज़िटरी) |
| 1b — Streaming Availability (ऑडियो भाषा) | ⏸ रुका — RapidAPI subscribe होना बाक़ी |
| 2 — वेबसाइट | ✅ पूरा। होमपेज, title, platform, भाषा, changes (naya/hata) पन्ने + sitemap.xml — सब असली MariaDB पर परखे गए |
| 3 — बंडल / tier / अलर्ट | ⬜ बाद में |

**sync आज से चलना ज़रूरी है।** जो दिन बीत गया उसका इतिहास वापस नहीं आएगा —
इसलिए वेबसाइट से पहले cron चालू होना चाहिए।

---

## 3. तीन नियम — इन्हें कभी न तोड़ें

ये चरण 0 की असली विफलताओं से निकले हैं, किताबी सलाह नहीं हैं।

### नियम 1 — हर API कॉल पर retry
`lib/http.php` में `http_get_json()`। 429 / 500 / timeout पर रुककर दोबारा।

**वजह:** बिना retry पहले टेस्ट में 30 में से **8 titles चुपचाप गायब** हो गए —
Jawan, Mirzapur, Sacred Games जैसी फिल्में, जो TMDB पर पक्का मौजूद हैं। retry
जोड़ने पर 30/30 मिल गए। यानी "TMDB की भारत coverage कमज़ोर है" वाला निष्कर्ष
पूरी तरह गलत था — गड़बड़ हमारे कोड में थी।

### नियम 2 — विफल कॉल का मतलब "पता नहीं", कभी "डेटा नहीं"
`bin/sync_providers.php` में: कॉल विफल हो तो `providers_last_checked` बढ़ता है,
पर `providers_last_success` **नहीं** बदलता, और उस title का **कोई diff नहीं**
लिखा जाता।

**वजह:** अगर विफल कॉल को "provider हट गया" मान लिया जाए तो
`availability_changes` में झूठी entries भर जाएँगी — और वही टेबल हमारी सबसे
कीमती चीज़ है। एक बार कचरा घुसा तो पूरा मूल्य ख़त्म।

### नियम 3 — सुरक्षा ब्रेक
`lib/run.php` में `safety_check()`। एक दौड़ में 40% से ज़्यादा titles से provider
"हटते" दिखें तो **कुछ भी लिखे बिना** दौड़ `halted` हो जाती है और मेल जाता है।

**वजह:** API का बड़ा outage, region ब्लॉक, या key बंद होना — इनमें से कोई भी
एक दौड़ में हज़ारों झूठे "removed" बना सकता है।

**टेस्ट में परखा गया:** सारे providers ख़ाली भेजे गए (100% हटाव) → दौड़ halted,
`availability` और `availability_changes` में एक भी बदलाव नहीं। outage ख़त्म होने
पर सिस्टम अपने-आप सँभल गया।

---

## 4. फाइलें

```
config.php              सिर्फ़ यही बदली जाती है (DB, tmdb_key, run_token, alert_email)
schema.sql              8 टेबलें
queries.sql             वेबसाइट के लिए 13 तैयार queries (सब चलकर परखी गईं)
README.md               लगाने का तरीक़ा, cron, रफ़्तार का हिसाब
lib/
  boot.php              config + पहुँच जाँच + DB. $GLOBALS['__RAW_OUTPUT'] से HTML शेल बंद
  db.php                PDO + state_get/state_set (कर्सर)
  http.php              retry वाला HTTP          ← नियम 1
  tmdb.php              TMDB client + कॉल गिनती
  util.php              slugify, norm_name, base_service_name, compute_tier
  run.php               run_start/finish, safety_check, lock  ← नियम 3
bin/
  install.php           एक बार — टेबलें + TMDB से providers + alias
  sync_catalog.php      नए titles खोजना (कर्सर से resumable)
  sync_providers.php    providers जाँचना + diff  ← नियम 2
  status.php            सेहत का पन्ना
public/                 वेबसाइट का web root — index.php (router), .htaccess, assets/
site/                   वेबसाइट का कोड — web.php (bootstrap), helpers, layout,
                        pages/ (home, title, provider, 404)। सिर्फ़ पढ़ता है, DB में
                        लिखता केवल sync engine है
```

---

## 5. डेटाबेस के फ़ैसले (बदलने से पहले पूछें)

- **`availability_changes` पर जान-बूझकर कोई FOREIGN KEY नहीं।** बाक़ी सब पर
  CASCADE है, इस एक पर नहीं — ताकि कोई delete, कोई cleanup स्क्रिप्ट इसे छू न
  सके। यह append-only है।

- **`providers` + `provider_aliases`** — TMDB एक ही सेवा को कई नामों से भेजता है।
  असली टेस्ट में मिले नाम:
  ```
  Amazon Prime Video  /  Amazon Prime Video with Ads
  MX Player           /  Amazon MX Player
  ManoramaMax         /  ManoramaMAX Amazon Channel
  Sony Liv (असली: SonyLIV)   Zee5 (असली: ZEE5)
  ```
  `install.php` इन्हें अपने-आप एक canonical provider पर मिला देता है।
  `with Ads` वाले `implies_ads` से `offer_type='ads'` बन जाते हैं — अलग provider
  नहीं बनते।

- **`availability` में spell रखा जाता है** — `first_seen` / `last_seen` /
  `is_current`. `is_current=0` वाली पंक्तियाँ **कभी मत मिटाइए**, वही इतिहास है।
  दोबारा जुड़ने पर `first_seen` नया spell शुरू करता है।

- **`slug` एक बार बनने के बाद कभी नहीं बदलता** — चाहे TMDB नाम बदल दे। SEO के
  लिए ज़रूरी। `sync_catalog.php` का UPDATE जान-बूझकर slug को नहीं छूता।

- **`tier`** 1/2/3 = रोज़/हफ़्ता/महीना। `compute_tier()` popularity + रिलीज़ की
  ताज़गी से तय करता है।

- **poller की ORDER BY सिर्फ़ `tier, providers_last_success` है।**
  `popularity` **मत जोड़िए** — उससे `ix_poll` index टूट जाएगा और filesort लौट
  आएगा। MySQL में NULL अपने-आप पहले आते हैं, इसलिए "कभी नहीं जाँचे गए" titles को
  प्राथमिकता मिल जाती है। (EXPLAIN से पुष्टि: `Using where; Using index`)

- **`title_languages` फिल्म की भाषा है, dub नहीं।** "Netflix पर हिंदी dub है या
  नहीं" अलग सवाल है और वो Streaming Availability से आएगा। **इन दोनों को कभी
  मिलाकर मत दिखाइए** — एक बार गलत जानकारी दिखी तो यूज़र लौटता नहीं।

---

## 6. होस्टिंग की सीमाएँ

Hostinger **Cloud Startup** — 4 CPU cores, 4 GB RAM, 100 GB NVMe, 100 PHP workers।

- **कोई persistent background process/daemon नहीं चलता।** Redis queue, worker
  pool, supervisor — कुछ नहीं। सब कुछ **cron-driven PHP** है।
- **PHP का max_execution_time सीमित है** — इसलिए हर स्क्रिप्ट टुकड़ों में काम
  करती है और `sync_state` में कर्सर सहेजती है। यह डिज़ाइन तोड़िए मत।
- **असली सीमा MySQL की database size है**, 100 GB डिस्क नहीं। hPanel में देखिए।
- **उसी होस्टिंग पर उनकी और साइटें चल रही हैं** (apnesoftware, examskitayari,
  tayariportal, ChangeTracker)। sync रात 1–5 बजे चलाइए, वरना बाक़ी साइटें धीमी
  पड़ेंगी या 503 देंगी।
- **`config.php` में DB पासवर्ड है** — फोल्डर `public_html` के बाहर रहना चाहिए।
- **TMDB का पता `api.tmdb.org` रखिए, `api.themoviedb.org` नहीं** — Hostinger
  Mumbai से मुख्य पते पर रुक-रुककर connection reset आता है (असली deployment में
  पैमाइश: 5 में से 2 कॉल टूटीं; दूसरे पते पर 5/5 साफ़)। जाँच का तरीक़ा README में।

### रफ़्तार का हिसाब
हर title पर **एक** API कॉल (`append_to_response` से मेटाडेटा + providers एक साथ)।

```
tier 1 titles = रोज़ की ज़रूरत
provider_titles_per_run × रोज़ की दौड़ें ≥ वो संख्या
```
`sleep_ms=120` पर ~8 कॉल/सेकंड। `status.php` की "अवधि" `max_seconds` के पास
पहुँचे तो **batch घटाइए और cron बढ़ाइए**, उल्टा नहीं।

---

## 7. अगले काम, क्रम से

1. **cron चालू कीजिए** — इतिहास आज से जमा हो (README में तैयार लाइनें)
2. **RapidAPI पर Streaming Availability subscribe** कीजिए, फिर
   `provider_audio` टेबल जोड़कर ऑडियो भाषा भरें
3. **चरण 2 — वेबसाइट**: title पेज, provider पेज, भाषा पेज, changes पेज,
   sitemap, schema.org. सब queries `queries.sql` में हैं
4. **WhatsApp चैनल + watchlist अलर्ट** — भारत में email से 10 गुना असरदार,
   और यही बार-बार ट्रैफ़िक लाएगा
5. मैन्युअल डेटा: plan tier के नियम (~60 rows), टेलीकॉम बंडल (~100 rows)

---

## 8. जो नहीं करना

- ❌ **JustWatch स्क्रैप** — उनका पूरा बिज़नेस यही डेटा लाइसेंस करना है
- ❌ **पायरेसी लिंक** — AdSense तुरंत बंद, और क़ानूनी लफड़ा
- ❌ **posters अपने सर्वर पर** — TMDB CDN से ही serve कीजिए
- ❌ **पतले (thin) auto-generated पेज** — हर पेज पर कम से कम एक चीज़ ऐसी हो जो
  सिर्फ़ हमारे पास है (इतिहास, बंडल, tier, भाषा तालिका), वरना Google पूरी साइट
  deindex कर देगा
- ❌ **विफल कॉल को "डेटा नहीं" मानना** — नियम 2
- ❌ **`is_current=0` पंक्तियाँ मिटाना** — वही इतिहास है
- ❌ TMDB attribution हटाना — शर्त है

---

## 9. तरीक़े और सलीका

- कोड की टिप्पणियाँ **हिंदी में**। Subhash हिंदी/Hinglish में काम करते हैं।
- **साइट की UI दो भाषाओं में — default अंग्रेज़ी, header में हिंदी का switch**
  (Subhash का फ़ैसला, 27 जुलाई 2026)। strings कोड में हिंदी में ही लिखी जाती
  हैं, `site/i18n.php` की `t()`/`tf()` अंग्रेज़ी में बदलती है। **नया UI-text
  जोड़ें तो entry i18n के नक़्शे में भी जोड़िए** — key छूटे तो अंग्रेज़ी mode
  में हिंदी दिखने लगती है (टूटता कुछ नहीं, पर भद्दा लगता है)। पसंद cookie
  `ottg_lang` में; `?lang=hi|en` से बदलती है।
- PHP 8 + MySQL, कोई framework नहीं, कोई composer dependency नहीं — होस्टिंग
  की सीमाओं के हिसाब से जान-बूझकर सादा रखा है
- **बदलाव के बाद असल में चलाकर देखिए**, सिर्फ़ lint पर भरोसा न कीजिए।
  पूरा इंजन नक़ली TMDB सर्वर पर चलाकर परखा गया था — वही तरीक़ा आगे भी रखिए
- Subhash पूरी, चलने लायक़ चीज़ पसंद करते हैं — आधे-अधूरे टुकड़े नहीं

---

## 10. चरण 0 के आँकड़े (संदर्भ के लिए)

30 titles, भारत, TMDB `watch/providers`:

| | retry से पहले | retry के बाद |
|---|---|---|
| TMDB पर मिले | 22/30 | **30/30** |
| provider डेटा मिला | 15/30 | **29/30** |
| Subhash का फ़ैसला | — | **29 सही, 1 गलत** |

वो एक "गलत" `HanuMan (2024)` था, जिसमें title-matching गलत फिल्म उठा लाई
(1998 की "Hanuman") — TMDB की गलती नहीं। अगर title matching पर काम करें तो यह
मामला टेस्ट केस के तौर पर रखिए।

Streaming Availability अभी परखी **नहीं** गई — key से
`You are not subscribed to this API` आ रहा था। ऑडियो भाषा के बारे में कोई भी
दावा तब तक न करें जब तक वो टेस्ट न हो जाए।
