<?php
/**
 * COMPARE — /compare?p=netflix,zee5  (OTT plan + बंडल की साथ-साथ तुलना)
 * यही §1 का असली भेद — कौन सा plan सस्ता, कौन TV पर चलेगा, कौन telecom में मुफ़्त।
 * डेटा manual (provider_plans + telecom_bundles); न भरा हो तो साफ़ बताता है।
 * server-rendered chips (toggle links) — कोई JS ज़रूरी नहीं; filtered view noindex।
 */
declare(strict_types=1);

$L = OTT_LANG === 'hi';

$allProvs = all($PDO, "SELECT id, slug, name, logo_path FROM providers WHERE is_active = 1 ORDER BY display_priority, name");
$bySlug = [];
foreach ($allProvs as $p) {
    $bySlug[$p['slug']] = $p;
}

// चुने हुए (?p=slug,slug) — सिर्फ़ असली slugs, अधिकतम 4
$sel = [];
foreach (explode(',', (string) ($_GET['p'] ?? '')) as $s) {
    $s = trim($s);
    if (isset($bySlug[$s]) && !in_array($s, $sel, true)) {
        $sel[] = $s;
    }
}
$sel = array_slice($sel, 0, 4);

// chip toggle का URL
$toggleUrl = function (string $slug) use ($sel): string {
    $new = in_array($slug, $sel, true)
        ? array_values(array_filter($sel, fn ($s) => $s !== $slug))
        : array_merge($sel, [$slug]);
    return '/compare' . ($new !== [] ? '?p=' . implode(',', array_map('rawurlencode', $new)) : '');
};

