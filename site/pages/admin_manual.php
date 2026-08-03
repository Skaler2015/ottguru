<?php
/**
 * Admin — मैन्युअल डेटा  (/admin?view=manual)
 * OTT plan tier + telecom बंडल — §1 का असली भेद, कोई API नहीं देता।
 * admin.php से मिलता है: $mProvs, $mPlans, $mBundles, $selfPath, $CSRF, $e, $nf, $flash
 */
declare(strict_types=1);
$L = OTT_LANG === 'hi';

/** provider चुनने का <select> — दोनों फ़ॉर्म में एक जैसा */
$provSelect = function (string $name) use ($mProvs, $e, $L): string {
    $h = '<select name="' . $e($name) . '" class="asel" required>'
       . '<option value="">' . ($L ? '— OTT चुनिए —' : '— choose OTT —') . '</option>';
    foreach ($mProvs as $p) {
        $h .= '<option value="' . (int) $p['id'] . '">' . $e($p['name']) . '</option>';
    }
    return $h . '</select>';
};
?><!doctype html>
<html lang="<?= OTT_LANG ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>OTTGuru · Admin · <?= $L ? 'plan + बंडल' : 'Plans + bundles' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css">
</head>
<body>

<div class="abar"><div class="wrap abar-in">
  <a class="logo" href="/">OTT<span>Guru</span></a>
  <span class="tag">Admin</span>
  <a class="alogout" href="<?= $e($selfPath) ?>" style="margin-left:auto"><?= $L ? '← डैशबोर्ड' : '← Dashboard' ?></a>
  <a class="alogout" href="<?= $e($selfPath) ?>?logout=1"><?= $L ? 'लॉगआउट ↩' : 'Log out ↩' ?></a>
</div></div>

