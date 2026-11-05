<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/email_notifications.php';
$startedAt = date('Y-m-d H:i:s');
require_once __DIR__ . '/../includes/admin_workflow.php';
require_once __DIR__ . '/../includes/social_sync.php';
require_once __DIR__ . '/../includes/news_sync.php';
ensure_social_sync_schema($pdo);
ensure_news_sync_schema($pdo);
ensure_email_subscription_schema($pdo);
ensure_admin_workflow_schema($pdo);
if (getenv('RCM_PUBLIC_SITE_URL') === false || trim((string)getenv('RCM_PUBLIC_SITE_URL')) === '') {
  fwrite(STDERR, "Configure RCM_PUBLIC_SITE_URL before sending email digests.\n");
  workflow_record_job($pdo, 'email_digest', 'failed', 'Public HTTPS site URL is not configured.', $startedAt, date('Y-m-d H:i:s'));
  exit(1);
}
$result = send_email_notification_digests($pdo);
printf("Email digests: %d sent, %d with no new updates, %d failed.\n", $result['sent'], $result['skipped'], $result['failed']);
workflow_record_job($pdo, 'email_digest', $result['failed'] > 0 ? 'partial' : 'success', sprintf('%d sent, %d with no new updates, %d failed.', $result['sent'], $result['skipped'], $result['failed']), $startedAt, date('Y-m-d H:i:s'));
exit($result['failed'] > 0 ? 1 : 0);
