<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/news_sync.php';
$pageTitle = 'News & Updates — RCM Radio Media';
$news = get_news($pdo, 100);
$newsFeedStatuses = get_news_feed_status($pdo);
$newsFeedLatest = null;
foreach ($newsFeedStatuses as $feedStatus) {
  if ($feedStatus['last_synced_at'] && (!$newsFeedLatest || $feedStatus['last_synced_at'] > $newsFeedLatest)) $newsFeedLatest = $feedStatus['last_synced_at'];
}
$featuredNews = $news[0] ?? null;
$latestNews = array_slice($news, 1);
$categories = array_values(array_unique(array_filter(array_map(
  static function (array $item): string {
    return trim((string)($item['category'] ?? ''));
  },
  $latestNews
))));
require __DIR__ . '/includes/header.php';
?>
<main class="section-wrap page-content newsroom-page">
  <section class="newsroom-heading" aria-labelledby="newsroom-title">
    <p class="eyebrow">NEWSROOM · KENYA & BEYOND</p>
    <h1 class="page-title" id="newsroom-title">The stories <span>moving us.</span></h1>
    <p class="page-intro">A quick, searchable round-up of fresh headlines from Kenyan and international publishers. Every item is credited and links to the original source.</p>
  </section>

  <?php if ($featuredNews): ?>
    <section class="news-lead" aria-label="Top story">
      <div class="news-lead-copy">
        <div class="news-card-top">
          <span class="news-label"><?= e($featuredNews['category'] ?: 'Top story') ?></span>
          <time class="news-date" datetime="<?= e(date('Y-m-d', strtotime($featuredNews['published_at'] ?: 'now'))) ?>"><?= e(date('d M Y', strtotime($featuredNews['published_at'] ?: 'now'))) ?></time>
        </div>
        <h2 id="news-<?= (int)$featuredNews['id'] ?>"><?= e($featuredNews['title']) ?></h2>
        <p><?= e($featuredNews['summary']) ?></p>
        <?php if ($featuredNews['source_name']): ?><p class="source-by">Source: <?= e($featuredNews['source_name']) ?></p><?php endif; ?>
        <?php if ($featuredNews['source_url']): ?><a class="btn btn-primary" href="<?= e($featuredNews['source_url']) ?>" target="_blank" rel="noopener noreferrer">Read original story <span aria-hidden="true">↗</span></a><?php endif; ?>
      </div>
      <div class="news-lead-art" aria-hidden="true">
        <span class="news-lead-art-kicker"><?= e($featuredNews['category'] ?: 'RCM NEWS') ?></span>
        <span class="news-lead-art-mark">RCM<br><small>NEWSROOM</small></span>
      </div>
    </section>
  <?php endif; ?>

  <section class="latest-news" aria-labelledby="latest-news-title">
    <div class="section-heading">
      <div><p class="eyebrow">KENYA & AROUND THE WORLD</p><h2 id="latest-news-title">Latest <span>headlines.</span></h2></div>
      <p class="heading-note"><?= count($news) ?> <?= count($news) === 1 ? 'story' : 'stories' ?> · newest first</p>
    </div>
    <div class="news-source-bar">
      <span class="news-source-bar-label">Following</span>
      <?php foreach (['Daily Nation','The Standard','Al Jazeera'] as $sourceName): ?><span class="news-source-chip"><?= e($sourceName) ?></span><?php endforeach; ?>
      <?php if ($newsFeedLatest): ?><span class="news-refresh-time">Updated <?= e(date('d M, H:i', strtotime($newsFeedLatest))) ?> · EAT</span><?php else: ?><span class="news-refresh-time">Publisher feeds updating soon</span><?php endif; ?>
    </div>

    <?php if ($news): ?>
      <div class="content-tools">
        <label class="content-search">
          <span class="sr-only">Search news stories</span>
          <input type="search" data-news-search placeholder="Search headlines, topics or sources" autocomplete="off">
          <span aria-hidden="true">⌕</span>
        </label>
        <div class="filter-list" role="group" aria-label="Filter news by category">
          <button type="button" class="filter-chip is-active" data-news-filter="all" aria-pressed="true">All news</button>
          <?php foreach ($categories as $category): ?>
            <button type="button" class="filter-chip" data-news-filter="<?= e(strtolower($category)) ?>" aria-pressed="false"><?= e($category) ?></button>
          <?php endforeach; ?>
        </div>
      </div>

      <p class="filter-status" data-news-status aria-live="polite"><?= count($latestNews) ?> <?= count($latestNews) === 1 ? 'story' : 'stories' ?> shown</p>
      <div class="news-grid news-grid-full" data-news-list>
        <?php foreach ($latestNews as $item): ?>
          <?php $category = trim((string)($item['category'] ?: 'Kenya update')); ?>
          <article class="news-card" id="news-<?= (int)$item['id'] ?>" data-news-item data-category="<?= e(strtolower($category)) ?>" data-search="<?= e(strtolower($item['title'] . ' ' . $item['summary'] . ' ' . $category . ' ' . $item['source_name'])) ?>">
            <div class="news-card-top">
              <span class="news-label"><?= e($category) ?></span>
              <time class="news-date" datetime="<?= e(date('Y-m-d', strtotime($item['published_at'] ?: 'now'))) ?>"><?= e(date('d M Y', strtotime($item['published_at'] ?: 'now'))) ?></time>
            </div>
            <h3><?= e($item['title']) ?></h3>
            <p><?= e($item['summary']) ?></p>
            <?php if ($item['source_name']): ?><p class="source-by">Source: <?= e($item['source_name']) ?></p><?php endif; ?>
            <?php if ($item['source_url']): ?><a class="text-link" href="<?= e($item['source_url']) ?>" target="_blank" rel="noopener noreferrer">Read original story <span aria-hidden="true">↗</span></a><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <?php if (!$news): ?><div class="empty-state"><h2>More stories are on the way.</h2><p>New updates will appear here as publisher feeds are collected.</p></div><?php endif; ?>
      <p class="filter-empty empty-state" data-news-empty hidden>No stories match those filters. Try another category or search term.</p>
    <?php else: ?>
      <div class="empty-state"><h2>Our newsroom is warming up.</h2><p>Stories will appear here once they are added and published in the admin dashboard.</p></div>
    <?php endif; ?>
  </section>
  <p class="source-note">This latest-headlines feed is not a popularity ranking. Brief RSS headlines and descriptions are credited to their publishers and link to the original stories; RCM Radio Media does not republish full articles. Check the original source for updates and context.</p>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
