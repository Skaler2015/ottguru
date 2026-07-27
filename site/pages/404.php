<?php
/** 404 — पन्ना नहीं मिला */
declare(strict_types=1);

page_header(['title' => t('पन्ना नहीं मिला'), 'noindex' => true]);
?>
<h1><?= h(t('यह पन्ना नहीं मिला')) ?></h1>
<p class="dim"><?= h(t('हो सकता है लिंक पुराना हो या टाइप में चूक हुई हो।')) ?></p>
<p><a href="/"><?= h(t('← होमपेज पर चलिए')) ?></a></p>
<?php page_footer(); ?>