<main class="wrap" style="padding-top:22px;max-width:1000px">

  <h1 style="font-size:24px;margin-bottom:4px"><?= $L ? 'मैन्युअल डेटा — plan + बंडल' : 'Manual data — plans + bundles' ?></h1>
  <p class="dim" style="margin:0 0 18px;max-width:70ch">
    <?= $L ? 'यही OTT Guru को JustWatch से अलग करता है — कौन सा plan TV पर चलेगा, और कौन सा telecom recharge किस OTT को मुफ़्त देता है। यह डेटा किसी API से नहीं मिलता, इसलिए यहाँ हाथ से भरा जाता है। जो भरेंगे वही title/platform पेजों पर दिखेगा — कुछ भी नक़ली नहीं।'
           : 'This is what sets OTT Guru apart — which plan works on TV, and which telecom recharge bundles which OTT for free. No API gives this, so you enter it here. Only what you add shows on the site — nothing fake.' ?>
  </p>

  <?php
  $msg = ['plan' => $L ? 'plan जुड़ गया।' : 'Plan added.', 'pdel' => $L ? 'plan हटा दिया।' : 'Plan deleted.',
          'bundle' => $L ? 'बंडल जुड़ गया।' : 'Bundle added.', 'bdel' => $L ? 'बंडल हटा दिया।' : 'Bundle deleted.'];
  if (isset($msg[$flash])): ?>
  <div class="okline">✓ <?= $e($msg[$flash]) ?></div>
  <?php endif; ?>

  <!-- ============================ OTT PLAN TIERS ============================ -->
  <div class="panel" style="margin-bottom:18px">
    <div class="ph"><h3><?= $L ? 'OTT plan tiers' : 'OTT plan tiers' ?></h3>
      <span class="t"><?= $nf(count($mPlans)) ?> <?= $L ? 'plan' : 'plans' ?></span></div>
    <p class="dim small" style="margin:0 0 12px">
      <?= $L ? 'हर OTT के plan — कीमत, क्वालिटी, कितनी screens, और सबसे ज़रूरी: TV पर चलेगा या नहीं (₹149 Mobile वाला सवाल)।'
             : 'Each OTT\'s plans — price, quality, screens, and the key one: does it play on TV (the ₹149 Mobile question).' ?>
    </p>

    <div style="overflow-x:auto"><table class="atable">
      <tr><th>OTT</th><th><?= $L ? 'plan' : 'plan' ?></th><th><?= $L ? 'कीमत' : 'price' ?></th><th><?= $L ? 'क्वालिटी' : 'quality' ?></th><th>TV?</th><th><?= $L ? 'screens' : 'screens' ?></th><th><?= $L ? 'विज्ञापन' : 'ads' ?></th><th></th></tr>
      <?php foreach ($mPlans as $r): ?>
      <tr>
        <td><b><?= $e($r['pname']) ?></b></td>
        <td><?= $e($r['name']) ?></td>
        <td class="n">₹<?= (int) $r['price_inr'] ?><span class="dim">/<?= $e($r['period'] === 'month' ? ($L ? 'माह' : 'mo') : ($r['period'] === 'year' ? ($L ? 'साल' : 'yr') : ($L ? 'तिमाही' : 'qtr'))) ?></span></td>
        <td><?= $e($r['max_quality'] ?? '—') ?></td>
        <td><?= (int) $r['tv_allowed'] === 1 ? '<span style="color:var(--good)">✓</span>' : '<span style="color:var(--pink)">✗ ' . ($L ? 'सिर्फ़ mobile' : 'mobile only') . '</span>' ?></td>
        <td class="n"><?= $r['screens'] !== null ? (int) $r['screens'] : '—' ?></td>
        <td><?= (int) $r['has_ads'] === 1 ? ($L ? 'हाँ' : 'yes') : '—' ?></td>
        <td>
          <form method="post" action="<?= $e($selfPath) ?>" onsubmit="return confirm('<?= $L ? 'यह plan हटाएँ?' : 'Delete this plan?' ?>')" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $e($CSRF) ?>">
            <input type="hidden" name="do" value="plan_del">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button type="submit" class="abtn danger"><?= $L ? 'हटाएँ' : 'Delete' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if ($mPlans === []): ?><tr><td colspan="8" class="dim"><?= $L ? 'अभी कोई plan नहीं — नीचे से जोड़िए।' : 'No plans yet — add one below.' ?></td></tr><?php endif; ?>
    </table></div>

    <form method="post" action="<?= $e($selfPath) ?>" class="mform">
      <input type="hidden" name="csrf" value="<?= $e($CSRF) ?>">
      <input type="hidden" name="do" value="plan_add">
      <?= $provSelect('provider_id') ?>
      <input name="name" class="ainp" placeholder="<?= $L ? 'plan नाम (Mobile/Premium)' : 'plan name (Mobile/Premium)' ?>" required maxlength="60">
      <input name="price_inr" class="ainp" type="number" min="0" placeholder="₹" required style="max-width:90px">
      <select name="period" class="asel">
        <option value="month"><?= $L ? 'महीना' : 'month' ?></option>
        <option value="quarter"><?= $L ? 'तिमाही' : 'quarter' ?></option>
        <option value="year"><?= $L ? 'साल' : 'year' ?></option>
      </select>
      <input name="max_quality" class="ainp" placeholder="<?= $L ? 'क्वालिटी (1080p)' : 'quality (1080p)' ?>" maxlength="12" style="max-width:120px">
      <input name="screens" class="ainp" type="number" min="0" placeholder="screens" style="max-width:100px">
      <input name="devices" class="ainp" placeholder="<?= $L ? 'devices (वैकल्पिक)' : 'devices (optional)' ?>" maxlength="120">
      <label class="achk"><input type="checkbox" name="tv_allowed" checked> TV</label>
      <label class="achk"><input type="checkbox" name="has_ads"> <?= $L ? 'विज्ञापन' : 'ads' ?></label>
      <button type="submit" class="abtn"><?= $L ? '+ plan जोड़ें' : '+ Add plan' ?></button>
    </form>
  </div>

  <!-- ============================ TELECOM BUNDLES ============================ -->
  <div class="panel">
    <div class="ph"><h3><?= $L ? 'Telecom बंडल' : 'Telecom bundles' ?></h3>
      <span class="t"><?= $nf(count($mBundles)) ?> <?= $L ? 'बंडल' : 'bundles' ?></span></div>
    <p class="dim small" style="margin:0 0 12px">
      <?= $L ? 'कौन सा Jio/Airtel/Vi recharge किस OTT को मुफ़्त देता है — "Jio ₹399 में Hotstar Mobile मुफ़्त" जैसा।'
             : 'Which Jio/Airtel/Vi recharge bundles which OTT for free — like "Jio ₹399 includes Hotstar Mobile".' ?>
    </p>

    <div style="overflow-x:auto"><table class="atable">
      <tr><th><?= $L ? 'operator' : 'operator' ?></th><th><?= $L ? 'recharge' : 'recharge' ?></th><th>OTT</th><th><?= $L ? 'tier' : 'tier' ?></th><th><?= $L ? 'वैधता' : 'validity' ?></th><th></th></tr>
      <?php foreach ($mBundles as $r): ?>
      <tr>
        <td><b><?= $e($r['operator']) ?></b></td>
        <td class="n">₹<?= (int) $r['plan_price'] ?><?php if (nz($r['plan_label'] ?? null) !== null): ?> <span class="dim"><?= $e($r['plan_label']) ?></span><?php endif; ?></td>
        <td><?= $e($r['pname']) ?></td>
        <td><?= $e($r['ott_tier'] ?? '—') ?></td>
        <td class="n"><?= $r['validity_days'] !== null ? ((int) $r['validity_days'] . ($L ? ' दिन' : 'd')) : '—' ?></td>
        <td>
          <form method="post" action="<?= $e($selfPath) ?>" onsubmit="return confirm('<?= $L ? 'यह बंडल हटाएँ?' : 'Delete this bundle?' ?>')" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $e($CSRF) ?>">
            <input type="hidden" name="do" value="bundle_del">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button type="submit" class="abtn danger"><?= $L ? 'हटाएँ' : 'Delete' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if ($mBundles === []): ?><tr><td colspan="6" class="dim"><?= $L ? 'अभी कोई बंडल नहीं — नीचे से जोड़िए।' : 'No bundles yet — add one below.' ?></td></tr><?php endif; ?>
    </table></div>

    <form method="post" action="<?= $e($selfPath) ?>" class="mform">
      <input type="hidden" name="csrf" value="<?= $e($CSRF) ?>">
      <input type="hidden" name="do" value="bundle_add">
      <input name="operator" class="ainp" placeholder="<?= $L ? 'operator (Jio/Airtel/Vi)' : 'operator (Jio/Airtel/Vi)' ?>" required maxlength="24" style="max-width:170px" list="ops">
      <datalist id="ops"><option>Jio</option><option>Airtel</option><option>Vi</option><option>BSNL</option></datalist>
      <input name="plan_price" class="ainp" type="number" min="0" placeholder="₹ recharge" required style="max-width:120px">
      <input name="plan_label" class="ainp" placeholder="<?= $L ? 'ब्योरा (28 दिन · 2.5GB/दिन)' : 'label (28 days · 2.5GB/day)' ?>" maxlength="80">
      <?= $provSelect('provider_id') ?>
      <input name="ott_tier" class="ainp" placeholder="<?= $L ? 'कौन सा tier (Mobile)' : 'which tier (Mobile)' ?>" maxlength="60" style="max-width:150px">
      <input name="validity_days" class="ainp" type="number" min="0" placeholder="<?= $L ? 'दिन' : 'days' ?>" style="max-width:90px">
      <button type="submit" class="abtn"><?= $L ? '+ बंडल जोड़ें' : '+ Add bundle' ?></button>
    </form>
  </div>

  <p class="dim small" style="margin:18px 0 40px">
    <?= $L ? 'सुझाव: पहले हर बड़े OTT के 3–4 plan भरिए (~60 rows), फिर Jio/Airtel/Vi के मुख्य recharge (~100 rows)। बदलाव तुरंत live होते हैं (page-cache अगली रात या “कैश साफ़” से)।'
           : 'Tip: add 3–4 plans per major OTT (~60 rows), then the main Jio/Airtel/Vi recharges (~100 rows). Changes go live immediately (page-cache clears nightly or via “Clear cache”).' ?>
  </p>

</main>
</body>
</html>