// हर चुने provider का plans + bundles (try/catch — tables/डेटा न हों तो ख़ाली)
$cols = [];
foreach ($sel as $slug) {
    $p = $bySlug[$slug];
    $plans = $bundles = [];
    try {
        $plans = all($PDO, "SELECT name, price_inr, period, max_quality, screens, tv_allowed, has_ads
                              FROM provider_plans WHERE provider_id = ? ORDER BY sort_order, price_inr", [(int) $p['id']]);
        $bundles = all($PDO, "SELECT operator, plan_price, ott_tier FROM telecom_bundles
                               WHERE provider_id = ? ORDER BY plan_price", [(int) $p['id']]);
    } catch (Throwable $e) { /* manual tables अभी नहीं */ }
    $cols[] = ['p' => $p, 'plans' => $plans, 'bundles' => $bundles];
}

$perLabel = fn (string $pd) => $pd === 'year' ? ($L ? '/साल' : '/yr') : ($pd === 'quarter' ? ($L ? '/तिमाही' : '/qtr') : ($L ? '/माह' : '/mo'));

page_header([
    'title'       => $L ? 'OTT plan तुलना — भारत' : 'Compare OTT plans — India',
    'description' => $L ? 'Netflix, Prime, ZEE5, SonyLIV आदि के plan साथ-साथ रखिए — कौन सस्ता, कौन TV पर चलेगा, कौन telecom recharge में मुफ़्त। OTT गुरु।'
                        : 'Put Netflix, Prime, ZEE5, SonyLIV plans side by side — cheapest, which works on TV, which is free with a telecom recharge. OTT Guru.',
    'canonical'   => '/compare',
    'noindex'     => $sel !== [],   // चुनाव वाला view faceted → noindex; सादा /compare index
]);
?>

<div class="phead" style="padding-bottom:20px">
  <span class="eyebrow"><?= $L ? 'तुलना' : 'Compare' ?></span>
  <h1 style="margin:4px 0 6px"><?= $L ? 'OTT plan आमने-सामने' : 'OTT plans side by side' ?></h1>
  <p class="dim" style="max-width:64ch;margin:0">
    <?= $L ? 'दो या ज़्यादा OTT चुनिए — कौन सा plan सस्ता, कौन TV पर चलेगा, और कौन आपके telecom recharge में पहले से मुफ़्त है। यह जानकारी सिर्फ़ हमारे पास है।'
           : 'Pick two or more OTTs — which plan is cheapest, which works on TV, and which is already free with your telecom recharge. Data only we keep.' ?>
  </p>
</div>

<div class="chips cmp-pick">
  <?php foreach ($allProvs as $p): $on = in_array($p['slug'], $sel, true); ?>
  <a class="chip <?= $on ? 'on' : '' ?>" href="<?= h($toggleUrl($p['slug'])) ?>"><?= h($p['name']) ?><?= $on ? ' <span class="x">✕</span>' : '' ?></a>
  <?php endforeach; ?>
</div>

<?php if ($cols === []): ?>
  <div class="wl-empty" data-empty style="display:block;margin-top:22px">
    <div class="wl-ico" aria-hidden="true"><svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 6h7v14H3zM14 4h7v16h-7z"/></svg></div>
    <h3><?= $L ? 'ऊपर से OTT चुनिए' : 'Pick OTTs above' ?></h3>
    <p class="dim"><?= $L ? 'दो या ज़्यादा चुनते ही उनके plan साथ-साथ दिख जाएँगे।' : 'Choose two or more to see their plans side by side.' ?></p>
  </div>
<?php else: ?>
  <div class="cmp-grid" style="grid-template-columns:repeat(<?= count($cols) ?>,minmax(0,1fr))">
    <?php foreach ($cols as $c): $p = $c['p']; $logo = tmdb_img($p['logo_path'], 'w92');
      $cheap = $c['plans'] !== [] ? min(array_map(fn ($x) => (int) $x['price_inr'], $c['plans'])) : null; ?>
    <div class="cmp-col">
      <div class="cmp-head">
        <?php if ($logo !== null): ?><img src="<?= h($logo) ?>" alt=""><?php else: ?><span class="cmp-lg"><?= h(mb_substr($p['name'], 0, 2, 'UTF-8')) ?></span><?php endif; ?>
        <a href="<?= h(provider_url($p)) ?>"><?= h($p['name']) ?></a>
      </div>
      <?php if ($cheap !== null): ?><div class="cmp-cheap"><?= $L ? '₹' . $cheap . ' से' : 'From ₹' . $cheap ?></div><?php endif; ?>

      <?php if ($c['plans'] !== []): ?>
      <div class="cmp-plans">
        <?php foreach ($c['plans'] as $pl): ?>
        <div class="cmp-plan">
          <div class="cmp-pn"><?= h($pl['name']) ?> <span class="cmp-pp">₹<?= (int) $pl['price_inr'] ?><span class="dim"><?= h($perLabel($pl['period'])) ?></span></span></div>
          <div class="cmp-pm">
            <?= nz($pl['max_quality'] ?? null) !== null ? h($pl['max_quality']) . ' · ' : '' ?><?= $pl['screens'] !== null ? (int) $pl['screens'] . ($L ? ' screen' : ' scr') . ' · ' : '' ?>
            <?php if ((int) $pl['tv_allowed'] === 1): ?><span class="pt-yes">TV ✓</span><?php else: ?><span class="pt-no">TV ✗</span><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="cmp-nodata"><?= $L ? 'plan डेटा जल्द जुड़ेगा' : 'plan data coming soon' ?></div>
      <?php endif; ?>

      <?php if ($c['bundles'] !== []): ?>
      <div class="cmp-bh"><?= $L ? 'मुफ़्त इनके साथ' : 'Free with' ?></div>
      <div class="cmp-bundles">
        <?php foreach ($c['bundles'] as $b): ?><span class="op-bundle"><?= h($b['operator']) ?> ₹<?= (int) $b['plan_price'] ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="dim small" style="margin-top:14px"><?= $L ? 'कीमतें बदल सकती हैं — देखने से पहले OTT ऐप में पक्का कर लें।' : 'Prices can change — confirm in the OTT app before you watch.' ?></p>
<?php endif; ?>

<?php page_footer(); ?>
