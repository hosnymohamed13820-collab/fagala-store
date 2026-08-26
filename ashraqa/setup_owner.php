<?php
/**
 * setup_owner.php — إنشاء أول حساب أونر لفرع اشراقة (مرة واحدة بس)
 * =====================================================================
 *
 * ليه الملف ده موجود أصلاً:
 *   الباسورد في النظام متخزّن بـ password_hash() يعني bcrypt، وMySQL
 *   لوحدها مش بتعرف تعمل الهاش ده. فمينفعش تعمل اليوزر من phpMyAdmin.
 *   لازم PHP هي اللي تعمله.
 *
 * 🔒 الحماية: الصفحة دي **بتقفل نفسها**.
 *    لو جدول users فيه أي صف، بترفض تشتغل خالص وبتقولك امسحها.
 *    يعني حتى لو نسيتها على السيرفر، عمرها ما هتعمل يوزر تاني.
 *
 * الاستخدام:
 *   ١) ارفع الملف ده جنب index.php في مجلد اشراقة
 *   ٢) افتحه في المتصفح: https://<السب-دومين>/setup_owner.php
 *   ٣) املا الاسم والباسورد واضغط
 *   ٤) 🔴 امسح الملف من السيرفر بعد ما تخلص
 *
 * ⚠️ الملف ده **مايترفعش على موقع الفجالة** — لاشراقة بس، ومرة واحدة.
 */

require_once __DIR__ . '/config.php';

$done = false;
$err  = '';

// ── الحارس: لو فيه أي مستخدم، الصفحة مقفولة ─────────────────────────
try {
    $existing = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $ex) {
    $existing = -1;
    $err = 'مش قادر أوصل لقاعدة البيانات. اتأكد إن config.php فيها بيانات صح '
         . 'وإن ملف schema_ashraqa.sql اتستورد. رسالة الخطأ: ' . $ex->getMessage();
}

if ($existing > 0) {
    $err = 'فيه مستخدمين موجودين بالفعل (' . $existing . '). الصفحة دي بتشتغل مرة '
         . 'واحدة بس وقفلت نفسها. امسح الملف من السيرفر.';
}

// ── الإنشاء ─────────────────────────────────────────────────────────
if ($existing === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $pass     = (string) ($_POST['password'] ?? '');
    $pass2    = (string) ($_POST['password2'] ?? '');

    if ($username === '' || $fullName === '') {
        $err = 'اسم الدخول والاسم الكامل مايصحّش يكونوا فاضيين.';
    } elseif (!preg_match('/^[A-Za-z0-9_.]{3,60}$/', $username)) {
        $err = 'اسم الدخول لازم يكون حروف إنجليزي وأرقام و_ و. بس، من ٣ لـ٦٠ حرف.';
    } elseif (mb_strlen($pass) < 8) {
        $err = 'الباسورد لازم ٨ حروف على الأقل.';
    } elseif ($pass !== $pass2) {
        $err = 'الباسوردين مش زي بعض.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // دور الأونر — لو موجود نستخدمه، لو لأ نعمله
            $r = $pdo->prepare('SELECT id FROM roles WHERE is_owner = 1 LIMIT 1');
            $r->execute();
            $roleId = (int) $r->fetchColumn();

            if ($roleId === 0) {
                $pdo->prepare(
                    'INSERT INTO roles (name, name_ar, is_owner) VALUES (?, ?, 1)'
                )->execute(['owner', 'المالك']);
                $roleId = (int) $pdo->lastInsertId();
            }

            // اليوزر — الهاش بنفس اللي بيستخدمه النظام في users.php
            $pdo->prepare(
                'INSERT INTO users (username, full_name, password_hash, role_id, branch_id, is_active)
                 VALUES (?, ?, ?, ?, NULL, 1)'
            )->execute([
                $username,
                $fullName,
                password_hash($pass, PASSWORD_DEFAULT),
                $roleId,
            ]);

            $pdo->commit();
            $done = true;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $err = 'حصل خطأ: ' . $ex->getMessage();
        }
    }
}
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تثبيت اشراقة — أول حساب</title>
<style>
  body { font-family: system-ui, "Segoe UI", sans-serif; background:#f4f5f8; margin:0;
         display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px; }
  .box { background:#fff; border-radius:16px; padding:28px; max-width:440px; width:100%;
         box-shadow:0 4px 24px rgba(0,0,0,.07); }
  h1 { font-size:20px; margin:0 0 6px; color:#16233d; }
  p.sub { font-size:13.5px; color:#6b7280; margin:0 0 20px; line-height:1.7; }
  label { display:block; font-size:13px; color:#374151; margin-bottom:5px; font-weight:600; }
  input { width:100%; padding:10px 12px; border:1.5px solid #dfe3ea; border-radius:9px;
          font-family:inherit; font-size:14px; margin-bottom:14px; box-sizing:border-box; }
  input:focus { outline:none; border-color:#7c6ff7; }
  button { width:100%; padding:12px; background:#7c6ff7; color:#fff; border:0; border-radius:10px;
           font-family:inherit; font-size:15px; font-weight:600; cursor:pointer; }
  button:hover { background:#6a5ce6; }
  .msg { padding:13px 15px; border-radius:10px; font-size:13.5px; line-height:1.7; margin-bottom:18px; }
  .err { background:#fdeaea; color:#a32b2b; }
  .ok  { background:#e6efe9; color:#2f5f47; }
  .warn { background:#fff6e0; color:#8a6318; font-size:13px; padding:13px 15px;
          border-radius:10px; margin-top:18px; line-height:1.7; }
  code { background:#f0f1f4; padding:2px 6px; border-radius:4px; font-size:12.5px; direction:ltr;
         display:inline-block; }
</style>
</head>
<body>
<div class="box">

<?php if ($done): ?>
  <h1>تمام ✅</h1>
  <div class="msg ok">
    الحساب اتعمل. تقدر تدخل دلوقتي من <code>login.php</code> بالاسم والباسورد اللي كتبتهم.
  </div>
  <div class="warn">
    🔴 <b>امسح الملف ده من السيرفر دلوقتي.</b><br>
    هو أصلاً قفل نفسه ومش هيعمل يوزر تاني، بس الأنضف إنه يتشال خالص.
  </div>

<?php else: ?>
  <h1>أول حساب — فرع اشراقة</h1>
  <p class="sub">
    الصفحة دي بتعمل حساب المالك الأول عشان تقدر تدخل النظام.
    بتشتغل <b>مرة واحدة بس</b> — بعد كده بتقفل نفسها.
  </p>

  <?php if ($err !== ''): ?>
    <div class="msg err"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <?php if ($existing === 0): ?>
  <form method="post" autocomplete="off">
    <label>اسم الدخول (إنجليزي)</label>
    <input type="text" name="username" required dir="ltr" placeholder="mohamed"
           value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <label>الاسم الكامل</label>
    <input type="text" name="full_name" required placeholder="محمد حسني"
           value="<?= htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <label>الباسورد (٨ حروف على الأقل)</label>
    <input type="password" name="password" required minlength="8">

    <label>الباسورد تاني</label>
    <input type="password" name="password2" required minlength="8">

    <button type="submit">اعمل الحساب</button>
  </form>
  <?php endif; ?>
<?php endif; ?>

</div>
</body>
</html>
