<?php
declare(strict_types=1);

function ensure_email_subscription_schema(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS email_subscribers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL UNIQUE,
    wants_programs TINYINT(1) NOT NULL DEFAULT 1,
    wants_news TINYINT(1) NOT NULL DEFAULT 1,
    pending_wants_programs TINYINT(1) DEFAULT NULL,
    pending_wants_news TINYINT(1) DEFAULT NULL,
    confirmation_token_hash CHAR(64) DEFAULT NULL,
    confirmed_at DATETIME DEFAULT NULL,
    unsubscribed_at DATETIME DEFAULT NULL,
    last_digest_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email_subscribers_delivery (confirmed_at,unsubscribed_at,last_digest_at)
  ) ENGINE=InnoDB");
}

function email_notification_env(string $key): string {
  $value = getenv($key);
  if ($value !== false && trim($value) !== '') return trim($value);
  $configKey = strtolower(substr($key, 4));
  return trim((string)($GLOBALS['config'][$configKey] ?? ''));
}

function email_notification_site_url(): string {
  $base = rtrim(email_notification_env('RCM_PUBLIC_SITE_URL'), '/');
  if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL) || strtolower((string)parse_url($base, PHP_URL_SCHEME)) !== 'https') {
    throw new RuntimeException('Set RCM_PUBLIC_SITE_URL to the public HTTPS address of the RCM website before enabling email.');
  }
  return $base;
}

