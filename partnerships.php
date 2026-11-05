<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pageTitle = 'Partners & Advertising — RCM Radio Media';
$promotions = require __DIR__ . '/includes/promotions.php';
try {
  $partnerContent = $pdo->query("SELECT c.id,c.title,c.description,c.mime_type,p.name AS partner_name,p.website_url FROM partner_content c INNER JOIN partners p ON p.id=c.partner_id WHERE c.review_status='approved' AND c.is_published=1 AND p.is_published=1 ORDER BY c.created_at DESC")->fetchAll();
} catch (PDOException $exception) {
  $partnerContent = [];
}
require __DIR__ . '/includes/header.php';
?>
<main class="section-wrap page-content partnerships-page">
  <section class="partnership-hero">
    <p class="eyebrow">PARTNER WITH RCM</p>
    <h1 class="page-title">Good stories make <span>strong partnerships.</span></h1>
    <p class="page-intro">Meet the organisations and campaigns in our community. Explore their offers, then talk to RCM about advertising, sponsorship or a media partnership for your brand.</p>
    <div class="hero-actions">
      <a class="btn btn-primary" href="<?= e(site_url('contact.php?subject=' . rawurlencode('Advertising and partnership enquiry'))) ?>">Discuss advertising <span aria-hidden="true">↗</span></a>
      <a class="btn btn-ghost" href="#partner-promotions">Explore partner promotions</a>
    </div>
  </section>

  <section class="advertising-options" aria-labelledby="advertising-options-title">
    <div class="section-heading">
      <div><p class="eyebrow">GROW WITH US</p><h2 id="advertising-options-title">Make your brand <span>part of the conversation.</span></h2></div>
      <p class="heading-note">Tell us what you are promoting and who you want to reach. We will discuss a suitable campaign and share availability and pricing.</p>
    </div>
    <div class="advertising-options-grid">
      <article class="advertising-option"><span class="advertising-option-number">01</span><h3>On-air advertising</h3><p>Discuss radio spots, sponsored segments or a branded message in the station schedule.</p></article>
      <article class="advertising-option"><span class="advertising-option-number">02</span><h3>Show sponsorship</h3><p>Explore a sponsorship that fits a programme, audience or time slot.</p></article>
      <article class="advertising-option"><span class="advertising-option-number">03</span><h3>Digital promotion</h3><p>Ask about campaign features across RCM’s website and social channels.</p></article>
      <article class="advertising-option"><span class="advertising-option-number">04</span><h3>Event partnerships</h3><p>Talk to us about promoting or collaborating on an event with the RCM community.</p></article>
    </div>
    <p class="advertising-note">Formats, dates, audience information and rates are confirmed directly with RCM for each enquiry; no rate card or reach guarantee is published here.</p>
  </section>

  <section class="partner-promotions" id="partner-promotions" aria-labelledby="partner-promotions-title">
    <div class="section-heading">
      <div><p class="eyebrow">OUR PARTNER COMMUNITY</p><h2 id="partner-promotions-title">Explore partner <span>promotions.</span></h2></div>
      <p class="heading-note">See the details shared by our partners and contact each organisation directly with questions.</p>
    </div>
    <div class="partner-promo-grid">
      <?php foreach ($promotions as $index => $promotion): ?>
        <article class="partner-promo-card" id="partner-<?= e((string)$index) ?>">
          <div class="partner-promo-image">
            <img src="<?= e(asset_url($promotion['image'])) ?>" alt="<?= e($promotion['partner'] . ' — ' . $promotion['campaign']) ?>" loading="lazy">
            <span class="partner-promo-category"><?= e($promotion['category']) ?></span>
          </div>
          <div class="partner-promo-content">
            <p class="partner-promo-name"><?= e($promotion['partner']) ?></p>
            <h3><?= e($promotion['campaign']) ?></h3>
            <p class="partner-promo-description"><?= e($promotion['description']) ?></p>
            <details class="partner-promo-details">
              <summary>Offer details and important notes</summary>
              <ul>
                <?php foreach ($promotion['details'] as $detail): ?><li><?= e($detail) ?></li><?php endforeach; ?>
              </ul>
            </details>
            <div class="partner-promo-actions">
              <a class="btn btn-primary" href="<?= e($promotion['contact_href']) ?>"><?= e($promotion['contact_label']) ?></a>
              <a class="text-link" href="<?= e(site_url('contact.php?subject=' . rawurlencode('Advertising and partnership enquiry: ' . $promotion['partner']))) ?>">Advertise with RCM <span aria-hidden="true">↗</span></a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if ($partnerContent): ?>
      <div class="partner-promo-grid partner-managed-content">
        <?php foreach ($partnerContent as $content): ?>
          <article class="partner-promo-card">
            <div class="partner-promo-content">
              <p class="partner-promo-name"><?= e($content['partner_name']) ?></p>
              <h3><?= e($content['title']) ?></h3>
              <p class="partner-promo-description"><?= nl2br(e($content['description'])) ?></p>
              <?php if (strpos($content['mime_type'], 'image/') === 0): ?>
                <a href="<?= e(site_url('partner-media.php?id=' . (int)$content['id'])) ?>"><img src="<?= e(site_url('partner-media.php?id=' . (int)$content['id'])) ?>" alt="<?= e($content['partner_name'] . ' — ' . $content['title']) ?>" loading="lazy"></a>
              <?php else: ?>
                <a class="btn btn-ghost" href="<?= e(site_url('partner-media.php?id=' . (int)$content['id'])) ?>" target="_blank" rel="noopener noreferrer">Open partner offer (PDF) <span aria-hidden="true">↗</span></a>
              <?php endif; ?>
              <?php if ($content['website_url']): ?><a class="text-link" href="<?= e($content['website_url']) ?>" target="_blank" rel="noopener noreferrer">Visit partner website ↗</a><?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="source-note">Promotional facts and prices are transcribed from partner-supplied artwork, not independently verified. Availability, dates, eligibility, pricing, payment instructions and offer terms can change; confirm them with the organisation before acting.</p>
  </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
