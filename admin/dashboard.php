<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/social_sync.php';
require_once __DIR__ . '/../includes/news_sync.php';
require_once __DIR__ . '/../includes/data_monitor.php';
require_once __DIR__ . '/../includes/email_notifications.php';
require_once __DIR__ . '/../includes/admin_workflow.php';
if (empty($_SESSION['admin_id'])) {
  header('Location: login.php');
  exit;
}
ensure_social_sync_schema($pdo);
ensure_news_sync_schema($pdo);
ensure_email_subscription_schema($pdo);
ensure_admin_workflow_schema($pdo);

$adminError = '';
$action = $_POST['action'] ?? '';
$platformDomains = [
  'YouTube' => ['youtube.com','youtu.be'],
  'TikTok' => ['tiktok.com'],
  'Facebook' => ['facebook.com','fb.watch'],
  'Instagram' => ['instagram.com']
];
$isPlatformUrl = static function (string $url, string $platform) use ($platformDomains): bool {
  if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') return false;
  $host = strtolower((string)parse_url($url, PHP_URL_HOST));
  foreach ($platformDomains[$platform] ?? [] as $domain) {
    if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) return true;
  }
  return false;
};
$isHttpsUrl = static function (string $url): bool {
  return filter_var($url, FILTER_VALIDATE_URL) !== false && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https';
};
$isSafeImagePath = static function (string $path) use ($isHttpsUrl): bool {
  if ($path === '' || $isHttpsUrl($path)) return true;
  return preg_match('#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9][A-Za-z0-9_./-]*$#', $path) === 1;
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  if ($action === 'news') {
    $title = trim($_POST['title'] ?? '');
    $summary = trim($_POST['summary'] ?? '');
    $sourceUrl = trim($_POST['source_url'] ?? '');
    $publishedInput = trim($_POST['published_at'] ?? '');
    $publishedDate = $publishedInput === '' ? new DateTime() : DateTime::createFromFormat('Y-m-d\TH:i', $publishedInput);
    if ($title === '' || strlen($title) > 250 || $summary === '' || !$publishedDate || ($sourceUrl !== '' && (!filter_var($sourceUrl, FILTER_VALIDATE_URL) || strtolower((string)parse_url($sourceUrl, PHP_URL_SCHEME)) !== 'https'))) {
      $adminError = 'Enter a headline and journalist-written summary, a valid publication time, and an optional valid HTTPS source URL.';
    } else {
      $published = isset($_POST['is_published']) ? 1 : 0;
      $stmt = $pdo->prepare('INSERT INTO news (title,summary,category,source_name,source_url,published_at,is_published,public_since) VALUES (?,?,?,?,?,?,?,IF(?,NOW(),NULL))');
      $stmt->execute([$title,$summary,trim($_POST['category'] ?? ''),trim($_POST['source_name'] ?? 'RCM Newsroom'),$sourceUrl,$publishedDate->format('Y-m-d H:i:s'),$published,$published]);
      workflow_log($pdo, $published ? 'published' : 'drafted', 'news', (string)$pdo->lastInsertId(), 'Manually authored newsroom item.');
    }
  } elseif ($action === 'program') {
    $title = trim($_POST['title'] ?? '');
    $imageUrl = trim($_POST['image_url'] ?? '');
    $mediaUrl = trim($_POST['media_url'] ?? '');
    if ($title === '' || strlen($title) > 200 || !$isSafeImagePath($imageUrl) || ($mediaUrl !== '' && !$isHttpsUrl($mediaUrl))) {
      $adminError = 'Enter a programme title and use HTTPS links (or a safe relative path) for its artwork and media.';
    } else {
      $published = isset($_POST['is_published']) ? 1 : 0;
      $stmt = $pdo->prepare('INSERT INTO programs (title,description,category,schedule,image_url,media_url,is_featured,is_published,public_since) VALUES (?,?,?,?,?,?,?,?,IF(?,NOW(),NULL))');
      $stmt->execute([$title,trim($_POST['description'] ?? ''),trim($_POST['category'] ?? ''),trim($_POST['schedule'] ?? ''),$imageUrl,$mediaUrl,isset($_POST['is_featured']) ? 1 : 0,$published,$published]);
      workflow_log($pdo, $published ? 'published' : 'drafted', 'program', (string)$pdo->lastInsertId());
    }
  } elseif ($action === 'setting') {
    $streamUrl = trim($_POST['stream_url'] ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');
    if (($streamUrl !== '' && !$isHttpsUrl($streamUrl)) || ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL))) {
      $adminError = 'Use a valid HTTPS stream URL and a valid contact email address.';
    } else {
      $stmt = $pdo->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
      foreach (['stream_url' => $streamUrl,'contact_email' => $contactEmail] as $key => $value) $stmt->execute([$key,$value]);
      workflow_log($pdo, 'updated', 'station_settings');
    }
  } elseif ($action === 'social_channels') {
    $platforms = ['YouTube','TikTok','Facebook','Instagram'];
    $channels = [];
    foreach ($platforms as $platform) {
      $url = trim($_POST['social_' . strtolower($platform)] ?? '');
      if ($url !== '' && !$isPlatformUrl($url, $platform)) {
        $adminError = 'Enter a valid HTTPS ' . $platform . ' URL, or leave it blank.';
        break;
      }
      $channels[$platform] = $url;
    }
    if ($adminError === '') {
      $findSocial = $pdo->prepare('SELECT id FROM socials WHERE platform=? ORDER BY sort_order,id LIMIT 1');
      $updateSocial = $pdo->prepare('UPDATE socials SET url=?, is_published=? WHERE id=?');
      $insertSocial = $pdo->prepare('INSERT INTO socials (platform,url,is_published,sort_order) VALUES (?,?,?,?)');
      foreach ($channels as $platform => $url) {
        $findSocial->execute([$platform]);
        $existing = $findSocial->fetch();
        if ($existing) {
          $updateSocial->execute([$url,$url !== '' ? 1 : 0,$existing['id']]);
        } else {
          $insertSocial->execute([$platform,$url,$url !== '' ? 1 : 0,array_search($platform, $platforms, true) + 1]);
        }
      }
      workflow_log($pdo, 'updated', 'social_channels', '', 'Account links saved; blank links are hidden.');
      header('Location: dashboard.php?saved=channels');
      exit;
    }
  } elseif ($action === 'social_update') {
    $platform = trim($_POST['platform'] ?? '');
    $contentType = trim($_POST['content_type'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $summary = trim($_POST['summary'] ?? '');
    $url = trim($_POST['content_url'] ?? '');
    $allowedPlatforms = ['YouTube','TikTok','Facebook','Instagram'];
    $allowedContentTypes = ['Live replay','Recorded show','Short clip','Behind the scenes','News update'];
    if (!in_array($platform, $allowedPlatforms, true) || !in_array($contentType, $allowedContentTypes, true)) {
      $adminError = 'Select a supported platform and content type.';
    } elseif ($title === '' || strlen($title) > 200 || strlen($summary) > 5000) {
      $adminError = 'Add a title (up to 200 characters) and a summary up to 5,000 characters.';
    } elseif (!$isPlatformUrl($url, $platform)) {
      $adminError = 'Enter a valid HTTPS ' . $platform . ' link to the published content.';
    } else {
      $publishedInput = trim($_POST['published_at'] ?? '');
      $publishedDate = $publishedInput === '' ? date('Y-m-d H:i:s') : DateTime::createFromFormat('Y-m-d\TH:i', $publishedInput);
      if (!$publishedDate) {
        $adminError = 'Enter a valid publication date and time.';
      } else {
        $publishedAt = $publishedDate instanceof DateTime ? $publishedDate->format('Y-m-d H:i:s') : $publishedDate;
        $stmt = $pdo->prepare('INSERT INTO social_updates (title,summary,platform,content_type,content_url,published_at,is_published) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$title,$summary,$platform,$contentType,$url,$publishedAt,isset($_POST['is_published']) ? 1 : 0]);
        workflow_log($pdo, isset($_POST['is_published']) ? 'published' : 'drafted', 'social_update', (string)$pdo->lastInsertId(), 'Manually submitted content link.');
        header('Location: dashboard.php?saved=updates');
        exit;
      }
    }
  } elseif ($action === 'sync_platforms') {
    $startedAt = date('Y-m-d H:i:s');
    try {
      $results = sync_social_platforms($pdo);
      $failed = count(array_filter($results, static function (array $result): bool { return $result['status'] !== 'connected'; })) > 0;
      workflow_record_job($pdo, 'manual_social_sync', $failed ? 'partial' : 'success', 'Social items are held for admin review.', $startedAt, date('Y-m-d H:i:s'));
      workflow_log($pdo, 'manual_sync', 'social', '', 'Collected content is private until approved.');
      header('Location: dashboard.php?synced=1');
      exit;
    } catch (Throwable $exception) {
      workflow_record_job($pdo, 'manual_social_sync', 'failed', 'Sync failed; check platform health details.', $startedAt, date('Y-m-d H:i:s'));
      $adminError = 'Social sync did not finish. Check platform health below and the server error log.';
    }
  } elseif ($action === 'sync_news_feeds') {
    $startedAt = date('Y-m-d H:i:s');
    try {
      $results = sync_news_feeds($pdo);
      $failed = count(array_filter($results, static function (array $result): bool { return $result['status'] !== 'connected'; })) > 0;
      workflow_record_job($pdo, 'manual_news_sync', $failed ? 'partial' : 'success', 'Headlines are held for admin review.', $startedAt, date('Y-m-d H:i:s'));
      workflow_log($pdo, 'manual_sync', 'news', '', 'Collected RSS items are private until approved.');
      header('Location: dashboard.php?news_synced=1');
      exit;
    } catch (Throwable $exception) {
      workflow_record_job($pdo, 'manual_news_sync', 'failed', 'Sync failed; check publisher health details.', $startedAt, date('Y-m-d H:i:s'));
      $adminError = 'News feed sync did not finish. Check publisher health below and the server error log.';
    }
  } elseif ($action === 'review_social' || $action === 'review_news') {
    $id = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
    $decision = $_POST['decision'] ?? '';
    if (!$id || !in_array($decision, ['approve','reject'], true)) {
      $adminError = 'Select a valid item and review action.';
    } else {
      if ($action === 'review_social') {
        if ($decision === 'approve') {
          $stmt = $pdo->prepare('UPDATE social_updates SET is_published=1 WHERE id=?');
          $stmt->execute([$id]);
        } else {
          workflow_reject_synced_item($pdo, 'social', $id);
          $pdo->prepare('DELETE FROM social_sync_items WHERE social_update_id=?')->execute([$id]);
          $pdo->prepare('DELETE FROM social_updates WHERE id=?')->execute([$id]);
        }
        workflow_log($pdo, $decision, 'social_update', (string)$id, $decision === 'approve' ? 'Approved for public feed.' : 'Rejected and removed from the feed.');
      } else {
        if ($decision === 'approve') {
          $stmt = $pdo->prepare('UPDATE news SET is_published=1,public_since=NOW() WHERE id=?');
          $stmt->execute([$id]);
        } else {
          workflow_reject_synced_item($pdo, 'news', $id);
          $pdo->prepare('DELETE FROM news_feed_items WHERE news_id=?')->execute([$id]);
          $pdo->prepare('DELETE FROM news WHERE id=?')->execute([$id]);
        }
        workflow_log($pdo, $decision, 'news', (string)$id, $decision === 'approve' ? 'Approved for the public newsroom.' : 'Rejected and removed from the newsroom.');
      }
      header('Location: dashboard.php?saved=review');
      exit;
    }
  } elseif ($action === 'social_update_manage') {
    $id = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
    $decision = $_POST['decision'] ?? '';
    if (!$id || !in_array($decision, ['publish','unpublish','delete'], true)) {
      $adminError = 'Select valid shared content and an action.';
    } else {
      if ($decision === 'delete') {
        workflow_reject_synced_item($pdo, 'social', $id);
        $pdo->prepare('DELETE FROM social_sync_items WHERE social_update_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM social_updates WHERE id=?')->execute([$id]);
      } else {
        $pdo->prepare('UPDATE social_updates SET is_published=? WHERE id=?')->execute([$decision === 'publish' ? 1 : 0,$id]);
      }
      workflow_log($pdo, $decision, 'social_update', (string)$id);
      header('Location: dashboard.php?saved=review');
      exit;
    }
  } elseif ($action === 'news_manage') {
    $id = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
    $decision = $_POST['decision'] ?? '';
    if (!$id || !in_array($decision, ['publish','unpublish','delete'], true)) {
      $adminError = 'Select valid newsroom content and an action.';
    } else {
      if ($decision === 'delete') {
        workflow_reject_synced_item($pdo, 'news', $id);
        $pdo->prepare('DELETE FROM news_feed_items WHERE news_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM news WHERE id=?')->execute([$id]);
      } else {
        $published = $decision === 'publish' ? 1 : 0;
        $pdo->prepare('UPDATE news SET is_published=?,public_since=IF(?,NOW(),NULL) WHERE id=?')->execute([$published,$published,$id]);
      }
      workflow_log($pdo, $decision, 'news', (string)$id);
      header('Location: dashboard.php?saved=review');
      exit;
    }
  } elseif ($action === 'program_toggle') {
    $id = filter_var($_POST['program_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
      $adminError = 'Select a valid programme.';
    } else {
      $enabled = isset($_POST['is_published']) ? 1 : 0;
      $stmt = $pdo->prepare('UPDATE programs SET is_published=?,public_since=IF(?,COALESCE(publish_at,NOW()),NULL) WHERE id=?');
      $stmt->execute([$enabled,$enabled,$id]);
      workflow_log($pdo, $enabled ? 'enabled' : 'disabled', 'program', (string)$id);
      header('Location: dashboard.php?saved=program');
      exit;
    }
  } elseif ($action === 'program_schedule') {
    $id = filter_var($_POST['program_id'] ?? null, FILTER_VALIDATE_INT);
    $publishInput = trim($_POST['publish_at'] ?? '');
    $unpublishInput = trim($_POST['unpublish_at'] ?? '');
    $parseDate = static function (string $input) {
      if ($input === '') return null;
      $date = DateTime::createFromFormat('Y-m-d\TH:i', $input);
      $errors = DateTime::getLastErrors();
      if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) return false;
      return $date->format('Y-m-d H:i:s');
    };
    $publishAt = $parseDate($publishInput);
    $unpublishAt = $parseDate($unpublishInput);
    if (!$id || $publishAt === false || $unpublishAt === false || ($publishAt && $unpublishAt && $unpublishAt <= $publishAt)) {
      $adminError = 'Choose valid schedule times; the end time must be later than the start time.';
    } else {
      $stmt = $pdo->prepare('UPDATE programs SET publish_at=?,unpublish_at=?,is_published=1,public_since=COALESCE(?,NOW()) WHERE id=?');
      $stmt->execute([$publishAt,$unpublishAt,$publishAt,$id]);
      workflow_log($pdo, 'scheduled', 'program', (string)$id, 'Times use the hosting server timezone.');
      header('Location: dashboard.php?saved=schedule');
      exit;
    }
  } elseif ($action === 'partner_save') {
    $id = filter_var($_POST['partner_id'] ?? null, FILTER_VALIDATE_INT);
    $name = trim($_POST['name'] ?? '');
    $shortName = trim($_POST['short_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $website = trim($_POST['website_url'] ?? '');
    if ($name === '' || strlen($name) > 200 || $shortName === '' || strlen($shortName) > 20 || strlen($description) > 400 || ($website !== '' && (!filter_var($website, FILTER_VALIDATE_URL) || strtolower((string)parse_url($website, PHP_URL_SCHEME)) !== 'https'))) {
      $adminError = 'Enter a partner name, short name, description up to 400 characters, and an optional HTTPS website URL.';
    } else {
      if ($id) {
        $stmt = $pdo->prepare('UPDATE partners SET name=?,short_name=?,description=?,website_url=? WHERE id=?');
        $stmt->execute([$name,$shortName,$description,$website,$id]);
        workflow_log($pdo, 'updated', 'partner', (string)$id, 'Partner details updated.');
      } else {
        $stmt = $pdo->prepare('INSERT INTO partners (name,short_name,description,website_url,is_published,sort_order) VALUES (?,?,?,?,0,0)');
        $stmt->execute([$name,$shortName,$description,$website]);
        workflow_log($pdo, 'created', 'partner', (string)$pdo->lastInsertId(), 'New partner starts unpublished.');
      }
      header('Location: dashboard.php?saved=partner');
      exit;
    }
  } elseif ($action === 'partner_toggle') {
    $id = filter_var($_POST['partner_id'] ?? null, FILTER_VALIDATE_INT);
    $enabled = isset($_POST['is_published']) ? 1 : 0;
    if (!$id) {
      $adminError = 'Select a valid partner.';
    } elseif ($enabled && !isset($_POST['partner_verified'])) {
      $adminError = 'Confirm the partner identity, official link, and promotion details before publishing.';
    } else {
      $pdo->prepare('UPDATE partners SET is_published=? WHERE id=?')->execute([$enabled,$id]);
      workflow_log($pdo, $enabled ? 'approved' : 'unpublished', 'partner', (string)$id);
      header('Location: dashboard.php?saved=partner');
      exit;
    }
  } elseif ($action === 'partner_content_upload') {
    $partnerId = filter_var($_POST['partner_id'] ?? null, FILTER_VALIDATE_INT);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $partnerCheck = $pdo->prepare('SELECT id FROM partners WHERE id=?');
    $partnerCheck->execute([$partnerId]);
    if (!$partnerId || !$partnerCheck->fetch() || $title === '' || strlen($title) > 200 || strlen($description) > 2000) {
      $adminError = 'Select a partner and enter a title and description (up to 2,000 characters).';
    } else {
      $upload = null;
      $contentId = null;
      try {
        $upload = workflow_save_partner_upload($_FILES['content_file'] ?? []);
        $stmt = $pdo->prepare("INSERT INTO partner_content (partner_id,title,description,file_name,mime_type,review_status,is_published) VALUES (?,?,?,?,?,'pending',0)");
        $stmt->execute([$partnerId,$title,$description,$upload['file_name'],$upload['mime_type']]);
        $contentId = (string)$pdo->lastInsertId();
        workflow_log($pdo, 'uploaded_for_review', 'partner_content', $contentId, 'Private media awaiting approval.');
        header('Location: dashboard.php?saved=upload');
        exit;
      } catch (Throwable $exception) {
        if ($upload && $contentId === null) workflow_delete_partner_upload($upload['file_name']);
        $adminError = $exception instanceof RuntimeException ? $exception->getMessage() : 'The upload could not be saved.';
      }
    }
  } elseif ($action === 'partner_content_review') {
    $id = filter_var($_POST['content_id'] ?? null, FILTER_VALIDATE_INT);
    $decision = $_POST['decision'] ?? '';
    if (!$id || !in_array($decision, ['approve','reject','delete'], true)) {
      $adminError = 'Select valid partner content and a review action.';
    } elseif ($decision === 'approve' && !isset($_POST['verification_confirmed'])) {
      $adminError = 'Verify the partner, offer details, content rights, and uploaded file before publishing.';
    } else {
      $stmt = $pdo->prepare('SELECT file_name FROM partner_content WHERE id=?');
      $stmt->execute([$id]);
      $item = $stmt->fetch();
      if ($decision === 'delete' && $item) {
        workflow_delete_partner_upload($item['file_name']);
        $pdo->prepare('DELETE FROM partner_content WHERE id=?')->execute([$id]);
      } elseif ($decision === 'approve') {
        $pdo->prepare("UPDATE partner_content SET review_status='approved',is_published=1 WHERE id=?")->execute([$id]);
      } else {
        $pdo->prepare("UPDATE partner_content SET review_status='rejected',is_published=0 WHERE id=?")->execute([$id]);
      }
      workflow_log($pdo, $decision, 'partner_content', (string)$id);
      header('Location: dashboard.php?saved=review');
      exit;
    }
  } elseif ($action === 'contact_status') {
    $id = filter_var($_POST['message_id'] ?? null, FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? '';
    if (!$id || !in_array($status, ['read','handled'], true)) {
      $adminError = 'Select a valid contact message status.';
    } else {
      $stmt = $pdo->prepare('INSERT INTO admin_message_status (contact_message_id,status) VALUES (?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)');
      $stmt->execute([$id,$status]);
      workflow_log($pdo, $status, 'contact_message', (string)$id);
      header('Location: dashboard.php?saved=message');
      exit;
    }
  } elseif ($action === 'comment_review') {
    $id = filter_var($_POST['comment_id'] ?? null, FILTER_VALIDATE_INT);
    $decision = $_POST['decision'] ?? '';
    if (!$id || !in_array($decision, ['approve','reject','delete'], true)) {
      $adminError = 'Select a valid comment and review action.';
    } else {
      if ($decision === 'delete') $pdo->prepare('DELETE FROM listener_comments WHERE id=?')->execute([$id]);
      else $pdo->prepare('UPDATE listener_comments SET status=? WHERE id=?')->execute([$decision === 'approve' ? 'approved' : 'rejected',$id]);
      workflow_log($pdo, $decision, 'listener_comment', (string)$id);
      header('Location: dashboard.php?saved=review');
      exit;
    }
  } elseif ($action === 'subscriber_unsubscribe') {
    $id = filter_var($_POST['subscriber_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
      $adminError = 'Select a valid subscriber.';
    } else {
      $pdo->prepare('UPDATE email_subscribers SET unsubscribed_at=NOW() WHERE id=?')->execute([$id]);
      workflow_log($pdo, 'unsubscribed_by_admin', 'subscriber', (string)$id);
      header('Location: dashboard.php?saved=subscriber');
      exit;
    }
  } elseif ($action === 'subscriber_preferences') {
    $id = filter_var($_POST['subscriber_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
      $adminError = 'Select a valid subscriber.';
    } else {
      $pdo->prepare('UPDATE email_subscribers SET wants_programs=?,wants_news=? WHERE id=?')->execute([isset($_POST['wants_programs']) ? 1 : 0,isset($_POST['wants_news']) ? 1 : 0,$id]);
      workflow_log($pdo, 'preferences_updated', 'subscriber', (string)$id);
      header('Location: dashboard.php?saved=subscriber');
      exit;
    }
  }
  if ($adminError === '' && in_array($action, ['news','program','setting'], true)) {
    header('Location: dashboard.php?saved=1');
    exit;
  }
}

$pageTitle = 'Admin Dashboard — RCM Radio Media';
$socialMap = ['YouTube' => '', 'TikTok' => '', 'Facebook' => '', 'Instagram' => ''];
foreach (get_socials($pdo) as $social) {
  if (array_key_exists($social['platform'], $socialMap)) $socialMap[$social['platform']] = $social['url'];
}
$latestSocialUpdates = $pdo->query('SELECT id,title,platform,content_type,content_url,published_at,is_published FROM social_updates ORDER BY created_at DESC LIMIT 10')->fetchAll();
$socialSyncStatuses = get_social_sync_status($pdo);
$socialSyncStatusMap = [];
foreach ($socialSyncStatuses as $syncStatus) $socialSyncStatusMap[$syncStatus['platform']] = $syncStatus;
$newsFeedStatuses = get_news_feed_status($pdo);
$dataMonitor = get_data_monitor_report($pdo);
$socialMonitorStatusMap = [];
foreach ($dataMonitor['social_sync_status'] as $syncStatus) $socialMonitorStatusMap[$syncStatus['platform']] = $syncStatus;
$newsMonitorStatusMap = [];
foreach ($dataMonitor['news_feed_status'] as $feedStatus) $newsMonitorStatusMap[$feedStatus['source_name']] = $feedStatus;
$emailSubscriptionCounts = $pdo->query("SELECT
  SUM(confirmed_at IS NOT NULL AND unsubscribed_at IS NULL) AS active,
  SUM(confirmed_at IS NULL AND confirmation_token_hash IS NOT NULL) AS awaiting_confirmation,
  SUM(unsubscribed_at IS NOT NULL) AS unsubscribed
  FROM email_subscribers")->fetch();
$emailDeliverySettings = [
  'Gmail sender address' => filter_var(email_notification_env('RCM_GMAIL_ADDRESS'), FILTER_VALIDATE_EMAIL) !== false,
  'Gmail app password' => email_notification_env('RCM_GMAIL_APP_PASSWORD') !== '',
  'Public HTTPS site URL' => email_notification_env('RCM_PUBLIC_SITE_URL') !== '',
  'Email link-signing key' => strlen(email_notification_env('RCM_EMAIL_TOKEN_SECRET')) >= 32,
];
$workflowCredentialStatus = workflow_track_credentials($pdo);
$workflowSchemaReport = workflow_schema_report($pdo);
$workflowLastJobs = workflow_last_job_runs($pdo);
$workflowAudit = $pdo->query('SELECT actor,action_name,entity_type,entity_id,detail,created_at FROM workflow_audit_log ORDER BY id DESC LIMIT 15')->fetchAll();
$programList = $pdo->query('SELECT id,title,schedule,is_published,publish_at,unpublish_at FROM programs ORDER BY sort_order,title')->fetchAll();
$partnerList = $pdo->query('SELECT id,name,short_name,description,website_url,is_published FROM partners ORDER BY sort_order,name')->fetchAll();
$partnerContentList = $pdo->query('SELECT c.id,c.title,c.description,c.file_name,c.mime_type,c.review_status,c.is_published,c.created_at,p.name AS partner_name FROM partner_content c INNER JOIN partners p ON p.id=c.partner_id ORDER BY c.id DESC LIMIT 30')->fetchAll();
$pendingNews = $pdo->query('SELECT n.id,n.title,n.summary,n.source_name,n.source_url,n.published_at,IF(f.news_id IS NULL,0,1) AS is_feed_item FROM news n LEFT JOIN news_feed_items f ON f.news_id=n.id WHERE n.is_published=0 ORDER BY n.created_at DESC LIMIT 30')->fetchAll();
$pendingSocial = $pdo->query('SELECT id,title,summary,platform,content_type,content_url,published_at FROM social_updates WHERE is_published=0 ORDER BY created_at DESC LIMIT 30')->fetchAll();
$latestNewsStories = $pdo->query('SELECT id,title,source_name,source_url,is_published,created_at FROM news ORDER BY created_at DESC LIMIT 20')->fetchAll();
$contactMessages = $pdo->query("SELECT m.id,m.name,m.email,m.subject,m.message,m.created_at,COALESCE(s.status,'new') AS admin_status FROM contact_messages m LEFT JOIN admin_message_status s ON s.contact_message_id=m.id ORDER BY m.id DESC LIMIT 30")->fetchAll();
$listenerComments = $pdo->query("SELECT id,display_name,comment_text,status,created_at FROM listener_comments WHERE status='pending' ORDER BY id DESC LIMIT 30")->fetchAll();
$latestListenerComments = $pdo->query('SELECT id,display_name,comment_text,status,created_at FROM listener_comments ORDER BY id DESC LIMIT 25')->fetchAll();
$subscriberList = $pdo->query("SELECT id,email,wants_programs,wants_news,confirmed_at,unsubscribed_at,last_digest_at FROM email_subscribers ORDER BY id DESC LIMIT 50")->fetchAll();
$workflowPendingCount = (int)$pdo->query("SELECT
  (SELECT COUNT(*) FROM news WHERE is_published=0) +
  (SELECT COUNT(*) FROM social_updates WHERE is_published=0) +
  (SELECT COUNT(*) FROM listener_comments WHERE status='pending') +
  (SELECT COUNT(*) FROM partner_content WHERE review_status='pending')")->fetchColumn();
require __DIR__ . '/../includes/header.php';
?>
<main class="section-wrap page-content admin-page">
  <p class="eyebrow">RCM MANAGEMENT</p>
  <h1 class="page-title">Welcome to <span>the control room.</span></h1>
  <div class="admin-top"><p>Signed in as <?= e($_SESSION['admin_username'] ?? 'admin') ?>. Publish programmes, news and follow-up links for recorded or live content.</p><a class="btn btn-ghost" href="logout.php">Log out</a></div>
  <section class="workflow-overview" aria-label="Admin workflow summary">
    <article><span>Needs review</span><strong><?= $workflowPendingCount ?></strong><a href="#review-queue">Open review queue</a></article>
    <article><span>Contact messages</span><strong><?= count(array_filter($contactMessages, static function (array $message): bool { return $message['admin_status'] === 'new'; })) ?></strong><a href="#listener-inbox">Open inbox</a></article>
    <article><span>Active subscribers</span><strong><?= (int)($emailSubscriptionCounts['active'] ?? 0) ?></strong><a href="#subscriber-monitor">Manage subscribers</a></article>
    <article><span>Daily tasks</span><strong><?= count($workflowLastJobs) ?></strong><a href="#automation-monitor">Check sync & digest</a></article>
  </section>
  <nav class="workflow-nav" aria-label="Admin workflow shortcuts">
    <a href="#station-settings">Live broadcast</a><a href="#program-schedule-manager">Shows & podcasts</a><a href="#partner-manager">Partners & content</a>
    <a href="#channel-manager">Social accounts</a><a href="#news-editor">Newsroom</a><a href="#review-queue">Review & publish <span><?= $workflowPendingCount ?></span></a><a href="#listener-inbox">Listener inbox</a>
    <a href="#subscriber-monitor">Subscriptions</a><a href="#email-digest-monitor">Gmail & digest</a><a href="#automation-monitor">Automation & health</a>
    <a href="#schema-monitor">Sync schema</a><a href="#credential-monitor">Credential changes</a><a href="#privacy-monitor">Privacy monitor</a>
  </nav>
  <?php if (isset($_GET['saved'])): ?><p class="notice success">Saved successfully.</p><?php endif; ?>
  <?php if (isset($_GET['synced'])): ?><p class="notice success">Sync finished. Review each platform’s result below.</p><?php endif; ?>
  <?php if (isset($_GET['news_synced'])): ?><p class="notice success">News feeds checked. Review the publisher results below.</p><?php endif; ?>
  <?php if ($adminError): ?><p class="notice error" role="alert"><?= e($adminError) ?></p><?php endif; ?>

  <div class="admin-grid">
    <section class="admin-panel social-sync-panel" id="email-digest-monitor">
      <h2>Email notification delivery</h2>
      <p class="admin-panel-intro">Listeners confirm their email before joining. Only delivery configuration and aggregate subscriber totals are shown here; addresses and secret values are not displayed.</p>
      <div class="social-sync-status-list email-delivery-status">
        <?php foreach ($emailDeliverySettings as $settingLabel => $configured): ?>
          <article class="social-sync-status-row"><strong><?= e($settingLabel) ?></strong><span class="sync-state sync-state-<?= $configured ? 'connected' : 'not_configured' ?>"><?= $configured ? 'configured' : 'not configured' ?></span></article>
        <?php endforeach; ?>
      </div>
      <p class="email-subscriber-counts"><strong><?= (int)($emailSubscriptionCounts['active'] ?? 0) ?></strong> active · <strong><?= (int)($emailSubscriptionCounts['awaiting_confirmation'] ?? 0) ?></strong> awaiting email confirmation · <strong><?= (int)($emailSubscriptionCounts['unsubscribed'] ?? 0) ?></strong> unsubscribed</p>
      <p class="source-note">Daily digests are sent by the scheduled notification task only to confirmed subscribers, according to their selected interests. Follow the Gmail app-password and task-scheduler setup in README.</p>
    </section>

    <section class="admin-panel social-sync-panel" id="privacy-monitor">
      <h2>Privacy & data monitor</h2>
      <p class="admin-panel-intro">A safe overview of account connection, credential protection and stored post/headline counts. Secret values and encrypted token contents are never displayed here.</p>

      <div class="data-monitor-summary">
        <article class="data-monitor-note">
          <strong>Database content</strong>
          <p>Public profiles, published posts and headlines are readable database content so the website can display and search them. They are not encrypted by the app.</p>
        </article>
        <article class="data-monitor-note">
          <strong>TikTok token protection</strong>
          <p><?= e(str_replace('_',' ',$dataMonitor['token_health'])) ?><?= $dataMonitor['token_health'] === 'encrypted_verified' ? ' · AES-256-GCM record decrypted successfully for an integrity check.' : '' ?></p>
          <span><?= $dataMonitor['encryption_key_configured'] ? 'Encryption key is present in server environment.' : 'Encryption key is missing or too short.' ?></span>
          <?php if ($dataMonitor['token_updated_at']): ?><time datetime="<?= e(date('c', strtotime($dataMonitor['token_updated_at']))) ?>">Record updated <?= e(date('d M Y, H:i', strtotime($dataMonitor['token_updated_at']))) ?> · <?= (int)$dataMonitor['token_record_bytes'] ?> encrypted bytes</time><?php endif; ?>
        </article>
      </div>

      <h3 class="data-monitor-subtitle">Connected public accounts</h3>
      <div class="data-monitor-table-wrap">
        <table class="data-monitor-table">
          <thead><tr><th>Platform</th><th>Profile</th><th>Credential configuration</th><th>Synced / stored items</th><th>Sync health</th></tr></thead>
          <tbody>
            <?php foreach ($dataMonitor['accounts'] as $account): $sync = $socialMonitorStatusMap[$account['name']] ?? null; $config = array_filter($dataMonitor['credentials'], static function (array $credential) use ($account): bool { return stripos($credential['label'], $account['name']) === 0 || ($account['name'] === 'Facebook' && $credential['label'] === 'Facebook Page token'); }); ?>
              <tr>
                <th scope="row"><?= e($account['name']) ?></th>
                <td><?= $account['configured'] ? e($account['domain']) : 'Not linked' ?></td>
                <td>
                  <?php if ($account['name'] === 'YouTube' || $account['name'] === 'Instagram'): ?>No API secret used by this monitor.
                  <?php else: $configuredCount = count(array_filter($config, static function (array $credential): bool { return $credential['configured']; })); ?>
                    <?= $configuredCount ?> of <?= count($config) ?> required secrets present in server environment.
                  <?php endif; ?>
                </td>
                <td><?= (int)($dataMonitor['social_sync_counts'][$account['name']] ?? 0) ?> synced · <?= (int)($dataMonitor['social_post_counts'][$account['name']] ?? 0) ?> total</td>
                <td><span class="sync-state sync-state-<?= e($sync['status'] ?? ($account['name'] === 'Instagram' ? 'not_configured' : 'not_synced')) ?>"><?= e(str_replace('_',' ',$sync['status'] ?? ($account['name'] === 'Instagram' ? 'not configured' : 'not synced'))) ?></span><?php if (!empty($sync['last_synced_at'])): ?><small><?= e(date('d M Y, H:i', strtotime($sync['last_synced_at']))) ?></small><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <h3 class="data-monitor-subtitle">News collection</h3>
      <div class="data-monitor-table-wrap">
        <table class="data-monitor-table">
          <thead><tr><th>Publisher</th><th>Coverage</th><th>Collected stories</th><th>Feed health</th><th>Last checked</th></tr></thead>
          <tbody>
            <?php foreach (news_feed_sources() as $sourceKey => $source): $feed = $newsMonitorStatusMap[$source['name']] ?? null; ?>
              <tr>
                <th scope="row"><?= e($source['name']) ?></th>
                <td><?= e($source['region']) ?></td>
                <td><?= (int)($dataMonitor['news_source_counts'][$source['name']] ?? 0) ?></td>
                <td><span class="sync-state sync-state-<?= e($feed['status'] ?? 'not_synced') ?>"><?= e(str_replace('_',' ',$feed['status'] ?? 'not synced')) ?></span></td>
                <td><?= !empty($feed['last_synced_at']) ? e(date('d M Y, H:i', strtotime($feed['last_synced_at']))) : 'Never' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="data-monitor-footnote"><?= (int)$dataMonitor['manual_news_count'] ?> manually managed news stories are also stored in the newsroom. Database credentials, server environment variables and backups need their own host/database access controls; this monitor does not claim those are encrypted.</p>
    </section>

    <section class="admin-panel social-sync-panel" id="news-sync-monitor">
      <h2>Collect news headlines</h2>
      <p class="admin-panel-intro">Collect the latest short headlines from Kenyan publishers and international news sources. Each story links to its original publisher; full articles are not copied.</p>
      <form method="post" class="social-sync-action">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="sync_news_feeds">
        <button class="btn btn-primary">Refresh news now</button>
        <a class="text-link" href="<?= e(site_url('news.php')) ?>">View listener news panel <span aria-hidden="true">↗</span></a>
      </form>
      <div class="social-sync-status-list news-feed-status-list">
        <?php foreach ($newsFeedStatuses as $feedStatus): ?>
          <article class="social-sync-status-row">
            <strong><?= e($feedStatus['source_name']) ?></strong>
            <span class="sync-state sync-state-<?= e($feedStatus['status']) ?>"><?= e(str_replace('_',' ',$feedStatus['status'])) ?></span>
            <p><?= e($feedStatus['region']) ?> · <?= (int)$feedStatus['stories_synced'] ?> headlines · <?= e($feedStatus['message']) ?></p>
            <?php if (!empty($feedStatus['last_synced_at'])): ?><time datetime="<?= e(date('c', strtotime($feedStatus['last_synced_at']))) ?>">Last checked <?= e(date('d M Y, H:i', strtotime($feedStatus['last_synced_at']))) ?></time><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <p class="source-note">Sources: Daily Nation and The Standard (Kenya), Al Jazeera (Africa and world). Collection is based on publisher RSS feeds and sorted by publication time, not an algorithmic popularity score.</p>
    </section>

    <section class="admin-panel social-sync-panel" id="social-sync-monitor">
      <h2>Automatic social feed sync</h2>
      <p class="admin-panel-intro">Pull the latest public RCM posts from YouTube, Facebook and TikTok. Instagram is not connected. API credentials are read from private server environment variables and never shown here.</p>
      <form method="post" class="social-sync-action">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="sync_platforms">
        <button class="btn btn-primary">Sync platforms now</button>
        <a class="text-link" href="<?= e(site_url('README.md')) ?>">Credential setup guide <span aria-hidden="true">↗</span></a>
      </form>
      <div class="social-sync-status-list">
        <?php foreach (['YouTube','Facebook','TikTok'] as $syncPlatform): $sync = $socialSyncStatusMap[$syncPlatform] ?? null; $syncState = $sync['status'] ?? 'not_configured'; ?>
          <article class="social-sync-status-row">
            <strong><?= e($syncPlatform) ?></strong>
            <span class="sync-state sync-state-<?= e($syncState) ?>"><?= e(str_replace('_',' ',$syncState)) ?></span>
            <p><?= e($sync['message'] ?? 'Not synced yet. Set up server credentials, then sync.') ?></p>
            <?php if (!empty($sync['last_synced_at'])): ?><time datetime="<?= e(date('c', strtotime($sync['last_synced_at']))) ?>">Last synced <?= e(date('d M Y, H:i', strtotime($sync['last_synced_at']))) ?></time><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <p class="source-note">YouTube sync uses its public channel feed; Facebook requires a Page access token; TikTok requires an approved Display API app with video.list permission. Configure daily sync with the Windows Task Scheduler or server cron as described in README.</p>
    </section>

    <section class="admin-panel" id="news-editor">
      <h2>Add a news story</h2>
      <form method="post" class="contact-form compact">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="news">
        <label>Headline<input name="title" required maxlength="250"></label>
        <label>Journalist-written summary<textarea name="summary" rows="4" required maxlength="5000"></textarea></label>
        <label>Category<input name="category" placeholder="Kenya, Business, Culture"></label>
        <label>Source / journalist<input name="source_name" value="RCM Newsroom"></label>
        <label>Original source URL (optional)<input type="url" name="source_url" placeholder="https://..."></label>
        <label>Published at<input type="datetime-local" name="published_at"></label>
        <label class="check-label"><input type="checkbox" name="is_published"> Publish now (only after editorial review)</label>
        <button class="btn btn-primary">Save story</button>
      </form>
      <h3 class="data-monitor-subtitle">Recent newsroom stories</h3>
      <div class="workflow-list"><?php foreach ($latestNewsStories as $story): ?>
        <article class="workflow-item"><div class="workflow-item-heading"><strong><?= e($story['title']) ?></strong><span class="news-label"><?= $story['is_published'] ? 'public' : 'draft / review' ?></span></div><p><?= e($story['source_name']) ?> · <?= e(date('d M Y', strtotime($story['created_at']))) ?></p>
          <form method="post" class="workflow-inline-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="news_manage"><input type="hidden" name="item_id" value="<?= (int)$story['id'] ?>"><?php if ($story['is_published']): ?><button name="decision" value="unpublish" class="btn btn-ghost">Unpublish</button><?php else: ?><button name="decision" value="publish" class="btn btn-primary">Publish</button><?php endif; ?><button name="decision" value="delete" class="btn btn-danger" data-confirm="Delete this story and its feed mapping?">Delete</button><?php if ($story['source_url']): ?><a class="text-link" href="<?= e($story['source_url']) ?>" target="_blank" rel="noopener noreferrer">Open source ↗</a><?php endif; ?></form>
        </article>
      <?php endforeach; ?></div>
    </section>

    <section class="admin-panel" id="program-manager">
      <h2>Add a show / podcast</h2>
      <form method="post" class="contact-form compact">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="program">
        <label>Show title<input name="title" required></label>
        <label>Description<textarea name="description" rows="3"></textarea></label>
        <label>Category<input name="category" placeholder="Music, Talk, Podcast"></label>
        <label>Schedule<input name="schedule" placeholder="Weekdays · 10:00"></label>
        <label>Thumbnail URL<input type="url" name="image_url"></label>
        <label>Audio / video URL<input type="url" name="media_url"></label>
        <label class="check-label"><input type="checkbox" name="is_featured"> Feature on home page</label>
        <label class="check-label"><input type="checkbox" name="is_published" checked> Publish now</label>
        <button class="btn btn-primary">Add program</button>
      </form>
    </section>

    <section class="admin-panel" id="channel-manager">
      <h2>Social channel links</h2>
      <p class="admin-panel-intro">Set the public accounts visitors should follow. Leave YouTube blank until the official channel URL is available.</p>
      <form method="post" class="contact-form compact">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="social_channels">
        <?php foreach (['YouTube','TikTok','Facebook','Instagram'] as $platform): $field = 'social_' . strtolower($platform); ?>
          <label><?= e($platform) ?> URL<input type="url" name="<?= e($field) ?>" value="<?= e($_POST[$field] ?? $socialMap[$platform]) ?>" placeholder="https://<?= e(strtolower($platform)) ?>.com/..." inputmode="url"></label>
        <?php endforeach; ?>
        <button class="btn btn-primary">Save channel links</button>
      </form>
    </section>

    <section class="admin-panel" id="content-link-manager">
      <h2>Share a broadcast or recording</h2>
      <p class="admin-panel-intro">Add the public platform link for a recorded show, podcast, clip or news post. Published items appear in the searchable homepage content hub. Add one item for each platform post; this does not automatically sync social accounts.</p>
      <form method="post" class="contact-form compact">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="social_update">
        <label>Content title<input name="title" required maxlength="200" value="<?= e($action === 'social_update' ? ($_POST['title'] ?? '') : '') ?>" placeholder="e.g. RCM Breakfast — today's highlights"></label>
        <label>Platform<select name="platform" required><option value="YouTube">YouTube</option><option value="TikTok">TikTok</option><option value="Facebook">Facebook</option><option value="Instagram">Instagram</option></select></label>
        <label>Content type<select name="content_type" required><option>Live replay</option><option>Recorded show</option><option>Short clip</option><option>Behind the scenes</option><option>News update</option></select></label>
        <label>Short description<textarea name="summary" rows="3" maxlength="5000" placeholder="What is this broadcast, recording or news post about?"></textarea></label>
        <label>Public content URL<input type="url" name="content_url" required placeholder="https://..." inputmode="url"></label>
        <label>Published at<input type="datetime-local" name="published_at"></label>
        <label class="check-label"><input type="checkbox" name="is_published" checked> Show on homepage now</label>
        <button class="btn btn-primary">Publish content link</button>
      </form>
    </section>

    <section class="admin-panel" id="station-settings">
      <h2>Station settings</h2>
      <form method="post" class="contact-form compact">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="setting">
        <label>Live stream URL<input type="url" name="stream_url" value="<?= e(get_settings($pdo)['stream_url'] ?? '') ?>" placeholder="https://stream.example.com/live.mp3"></label>
        <label>Contact email<input type="email" name="contact_email" value="<?= e(get_settings($pdo)['contact_email'] ?? 'hello@rcmradiomedia.co.ke') ?>"></label>
        <button class="btn btn-primary">Save settings</button>
      </form>
      <p class="source-note">The public player uses the saved direct stream URL. Test it over HTTPS; a webpage/player URL may not be a playable audio stream.</p>
    </section>

    <section class="admin-panel workflow-panel" id="program-schedule-manager">
      <h2>Program on/off & schedule</h2>
      <p class="admin-panel-intro">Switch a show on or off now, or schedule its public visibility. Times use the hosting server timezone. Scheduled publishing still requires the listing to be enabled.</p>
      <?php foreach ($programList as $program): ?>
        <article class="workflow-item">
          <div class="workflow-item-heading"><strong><?= e($program['title']) ?></strong><span class="sync-state sync-state-<?= $program['is_published'] ? 'connected' : 'not_configured' ?>"><?= $program['is_published'] ? 'enabled' : 'off' ?></span></div>
          <p><?= e($program['schedule']) ?><?php if ($program['publish_at']): ?> · starts <?= e(date('d M Y H:i', strtotime($program['publish_at']))) ?><?php endif; ?><?php if ($program['unpublish_at']): ?> · ends <?= e(date('d M Y H:i', strtotime($program['unpublish_at']))) ?><?php endif; ?></p>
          <form method="post" class="workflow-inline-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="program_toggle"><input type="hidden" name="program_id" value="<?= (int)$program['id'] ?>">
            <label class="check-label"><input type="checkbox" name="is_published" <?= $program['is_published'] ? 'checked' : '' ?>> Enabled</label><button class="btn btn-ghost">Save on/off</button>
          </form>
          <form method="post" class="workflow-inline-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="program_schedule"><input type="hidden" name="program_id" value="<?= (int)$program['id'] ?>">
            <label>Publish from<input type="datetime-local" name="publish_at" value="<?= $program['publish_at'] ? e(date('Y-m-d\TH:i', strtotime($program['publish_at']))) : '' ?>"></label>
            <label>Hide after<input type="datetime-local" name="unpublish_at" value="<?= $program['unpublish_at'] ? e(date('Y-m-d\TH:i', strtotime($program['unpublish_at']))) : '' ?>"></label>
            <button class="btn btn-primary">Save schedule</button>
          </form>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="admin-panel workflow-panel" id="partner-manager">
      <h2>Partner directory & promotion content</h2>
      <p class="admin-panel-intro">New partners are hidden until you enable them. Upload partner-provided JPEG, PNG, WebP or PDF content (maximum 8 MB); uploads are stored outside the public web root and remain private until approved.</p>
      <form method="post" class="contact-form compact">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="partner_save">
        <label>Partner name<input name="name" required maxlength="200"></label><label>Short name<input name="short_name" required maxlength="20"></label>
        <label>Description<textarea name="description" rows="3" maxlength="400"></textarea></label><label>Official website<input type="url" name="website_url" placeholder="https://..."></label>
        <button class="btn btn-primary">Add partner (starts hidden)</button>
      </form>
      <div class="workflow-list">
        <?php foreach ($partnerList as $partner): ?>
          <article class="workflow-item">
            <div class="workflow-item-heading"><strong><?= e($partner['name']) ?></strong><span class="sync-state sync-state-<?= $partner['is_published'] ? 'connected' : 'not_configured' ?>"><?= $partner['is_published'] ? 'public' : 'hidden' ?></span></div>
            <p><?= e($partner['description']) ?><?php if ($partner['website_url']): ?> · <a class="text-link" href="<?= e($partner['website_url']) ?>" target="_blank" rel="noopener noreferrer">website ↗</a><?php endif; ?></p>
            <form method="post" class="workflow-inline-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="partner_toggle"><input type="hidden" name="partner_id" value="<?= (int)$partner['id'] ?>"><label class="check-label"><input type="checkbox" name="is_published" <?= $partner['is_published'] ? 'checked' : '' ?>> Publish partner</label><label class="check-label"><input type="checkbox" name="partner_verified" value="1"> I verified partner identity, official link and details</label><button class="btn btn-ghost">Save status</button></form>
            <details><summary>Edit partner details</summary><form method="post" class="contact-form compact"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="partner_save"><input type="hidden" name="partner_id" value="<?= (int)$partner['id'] ?>"><label>Name<input name="name" required maxlength="200" value="<?= e($partner['name']) ?>"></label><label>Short name<input name="short_name" required maxlength="20" value="<?= e($partner['short_name']) ?>"></label><label>Description<textarea name="description" rows="3" maxlength="400"><?= e($partner['description']) ?></textarea></label><label>Website<input type="url" name="website_url" value="<?= e($partner['website_url']) ?>"></label><button class="btn btn-primary">Save details</button></form></details>
          </article>
        <?php endforeach; ?>
      </div>
      <h3 class="data-monitor-subtitle">Upload campaign content for review</h3>
      <form method="post" enctype="multipart/form-data" class="contact-form compact">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="partner_content_upload">
        <label>Partner<select name="partner_id" required><option value="">Choose partner</option><?php foreach ($partnerList as $partner): ?><option value="<?= (int)$partner['id'] ?>"><?= e($partner['name']) ?></option><?php endforeach; ?></select></label>
        <label>Campaign title<input name="title" required maxlength="200"></label><label>Caption / terms<textarea name="description" rows="3" maxlength="2000" required></textarea></label>
        <label>Image or PDF (max 8 MB)<input type="file" name="content_file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" required></label>
        <button class="btn btn-primary">Upload privately for review</button>
      </form>
      <?php if ($partnerContentList): ?><div class="workflow-list"><?php foreach ($partnerContentList as $content): ?>
        <article class="workflow-item"><div class="workflow-item-heading"><strong><?= e($content['title']) ?> · <?= e($content['partner_name']) ?></strong><span class="sync-state sync-state-<?= $content['review_status'] === 'approved' ? 'connected' : ($content['review_status'] === 'pending' ? 'not_synced' : 'error') ?>"><?= e($content['review_status']) ?></span></div>
          <p><?= e($content['description']) ?> · <?= e(strtoupper(str_replace('image/','',$content['mime_type']))) ?> · <?= e(date('d M Y H:i', strtotime($content['created_at']))) ?></p>
          <a class="text-link" href="<?= e(site_url('admin/partner-media.php?id=' . (int)$content['id'])) ?>" target="_blank" rel="noopener noreferrer">Review file ↗</a>
          <form method="post" class="workflow-inline-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="partner_content_review"><input type="hidden" name="content_id" value="<?= (int)$content['id'] ?>"><label class="check-label"><input type="checkbox" name="verification_confirmed" value="1"> I verified the offer, partner and content rights</label><button name="decision" value="approve" class="btn btn-primary">Approve & publish</button><button name="decision" value="reject" class="btn btn-ghost">Reject</button><button name="decision" value="delete" class="btn btn-danger" data-confirm="Delete this uploaded file permanently?">Delete file</button></form>
        </article>
      <?php endforeach; ?></div><?php else: ?><p class="muted">No partner campaign uploads yet.</p><?php endif; ?>
    </section>

    <section class="admin-panel workflow-panel" id="review-queue">
      <h2>Review queue <span class="workflow-count"><?= $workflowPendingCount ?></span></h2>
      <p class="admin-panel-intro">Nothing collected from social platforms or publisher RSS is published automatically. Approve an item to make it public, or reject it to remove it.</p>
      <h3 class="data-monitor-subtitle">Social posts awaiting approval (<?= count($pendingSocial) ?>)</h3>
      <?php if ($pendingSocial): ?><div class="workflow-list"><?php foreach ($pendingSocial as $item): ?>
        <article class="workflow-item"><div class="workflow-item-heading"><strong><?= e($item['title']) ?></strong><span class="news-label"><?= e($item['platform']) ?> · <?= e($item['content_type']) ?></span></div><p><?= e($item['summary']) ?></p><a class="text-link" href="<?= e($item['content_url']) ?>" target="_blank" rel="noopener noreferrer">Open original post ↗</a>
          <form method="post" class="workflow-inline-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="review_social"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>"><button name="decision" value="approve" class="btn btn-primary">Approve & publish</button><button name="decision" value="reject" class="btn btn-ghost">Reject & remove</button></form>
        </article><?php endforeach; ?></div><?php else: ?><p class="muted">No social posts need review.</p><?php endif; ?>
      <h3 class="data-monitor-subtitle">RSS headlines awaiting editorial review (<?= count($pendingNews) ?>)</h3>
      <?php if ($pendingNews): ?><div class="workflow-list"><?php foreach ($pendingNews as $item): ?>
        <article class="workflow-item"><div class="workflow-item-heading"><strong><?= e($item['title']) ?></strong><span class="news-label"><?= e($item['source_name'] ?: 'RCM newsroom') ?></span></div><p><?= e($item['summary']) ?></p><a class="text-link" href="<?= e($item['source_url']) ?>" target="_blank" rel="noopener noreferrer">Read original source ↗</a>
          <form method="post" class="workflow-inline-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="review_news"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>"><button name="decision" value="approve" class="btn btn-primary">Approve headline</button><button name="decision" value="reject" class="btn btn-ghost">Reject & remove</button></form>
        </article><?php endforeach; ?></div><?php else: ?><p class="muted">No RSS headlines need review.</p><?php endif; ?>
      <h3 class="data-monitor-subtitle">Listener comments awaiting moderation (<?= count($listenerComments) ?>)</h3>
      <?php if ($listenerComments): ?><div class="workflow-list"><?php foreach ($listenerComments as $comment): ?>
        <article class="workflow-item"><div class="workflow-item-heading"><strong><?= e($comment['display_name']) ?></strong><time><?= e(date('d M Y H:i', strtotime($comment['created_at']))) ?></time></div><p><?= e($comment['comment_text']) ?></p><form method="post" class="workflow-inline-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="comment_review"><input type="hidden" name="comment_id" value="<?= (int)$comment['id'] ?>"><button name="decision" value="approve" class="btn btn-primary">Approve</button><button name="decision" value="reject" class="btn btn-ghost">Reject</button><button name="decision" value="delete" class="btn btn-danger" data-confirm="Delete this comment permanently?">Delete</button></form></article>
      <?php endforeach; ?></div><?php else: ?><p class="muted">No listener comments need moderation.</p><?php endif; ?>
    </section>

    <section class="admin-panel workflow-panel">
      <h2>Recent listener comments</h2><p class="admin-panel-intro">Review all recent comment states, including previously approved public comments. Reject or delete a comment to take it offline.</p>
      <?php if ($latestListenerComments): ?><div class="workflow-list"><?php foreach ($latestListenerComments as $comment): ?><article class="workflow-item"><div class="workflow-item-heading"><strong><?= e($comment['display_name']) ?></strong><span class="news-label"><?= e($comment['status']) ?></span></div><p><?= e($comment['comment_text']) ?></p><form method="post" class="workflow-inline-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="comment_review"><input type="hidden" name="comment_id" value="<?= (int)$comment['id'] ?>"><button name="decision" value="approve" class="btn btn-primary">Approve</button><button name="decision" value="reject" class="btn btn-ghost">Take offline</button><button name="decision" value="delete" class="btn btn-danger" data-confirm="Delete this comment permanently?">Delete</button></form></article><?php endforeach; ?></div><?php else: ?><p class="muted">No listener comments have been submitted.</p><?php endif; ?>
    </section>

    <section class="admin-panel workflow-panel" id="listener-inbox">
      <h2>Listener inbox</h2><p class="admin-panel-intro">Read contact messages and mark them read or handled. Email addresses are visible only to signed-in admins. Replies are sent from your normal email account; this dashboard does not send replies.</p>
      <?php if ($contactMessages): ?><div class="workflow-list"><?php foreach ($contactMessages as $message): ?>
        <article class="workflow-item"><div class="workflow-item-heading"><strong><?= e($message['subject'] ?: 'General enquiry') ?></strong><span class="news-label"><?= e($message['admin_status']) ?></span></div><p><strong><?= e($message['name']) ?></strong> · <a href="mailto:<?= e($message['email']) ?>"><?= e($message['email']) ?></a> · <?= e(date('d M Y H:i', strtotime($message['created_at']))) ?></p><p class="workflow-message"><?= nl2br(e($message['message'])) ?></p><form method="post" class="workflow-inline-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="contact_status"><input type="hidden" name="message_id" value="<?= (int)$message['id'] ?>"><button name="status" value="read" class="btn btn-ghost">Mark read</button><button name="status" value="handled" class="btn btn-primary">Mark handled</button></form></article>
      <?php endforeach; ?></div><?php else: ?><p class="muted">No contact messages yet.</p><?php endif; ?>
    </section>

    <section class="admin-panel workflow-panel" id="subscriber-monitor">
      <h2>Subscription follow-up</h2><p class="admin-panel-intro">Manage subscription state and review preferences. Use the daily digest task to send approved, newly published show/news links to confirmed subscribers.</p>
      <?php if ($subscriberList): ?><div class="workflow-list"><?php foreach ($subscriberList as $subscriber): ?>
        <article class="workflow-item"><div class="workflow-item-heading"><strong><?= e($subscriber['email']) ?></strong><span class="news-label"><?= $subscriber['unsubscribed_at'] ? 'unsubscribed' : ($subscriber['confirmed_at'] ? 'confirmed' : 'awaiting confirmation') ?></span></div><p>Last digest: <?= $subscriber['last_digest_at'] ? e(date('d M Y H:i', strtotime($subscriber['last_digest_at']))) : 'not sent' ?></p>
          <?php if (!$subscriber['unsubscribed_at']): ?><form method="post" class="workflow-inline-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="subscriber_preferences"><input type="hidden" name="subscriber_id" value="<?= (int)$subscriber['id'] ?>"><label class="check-label"><input type="checkbox" name="wants_programs" <?= $subscriber['wants_programs'] ? 'checked' : '' ?>> Shows</label><label class="check-label"><input type="checkbox" name="wants_news" <?= $subscriber['wants_news'] ? 'checked' : '' ?>> News</label><button class="btn btn-ghost">Save interests</button></form><?php endif; ?>
          <?php if (!$subscriber['unsubscribed_at']): ?><form method="post" class="workflow-inline-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="subscriber_unsubscribe"><input type="hidden" name="subscriber_id" value="<?= (int)$subscriber['id'] ?>"><button class="btn btn-ghost" data-confirm="Unsubscribe this address from future RCM digests?">Unsubscribe</button></form><?php endif; ?>
        </article>
      <?php endforeach; ?></div><?php else: ?><p class="muted">No listener subscriptions yet.</p><?php endif; ?>
    </section>

    <section class="admin-panel workflow-panel" id="automation-monitor">
      <h2>Automation & digest monitor</h2><p class="admin-panel-intro">cPanel must run the CLI task; the site cannot create or verify your host's cron schedule. Each run below is recorded by the task, with failures shown for follow-up.</p>
      <div class="workflow-list"><?php foreach (['social_sync'=>'Daily social feed sync','news_sync'=>'Daily RSS headline collection','email_digest'=>'Subscriber email digest'] as $jobName => $label): $job = $workflowLastJobs[$jobName] ?? null; $jobOverdue = !$job || time() - strtotime($job['started_at']) > 36 * 3600; $jobLabel = !$job ? 'not recorded' : ($jobOverdue ? 'overdue' : $job['status']); $jobState = !$job ? 'not_synced' : ($jobOverdue || $job['status'] !== 'success' ? 'error' : 'connected'); ?>
        <article class="workflow-item"><div class="workflow-item-heading"><strong><?= e($label) ?></strong><span class="sync-state sync-state-<?= e($jobState) ?>"><?= e($jobLabel) ?></span></div><p><?= $job ? e($job['summary']) . ' · Last run ' . e(date('d M Y H:i', strtotime($job['started_at']))) : 'No recorded run yet.' ?></p></article>
      <?php endforeach; ?></div>
      <p class="source-note">Configure cPanel Cron to run <code>php /home/CPANELUSER/public_html/scripts/sync-social-platforms.php</code> daily. It collects content into the review queue, then sends only currently published items to confirmed subscribers. Confirm the full path and PHP binary with your host.</p>
    </section>

    <section class="admin-panel workflow-panel" id="schema-monitor">
      <h2>Database & sync schema health</h2><p class="admin-panel-intro">Checks for required tables and workflow modules. Missing tables usually mean the matching migration has not been run successfully.</p>
      <div class="workflow-list"><?php foreach ($workflowSchemaReport as $schemaGroup): ?><article class="workflow-item"><div class="workflow-item-heading"><strong><?= e($schemaGroup['group']) ?></strong><span class="sync-state sync-state-<?= $schemaGroup['ok'] ? 'connected' : 'error' ?>"><?= $schemaGroup['ok'] ? 'ready' : 'missing' ?></span></div><?php if (!$schemaGroup['ok']): ?><p>Missing: <?= e(implode(', ', $schemaGroup['missing'])) ?></p><?php endif; ?></article><?php endforeach; ?></div>
    </section>

    <section class="admin-panel workflow-panel" id="credential-monitor">
      <h2>Private credentials & change monitor</h2><p class="admin-panel-intro">Values are never displayed or stored here. To detect when a configured value changes, set a private random <code>RCM_MONITOR_FINGERPRINT_KEY</code> in the server environment. The database stores only keyed fingerprints. Database credentials stay in the private config file and are not fingerprinted by this panel.</p>
      <div class="workflow-list"><?php foreach ($workflowCredentialStatus as $credential): ?><article class="workflow-item"><div class="workflow-item-heading"><strong><?= e($credential['label']) ?></strong><span class="sync-state sync-state-<?= $credential['is_configured'] ? 'connected' : 'not_configured' ?>"><?= $credential['is_configured'] ? 'configured' : 'not configured' ?></span></div><p><?= $credential['change_tracking'] ? 'Change tracking active' : 'Presence only; fingerprint key is not configured' ?> · <?= $credential['last_changed_at'] ? 'Last changed ' . e(date('d M Y H:i', strtotime($credential['last_changed_at']))) : 'No baseline recorded' ?></p></article><?php endforeach; ?></div>
    </section>

    <section class="admin-panel workflow-panel">
      <h2>Recent admin activity</h2><p class="admin-panel-intro">Review decisions and changes are recorded for operational follow-up.</p>
      <?php if ($workflowAudit): ?><div class="workflow-list"><?php foreach ($workflowAudit as $entry): ?><article class="workflow-item"><div class="workflow-item-heading"><strong><?= e($entry['action_name']) ?> · <?= e($entry['entity_type']) ?> <?= e($entry['entity_id']) ?></strong><time><?= e(date('d M Y H:i', strtotime($entry['created_at']))) ?></time></div><p><?= e($entry['actor']) ?><?= $entry['detail'] ? ' · ' . e($entry['detail']) : '' ?></p></article><?php endforeach; ?></div><?php else: ?><p class="muted">No workflow actions recorded yet.</p><?php endif; ?>
    </section>

    <section class="admin-panel admin-updates-panel">
      <h2>Recent shared content</h2>
      <?php if ($latestSocialUpdates): ?>
        <div class="admin-updates-list">
          <?php foreach ($latestSocialUpdates as $update): ?>
            <article class="admin-update-row">
              <div><strong><?= e($update['title']) ?></strong><p><?= e($update['platform']) ?> · <?= e($update['content_type']) ?> · <?= e(date('d M Y H:i', strtotime($update['published_at']))) ?></p></div>
              <span class="news-label"><?= $update['is_published'] ? 'Published' : 'Draft' ?></span>
              <a class="text-link" href="<?= e($update['content_url']) ?>" target="_blank" rel="noopener noreferrer">Open link ↗</a>
                  <form method="post" class="workflow-inline-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="social_update_manage"><input type="hidden" name="item_id" value="<?= (int)$update['id'] ?>"><?php if ($update['is_published']): ?><button name="decision" value="unpublish" class="btn btn-ghost">Unpublish</button><?php else: ?><button name="decision" value="publish" class="btn btn-primary">Publish</button><?php endif; ?><button name="decision" value="delete" class="btn btn-danger" data-confirm="Delete this content link?">Delete</button></form>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="muted">No broadcast or recording links have been published yet.</p>
      <?php endif; ?>
    </section>
  </div>
  <p class="source-note">Social content is added as public links; this workflow does not automatically publish or sync content through platform APIs. Confirm every URL is public and points to the correct RCM content.</p>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
