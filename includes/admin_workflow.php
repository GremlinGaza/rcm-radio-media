<?php
declare(strict_types=1);

function ensure_admin_workflow_schema(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS workflow_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor VARCHAR(100) NOT NULL,
    action_name VARCHAR(80) NOT NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_id VARCHAR(80) NOT NULL DEFAULT '',
    detail VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_workflow_audit_created (created_at)
  ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS admin_message_status (
    contact_message_id BIGINT UNSIGNED PRIMARY KEY,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS partner_content (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    review_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_partner_content_review (review_status,is_published,created_at),
    INDEX idx_partner_content_partner (partner_id)
  ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS workflow_job_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL,
    summary VARCHAR(255) NOT NULL DEFAULT '',
    started_at DATETIME NOT NULL,
    finished_at DATETIME DEFAULT NULL,
    INDEX idx_workflow_job_last_run (job_name,started_at)
  ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS workflow_credential_monitor (
    credential_name VARCHAR(100) PRIMARY KEY,
    fingerprint CHAR(64) DEFAULT NULL,
    is_configured TINYINT(1) NOT NULL DEFAULT 0,
    last_changed_at DATETIME DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS workflow_migrations (
    migration_key VARCHAR(100) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS workflow_rejections (
    entity_type VARCHAR(20) NOT NULL,
    source_key VARCHAR(40) NOT NULL,
    external_id VARCHAR(191) NOT NULL,
    rejected_by VARCHAR(100) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (entity_type,source_key,external_id)
  ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS listener_comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(100) NOT NULL,
    comment_text TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_listener_comments_review (status,created_at)
  ) ENGINE=InnoDB");

  $programColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='programs'")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('publish_at', $programColumns, true)) $pdo->exec('ALTER TABLE programs ADD COLUMN publish_at DATETIME DEFAULT NULL');
  if (!in_array('unpublish_at', $programColumns, true)) $pdo->exec('ALTER TABLE programs ADD COLUMN unpublish_at DATETIME DEFAULT NULL');
  if (!in_array('public_since', $programColumns, true)) $pdo->exec('ALTER TABLE programs ADD COLUMN public_since DATETIME DEFAULT NULL');
  $newsColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='news'")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('public_since', $newsColumns, true)) $pdo->exec('ALTER TABLE news ADD COLUMN public_since DATETIME DEFAULT NULL');
  $pdo->exec('UPDATE programs SET public_since=COALESCE(publish_at,created_at) WHERE is_published=1 AND public_since IS NULL');
  $pdo->exec('UPDATE news SET public_since=created_at WHERE is_published=1 AND public_since IS NULL');

  $migrationKey = 'hold_existing_synced_items_for_review_v1';
  $migration = $pdo->prepare('SELECT migration_key FROM workflow_migrations WHERE migration_key=?');
  $migration->execute([$migrationKey]);
  if (!$migration->fetchColumn()) {
    $pdo->beginTransaction();
    try {
      $pdo->exec('UPDATE social_updates SET is_published=0 WHERE id IN (SELECT social_update_id FROM social_sync_items)');
      $pdo->exec('UPDATE news SET is_published=0 WHERE id IN (SELECT news_id FROM news_feed_items)');
      $pdo->prepare('INSERT INTO workflow_migrations (migration_key) VALUES (?)')->execute([$migrationKey]);
      $pdo->commit();
    } catch (Throwable $exception) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $exception;
    }
  }
}

function workflow_log(PDO $pdo, string $action, string $entity, string $id = '', string $detail = ''): void {
  $stmt = $pdo->prepare('INSERT INTO workflow_audit_log (actor,action_name,entity_type,entity_id,detail) VALUES (?,?,?,?,?)');
  $stmt->execute([
    substr((string)($_SESSION['admin_username'] ?? 'system'), 0, 100),
    substr($action, 0, 80),
    substr($entity, 0, 40),
    substr($id, 0, 80),
    substr($detail, 0, 255),
  ]);
}

function workflow_record_job(PDO $pdo, string $name, string $status, string $summary, string $startedAt, ?string $finishedAt = null): void {
  $stmt = $pdo->prepare('INSERT INTO workflow_job_runs (job_name,status,summary,started_at,finished_at) VALUES (?,?,?,?,?)');
  $stmt->execute([substr($name, 0, 80), substr($status, 0, 20), substr($summary, 0, 255), $startedAt, $finishedAt]);
}

function workflow_reject_synced_item(PDO $pdo, string $type, int $id): void {
  if ($type === 'social') {
    $stmt = $pdo->prepare('SELECT platform AS source_key,external_id FROM social_sync_items WHERE social_update_id=?');
  } elseif ($type === 'news') {
    $stmt = $pdo->prepare('SELECT source_key,external_id FROM news_feed_items WHERE news_id=?');
  } else {
    return;
  }
  $stmt->execute([$id]);
  $reject = $pdo->prepare('INSERT IGNORE INTO workflow_rejections (entity_type,source_key,external_id,rejected_by) VALUES (?,?,?,?)');
  foreach ($stmt->fetchAll() as $item) {
    $reject->execute([$type,$item['source_key'],$item['external_id'],substr((string)($_SESSION['admin_username'] ?? 'system'),0,100)]);
  }
}

function workflow_track_credentials(PDO $pdo): array {
  $labels = [
    'RCM_FACEBOOK_PAGE_ACCESS_TOKEN' => 'Facebook Page token',
    'RCM_TIKTOK_CLIENT_KEY' => 'TikTok app key',
    'RCM_TIKTOK_CLIENT_SECRET' => 'TikTok app secret',
    'RCM_TIKTOK_ACCESS_TOKEN' => 'TikTok access token',
    'RCM_TIKTOK_REFRESH_TOKEN' => 'TikTok refresh token',
    'RCM_SOCIAL_CREDENTIAL_KEY' => 'Social token encryption key',
    'RCM_GMAIL_ADDRESS' => 'Gmail sender address',
    'RCM_GMAIL_APP_PASSWORD' => 'Gmail app password',
    'RCM_EMAIL_TOKEN_SECRET' => 'Email link-signing key',
    'RCM_PUBLIC_SITE_URL' => 'Public site URL',
  ];
  $monitorKey = getenv('RCM_MONITOR_FINGERPRINT_KEY');
  $monitorKey = $monitorKey === false ? '' : trim($monitorKey);
  if (strlen($monitorKey) < 32) $monitorKey = '';
  $upsert = $pdo->prepare("INSERT INTO workflow_credential_monitor (credential_name,fingerprint,is_configured,last_changed_at)
    VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE
    last_changed_at=IF(NOT (fingerprint <=> VALUES(fingerprint)) OR is_configured<>VALUES(is_configured),NOW(),last_changed_at),
    fingerprint=VALUES(fingerprint),is_configured=VALUES(is_configured)");
  foreach ($labels as $key => $label) {
    $value = getenv($key);
    $value = $value === false ? '' : trim($value);
    $configured = $value !== '';
    $fingerprint = $monitorKey !== '' && $configured ? hash_hmac('sha256', $value, $monitorKey) : null;
    $upsert->execute([$key, $fingerprint, $configured ? 1 : 0]);
  }
  $rows = $pdo->query('SELECT credential_name,is_configured,last_changed_at,fingerprint FROM workflow_credential_monitor ORDER BY credential_name')->fetchAll();
  foreach ($rows as &$row) {
    $row['label'] = $labels[$row['credential_name']] ?? $row['credential_name'];
    $row['change_tracking'] = $monitorKey !== '' && $row['fingerprint'] !== null;
    unset($row['fingerprint']);
  }
  unset($row);
  return $rows;
}

function workflow_schema_report(PDO $pdo): array {
  $tables = [
    'Core publishing' => ['programs', 'news', 'partners', 'socials', 'settings'],
    'Listener contact and subscriptions' => ['contact_messages', 'email_subscribers', 'listener_comments'],
    'Social review and sync' => ['social_updates', 'social_sync_items', 'social_sync_status', 'social_sync_tokens'],
    'News review and sync' => ['news_feed_items', 'news_feed_status'],
    'Admin workflow' => ['workflow_audit_log', 'admin_message_status', 'partner_content', 'workflow_job_runs', 'workflow_credential_monitor', 'workflow_migrations', 'workflow_rejections'],
  ];
  $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
  $report = [];
  foreach ($tables as $group => $names) {
    $missing = [];
    foreach ($names as $name) {
      $check->execute([$name]);
      if ((int)$check->fetchColumn() === 0) $missing[] = $name;
    }
    $report[] = ['group' => $group, 'ok' => count($missing) === 0, 'missing' => $missing];
  }
  $requiredColumns = [
    'programs' => ['is_published','publish_at','unpublish_at','public_since'],
    'news' => ['is_published','public_since'],
    'social_sync_status' => ['status','last_synced_at'],
    'news_feed_status' => ['status','last_synced_at'],
    'partner_content' => ['review_status','is_published','file_name'],
    'workflow_job_runs' => ['job_name','status','started_at'],
  ];
  $missingColumns = [];
  foreach ($requiredColumns as $table => $columns) {
    foreach ($columns as $column) {
      if (!db_has_column($pdo, $table, $column)) $missingColumns[] = $table . '.' . $column;
    }
  }
  $report[] = ['group' => 'Required workflow columns', 'ok' => count($missingColumns) === 0, 'missing' => $missingColumns];
  return $report;
}

function workflow_last_job_runs(PDO $pdo): array {
  $rows = $pdo->query("SELECT r.job_name,r.status,r.summary,r.started_at,r.finished_at
    FROM workflow_job_runs r
    INNER JOIN (SELECT job_name,MAX(id) AS latest_id FROM workflow_job_runs GROUP BY job_name) latest ON latest.latest_id=r.id
    ORDER BY r.job_name")->fetchAll();
  $runs = [];
  foreach ($rows as $row) $runs[$row['job_name']] = $row;
  return $runs;
}

function workflow_private_upload_dir(): string {
  $configured = getenv('RCM_PRIVATE_UPLOAD_DIR');
  $directory = $configured !== false && trim($configured) !== ''
    ? rtrim(trim($configured), '\\/')
    : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'rcm-private' . DIRECTORY_SEPARATOR . 'partner-media';
  $resolved = realpath($directory);
  $documentRoot = realpath(dirname(__DIR__));
  if ($resolved !== false && $documentRoot !== false) {
    $resolved = rtrim(str_replace('\\', '/', $resolved), '/');
    $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/') . '/';
    if ($resolved === rtrim($documentRoot, '/') || strpos($resolved . '/', $documentRoot) === 0) {
      throw new RuntimeException('Partner uploads must be stored outside the public document root.');
    }
    return $resolved;
  }
  return $directory;
}

function workflow_save_partner_upload(array $file): array {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Choose an image or PDF file no larger than 8 MB.');
  }
  if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || (int)($file['size'] ?? 0) > 8 * 1024 * 1024) {
    throw new RuntimeException('The upload is invalid or exceeds the 8 MB limit.');
  }
  if (!class_exists('finfo')) throw new RuntimeException('PHP fileinfo is required to verify uploaded files.');
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($file['tmp_name']);
  $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
  if (!isset($extensions[$mime])) throw new RuntimeException('Only JPEG, PNG, WebP images and PDF files are allowed.');

  $directory = workflow_private_upload_dir();
  if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('The private upload folder could not be created. Ask the host to configure RCM_PRIVATE_UPLOAD_DIR.');
  }
  $directory = workflow_private_upload_dir();
  $name = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
  if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $name)) {
    throw new RuntimeException('The private upload could not be saved.');
  }
  return ['file_name' => $name, 'mime_type' => $mime];
}

function workflow_delete_partner_upload(string $fileName): void {
  if (!preg_match('/^[a-f0-9]{48}\.(?:jpg|png|webp|pdf)$/', $fileName)) return;
  $path = workflow_private_upload_dir() . DIRECTORY_SEPARATOR . $fileName;
  if (is_file($path)) unlink($path);
}
