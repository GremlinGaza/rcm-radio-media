<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/social_sync.php';
require_once __DIR__ . '/../includes/news_sync.php';
require_once __DIR__ . '/../includes/email_notifications.php';
require_once __DIR__ . '/../includes/admin_workflow.php';
ensure_social_sync_schema($pdo);
ensure_news_sync_schema($pdo);
ensure_email_subscription_schema($pdo);
ensure_admin_workflow_schema($pdo);
echo "Admin workflow tables and scheduled program fields are ready.\n";
