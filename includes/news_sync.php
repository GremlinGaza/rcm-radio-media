<?php
declare(strict_types=1);

function ensure_news_sync_schema(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS news_feed_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key VARCHAR(40) NOT NULL,
    external_id VARCHAR(191) NOT NULL,
    news_id INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_news_feed_item (source_key, external_id),
    INDEX idx_news_feed_news (news_id)
  ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS news_feed_status (
    source_key VARCHAR(40) PRIMARY KEY,
    source_name VARCHAR(100) NOT NULL,
    region VARCHAR(40) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'not_synced',
    last_synced_at DATETIME DEFAULT NULL,
    stories_synced INT UNSIGNED NOT NULL DEFAULT 0,
    message VARCHAR(255) NOT NULL DEFAULT '',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB");
}

function news_feed_sources(): array {
  return [
    'nation' => [
      'name' => 'Daily Nation',
      'region' => 'Kenya',
      'url' => 'https://nation.africa/kenya/rss.xml',
      'domains' => ['nation.africa'],
      'category' => 'Kenya',
    ],
    'standard' => [
      'name' => 'The Standard',
      'region' => 'Kenya',
      'url' => 'https://www.standardmedia.co.ke/rss/headlines.php',
      'domains' => ['standardmedia.co.ke'],
      'category' => 'Kenya',
    ],
    'aljazeera' => [
      'name' => 'Al Jazeera',
      'region' => 'Africa & World',
      'url' => 'https://www.aljazeera.com/xml/rss/all.xml',
      'domains' => ['aljazeera.com'],
      'category' => 'Africa & World',
    ],
  ];
}

function news_feed_url_is_allowed(string $url, array $domains): bool {
  if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') return false;
  $host = strtolower((string)parse_url($url, PHP_URL_HOST));
  foreach ($domains as $domain) {
    if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) return true;
  }
  return false;
}

function news_feed_text(string $value, int $limit): string {
  $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $value = trim(preg_replace('/\s+/u', ' ', $value));
  if (function_exists('mb_substr')) return mb_substr($value, 0, $limit, 'UTF-8');
  return substr($value, 0, $limit);
}

function news_feed_upsert(PDO $pdo, string $sourceKey, array $source, string $externalId, string $title, string $summary, string $url, string $publishedAt): void {
  $title = news_feed_text($title, 250);
  $summary = news_feed_text($summary, 280);
  if ($title === '' || !news_feed_url_is_allowed($url, $source['domains'])) return;
  if ($externalId === '') $externalId = $url;
  if (strlen($externalId) > 191) $externalId = hash('sha256', $externalId);

  $rejected = $pdo->prepare("SELECT 1 FROM workflow_rejections WHERE entity_type='news' AND source_key=? AND external_id=?");
  $rejected->execute([$sourceKey,$externalId]);
  if ($rejected->fetchColumn()) return;

  $find = $pdo->prepare('SELECT f.news_id,n.title,n.summary,n.source_url,n.published_at,n.is_published FROM news_feed_items f INNER JOIN news n ON n.id=f.news_id WHERE f.source_key=? AND f.external_id=?');
  $find->execute([$sourceKey,$externalId]);
  $existing = $find->fetch();
  if ($existing) {
    $changed = $existing['title'] !== $title || $existing['summary'] !== $summary || $existing['source_url'] !== $url || $existing['published_at'] !== $publishedAt;
    $update = $pdo->prepare('UPDATE news SET title=?, summary=?, category=?, source_name=?, source_url=?, published_at=?, is_published=?, public_since=IF(?,NULL,public_since) WHERE id=?');
    $update->execute([$title,$summary,$source['category'],$source['name'],$url,$publishedAt,$changed ? 0 : (int)$existing['is_published'],$changed ? 1 : 0,$existing['news_id']]);
    return;
  }

  $pdo->beginTransaction();
  try {
    $insert = $pdo->prepare('INSERT INTO news (title,summary,category,source_name,source_url,published_at,is_published) VALUES (?,?,?,?,?,?,0)');
    $insert->execute([$title,$summary,$source['category'],$source['name'],$url,$publishedAt]);
    $link = $pdo->prepare('INSERT INTO news_feed_items (source_key,external_id,news_id) VALUES (?,?,?)');
    $link->execute([$sourceKey,$externalId,$pdo->lastInsertId()]);
    $pdo->commit();
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
  }
}

