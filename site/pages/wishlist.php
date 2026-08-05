<?php
/**
 * WISHLIST — /wishlist  (client-side, localStorage से भरता है)
 * server के पास कुछ नहीं होता — यूज़र के इसी ब्राउज़र में सहेजा list।
 * इसलिए noindex; JS ख़ाली shell भरता है, न हो तो empty-state दिखता है।
 */
declare(strict_types=1);

$L = OTT_LANG === 'hi';

page_header([
    'title'   => $L ? 'मेरी वॉचलिस्ट' : 'My Wishlist',
    'noindex' => true,
]);
?>

<h1 style="margin-bottom:6px"><?= $L ? 'मेरी वॉचलिस्ट' : 'My Wishlist' ?></h1>
<p class="dim" style="margin:0 0 20px">
  <?= $L ? 'आपके सहेजे titles — इसी ब्राउज़र में रखे जाते हैं (कोई login नहीं)।'
         : 'Titles you saved — kept in this browser (no login needed).' ?>
</p>

<div data-wish-list>
  <div class="grid" data-grid></div>
  <?php /* JS list भर दे तो इसे छुपा देता है; ख़ाली/no-JS पर यही दिखता है */ ?>
  <div class="wl-empty" data-empty>
    <div class="wl-ico" aria-hidden="true">
      <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
    </div>
    <h3><?= $L ? 'अभी वॉचलिस्ट ख़ाली है' : 'Your wishlist is empty' ?></h3>
    <p class="dim"><?= $L ? 'किसी फिल्म या सीरीज़ के पेज पर “वॉचलिस्ट में जोड़ें” दबाइए — यहाँ दिख जाएगी।'
                         : 'Open any movie or series and tap “Add to Wishlist” — it’ll show up here.' ?></p>
    <a class="btn-grad" href="/browse" style="margin-top:14px"><?= $L ? 'ब्राउज़ करें' : 'Browse titles' ?></a>
  </div>
</div>

<?php page_footer(); ?>
