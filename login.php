<?php
require __DIR__.'/config.php';

$error = '';
if ($_SERVER['REQUEST_METHOD']==='POST'){
    $u = trim($_POST['username'] ?? '');
    $p = trim($_POST['password'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username=?");
    $stmt->execute([$u]);
    $row = $stmt->fetch();

    if ($row && password_verify($p, $row['password_hash'])){
        $_SESSION['uid']    = $row['id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['role']   = $row['role'] ?? 'user';

        // Eğer login'e "next" parametresi ile gelindiyse oraya yönlendir
        $next = $_GET['next'] ?? 'index.php';
        header('Location: '.$next);
        exit;
    } else {
        $error = 'Kullanıcı adı veya şifre hatalı.';
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Görevli Girişi</title>
<link rel="stylesheet" href="assets/style.css">
<style>
body{background:#f9fafb;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
.container.small{max-width:360px;width:100%;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;box-shadow:0 6px 20px rgba(0,0,0,.08)}
.logo-wrap{text-align:center;margin-bottom:12px}
.logo-wrap img{max-height:60px}
h2{text-align:center;margin:12px 0 20px 0}
form label{display:block;font-weight:600;margin-bottom:6px;font-size:14px}
form input{width:100%;height:40px;border:1px solid #d1d5db;border-radius:8px;padding:0 10px;margin-bottom:14px}
button{width:100%}
</style>
</head>
<body>
<div class="container small">
  <div class="logo-wrap">
    <img src="assets/logo.png" alt="Logo" onerror="this.style.display='none'">
  </div>

  <h2>Görevli Girişi</h2>

  <?php if($error): ?><div class="alert"><?=$error?></div><?php endif; ?>

  <form method="post">
    <label>Kullanıcı Adı</label>
    <input type="text" name="username" required>

    <label>Şifre</label>
    <input type="password" name="password" required>

    <button type="submit" class="button">Giriş</button>
  </form>

  <div style="text-align:center;margin-top:10px;">
    <a class="button ghost" href="index.php">Ziyaretçi Ekranına Dön</a>
  </div>
</div>
</body>
</html>
