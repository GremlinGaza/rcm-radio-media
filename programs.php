<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pageTitle = 'Shows, Programs & Podcasts — RCM Radio Media';
$programs = get_programs($pdo);
$categories = array_values(array_unique(array_filter(array_map(
  static function (array $program): string {
    return trim((string)($program['category'] ?? ''));
  },
  $programs
))));
require __DIR__ . '/includes/header.php';
?>
<main class="section-wrap page-content programs-page">
  <section class="programs-heading" aria-labelledby="programs-title">
    <p class="eyebrow">THE RCM LINE-UP</p>
    <h1 class="page-title" id="programs-title">A show for <span>every mood.</span></h1>
    <p class="page-intro">Meet the hosts, discover new voices and find the shows that fit your day. Browse the official weekday and weekend lineup with artwork supplied for selected shows.</p>
  </section>

  <?php if ($programs): ?>
    <section aria-labelledby="all-programs-title">
      <div class="section-heading">
        <div><p class="eyebrow">ON AIR & ON DEMAND</p><h2 id="all-programs-title">Explore the <span>line-up.</span></h2></div>
        <p class="heading-note"><?= count($programs) ?> <?= count($programs) === 1 ? 'show' : 'shows' ?> to discover</p>
      </div>
      <div class="content-tools">
        <label class="content-search">
          <span class="sr-only">Search shows and programs</span>
          <input type="search" data-program-search placeholder="Search shows, topics or schedules" autocomplete="off">
          <span aria-hidden="true">⌕</span>
        </label>
        <div class="filter-list" role="group" aria-label="Filter shows by category">
          <button type="button" class="filter-chip is-active" data-program-filter="all" aria-pressed="true">All shows</button>
          <?php foreach ($categories as $category): ?>
            <button type="button" class="filter-chip" data-program-filter="<?= e(strtolower($category)) ?>" aria-pressed="false"><?= e($category) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <p class="filter-status" data-program-status aria-live="polite"><?= count($programs) ?> <?= count($programs) === 1 ? 'show' : 'shows' ?> shown</p>
      <p class="program-poster-note">Poster artwork is shown for selected programmes. Where poster times differ, the current RCM schedule and presenters are listed on each show card below.</p>
      <?php foreach ($categories as $category): ?>
        <?php
          $categoryPrograms = array_values(array_filter($programs, static function (array $program) use ($category): bool {
            return trim((string)($program['category'] ?: 'RCM Original')) === $category;
          }));
        ?>
        <section class="program-category-section" data-program-group="<?= e(strtolower($category)) ?>" aria-label="<?= e($category) ?>">
          <div class="program-category-heading">
            <h3><?= e($category) ?></h3>
            <span><?= count($categoryPrograms) ?> <?= count($categoryPrograms) === 1 ? 'show' : 'shows' ?></span>
          </div>
          <div class="programs-grid" data-program-list>
            <?php foreach ($categoryPrograms as $i => $program): ?>
              <?php $searchText = strtolower($program['title'] . ' ' . $program['description'] . ' ' . $category . ' ' . $program['schedule']); ?>
              <article class="media-card program-card" id="program-<?= (int)$program['id'] ?>" data-program-item data-category="<?= e(strtolower($category)) ?>" data-search="<?= e($searchText) ?>">
                <div class="media-image image-tone-<?= ($i % 3) + 1 ?><?= $program['image_url'] ? ' has-program-poster' : '' ?>">
                  <?php if ($program['image_url']): ?>
                    <img src="<?= e(program_image_url($program['image_url'])) ?>" alt="<?= e($program['title']) ?> promotional poster" loading="lazy">
                  <?php else: ?>
                    <span class="image-index"><?= sprintf('%02d', $i + 1) ?></span>
                    <span class="image-word"><?= e($program['title']) ?></span>
                  <?php endif; ?>
                  <?php if ($program['media_url']): ?><a class="image-play" href="<?= e($program['media_url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="Listen to or watch <?= e($program['title']) ?>">▶</a><?php endif; ?>
                </div>
                <div class="media-card-copy">
                  <p class="card-category"><?= e($category) ?></p>
                  <h3><?= e($program['title']) ?></h3>
                  <?php if ($program['schedule']): ?><p class="program-schedule"><span aria-hidden="true">◷</span> <?= e($program['schedule']) ?></p><?php endif; ?>
                  <p><?= e($program['description']) ?></p>
                  <?php if ($program['media_url']): ?><a class="text-link" href="<?= e($program['media_url']) ?>" target="_blank" rel="noopener noreferrer">Listen / watch <span aria-hidden="true">↗</span></a><?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
      <p class="filter-empty empty-state" data-program-empty hidden>No shows match those filters. Try another category or search term.</p>
    </section>
  <?php else: ?>
    <div class="empty-state"><h2>Your line-up is on the way.</h2><p>Shows will appear here after they are added and published in the admin dashboard. Add the confirmed titles, descriptions, schedules and artwork when ready.</p></div>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
