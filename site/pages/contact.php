<?php
/**
 * CONTACT — /contact  (फ़ॉर्म → email; honeypot spam-रोक)
 * बिना login/DB — भरते ही config के alert_email पर mail() जाता है।
 */
declare(strict_types=1);

$L    = OTT_LANG === 'hi';
$to   = trim((string) ($CFG['safety']['alert_email'] ?? ''));
$sent = false;
$err  = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // honeypot — bots अक्सर छुपा field भर देते हैं
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        $sent = true;   // spam — चुपचाप "भेज दिया" दिखाओ, कुछ मत भेजो
    } else {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80, 'UTF-8');
        $from = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 120, 'UTF-8');
        $msg  = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 4000, 'UTF-8');
        if ($name === '' || $msg === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $err = $L ? 'नाम, सही ईमेल और संदेश — तीनों भरिए।' : 'Please fill name, a valid email and a message.';
        } else {
            $body = "Name: $name\nEmail: $from\n\n$msg\n";
            $headers = 'From: OTTGuru <noreply@ottguru.in>' . "\r\n"
                     . 'Reply-To: ' . $from . "\r\n"
                     . 'Content-Type: text/plain; charset=utf-8';
            if ($to !== '') {
                @mail($to, 'OTTGuru संपर्क — ' . $name, $body, $headers);
            }
            $sent = true;
        }
    }
}

$crumbs = [
    ['name' => $L ? 'होम' : 'Home', 'url' => '/'],
    ['name' => $L ? 'संपर्क' : 'Contact', 'url' => '/contact'],
];
page_header([
    'title'      => $L ? 'संपर्क करें' : 'Contact',
    'description'=> $L ? 'OTT गुरु से संपर्क — सुझाव, सुधार या गलती बताइए।' : 'Contact OTT Guru — suggestions, corrections or feedback.',
    'canonical'  => '/contact',
    'noindex'    => true,
    'breadcrumb' => $crumbs,
]);
crumbs($crumbs);
?>
<article class="prose" style="max-width:600px">
  <h1><?= $L ? 'संपर्क करें' : 'Contact us' ?></h1>
  <p class="dim"><?= $L ? 'कोई सुझाव, गलती या फ़ीचर की माँग? हमें लिखिए — हम पढ़ते हैं।' : 'A suggestion, a correction, or a feature request? Write to us — we read everything.' ?></p>

  <?php if ($sent): ?>
    <div class="okline" style="margin-top:16px">✓ <?= $L ? 'धन्यवाद! आपका संदेश भेज दिया गया।' : 'Thank you! Your message has been sent.' ?></div>
  <?php else: ?>
    <?php if ($err !== ''): ?><div class="offer-none" style="margin:14px 0"><?= h($err) ?></div><?php endif; ?>
    <form method="post" action="/contact" class="cform">
      <label><?= $L ? 'आपका नाम' : 'Your name' ?>
        <input name="name" value="<?= h((string) ($_POST['name'] ?? '')) ?>" required maxlength="80"></label>
      <label><?= $L ? 'आपका ईमेल' : 'Your email' ?>
        <input name="email" type="email" value="<?= h((string) ($_POST['email'] ?? '')) ?>" required maxlength="120"></label>
      <label><?= $L ? 'संदेश' : 'Message' ?>
        <textarea name="message" rows="5" required maxlength="4000"><?= h((string) ($_POST['message'] ?? '')) ?></textarea></label>
      <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
      <button type="submit" class="btn-grad"><?= $L ? 'भेजें' : 'Send' ?></button>
    </form>
  <?php endif; ?>
</article>
<?php page_footer(); ?>