function email_notification_token(): string {
  return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function email_notification_token_hash(string $token): string {
  return hash('sha256', $token);
}

function email_notification_manage_token(int $subscriberId, string $email): string {
  $secret = email_notification_env('RCM_EMAIL_TOKEN_SECRET');
  if (strlen($secret) < 32) throw new RuntimeException('Set RCM_EMAIL_TOKEN_SECRET to a private random value of at least 32 characters.');
  $signature = hash_hmac('sha256', $subscriberId . ':' . strtolower($email), $secret, true);
  return $subscriberId . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
}

function email_notification_manage_subscriber(PDO $pdo, string $token): ?array {
  if (!preg_match('/^(\d{1,20})\.([A-Za-z0-9_-]{43})$/', $token, $match)) return null;
  $stmt = $pdo->prepare('SELECT id,email FROM email_subscribers WHERE id=?');
  $stmt->execute([$match[1]]);
  $subscriber = $stmt->fetch();
  if (!$subscriber) return null;
  try {
    $expected = email_notification_manage_token((int)$subscriber['id'], $subscriber['email']);
  } catch (RuntimeException $exception) {
    return null;
  }
  return hash_equals($expected, $token) ? $subscriber : null;
}

function email_notification_send(string $recipient, string $subject, string $htmlBody, string $textBody): void {
  if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required for Gmail SMTP delivery.');
  $gmailAddress = email_notification_env('RCM_GMAIL_ADDRESS');
  $appPassword = preg_replace('/\s+/', '', email_notification_env('RCM_GMAIL_APP_PASSWORD'));
  if (!filter_var($gmailAddress, FILTER_VALIDATE_EMAIL) || $appPassword === '') {
    throw new RuntimeException('Configure RCM_GMAIL_ADDRESS and a Google App Password in the private server environment.');
  }
  if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The notification recipient email is invalid.');

  $boundary = 'rcm_' . bin2hex(random_bytes(12));
  $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  $body = "MIME-Version: 1.0\r\n";
  $body .= 'From: RCM Radio Media <' . $gmailAddress . ">\r\n";
  $body .= 'To: <' . $recipient . ">\r\n";
  $body .= 'Subject: ' . $encodedSubject . "\r\n";
  $body .= "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"\r\n\r\n";
  $body .= '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
  $body .= chunk_split(base64_encode($textBody)) . "\r\n";
  $body .= '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
  $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
  $body .= '--' . $boundary . "--\r\n";
  $body = preg_replace('/(?m)^\./', '..', str_replace(["\r\n", "\r", "\n"], "\r\n", $body));
  $mailData = fopen('php://temp', 'r+');
  if (!$mailData) throw new RuntimeException('Could not prepare the notification email.');
  fwrite($mailData, $body);
  rewind($mailData);

  $curl = curl_init('smtp://smtp.gmail.com:587');
  curl_setopt_array($curl, [
    CURLOPT_UPLOAD => true,
    CURLOPT_INFILE => $mailData,
    CURLOPT_INFILESIZE => strlen($body),
    CURLOPT_MAIL_FROM => '<' . $gmailAddress . '>',
    CURLOPT_MAIL_RCPT => ['<' . $recipient . '>'],
    CURLOPT_USERNAME => $gmailAddress,
    CURLOPT_PASSWORD => $appPassword,
    CURLOPT_USE_SSL => CURLUSESSL_ALL,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_PROTOCOLS => CURLPROTO_SMTP,
    CURLOPT_VERBOSE => false,
  ]);
  $result = curl_exec($curl);
  $errorNumber = curl_errno($curl);
  curl_close($curl);
  fclose($mailData);
  if ($result === false || $errorNumber !== 0) {
    throw new RuntimeException('Gmail could not send the notification. Check the app password, account security settings and server network access.');
  }
}

function email_notification_digest_content(PDO $pdo, array $subscriber, ?string $cutoff = null): array {
  if ($cutoff === null) $cutoff = (string)$pdo->query('SELECT NOW()')->fetchColumn();
  $lastSent = $subscriber['last_digest_at'] ?: $cutoff;
  $stories = [];
  $programs = [];
  if ((int)$subscriber['wants_news'] === 1) {
    $hasPublicSince = function_exists('db_has_column') && db_has_column($pdo, 'news', 'public_since');
    $publicationTime = $hasPublicSince ? 'COALESCE(public_since,created_at)' : 'created_at';
    $stmt = $pdo->prepare('SELECT id,title,summary,category,source_name,published_at FROM news WHERE is_published=1 AND ' . $publicationTime . ' > ? AND ' . $publicationTime . ' <= ? ORDER BY published_at DESC,id DESC LIMIT 20');
    $stmt->execute([$lastSent,$cutoff]);
    $stories = $stmt->fetchAll();
  }
  if ((int)$subscriber['wants_programs'] === 1) {
    $hasSchedule = function_exists('db_has_column') && db_has_column($pdo, 'programs', 'publish_at') && db_has_column($pdo, 'programs', 'unpublish_at');
    $scheduleFilter = $hasSchedule ? ' AND (publish_at IS NULL OR publish_at <= ?) AND (unpublish_at IS NULL OR unpublish_at > ?)' : '';
    $hasPublicSince = function_exists('db_has_column') && db_has_column($pdo, 'programs', 'public_since');
    $publicationTime = $hasPublicSince ? ($hasSchedule ? 'COALESCE(public_since,publish_at,created_at)' : 'COALESCE(public_since,created_at)') : 'created_at';
    $stmt = $pdo->prepare('SELECT id,title,description,category,schedule,created_at FROM programs WHERE is_published=1' . $scheduleFilter . ' AND ' . $publicationTime . ' > ? AND ' . $publicationTime . ' <= ? ORDER BY created_at DESC,id DESC LIMIT 20');
    $params = $hasSchedule ? [$cutoff,$cutoff,$lastSent,$cutoff] : [$lastSent,$cutoff];
    $stmt->execute($params);
    $programs = $stmt->fetchAll();
  }
  return ['stories' => $stories, 'programs' => $programs];
}

function email_notification_build_digest(array $subscriber, array $content): array {
  $site = email_notification_site_url();
  $unsubscribeToken = email_notification_manage_token((int)$subscriber['id'], $subscriber['email']);
  $manageUrl = $site . '/notifications.php?unsubscribe=' . rawurlencode($unsubscribeToken);
  $htmlItems = [];
  $textItems = [];
  foreach ($content['programs'] as $program) {
    $url = $site . '/programs.php#program-' . (int)$program['id'];
    $description = trim((string)$program['description']);
    $details = trim((string)$program['schedule']);
    $htmlItems[] = '<li><strong>' . e($program['title']) . '</strong>' . ($details !== '' ? ' · ' . e($details) : '') . ($description !== '' ? '<br>' . e($description) : '') . '<br><a href="' . e($url) . '">Explore this RCM show</a></li>';
    $textItems[] = $program['title'] . ($details !== '' ? ' · ' . $details : '') . "\n" . $url;
  }
  foreach ($content['stories'] as $story) {
    $url = $site . '/news.php#news-' . (int)$story['id'];
    $summary = trim((string)$story['summary']);
    $source = trim((string)$story['source_name']);
    $htmlItems[] = '<li><strong>' . e($story['title']) . '</strong>' . ($source !== '' ? ' · Source: ' . e($source) : '') . ($summary !== '' ? '<br>' . e($summary) : '') . '<br><a href="' . e($url) . '">Read this story on RCM</a></li>';
    $textItems[] = $story['title'] . ($source !== '' ? ' · Source: ' . $source : '') . "\n" . $url;
  }
  $count = count($content['programs']) + count($content['stories']);
  $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#14243a;line-height:1.6"><h1 style="color:#125ecb">Your RCM update</h1><p>Here are the latest RCM programme announcements and headlines you selected.</p><ul>' . implode('', $htmlItems) . '</ul><p><a href="' . e($site) . '">Visit RCM Radio Media</a></p><hr><p style="font-size:12px;color:#637188">You receive this email because you subscribed to RCM updates. <a href="' . e($manageUrl) . '">Unsubscribe</a></p></body></html>';
  $text = "Your RCM update\n\n" . implode("\n\n", $textItems) . "\n\nVisit RCM Radio Media: " . $site . "\n\nUnsubscribe: " . $manageUrl;
  return ['count' => $count, 'html' => $html, 'text' => $text];
}

function send_email_notification_digests(PDO $pdo): array {
  ensure_email_subscription_schema($pdo);
  $subscribers = $pdo->query('SELECT id,email,wants_programs,wants_news,last_digest_at FROM email_subscribers WHERE confirmed_at IS NOT NULL AND unsubscribed_at IS NULL AND (wants_programs=1 OR wants_news=1) ORDER BY id')->fetchAll();
  $sent = 0;
  $skipped = 0;
  $failed = 0;
  foreach ($subscribers as $subscriber) {
    try {
      $cutoff = (string)$pdo->query('SELECT NOW()')->fetchColumn();
      $content = email_notification_digest_content($pdo, $subscriber, $cutoff);
      if (count($content['programs']) + count($content['stories']) === 0) {
        $skipped++;
        continue;
      }
      $digest = email_notification_build_digest($subscriber, $content);
      $subject = 'RCM Radio Media — your latest updates';
      email_notification_send($subscriber['email'], $subject, $digest['html'], $digest['text']);
      $update = $pdo->prepare('UPDATE email_subscribers SET last_digest_at=? WHERE id=?');
      $update->execute([$cutoff,$subscriber['id']]);
      $sent++;
    } catch (Throwable $exception) {
      error_log('RCM email digest delivery failed for subscriber ' . (int)$subscriber['id'] . ': ' . $exception->getMessage());
      $failed++;
    }
  }
  return ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed];
}
