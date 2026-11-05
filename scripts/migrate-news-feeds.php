<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/news_sync.php';
ensure_news_sync_schema($pdo);
echo "News feed tables are ready.\n";
