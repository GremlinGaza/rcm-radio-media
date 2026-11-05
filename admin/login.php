<?php
require_once __DIR__ . '/../includes/bootstrap.php';
if (!empty($_SESSION['admin_id'])) { header('Location: dashboard.php'); exit; }
$error='';
if($_SERVER['REQUEST_METHOD']==='POST') { verify_csrf(); $stmt=$pdo->prepare('SELECT id,username,password_hash FROM admins WHERE username=?'); $stmt->execute([trim($_POST['username']??'')]); $user=$stmt->fetch(); if($user && password_verify($_POST['password']??'', $user['password_hash'])) { session_regenerate_id(true); $_SESSION['admin_id']=$user['id']; $_SESSION['admin_username']=$user['username']; header('Location: dashboard.php'); exit; } $error='Invalid username or password.'; }
$pageTitle='Admin Login — RCM Radio Media'; require __DIR__.'/../includes/header.php';
?>
<main class="section-wrap page-content"><p class="eyebrow">SITE MANAGEMENT</p><h1 class="page-title">Admin <span>sign in.</span></h1><?php if($error): ?><p class="notice error"><?= e($error) ?></p><?php endif; ?><form class="contact-form" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Username<input name="username" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="btn btn-primary">Sign in</button></form><p class="source-note">Create the first admin account using the setup instructions in README.md. Delete setup_admin.php after use.</p></main>
<?php require __DIR__.'/../includes/footer.php'; ?>