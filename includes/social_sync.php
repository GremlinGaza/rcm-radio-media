<?php
declare(strict_types=1);

function ensure_social_sync_schema(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS social_sync_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    platform VARCHAR(30) NOT NULL,
    external_id VARCHAR(191) NOT NULL,
    social_update_id INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_social_sync_item (platform, external_id),
    INDEX idx_social_sync_update (social_update_id)
  ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS social_sync_status (
    platform VARCHAR(30) PRIMARY KEY,
    status VARCHAR(20) NOT NULL DEFAULT 'not_configured',
    last_synced_at DATETIME DEFAULT NULL,
    items_synced INT UNSIGNED NOT NULL DEFAULT 0,
    message VARCHAR(255) NOT NULL DEFAULT '',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS social_sync_tokens (
    platform VARCHAR(30) PRIMARY KEY,
    encrypted_payload MEDIUMBLOB NOT NULL,
    nonce VARBINARY(12) NOT NULL,
    auth_tag VARBINARY(16) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB");
}

function social_sync_env(string $key): string {
  $value = getenv($key);
  return $value === false ? '' : trim($value);
}

function social_sync_profiles(PDO $pdo): array {
  $profiles = ['YouTube' => '', 'Facebook' => '', 'TikTok' => ''];
  foreach (get_socials($pdo) as $social) {
    if (isset($profiles[$social['platform']])) $profiles[$social['platform']] = $social['url'];
  }
  return $profiles;
}

function social_sync_channel_id(PDO $pdo): string {
  $configured = social_sync_env('RCM_YOUTUBE_CHANNEL_ID');
  if ($configured !== '') return $configured;
  $profiles = social_sync_profiles($pdo);
  return preg_match('~youtube\.com/channel/(UC[a-zA-Z0-9_-]+)~', $profiles['YouTube'], $match) ? $match[1] : '';
}

function social_sync_page_id(PDO $pdo): string {
  $configured = social_sync_env('RCM_FACEBOOK_PAGE_ID');
  if ($configured === '') $configured = trim((string)($GLOBALS['config']['facebook_page_id'] ?? ''));
  if ($configured !== '') return $configured;
  $profiles = social_sync_profiles($pdo);
  return preg_match('~(?:^|[-/])(\d{10,})(?:[/?#]|$)~', $profiles['Facebook'], $match) ? $match[1] : '';
}

function social_sync_tiktok_handle(PDO $pdo): string {
  $configured = ltrim(social_sync_env('RCM_TIKTOK_PROFILE_HANDLE'), '@');
  if ($configured !== '') return $configured;
  $profiles = social_sync_profiles($pdo);
  return preg_match('~tiktok\.com/@([a-zA-Z0-9._]+)~', $profiles['TikTok'], $match) ? $match[1] : '';
}

function social_sync_http(string $url, string $method = 'GET', array $headers = [], ?string $body = null): array {
  if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required for platform synchronization.');
  $curl = curl_init($url);
  curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_CUSTOMREQUEST => $method,
  ]);
  if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
  $response = curl_exec($curl);
  $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
  $curlError = curl_errno($curl);
  curl_close($curl);
  if ($response === false || $curlError !== 0) throw new RuntimeException('Could not reach the platform API. Check server internet access and TLS settings.');
  if ($status < 200 || $status >= 300) throw new RuntimeException('Platform API returned HTTP ' . $status . '. Check the account permissions and API credentials.');
  return ['status' => $status, 'body' => (string)$response];
}

function social_sync_json(string $body): array {
  $decoded = json_decode($body, true);
  if (!is_array($decoded)) throw new RuntimeException('The platform returned an invalid response.');
  return $decoded;
}

function social_sync_summary(string $value): string {
  $value = trim(strip_tags($value));
  if (function_exists('mb_substr')) return mb_substr($value, 0, 5000, 'UTF-8');
  return substr($value, 0, 5000);
}

function social_sync_title(string $value, string $fallback): string {
  $value = trim(strip_tags($value));
  if ($value === '') $value = $fallback;
  if (function_exists('mb_substr')) return mb_substr($value, 0, 200, 'UTF-8');
  return substr($value, 0, 200);
}

function social_sync_date($value): string {
  if (is_numeric($value)) return date('Y-m-d H:i:s', (int)$value);
  $timestamp = strtotime((string)$value);
  return date('Y-m-d H:i:s', $timestamp === false ? time() : $timestamp);
}

