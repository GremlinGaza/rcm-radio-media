<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/admin_workflow.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
  http_response_code(404);
  exit;
}
$stmt = $pdo->prepare("SELECT c.file_name,c.mime_type FROM partner_content c INNER JOIN partners p ON p.id=c.partner_id WHERE c.id=? AND c.review_status='approved' AND c.is_published=1 AND p.is_published=1");
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file || !preg_match('/^[a-f0-9]{48}\.(?:jpg|png|webp|pdf)$/', $file['file_name'])) {
  http_response_code(404);
  exit;
}
$path = workflow_private_upload_dir() . DIRECTORY_SEPARATOR . $file['file_name'];
if (!is_file($path)) {
  http_response_code(404);
  exit;
}
header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="partner-content.' . ($file['mime_type'] === 'application/pdf' ? 'pdf' : 'image') . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox");
readfile($path);
