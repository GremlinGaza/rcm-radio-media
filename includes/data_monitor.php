<?php
declare(strict_types=1);

function get_data_monitor_report(PDO $pdo): array {
  require_once __DIR__ . '/social_sync.php';
  require_once __DIR__ . '/news_sync.php';
  ensure_social_sync_schema($pdo);
  ensure_news_sync_schema($pdo);

  $profiles = social_sync_profiles($pdo);
  $accounts = [];
  foreach (['YouTube','Facebook','TikTok','Instagram'] as $platform) {
    $url = trim($profiles[$platform] ?? '');
    $accounts[] = [
      'name' => $platform,
      'configured' => $url !== '',
      'domain' => $url !== '' ? strtolower((string)parse_url($url, PHP_URL_HOST)) : '',
    ];
  }

  $credentialNames = [
    'Facebook Page token' => 'RCM_FACEBOOK_PAGE_ACCESS_TOKEN',
    'TikTok app key' => 'RCM_TIKTOK_CLIENT_KEY',
    'TikTok app secret' => 'RCM_TIKTOK_CLIENT_SECRET',
    'TikTok access token' => 'RCM_TIKTOK_ACCESS_TOKEN',
    'TikTok refresh token' => 'RCM_TIKTOK_REFRESH_TOKEN',
  ];
  $credentials = [];
  foreach ($credentialNames as $label => $environmentKey) {
    $credentials[] = [
      'label' => $label,
      'configured' => social_sync_env($environmentKey) !== '',
      'storage' => 'Server environment',
    ];
  }

  $keyConfigured = strlen(social_sync_env('RCM_SOCIAL_CREDENTIAL_KEY')) >= 32;
  $tokenRecord = $pdo->query("SELECT OCTET_LENGTH(encrypted_payload) AS payload_bytes, OCTET_LENGTH(nonce) AS nonce_bytes, OCTET_LENGTH(auth_tag) AS tag_bytes, updated_at FROM social_sync_tokens WHERE platform='TikTok'")->fetch();
  $tokenHealth = 'not_stored';
  $tokenUpdatedAt = null;
  if ($tokenRecord) {
    $tokenUpdatedAt = $tokenRecord['updated_at'];
    if (!$keyConfigured) {
      $tokenHealth = 'key_missing';
    } elseif ((int)$tokenRecord['payload_bytes'] > 0 && (int)$tokenRecord['nonce_bytes'] === 12 && (int)$tokenRecord['tag_bytes'] === 16) {
      try {
        social_sync_load_tiktok_tokens($pdo);
        $tokenHealth = 'encrypted_verified';
      } catch (Throwable $exception) {
        $tokenHealth = 'verification_failed';
      }
    } else {
      $tokenHealth = 'invalid_record';
    }
  }

  $socialPostCounts = [];
  foreach ($pdo->query('SELECT platform, COUNT(*) AS total FROM social_updates GROUP BY platform') as $row) {
    $socialPostCounts[$row['platform']] = (int)$row['total'];
  }
  $socialSyncCounts = [];
  foreach ($pdo->query('SELECT platform, COUNT(*) AS total FROM social_sync_items GROUP BY platform') as $row) {
    $socialSyncCounts[$row['platform']] = (int)$row['total'];
  }
  $newsCounts = [];
  foreach ($pdo->query('SELECT source_name, COUNT(*) AS total FROM news WHERE id IN (SELECT news_id FROM news_feed_items) GROUP BY source_name') as $row) {
    $newsCounts[$row['source_name']] = (int)$row['total'];
  }
  $manualNewsCount = (int)$pdo->query('SELECT COUNT(*) FROM news WHERE id NOT IN (SELECT news_id FROM news_feed_items)')->fetchColumn();

  return [
    'accounts' => $accounts,
    'credentials' => $credentials,
    'encryption_key_configured' => $keyConfigured,
    'token_health' => $tokenHealth,
    'token_updated_at' => $tokenUpdatedAt,
    'token_record_bytes' => $tokenRecord ? (int)$tokenRecord['payload_bytes'] : 0,
    'social_post_counts' => $socialPostCounts,
    'social_sync_counts' => $socialSyncCounts,
    'social_sync_status' => get_social_sync_status($pdo),
    'news_source_counts' => $newsCounts,
    'manual_news_count' => $manualNewsCount,
    'news_feed_status' => get_news_feed_status($pdo),
  ];
}
