<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/news_sync.php';
require_once __DIR__ . '/../includes/social_sync.php';
require_once __DIR__ . '/../includes/admin_workflow.php';
ensure_social_sync_schema($pdo);
ensure_news_sync_schema($pdo);
ensure_admin_workflow_schema($pdo);
$startedAt = date('Y-m-d H:i:s');
$results = sync_news_feeds($pdo);
$failed = false;
foreach ($results as $result) {
  printf("%s (%s): %s (%d headlines) — %s\n", $result['name'], $result['region'], $result['status'], $result['count'], $result['message']);
  if ($result['status'] !== 'connected') $failed = true;
}
workflow_record_job($pdo, 'news_sync', $failed ? 'partial' : 'success', 'News headlines collected; new or changed items await review.', $startedAt, date('Y-m-d H:i:s'));
exit($failed ? 1 : 0);
