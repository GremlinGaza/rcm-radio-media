<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/email_notifications.php';
ensure_email_subscription_schema($pdo);
echo "Email subscription table is ready.\n";
