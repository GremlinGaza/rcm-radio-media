<?php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require_once __DIR__ . '/../includes/bootstrap.php';

$username = isset($argv[1]) ? trim($argv[1]) : '';
$password = getenv('RCM_ADMIN_PASSWORD');
if ($username === '' || strlen($username) > 100) {
  fwrite(STDERR, "Usage: php scripts/create-admin.php \"Admin name\" (set RCM_ADMIN_PASSWORD privately first)\n");
  exit(2);
}
if (!is_string($password) || strlen($password) < 12) {
  fwrite(STDERR, "Set RCM_ADMIN_PASSWORD to a private password of at least 12 characters, then retry.\n");
  exit(2);
}

$check = $pdo->prepare('SELECT id FROM admins WHERE username=?');
$check->execute([$username]);
if ($check->fetch()) {
  fwrite(STDERR, "That username already exists; no account was changed.\n");
  exit(1);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if ($passwordHash === false) {
  throw new RuntimeException('Could not generate an admin password hash.');
}
$insert = $pdo->prepare('INSERT INTO admins (username,password_hash) VALUES (?,?)');
$insert->execute([$username,$passwordHash]);
fwrite(STDOUT, "Admin account created. Clear RCM_ADMIN_PASSWORD from the shell environment.\n");
