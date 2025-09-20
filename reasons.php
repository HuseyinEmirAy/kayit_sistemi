<?php
require __DIR__.'/config.php';
if (empty($_SESSION['uid'])) { header('Location: login.php'); exit; }

if ($_SERVER['REQUEST_METHOD']==='POST'){
    $action = $_POST['action'] ?? '';
    if ($action==='add'){
        $name = trim($_POST['name'] ?? '');
        if ($name){
            $stmt = $pdo->prepare("INSERT INTO reasons(name, active) VALUES(?,1)");
            $stmt->execute([$name]);
            header("Location: reasons.php?ok=1"); exit;
        }
    } elseif ($action==='toggle'){
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE reasons SET active = 1-active WHERE id=?")->execute([$id]);
        header("Location: reasons.php"); exit;
    } elseif ($action==='delete'){
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM reasons WHERE id=?")->execute([$id]);
        header("Location: reasons.php"); exit;
    }
}

$q = $_GET['q'] ?? '';
$sql = "SELECT * FROM reasons";
$params = [];
if ($q){
    $sql .= " WHERE name LIKE ?";
    $like = "%$q%";
    $params = [$like];
}
$sql .= " ORDER BY active DESC, name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$totalReasons = count($rows);
$activeReasons = 0;
foreach ($rows as $r) {
    if (!empty($r['active'])) {
        $activeReasons++;
    }
}
$flashSuccess = isset($_GET['ok']) ? 'Yeni ziyaret nedeni başarıyla eklendi.' : '';

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ziyaret Nedenleri</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php
  render_topbar('reasons', [
      'title'    => 'Ziyaret Nedenleri',
      'subtitle' => 'Ziyaretçi formunda gösterilecek ziyaret nedenlerini yönetin.',
      'chips'    => [
          ['icon' => '📝', 'label' => 'Toplam', 'value' => $totalReasons],
          ['icon' => '✅', 'label' => 'Aktif', 'value' => $activeReasons],
      ],
  ]);
?>

<main class="app-container">
  <?php if($flashSuccess): ?><div class="alert alert--success"><?=htmlspecialchars($flashSuccess)?></div><?php endif; ?>

  <section class="card">
    <div class="card-header">
      <div>
        <h1 class="card-title">Yeni Ziyaret Nedeni</h1>
        <p class="card-subtitle">Ziyaretçiler tarafından seçilebilecek yeni bir ziyaret nedeni ekleyin.</p>
      </div>
    </div>

    <form method="post" class="form-grid">
      <div class="field">
        <label for="reason_name">Neden Adı</label>
        <input type="text" id="reason_name" name="name" required>
      </div>
      <input type="hidden" name="action" value="add">
      <div class="form-actions">
        <button type="submit" class="btn btn--primary">Ekle</button>
      </div>
    </form>
  </section>

  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Kayıtlı Nedenler</h2>
        <p class="card-subtitle">Formda listelenen tüm ziyaret nedenleri.</p>
      </div>
      <form method="get" class="search-bar">
        <input type="text" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Neden ara">
        <button type="submit" class="btn btn--ghost btn--small">Ara</button>
        <?php if($q): ?><a class="btn btn--ghost btn--small" href="reasons.php">Temizle</a><?php endif; ?>
      </form>
    </div>

    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Neden</th>
            <th>Durum</th>
            <th>İşlem</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
          <tr>
            <td data-label="Neden"><?=htmlspecialchars($r['name'])?></td>
            <td data-label="Durum">
              <?php if(!empty($r['active'])): ?>
                <span class="badge badge--success">Aktif</span>
              <?php else: ?>
                <span class="badge badge--muted">Pasif</span>
              <?php endif; ?>
            </td>
            <td class="actions" data-label="İşlem">
              <form method="post" class="inline-form">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?=htmlspecialchars((string)$r['id'], ENT_QUOTES, 'UTF-8')?>">
                <button type="submit" class="btn btn--ghost btn--small"><?= $r['active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button>
              </form>
              <form method="post" class="inline-form" onsubmit="return confirm('Silinsin mi?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=htmlspecialchars((string)$r['id'], ENT_QUOTES, 'UTF-8')?>">
                <button type="submit" class="btn btn--danger btn--small">Sil</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(!$rows): ?>
          <tr>
            <td colspan="3" data-label="Bilgi" class="table-empty">Kayıt yok</td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
</body>
</html>
