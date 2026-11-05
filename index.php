<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/social_sync.php';
require_once __DIR__ . '/includes/news_sync.php';
$pageTitle = 'RCM Radio Media — Your Sound, Your Stories';
$settings = get_settings($pdo);
$programs = get_programs($pdo, true);
$schedulePrograms = get_programs($pdo);
$promotions = require __DIR__ . '/includes/promotions.php';
$news = get_news($pdo, 6);
$newsFeedStatuses = get_news_feed_status($pdo);
$newsFeedLatest = null;
foreach ($newsFeedStatuses as $feedStatus) {
  if ($feedStatus['last_synced_at'] && (!$newsFeedLatest || $feedStatus['last_synced_at'] > $newsFeedLatest)) $newsFeedLatest = $feedStatus['last_synced_at'];
}
$socials = get_socials($pdo);
$socialUpdates = get_social_updates($pdo, 6);
$allSocialUpdates = get_social_updates($pdo, null);
$socialSyncStatuses = get_social_sync_status($pdo);
$socialSyncLatest = null;
foreach ($socialSyncStatuses as $syncStatus) {
  if ($syncStatus['last_synced_at'] && (!$socialSyncLatest || $syncStatus['last_synced_at'] > $socialSyncLatest)) $socialSyncLatest = $syncStatus['last_synced_at'];
}
require __DIR__ . '/includes/header.php';
?>
<main>
  <section class="hero section-wrap" id="home">
    <div class="hero-copy">
      <p class="eyebrow"><span class="live-dot"></span> LIVE FROM KENYA</p>
      <h1>Your sound.<br><span>Your stories.</span></h1>
      <p class="hero-text">Music, conversations, culture and the stories moving Kenya. This is RCM Radio Media.</p>
      <div class="hero-actions">
        <a class="btn btn-primary" href="#listen"><span aria-hidden="true">▶</span> Listen live</a>
        <a class="btn btn-ghost" href="#programs">Explore shows <span aria-hidden="true">↗</span></a>
      </div>
      <div class="hero-meta"><span class="signal-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span> Radio that moves with you</div>
    </div>
    <div class="hero-art" aria-label="RCM Radio Media visual">
      <div class="art-orbit orbit-one"></div><div class="art-orbit orbit-two"></div>
      <div class="art-disc"><div class="disc-label">RCM<br><small>ON AIR</small></div></div>
      <div class="art-tag tag-top">LIVE · 24/7</div><div class="art-tag tag-bottom">KENYA, TUNE IN.</div>
      <div class="art-spark spark-one">✳</div><div class="art-spark spark-two">✦</div>
    </div>
  </section>

  <section class="listen-section" id="listen">
    <div class="section-wrap listen-grid">
      <div><p class="eyebrow">THE STATION</p><h2>Turn it up.<br><span>You're home.</span></h2><p class="muted">Join the conversation, wherever you are.</p></div>
      <div class="player-card">
        <div class="player-top"><div class="station-mark"><img src="<?= e(asset_url('assets/images/rcm-radio-logo.png')) ?>" alt="" aria-hidden="true"></div><div><p class="player-kicker">RCM RADIO MEDIA</p><h3>Live broadcast</h3><p class="muted small">Your daily soundtrack</p></div><span class="live-pill<?= empty($settings['stream_url']) ? ' stream-not-configured' : '' ?>"><i></i><?= empty($settings['stream_url']) ? 'SETUP NEEDED' : 'STREAM READY' ?></span></div>
        <audio id="liveAudio" preload="none" src="<?= e($settings['stream_url'] ?? '') ?>"></audio>
        <div class="player-controls"><button id="playLive" class="play-button" aria-label="Play live radio">▶</button><div class="player-track"><div class="track-line"><span></span></div><div class="track-labels"><span id="playerStatus">Ready to connect</span><span>LIVE STREAM</span></div></div><button id="muteLive" class="icon-button" aria-label="Mute audio">◖))</button></div>
        <p id="playerMessage" class="player-message"><?= empty($settings['stream_url']) ? 'The stream is not connected yet. Add the direct station audio URL in Admin → Station settings to enable live listening.' : 'Press play to listen to the live RCM broadcast.' ?></p>
      </div>
    </div>
    <div class="section-wrap on-air-dashboard" id="on-air">
      <section class="on-air-schedule" aria-labelledby="on-air-title">
        <div class="on-air-heading">
          <div><p class="eyebrow">ON-AIR GUIDE</p><h2 id="on-air-title">What’s on <span>RCM?</span></h2></div>
          <p class="on-air-clock"><span aria-hidden="true">◷</span> Nairobi time <time data-nairobi-clock>--:--</time></p>
        </div>
        <div class="now-next-grid" aria-live="polite">
          <article class="now-show-card">
            <p class="now-show-kicker"><span class="live-dot"></span> ON AIR NOW</p>
            <h3 data-current-show>Checking today’s schedule…</h3>
            <p data-current-schedule>Weekday and weekend programmes · East Africa Time</p>
          </article>
          <article class="next-show-card">
            <p class="next-show-kicker">COMING UP NEXT</p>
            <h3 data-next-show>Finding the next show…</h3>
            <p data-next-schedule></p>
          </article>
        </div>
        <div class="schedule-toolbar">
          <h3>Weekly schedule</h3>
          <div class="schedule-tabs" role="group" aria-label="Choose a programme schedule">
            <button type="button" class="schedule-tab is-active" data-schedule-filter="all" aria-pressed="true">All shows</button>
            <button type="button" class="schedule-tab" data-schedule-filter="weekday shows" aria-pressed="false">Weekdays</button>
            <button type="button" class="schedule-tab" data-schedule-filter="weekend shows" aria-pressed="false">Weekends</button>
          </div>
        </div>
        <div class="on-air-list">
          <?php foreach ($schedulePrograms as $program): ?>
            <article class="on-air-row" data-on-air-row data-category="<?= e(strtolower($program['category'] ?: '')) ?>" data-title="<?= e($program['title']) ?>" data-schedule="<?= e($program['schedule'] ?: '') ?>">
              <span class="on-air-row-indicator" aria-hidden="true"></span>
              <div class="on-air-row-main"><strong><?= e($program['title']) ?></strong><span><?= e($program['description'] ?: $program['category']) ?></span></div>
              <span class="on-air-row-time"><?= e($program['schedule'] ?: 'Schedule to be announced') ?></span>
              <a class="on-air-row-link" href="<?= e(site_url('programs.php')) ?>" aria-label="More about <?= e($program['title']) ?>">Details <span aria-hidden="true">↗</span></a>
            </article>
          <?php endforeach; ?>
          <?php if (!$schedulePrograms): ?><p class="muted">Program times will appear here when the lineup is published.</p><?php endif; ?>
        </div>
      </section>

      <aside class="on-air-updates" aria-labelledby="on-air-updates-title">
        <div class="on-air-heading">
          <div><p class="eyebrow">FROM OUR CHANNELS</p><h2 id="on-air-updates-title">Catch up <span>on RCM.</span></h2></div>
          <a class="text-link" href="#follow-ups">All updates <span aria-hidden="true">↗</span></a>
        </div>
        <?php if ($socialUpdates): ?>
          <div class="on-air-update-list">
            <?php foreach (array_slice($socialUpdates, 0, 3) as $update): ?>
              <article class="on-air-update">
                <p><?= e($update['platform']) ?> · <?= e($update['content_type']) ?></p>
                <h3><?= e($update['title']) ?></h3>
                <a href="<?= e($update['content_url']) ?>" target="_blank" rel="noopener noreferrer">Watch / listen <span aria-hidden="true">↗</span></a>
              </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="on-air-updates-empty"><span class="updates-play-icon" aria-hidden="true">▶</span><h3>Replays and clips, all in one place.</h3><p>After a broadcast, the RCM team can add the recording link in Admin. New episodes and clips will appear here.</p></div>
        <?php endif; ?>
        <div class="on-air-follow-links">
          <strong>Follow RCM</strong>
          <?php $hasOnAirSocial = false; foreach ($socials as $social): if (!$social['url']) continue; $hasOnAirSocial = true; ?>
            <a href="<?= e($social['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($social['platform']) ?> <span aria-hidden="true">↗</span></a>
          <?php endforeach; ?>
          <?php if (!$hasOnAirSocial): ?><span class="muted">Channel links coming soon</span><?php endif; ?>
        </div>
      </aside>
    </div>
  </section>

  <section class="section-wrap section-block" id="shows"><span id="programs" aria-hidden="true"></span>
    <div class="section-heading"><div><p class="eyebrow">ON AIR & ON DEMAND</p><h2>Find your <span>frequency.</span></h2></div><a class="text-link" href="<?= e(site_url('programs.php')) ?>">All programs <span>↗</span></a></div>
    <div class="media-grid">
      <?php if ($programs): foreach (array_slice($programs, 0, 3) as $i => $program): ?>
      <article class="media-card">
        <a class="media-image image-tone-<?= $i + 1 ?><?= !empty($program['image_url']) ? ' has-program-poster' : '' ?>" href="<?= e(site_url('programs.php')) ?>" aria-label="View <?= e($program['title']) ?>">
          <?php if (!empty($program['image_url'])): ?><img src="<?= e(program_image_url($program['image_url'])) ?>" alt="<?= e($program['title']) ?> promotional poster" loading="lazy"><?php else: ?><span class="image-index">0<?= $i + 1 ?></span><span class="image-word"><?= e($program['category'] ?: 'RCM ORIGINAL') ?></span><?php endif; ?>
          <span class="image-play">▶</span>
        </a>
        <div class="media-card-copy"><p class="card-category"><?= e($program['category'] ?: 'RCM ORIGINAL') ?></p><h3><?= e($program['title']) ?></h3><?php if (!empty($program['schedule'])): ?><p class="program-schedule"><span aria-hidden="true">◷</span> <?= e($program['schedule']) ?></p><?php endif; ?><p><?= e($program['description']) ?></p><a class="text-link" href="<?= e(site_url('programs.php')) ?>">Discover show <span>↗</span></a></div>
      </article>
      <?php endforeach; else: ?>
      <article class="media-card"><div class="media-image image-tone-1"><span class="image-index">01</span><span class="image-word">YOUR SHOW HERE</span></div><div class="media-card-copy"><p class="card-category">COMING SOON</p><h3>The RCM Line-up</h3><p>Shows, podcasts and voices you love. Your lineup can be added from the admin area.</p><a class="text-link" href="<?= e(site_url('programs.php')) ?>">Explore programs ↗</a></div></article>
      <article class="media-card"><div class="media-image image-tone-2"><span class="image-index">02</span><span class="image-word">GOOD VIBRATIONS</span></div><div class="media-card-copy"><p class="card-category">MUSIC</p><h3>Soundtrack Kenya</h3><p>Fresh sounds, familiar favourites and new discoveries.</p><a class="text-link" href="<?= e(site_url('programs.php')) ?>">Explore programs ↗</a></div></article>
      <article class="media-card"><div class="media-image image-tone-3"><span class="image-index">03</span><span class="image-word">REAL TALK</span></div><div class="media-card-copy"><p class="card-category">TALK & CULTURE</p><h3>Beyond the Mic</h3><p>Conversations and perspectives from across our community.</p><a class="text-link" href="<?= e(site_url('programs.php')) ?>">Explore programs ↗</a></div></article>
      <?php endif; ?>
    </div>
  </section>

  <section class="news-section" id="news">
    <div class="section-wrap section-block">
      <div class="section-heading"><div><p class="eyebrow">KENYA & BEYOND · LATEST HEADLINES</p><h2>Stories, <span>in the know.</span></h2></div><a class="text-link" href="news.php">All news <span>↗</span></a></div>
      <div class="news-grid">
        <?php if ($news): foreach ($news as $item): ?>
          <article class="news-card" id="news-<?= (int)$item['id'] ?>"><div class="news-card-top"><span class="news-label"><?= e($item['category'] ?: 'KENYA UPDATE') ?></span><span class="news-date"><?= e(date('d M Y', strtotime($item['published_at'] ?: 'now'))) ?></span></div><h3><?= e($item['title']) ?></h3><p><?= e($item['summary']) ?></p><?php if (!empty($item['source_name'])): ?><p class="source-by">Source: <?= e($item['source_name']) ?></p><?php endif; ?><?php if (!empty($item['source_url'])): ?><a class="text-link" href="<?= e($item['source_url']) ?>" target="_blank" rel="noopener noreferrer">Read original story <span>↗</span></a><?php endif; ?></article>
        <?php endforeach; else: ?><article class="news-card"><span class="news-label">EDITOR'S DESK</span><h3>Stories that matter to Kenya</h3><p>Our news desk will bring you sourced updates, culture, community and trending conversations. Add verified stories through the database.</p><a class="text-link" href="news.php">Visit news desk ↗</a></article><?php endif; ?>
      </div>
      <p class="source-note">Headlines from Daily Nation, The Standard and Al Jazeera link to their original publishers. <?= $newsFeedLatest ? 'Feeds last checked ' . e(date('d M Y, H:i', strtotime($newsFeedLatest))) . ' EAT.' : 'Publisher feeds will display their last-checked time after the first refresh.' ?> This is a latest-headlines roundup, not a popularity ranking.</p>
    </div>
  </section>

  <section class="section-wrap notification-promo" aria-labelledby="notification-promo-title">
    <div><p class="eyebrow">YOUR RCM INBOX</p><h2 id="notification-promo-title">Get the shows and stories <span>you care about.</span></h2><p>Choose program announcements, a daily Kenya and world headline round-up, or both. Every email brings you back to the full story on RCM.</p></div>
    <a class="btn btn-primary" href="<?= e(site_url('notifications.php')) ?>">Choose email updates <span aria-hidden="true">↗</span></a>
  </section>

  <section class="section-wrap section-block" id="partners">
    <div class="section-heading"><div><p class="eyebrow">BETTER TOGETHER</p><h2>Good people. <span>Big energy.</span></h2></div><a class="text-link" href="<?= e(site_url('partnerships.php')) ?>">Partner with RCM <span>↗</span></a></div>
    <div class="partner-promo-grid partner-promo-grid-home">
      <?php foreach (array_slice($promotions, 0, 4) as $promotion): ?>
        <a class="partner-promo-teaser" href="<?= e(site_url('partnerships.php')) ?>">
          <img src="<?= e(asset_url($promotion['image'])) ?>" alt="" loading="lazy">
          <span class="partner-promo-teaser-copy"><span><?= e($promotion['partner']) ?></span><b><?= e($promotion['campaign']) ?></b><span class="partner-teaser-link">Explore promotion ↗</span></span>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="partnership-callout"><div><p class="eyebrow">PROMOTE YOUR BRAND</p><h3>Let’s build a partnership that fits.</h3><p>Explore advertising options, partner promotions and ways to work with RCM.</p></div><a class="btn btn-primary" href="<?= e(site_url('partnerships.php')) ?>">Explore partnerships <span aria-hidden="true">↗</span></a></div>
  </section>

  <section class="news-section social-updates-section" id="follow-ups">
    <div class="section-wrap section-block">
      <div class="section-heading"><div><p class="eyebrow">RCM CONTENT HUB</p><h2>Shows, podcasts & <span>platform news.</span></h2><p class="muted">Browse recordings and updates shared on RCM’s social channels.</p></div><a class="text-link" href="#social">Follow RCM online <span>↗</span></a></div>
      <div class="social-feed-toolbar">
        <label class="social-feed-search-label" for="socialFeedSearch">Search the RCM feed</label>
        <input id="socialFeedSearch" class="social-feed-search" type="search" placeholder="Search shows, clips or news…" autocomplete="off" data-social-feed-search>
        <div class="social-feed-filters" role="group" aria-label="Filter by content type">
          <button type="button" class="schedule-tab is-active" data-social-kind-filter="all" aria-pressed="true">Everything</button>
          <button type="button" class="schedule-tab" data-social-kind-filter="video" aria-pressed="false">Shows & podcasts</button>
          <button type="button" class="schedule-tab" data-social-kind-filter="news" aria-pressed="false">Platform news</button>
        </div>
        <div class="social-feed-filters social-feed-platforms" role="group" aria-label="Filter by platform">
          <button type="button" class="schedule-tab is-active" data-social-platform-filter="all" aria-pressed="true">All platforms</button>
          <?php foreach (['YouTube','TikTok','Facebook','Instagram'] as $platform): ?>
            <button type="button" class="schedule-tab" data-social-platform-filter="<?= e(strtolower($platform)) ?>" aria-pressed="false"><?= e($platform) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <p class="social-feed-status" data-social-feed-status aria-live="polite"><?= count($allSocialUpdates) ?> <?= count($allSocialUpdates) === 1 ? 'item' : 'items' ?> in the RCM feed</p>
      <?php if ($socialSyncLatest): ?><p class="social-feed-sync-note">Latest platform sync: <?= e(date('d M Y, H:i', strtotime($socialSyncLatest))) ?> · East Africa Time</p><?php else: ?><p class="social-feed-sync-note">Platform sync is being set up. Follow the links above for the latest posts.</p><?php endif; ?>
      <?php if ($allSocialUpdates): ?>
        <div class="news-grid social-update-grid" data-social-feed-grid>
          <?php foreach ($allSocialUpdates as $update): $isPlatformNews = strtolower($update['content_type']) === 'news update'; ?>
            <article class="social-update-card" data-social-feed-item data-kind="<?= $isPlatformNews ? 'news' : 'video' ?>" data-platform="<?= e(strtolower($update['platform'])) ?>" data-search="<?= e(strtolower(implode(' ', [$update['title'], $update['summary'], $update['platform'], $update['content_type']]))) ?>">
              <div class="news-card-top"><span class="news-label"><?= e($update['content_type']) ?></span><span class="news-date"><?= e(date('d M Y', strtotime($update['published_at'] ?: 'now'))) ?></span></div>
              <p class="social-update-platform"><?= e($update['platform']) ?> · RCM Radio Media</p>
              <h3><?= e($update['title']) ?></h3>
              <?php if (!empty($update['summary'])): ?><p><?= e($update['summary']) ?></p><?php endif; ?>
              <a class="text-link" href="<?= e($update['content_url']) ?>" target="_blank" rel="noopener noreferrer"><?= $isPlatformNews ? 'Read on ' : 'Watch / listen on ' ?><?= e($update['platform']) ?> <span>↗</span></a>
            </article>
          <?php endforeach; ?>
        </div>
        <div class="empty-state social-updates-empty" data-social-feed-empty hidden><h2>No matching posts yet.</h2><p>Try another platform or content filter, or clear your search.</p></div>
      <?php else: ?>
        <div class="empty-state social-updates-empty"><h2>New recordings and updates will show here.</h2><p>The RCM team can add published show and podcast recordings, clips, and news links from YouTube, TikTok, Facebook, or Instagram in the admin dashboard.</p></div>
      <?php endif; ?>
      <p class="source-note">Content links are added by the RCM team and open on their original platforms. The feed does not automatically sync from social accounts.</p>
    </div>
  </section>

  <section class="social-band" id="social">
    <div class="section-wrap social-inner">
      <div><p class="eyebrow">KEEP THE CONNECTION</p><h2>Follow the <span>frequency.</span></h2><p>Follow RCM for live announcements, show clips and new recordings. Your favourite platform links are managed from the admin dashboard.</p></div>
      <div class="social-links">
        <?php $hasSocialLinks = false; foreach ($socials as $social): if (!$social['url']) continue; $hasSocialLinks = true; ?>
          <a href="<?= e($social['url']) ?>" target="_blank" rel="noopener noreferrer"><span><?= e($social['platform']) ?></span><span>↗</span></a>
        <?php endforeach; ?>
        <?php if (!$hasSocialLinks): ?><p>Official social channels will be linked here soon.</p><?php endif; ?>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>