<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/email_notifications.php';
ensure_email_subscription_schema($pdo);
$pageTitle = 'Email updates — RCM Radio Media';
$message = '';
$error = '';
$confirmToken = trim($_GET['confirm'] ?? '');
$unsubscribeToken = trim($_GET['unsubscribe'] ?? '');
$confirmHash = $confirmToken !== '' ? email_notification_token_hash($confirmToken) : '';
$pendingConfirmation = null;
if ($confirmHash !== '') {
  $stmt = $pdo->prepare('SELECT id FROM email_subscribers WHERE confirmation_token_hash=?');
  $stmt->execute([$confirmHash]);
  $pendingConfirmation = $stmt->fetch();
  if ($pendingConfirmation) {
    $confirm = $pdo->prepare('UPDATE email_subscribers SET wants_programs=COALESCE(pending_wants_programs,wants_programs),wants_news=COALESCE(pending_wants_news,wants_news),pending_wants_programs=NULL,pending_wants_news=NULL,confirmation_token_hash=NULL,confirmed_at=COALESCE(confirmed_at,NOW()),unsubscribed_at=NULL,last_digest_at=COALESCE(last_digest_at,NOW()) WHERE id=?');
    $confirm->execute([$pendingConfirmation['id']]);
    $message = 'Your email is confirmed. You’ll receive your selected RCM updates as they are published.';
  } else {
    $error = 'That confirmation link has expired or has already been used. Submit the form again for a new link.';
  }
}
$manageSubscriber = $unsubscribeToken !== '' ? email_notification_manage_subscriber($pdo, $unsubscribeToken) : null;
if ($unsubscribeToken !== '' && !$manageSubscriber && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  $error = 'That unsubscribe link is invalid or expired. Use the link from the latest RCM email.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $postedAction = trim($_POST['action'] ?? '');
  if ($postedAction === 'subscribe') {
    if (trim($_POST['website'] ?? '') !== '') {
      $message = 'If the address can receive RCM updates, a confirmation email will be sent.';
    } else {
      $email = trim($_POST['email'] ?? '');
      $wantsPrograms = isset($_POST['wants_programs']);
      $wantsNews = isset($_POST['wants_news']);
      if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $error = 'Enter a valid email address.';
      } elseif (!$wantsPrograms && !$wantsNews) {
        $error = 'Choose program announcements, news headlines, or both.';
      } else {
        try {
          email_notification_site_url();
          $tokenSecret = email_notification_env('RCM_EMAIL_TOKEN_SECRET');
          if (strlen($tokenSecret) < 32) throw new RuntimeException('RCM_EMAIL_TOKEN_SECRET is not configured.');
          $token = email_notification_token();
          $hash = email_notification_token_hash($token);
          $stmt = $pdo->prepare('INSERT INTO email_subscribers (email,wants_programs,wants_news,pending_wants_programs,pending_wants_news,confirmation_token_hash) VALUES (?,0,0,?,?,?) ON DUPLICATE KEY UPDATE pending_wants_programs=VALUES(pending_wants_programs),pending_wants_news=VALUES(pending_wants_news),confirmation_token_hash=VALUES(confirmation_token_hash)');
          $stmt->execute([$email,$wantsPrograms ? 1 : 0,$wantsNews ? 1 : 0,$hash]);
          $confirmationUrl = email_notification_site_url() . '/notifications.php?confirm=' . rawurlencode($token);
          $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#14243a;line-height:1.6"><h1 style="color:#125ecb">Confirm your RCM updates</h1><p>Confirm your email to receive the program and news updates you selected.</p><p><a href="' . e($confirmationUrl) . '" style="display:inline-block;padding:12px 18px;border-radius:8px;background:#125ecb;color:#fff;text-decoration:none">Confirm my email</a></p><p>If you did not request these emails, ignore this message.</p></body></html>';
          $text = "Confirm your RCM updates\n\nOpen this link to confirm your email:\n" . $confirmationUrl . "\n\nIf you did not request these emails, ignore this message.";
          email_notification_send($email, 'Confirm your RCM Radio Media email updates', $html, $text);
          $message = 'Check your inbox for a confirmation link. Your subscription starts only after you confirm.';
        } catch (Throwable $exception) {
          error_log('RCM subscription confirmation email failed: ' . $exception->getMessage());
          $error = 'We could not send the confirmation email. The request is not active yet. Please check the Gmail delivery setup or try again later.';
        }
      }
    }
  } elseif ($postedAction === 'unsubscribe') {
    $token = trim($_POST['unsubscribe_token'] ?? '');
    $subscriber = email_notification_manage_subscriber($pdo, $token);
    if (!$subscriber) {
      $error = 'That unsubscribe link is invalid. Use the latest RCM email and try again.';
    } else {
      $stmt = $pdo->prepare('UPDATE email_subscribers SET unsubscribed_at=NOW(),pending_wants_programs=NULL,pending_wants_news=NULL,confirmation_token_hash=NULL WHERE id=?');
      $stmt->execute([$subscriber['id']]);
      $manageSubscriber = null;
      $unsubscribeToken = '';
      $message = 'You are unsubscribed. No more RCM notification emails will be sent to this address.';
    }
  }
}
require __DIR__ . '/includes/header.php';
?>
<main class="section-wrap page-content notifications-page">
  <p class="eyebrow">YOUR RCM INBOX</p>
  <h1 class="page-title">Good updates.<br><span>Only what you choose.</span></h1>
  <p class="page-intro">Get a daily Gmail email with links to new RCM programs and/or the latest Kenya and world headlines. Emails link back to the RCM website so you can explore the full details.</p>
  <?php if ($message): ?><p class="notice success" role="status"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif; ?>

  <?php if ($unsubscribeToken !== '' && $manageSubscriber): ?>
    <section class="notification-card" aria-labelledby="unsubscribe-title">
      <p class="eyebrow">EMAIL PREFERENCES</p>
      <h2 id="unsubscribe-title">Unsubscribe from RCM emails?</h2>
      <p>Confirm below to stop all program and news notification emails. This will not affect your access to the site or social channels.</p>
      <form class="contact-form compact" method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="unsubscribe">
        <input type="hidden" name="unsubscribe_token" value="<?= e($unsubscribeToken) ?>">
        <button class="btn btn-primary" type="submit">Yes, unsubscribe me</button>
      </form>
    </section>
  <?php elseif (!$pendingConfirmation && $unsubscribeToken === ''): ?>
    <section class="notification-card" aria-labelledby="subscribe-title">
      <p class="eyebrow">FREE DAILY ROUND-UP</p>
      <h2 id="subscribe-title">Choose your RCM updates.</h2>
      <form class="contact-form compact" method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="subscribe">
        <div class="notification-honeypot" aria-hidden="true"><label>Leave this field empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <label>Email address<input type="email" name="email" required maxlength="254" autocomplete="email" placeholder="you@example.com"></label>
        <label class="check-label"><input type="checkbox" name="wants_programs" checked> New program and podcast announcements</label>
        <label class="check-label"><input type="checkbox" name="wants_news" checked> Daily Kenya and world headline links</label>
        <p class="notification-privacy">We’ll send a confirmation email before subscribing you. Your address is used only for the updates you select. Unsubscribe from any digest at any time.</p>
        <button class="btn btn-primary" type="submit">Email me RCM updates <span aria-hidden="true">↗</span></button>
      </form>
    </section>
  <?php endif; ?>
  <p class="source-note">News headlines are brief, credited and linked to their original publishers. RCM does not send emails until you confirm your address.</p>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