function sync_news_feeds(PDO $pdo): array {
  require_once __DIR__ . '/social_sync.php';
  ensure_news_sync_schema($pdo);
  $results = [];
  foreach (news_feed_sources() as $sourceKey => $source) {
    try {
      $response = social_sync_http($source['url'], 'GET', ['Accept: application/rss+xml, application/xml, text/xml;q=0.9']);
      $previousLibxmlSetting = libxml_use_internal_errors(true);
      $feed = simplexml_load_string($response['body'], 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
      libxml_clear_errors();
      libxml_use_internal_errors($previousLibxmlSetting);
      if (!$feed) throw new RuntimeException('The publisher returned an invalid RSS feed.');

      $items = isset($feed->channel->item) ? $feed->channel->item : $feed->item;
      $count = 0;
      foreach ($items as $item) {
        if ($count >= 20) break;
        $title = news_feed_text((string)$item->title, 250);
        $url = trim((string)$item->link);
        if ($url === '' && isset($item->guid) && filter_var((string)$item->guid, FILTER_VALIDATE_URL)) $url = trim((string)$item->guid);
        if ($title === '' || !news_feed_url_is_allowed($url, $source['domains'])) continue;
        $guid = trim((string)$item->guid);
        $externalId = $guid !== '' ? $guid : $url;
        $summary = news_feed_text((string)$item->description, 280);
        $published = strtotime((string)$item->pubDate);
        $publishedAt = date('Y-m-d H:i:s', $published === false ? time() : $published);
        news_feed_upsert($pdo, $sourceKey, $source, $externalId, $title, $summary, $url, $publishedAt);
        $count++;
      }

      $status = $pdo->prepare("INSERT INTO news_feed_status (source_key,source_name,region,status,last_synced_at,stories_synced,message) VALUES (?,?,?,'connected',NOW(),?,'Headlines refreshed.') ON DUPLICATE KEY UPDATE source_name=VALUES(source_name),region=VALUES(region),status='connected',last_synced_at=NOW(),stories_synced=VALUES(stories_synced),message='Headlines refreshed.'");
      $status->execute([$sourceKey,$source['name'],$source['region'],$count]);
      $results[$sourceKey] = ['name' => $source['name'], 'region' => $source['region'], 'status' => 'connected', 'count' => $count, 'message' => 'Headlines refreshed.'];
    } catch (Throwable $exception) {
      $message = substr($exception->getMessage(), 0, 255);
      $status = $pdo->prepare("INSERT INTO news_feed_status (source_key,source_name,region,status,stories_synced,message) VALUES (?,?,?,'error',0,?) ON DUPLICATE KEY UPDATE source_name=VALUES(source_name),region=VALUES(region),status='error',message=VALUES(message)");
      $status->execute([$sourceKey,$source['name'],$source['region'],$message]);
      $results[$sourceKey] = ['name' => $source['name'], 'region' => $source['region'], 'status' => 'error', 'count' => 0, 'message' => $message];
    }
  }
  return $results;
}

function get_news_feed_status(PDO $pdo): array {
  return $pdo->query('SELECT source_key,source_name,region,status,last_synced_at,stories_synced,message FROM news_feed_status ORDER BY source_name')->fetchAll();
}

function get_collected_news(PDO $pdo, int $limit = 12): array {
  $stmt = $pdo->prepare('SELECT n.* FROM news n INNER JOIN news_feed_items f ON f.news_id=n.id WHERE n.is_published=1 ORDER BY n.published_at DESC, n.id DESC LIMIT :lim');
  $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
  $stmt->execute();
  return $stmt->fetchAll();
}
