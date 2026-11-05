<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pageTitle='Contact RCM Radio Media';
$success=''; $error=''; $subject=trim($_GET['subject'] ?? '');
if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf();
  if (($_POST['form_type'] ?? '') === 'listener_comment') {
    $name=trim($_POST['comment_name'] ?? ''); $comment=trim($_POST['comment_text'] ?? '');
    if (!empty($_POST['company_website'])) {
      $success='Thanks for sharing your comment. It will appear after review.';
    } elseif ($name==='' || strlen($name)>100 || $comment==='' || strlen($comment)>2000) {
      $error='Add your name and a comment of up to 2,000 characters.';
    } elseif (isset($_SESSION['last_comment_at']) && time()-(int)$_SESSION['last_comment_at']<60) {
      $error='Please wait a minute before sending another comment.';
    } else {
      try {
        $stmt=$pdo->prepare("INSERT INTO listener_comments (display_name,comment_text,status) VALUES (?,?, 'pending')");
        $stmt->execute([$name,$comment]); $_SESSION['last_comment_at']=time(); $success='Thanks for sharing your comment. It will appear after review.';
      } catch (PDOException $exception) {
        $error='Listener comments are temporarily unavailable. Please try again later.';
      }
    }
  } else {
    $name=trim($_POST['name'] ?? ''); $email=trim($_POST['email'] ?? ''); $subject=trim($_POST['subject'] ?? ''); $message=trim($_POST['message'] ?? '');
    if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || $message==='') $error='Please enter your name, a valid email and a message.';
    else { $stmt=$pdo->prepare('INSERT INTO contact_messages (name,email,subject,message) VALUES (?,?,?,?)'); $stmt->execute([$name,$email,$subject,$message]); $success='Thanks for reaching out. Your message has been received.'; }
  }
}
try {
  $approvedComments=$pdo->query("SELECT display_name,comment_text,created_at FROM listener_comments WHERE status='approved' ORDER BY id DESC LIMIT 10")->fetchAll();
} catch (PDOException $exception) {
  $approvedComments=[];
}
require __DIR__ . '/includes/header.php';
?>
<main class="section-wrap page-content"><p class="eyebrow">SAY HELLO</p><h1 class="page-title">Let's make <span>some noise.</span></h1><p class="page-intro">Send us a story, show proposal or partnership enquiry.</p>
<?php if($success): ?><p class="notice success"><?= e($success) ?></p><?php endif; ?><?php if($error): ?><p class="notice error"><?= e($error) ?></p><?php endif; ?>
<form class="contact-form" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Your name<input name="name" required maxlength="120"></label><label>Email address<input type="email" name="email" required maxlength="190"></label><label>Subject<input name="subject" value="<?= e($subject) ?>" maxlength="180"></label><label>Message<textarea name="message" rows="6" required maxlength="5000"></textarea></label><button class="btn btn-primary" type="submit">Send message ↗</button></form>
<section class="listener-comments" aria-labelledby="listener-comments-title"><h2 id="listener-comments-title">Listener comments</h2><p>Comments are reviewed by the RCM team before they appear publicly. Please do not include private information.</p>
<?php if($approvedComments): ?><div class="workflow-list"><?php foreach($approvedComments as $comment): ?><article class="workflow-item"><strong><?= e($comment['display_name']) ?></strong><time><?= e(date('d M Y',strtotime($comment['created_at']))) ?></time><p><?= nl2br(e($comment['comment_text'])) ?></p></article><?php endforeach; ?></div><?php else: ?><p class="muted">No comments have been published yet.</p><?php endif; ?>
<form class="contact-form compact" method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="form_type" value="listener_comment"><label>Your name<input name="comment_name" required maxlength="100"></label><label>Your comment<textarea name="comment_text" rows="4" required maxlength="2000"></textarea></label><label class="sr-only" aria-hidden="true">Leave this field empty<input name="company_website" tabindex="-1" autocomplete="off"></label><button class="btn btn-primary" type="submit">Submit for review</button></form>
</section></main>
<?php require __DIR__ . '/includes/footer.php'; ?>