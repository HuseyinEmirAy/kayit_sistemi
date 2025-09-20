<?php
require __DIR__.'/config.php';

$defaultRedirect = 'index.php';

if (!function_exists('sanitize_redirect_target')) {
    function sanitize_redirect_target(?string $value, string $default = 'index.php'): string {
        if ($value === null) {
            return $default;
        }
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($value));
        if ($value === '' || strncmp($value, '//', 2) === 0) {
            return $default;
        }
        $parts = parse_url($value);
        if ($parts === false) {
            return $default;
        }
        if (!empty($parts['scheme']) || !empty($parts['host'])) {
            return $default;
        }
        $path = $parts['path'] ?? '';
        if ($path !== '' && strpos($path, '..') !== false) {
            return $default;
        }
        if ($path !== '' && $path[0] === '/') {
            $path = ltrim($path, '/');
        }
        if ($path === '' && empty($parts['query']) && empty($parts['fragment'])) {
            $path = $default;
        } elseif ($path === '') {
            $path = $default;
        }
        $safe = $path;
        if (!empty($parts['query'])) {
            $safe .= '?' . $parts['query'];
        }
        if (!empty($parts['fragment'])) {
            $safe .= '#' . $parts['fragment'];
        }
        return $safe;
    }
}

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

        // Eğer login'e "next" parametresi ile gelindiyse oraya güvenli şekilde yönlendir
        $next = sanitize_redirect_target($_GET['next'] ?? null, $defaultRedirect);
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
</head>
<body>
<?php render_topbar('login', [
    'title'    => 'Görevli Girişi',
    'subtitle' => 'Yetkili kullanıcı giriş ekranı',
    'links'    => [],
]); ?>

<main class="app-container app-container--narrow app-container--center">
  <section class="card auth-card">
    <div class="card-header">
      <div>
        <h1 class="card-title">Hesabınıza giriş yapın</h1>
        <p class="card-subtitle">Ziyaretçi kayıt paneline erişmek için kimlik doğrulaması yapın.</p>
      </div>
    </div>

    <?php if($error): ?><div class="alert alert--error"><?=htmlspecialchars($error)?></div><?php endif; ?>

    <form method="post" class="stack">
      <div class="field">
        <label for="username">Kullanıcı Adı</label>
        <input type="text" id="username" name="username" required>
      </div>

      <div class="field">
        <label for="password">Şifre</label>
        <input type="password" id="password" name="password" required>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn--primary btn--block">Giriş Yap</button>
      </div>
    </form>

    <div class="form-actions form-actions--center">
      <a class="btn btn--ghost" href="index.php">Ziyaretçi Ekranına Dön</a>
    </div>
  </section>
</main>
</body>
</html>
