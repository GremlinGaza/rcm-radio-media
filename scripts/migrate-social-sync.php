<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/social_sync.php';
ensure_social_sync_schema($pdo);
echo "Social sync tables are ready.\n";
