<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_workflow.php';
if (empty($_SESSION['admin_id'])) {
  header('Location: login.php');
  exit;
}
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$stmt = $pdo->prepare('SELECT file_name,mime_type FROM partner_content WHERE id=?');
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