function social_sync_upsert(PDO $pdo, string $platform, string $externalId, string $title, string $summary, string $contentType, string $url, string $publishedAt): void {
  if ($externalId === '' || strlen($externalId) > 191 || !filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
    throw new RuntimeException('A platform item contained an invalid ID or public link.');
  }
  $host = strtolower((string)parse_url($url, PHP_URL_HOST));
  $allowedHosts = [
    'YouTube' => ['youtube.com','youtu.be'],
    'Facebook' => ['facebook.com','fb.watch'],
    'TikTok' => ['tiktok.com'],
  ];
  $validHost = false;
  foreach ($allowedHosts[$platform] ?? [] as $domain) {
    if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) $validHost = true;
  }
  if (!$validHost) throw new RuntimeException('A platform item returned a link outside its own domain.');

  $rejected = $pdo->prepare("SELECT 1 FROM workflow_rejections WHERE entity_type='social' AND source_key=? AND external_id=?");
  $rejected->execute([$platform,$externalId]);
  if ($rejected->fetchColumn()) return;

  $find = $pdo->prepare('SELECT m.social_update_id,u.title,u.summary,u.content_type,u.content_url,u.published_at,u.is_published FROM social_sync_items m INNER JOIN social_updates u ON u.id=m.social_update_id WHERE m.platform=? AND m.external_id=?');
  $find->execute([$platform, $externalId]);
  $existing = $find->fetch();
  if ($existing) {
    $changed = $existing['title'] !== $title || $existing['summary'] !== $summary || $existing['content_type'] !== $contentType || $existing['content_url'] !== $url || $existing['published_at'] !== $publishedAt;
    $update = $pdo->prepare('UPDATE social_updates SET title=?, summary=?, content_type=?, content_url=?, published_at=?, is_published=? WHERE id=?');
    $update->execute([$title,$summary,$contentType,$url,$publishedAt,$changed ? 0 : (int)$existing['is_published'],$existing['social_update_id']]);
    return;
  }

  $pdo->beginTransaction();
  try {
    $insert = $pdo->prepare('INSERT INTO social_updates (title,summary,platform,content_type,content_url,published_at,is_published) VALUES (?,?,?,?,?,?,0)');
    $insert->execute([$title,$summary,$platform,$contentType,$url,$publishedAt]);
    $map = $pdo->prepare('INSERT INTO social_sync_items (platform,external_id,social_update_id) VALUES (?,?,?)');
    $map->execute([$platform,$externalId,$pdo->lastInsertId()]);
    $pdo->commit();
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
  }
}

