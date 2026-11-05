<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="RCM Radio Media — music, conversations, culture and stories from Kenya.">
  <meta name="theme-color" content="#edf5ff">
  <title><?= e($pageTitle ?? 'RCM Radio Media') ?></title>
  <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css?v=20261104-1')) ?>"><script src="<?= e(asset_url('assets/js/app.js')) ?>" defer></script>
</head>
<body>
<header class="site-header"><div class="header-inner section-wrap">
  <a class="brand" href="<?= e(site_url('index.php')) ?>" aria-label="RCM Radio Media home"><img class="brand-logo" src="<?= e(asset_url('assets/images/rcm-radio-logo.png')) ?>" alt="RCM Radio Media"></a>
  <button class="menu-toggle" id="menuToggle" aria-label="Open navigation" aria-expanded="false">☰</button>
  <nav class="main-nav" id="mainNav" aria-label="Main navigation"><a href="<?= e(site_url('programs.php')) ?>">Shows</a><a href="<?= e(site_url('programs.php')) ?>">Programs</a><a href="<?= e(site_url('news.php')) ?>">News</a><a href="<?= e(site_url('partnerships.php')) ?>">Partners</a><a href="<?= e(site_url('index.php#social')) ?>">Social</a></nav>
  <a class="header-live" href="<?= e(site_url('index.php#listen')) ?>"><span class="live-dot"></span> Listen live</a>
</div></header>