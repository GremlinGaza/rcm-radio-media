<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
  session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off',
    'samesite' => 'Lax',
  ]);
  session_start();
}
$configuredPath = getenv('RCM_CONFIG_FILE');
$configFile = $configuredPath !== false && trim($configuredPath) !== ''
  ? trim($configuredPath)
  : __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
  http_response_code(500);
  exit('Setup required: create a private config.php file with the database credentials, then import database.sql.');
}
$config = require $configFile;
if (!is_array($config) || !isset($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass'])) {
  http_response_code(500);
  exit('The private database configuration is incomplete.');
}
try {
  $pdo = new PDO(
    'mysql:host=' . $config['db_host'] . ';port=' . (int)($config['db_port'] ?? 3306) . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
    $config['db_user'], $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
  );
} catch (PDOException $ex) {
  http_response_code(500);
  exit('Database connection failed. Check the private database configuration and confirm the database has been imported.');
}
function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function db_has_column(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = $table . '.' . $column;
  if (!array_key_exists($key, $cache)) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table,$column]);
    $cache[$key] = (int)$stmt->fetchColumn() > 0;
  }
  return $cache[$key];
}
function site_url(string $path = ''): string {
  $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
  $base = str_replace('\\', '/', dirname($script));
  if ($base !== '/' && preg_match('#/(admin|api)$#', $base)) {
    $base = preg_replace('#/(admin|api)$#', '', $base);
  }
  $base = rtrim($base, '/');
  $path = '/' . ltrim($path, '/');
  return $base === '' || $base === '/' ? $path : $base . $path;
}
function asset_url(string $path): string {
  return site_url(ltrim($path, '/'));
}
function program_image_url(string $path): string {
  if ($path === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $path)) return $path;
  return asset_url($path);
}
function get_settings(PDO $pdo): array {
  try { $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(); $out=[]; foreach($rows as $r) $out[$r['setting_key']]=$r['setting_value']; return $out; } catch(Throwable $t) { return []; }
}
function get_programs(PDO $pdo, bool $featuredOnly = false): array {
  $sql = 'SELECT * FROM programs WHERE is_published = 1';
  if (db_has_column($pdo, 'programs', 'publish_at') && db_has_column($pdo, 'programs', 'unpublish_at')) {
    $sql .= ' AND (publish_at IS NULL OR publish_at <= NOW()) AND (unpublish_at IS NULL OR unpublish_at > NOW())';
  }
  $sql .= ($featuredOnly ? ' AND is_featured = 1' : '') . ' ORDER BY sort_order, created_at DESC';
  return $pdo->query($sql)->fetchAll();
}
function get_partners(PDO $pdo): array { return $pdo->query('SELECT * FROM partners WHERE is_published=1 ORDER BY sort_order, name')->fetchAll(); }
function get_news(PDO $pdo, int $limit = 30): array { $stmt=$pdo->prepare('SELECT * FROM news WHERE is_published=1 ORDER BY published_at DESC, id DESC LIMIT :lim'); $stmt->bindValue(':lim',$limit,PDO::PARAM_INT); $stmt->execute(); return $stmt->fetchAll(); }
function get_socials(PDO $pdo): array { return $pdo->query('SELECT * FROM socials WHERE is_published=1 ORDER BY sort_order')->fetchAll(); }
function get_social_updates(PDO $pdo, ?int $limit = 6): array {
  if ($limit === null) {
    return $pdo->query('SELECT id, title, summary, platform, content_type, content_url, published_at FROM social_updates WHERE is_published=1 ORDER BY published_at DESC, id DESC')->fetchAll();
  }
  $stmt = $pdo->prepare('SELECT id, title, summary, platform, content_type, content_url, published_at FROM social_updates WHERE is_published=1 ORDER BY published_at DESC, id DESC LIMIT :lim');
  $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll();
}
function csrf_token(): string { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verify_csrf(): void { if(!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); exit('Invalid security token. Reload the page and try again.'); } }
