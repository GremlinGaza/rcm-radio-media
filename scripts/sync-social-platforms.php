<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/social_sync.php';
require_once __DIR__ . '/../includes/news_sync.php';
require_once __DIR__ . '/../includes/email_notifications.php';
$startedAt = date('Y-m-d H:i:s');
$jobStartedAt = $startedAt;
require_once __DIR__ . '/../includes/admin_workflow.php';
ensure_social_sync_schema($pdo);
ensure_news_sync_schema($pdo);
ensure_email_subscription_schema($pdo);
ensure_admin_workflow_schema($pdo);
$results = sync_social_platforms($pdo);
$failed = false;
foreach ($results as $platform => $result) {
  printf("%s: %s (%d items) — %s\n", $platform, $result['status'], $result['count'], $result['message']);
  if ($result['status'] !== 'connected') $failed = true;
}
workflow_record_job($pdo, 'social_sync', $failed ? 'partial' : 'success', 'Daily social collection complete; new or changed items await review.', $jobStartedAt, date('Y-m-d H:i:s'));
$allFailed = $failed;
$failed = false;
$jobStartedAt = date('Y-m-d H:i:s');
$newsResults = sync_news_feeds($pdo);
foreach ($newsResults as $result) {
  printf("News — %s (%s): %s (%d headlines) — %s\n", $result['name'], $result['region'], $result['status'], $result['count'], $result['message']);
  if ($result['status'] !== 'connected') $failed = true;
}
workflow_record_job($pdo, 'news_sync', $failed ? 'partial' : 'success', 'Daily RSS collection complete; new or changed headlines await review.', $jobStartedAt, date('Y-m-d H:i:s'));
$allFailed = $allFailed || $failed;
$failed = false;
$jobStartedAt = date('Y-m-d H:i:s');
$digestResult = send_email_notification_digests($pdo);
printf("Email digests: %d sent, %d with no new updates, %d failed.\n", $digestResult['sent'], $digestResult['skipped'], $digestResult['failed']);
workflow_record_job($pdo, 'email_digest', $digestResult['failed'] > 0 ? 'partial' : 'success', sprintf('%d sent, %d with no new updates, %d failed.', $digestResult['sent'], $digestResult['skipped'], $digestResult['failed']), $jobStartedAt, date('Y-m-d H:i:s'));
if ($digestResult['failed'] > 0) $failed = true;
$allFailed = $allFailed || $failed;
exit($allFailed ? 1 : 0);