function social_sync_youtube(PDO $pdo): int {
  $channelId = social_sync_channel_id($pdo);
  if ($channelId === '' || !preg_match('/^UC[a-zA-Z0-9_-]{20,}$/', $channelId)) {
    throw new RuntimeException('Not configured: add the YouTube channel ID or set RCM_YOUTUBE_CHANNEL_ID.');
  }
  $url = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode($channelId);
  $response = social_sync_http($url);
  $previousLibxmlSetting = libxml_use_internal_errors(true);
  $feed = simplexml_load_string($response['body'], 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
  libxml_clear_errors();
  libxml_use_internal_errors($previousLibxmlSetting);
  if (!$feed) throw new RuntimeException('YouTube returned an invalid channel feed.');
  $count = 0;
  foreach ($feed->entry as $entry) {
    $namespaces = $entry->children('http://www.youtube.com/xml/schemas/2015');
    $videoId = (string)$namespaces->videoId;
    if ($videoId === '') continue;
    $title = social_sync_title((string)$entry->title, 'RCM YouTube video');
    $media = $entry->children('http://search.yahoo.com/mrss/');
    $group = $media->group;
    $description = (string)$group->description;
    $published = (string)$entry->published;
    social_sync_upsert($pdo, 'YouTube', $videoId, $title, social_sync_summary($description), 'Recorded show', 'https://www.youtube.com/watch?v=' . rawurlencode($videoId), social_sync_date($published));
    $count++;
  }
  return $count;
}

function social_sync_facebook(PDO $pdo): int {
  $pageId = social_sync_page_id($pdo);
  $token = social_sync_env('RCM_FACEBOOK_PAGE_ACCESS_TOKEN');
  if ($pageId === '' || !preg_match('/^\d{6,}$/', $pageId)) throw new RuntimeException('Not configured: add the Facebook Page ID or update the public Page URL.');
  if ($token === '') throw new RuntimeException('Not configured: set RCM_FACEBOOK_PAGE_ACCESS_TOKEN.');
  $version = social_sync_env('RCM_META_GRAPH_VERSION');
  if ($version === '') $version = 'v26.0';
  if (!preg_match('/^v\d+\.\d+$/', $version)) throw new RuntimeException('RCM_META_GRAPH_VERSION must look like v26.0.');
  $url = 'https://graph.facebook.com/' . $version . '/' . rawurlencode($pageId) . '/posts?fields=id,message,permalink_url,created_time,is_published,attachments%7Bmedia_type%7D&limit=50';
  $response = social_sync_http($url, 'GET', ['Authorization: Bearer ' . $token]);
  $data = social_sync_json($response['body']);
  if (isset($data['error'])) throw new RuntimeException('Facebook rejected the sync request. Check Page permissions and the access token.');
  $count = 0;
  foreach ($data['data'] ?? [] as $post) {
    if (empty($post['id']) || empty($post['permalink_url']) || (isset($post['is_published']) && !$post['is_published'])) continue;
    $message = (string)($post['message'] ?? '');
    $title = social_sync_title((string)strtok($message, "\r\n"), 'RCM Facebook update');
    $mediaType = strtolower((string)($post['attachments']['data'][0]['media_type'] ?? ''));
    $type = in_array($mediaType, ['video','reel','live_video'], true) ? 'Recorded show' : 'News update';
    social_sync_upsert($pdo, 'Facebook', (string)$post['id'], social_sync_summary($title), social_sync_summary($message), $type, (string)$post['permalink_url'], social_sync_date($post['created_time'] ?? 'now'));
    $count++;
  }
  return $count;
}

function social_sync_encryption_key(): string {
  $secret = social_sync_env('RCM_SOCIAL_CREDENTIAL_KEY');
  if (strlen($secret) < 32) throw new RuntimeException('Set RCM_SOCIAL_CREDENTIAL_KEY to a private random value of at least 32 characters to securely save refreshed TikTok tokens.');
  return hash('sha256', $secret, true);
}

function social_sync_save_tiktok_tokens(PDO $pdo, array $tokens): void {
  $key = social_sync_encryption_key();
  $nonce = random_bytes(12);
  $plainText = json_encode($tokens);
  $tag = '';
  $encrypted = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
  if ($encrypted === false) throw new RuntimeException('Could not securely store TikTok credentials.');
  $stmt = $pdo->prepare('INSERT INTO social_sync_tokens (platform,encrypted_payload,nonce,auth_tag) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE encrypted_payload=VALUES(encrypted_payload), nonce=VALUES(nonce), auth_tag=VALUES(auth_tag)');
  $stmt->execute(['TikTok',$encrypted,$nonce,$tag]);
}

function social_sync_load_tiktok_tokens(PDO $pdo): ?array {
  $stmt = $pdo->prepare('SELECT encrypted_payload,nonce,auth_tag FROM social_sync_tokens WHERE platform=?');
  $stmt->execute(['TikTok']);
  $row = $stmt->fetch();
  if (!$row) return null;
  $plainText = openssl_decrypt($row['encrypted_payload'], 'aes-256-gcm', social_sync_encryption_key(), OPENSSL_RAW_DATA, $row['nonce'], $row['auth_tag']);
  if ($plainText === false) throw new RuntimeException('Saved TikTok credentials could not be decrypted. Check RCM_SOCIAL_CREDENTIAL_KEY.');
  $tokens = json_decode($plainText, true);
  if (!is_array($tokens)) throw new RuntimeException('Saved TikTok credentials are invalid.');
  return $tokens;
}

function social_sync_tiktok_tokens(PDO $pdo): array {
  $tokens = social_sync_load_tiktok_tokens($pdo);
  if ($tokens && (int)($tokens['expires_at'] ?? 0) > time() + 300) return $tokens;

  $clientKey = social_sync_env('RCM_TIKTOK_CLIENT_KEY');
  $clientSecret = social_sync_env('RCM_TIKTOK_CLIENT_SECRET');
  $refreshToken = $tokens['refresh_token'] ?? social_sync_env('RCM_TIKTOK_REFRESH_TOKEN');
  if ($clientKey !== '' && $clientSecret !== '' && $refreshToken !== '') {
    social_sync_encryption_key();
    $form = http_build_query([
      'client_key' => $clientKey,
      'client_secret' => $clientSecret,
      'grant_type' => 'refresh_token',
      'refresh_token' => $refreshToken,
    ]);
    $response = social_sync_http('https://open.tiktokapis.com/v2/oauth/token/', 'POST', ['Content-Type: application/x-www-form-urlencoded'], $form);
    $result = social_sync_json($response['body']);
    if (empty($result['access_token']) || empty($result['refresh_token'])) throw new RuntimeException('TikTok token refresh failed. Check the app credentials and refresh-token permission.');
    $tokens = [
      'access_token' => $result['access_token'],
      'refresh_token' => $result['refresh_token'],
      'expires_at' => time() + (int)($result['expires_in'] ?? 86400),
    ];
    social_sync_save_tiktok_tokens($pdo, $tokens);
    return $tokens;
  }
  if ($tokens && !empty($tokens['access_token'])) return $tokens;
  $accessToken = social_sync_env('RCM_TIKTOK_ACCESS_TOKEN');
  if ($accessToken !== '') {
    return ['access_token' => $accessToken, 'refresh_token' => '', 'expires_at' => 0];
  }
  throw new RuntimeException('Not configured: set TikTok API credentials and a video.list-authorized access token.');
}

function social_sync_tiktok(PDO $pdo): int {
  $handle = social_sync_tiktok_handle($pdo);
  if ($handle === '') throw new RuntimeException('Not configured: set the TikTok profile URL or RCM_TIKTOK_PROFILE_HANDLE.');
  $tokens = social_sync_tiktok_tokens($pdo);
  $fields = 'id,create_time,share_url,video_description,title';
  $url = 'https://open.tiktokapis.com/v2/video/list/?fields=' . rawurlencode($fields);
  $response = social_sync_http($url, 'POST', [
    'Authorization: Bearer ' . $tokens['access_token'],
    'Content-Type: application/json',
  ], json_encode(['max_count' => 20]));
  $data = social_sync_json($response['body']);
  if (!empty($data['error']['code']) && $data['error']['code'] !== 'ok') throw new RuntimeException('TikTok video sync failed. Check the video.list scope and account authorization.');
  $count = 0;
  foreach ($data['data']['videos'] ?? [] as $video) {
    if (empty($video['id'])) continue;
    $id = (string)$video['id'];
    $link = (string)($video['share_url'] ?? '');
    if (!filter_var($link, FILTER_VALIDATE_URL) || stripos((string)parse_url($link, PHP_URL_HOST), 'tiktok.com') === false) {
      $link = 'https://www.tiktok.com/@' . rawurlencode($handle) . '/video/' . rawurlencode($id);
    }
    $description = trim((string)($video['video_description'] ?? ''));
    $title = trim((string)($video['title'] ?? ''));
    if ($title === '') $title = $description !== '' ? $description : 'RCM TikTok video';
    social_sync_upsert($pdo, 'TikTok', $id, social_sync_title($title, 'RCM TikTok video'), social_sync_summary($description), 'Short clip', $link, social_sync_date($video['create_time'] ?? time()));
    $count++;
  }
  return $count;
}

function sync_social_platforms(PDO $pdo): array {
  ensure_social_sync_schema($pdo);
  $syncers = [
    'YouTube' => 'social_sync_youtube',
    'Facebook' => 'social_sync_facebook',
    'TikTok' => 'social_sync_tiktok',
  ];
  $results = [];
  foreach ($syncers as $platform => $syncer) {
    try {
      $count = $syncer($pdo);
      $stmt = $pdo->prepare("INSERT INTO social_sync_status (platform,status,last_synced_at,items_synced,message) VALUES (?,'connected',NOW(),?,'Sync completed.') ON DUPLICATE KEY UPDATE status='connected',last_synced_at=NOW(),items_synced=VALUES(items_synced),message='Sync completed.'");
      $stmt->execute([$platform,$count]);
      $results[$platform] = ['status' => 'connected', 'count' => $count, 'message' => 'Sync completed.'];
    } catch (Throwable $exception) {
      $message = $exception->getMessage();
      $status = strpos($message, 'Not configured:') === 0 ? 'not_configured' : 'error';
      $safeMessage = $status === 'not_configured' ? $message : $message;
      $stmt = $pdo->prepare('INSERT INTO social_sync_status (platform,status,items_synced,message) VALUES (?,?,0,?) ON DUPLICATE KEY UPDATE status=VALUES(status),message=VALUES(message)');
      $stmt->execute([$platform,$status,substr($safeMessage,0,255)]);
      $results[$platform] = ['status' => $status, 'count' => 0, 'message' => $safeMessage];
    }
  }
  return $results;
}

function get_social_sync_status(PDO $pdo): array {
  return $pdo->query('SELECT platform,status,last_synced_at,items_synced,message FROM social_sync_status ORDER BY platform')->fetchAll();
}
