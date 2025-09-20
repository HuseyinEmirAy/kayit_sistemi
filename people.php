<?php
require __DIR__.'/config.php';
if (empty($_SESSION['uid'])) { header('Location: login.php'); exit; }

if ($_SERVER['REQUEST_METHOD']==='POST'){
    $action = $_POST['action'] ?? '';
    if ($action==='add'){
        $name = trim($_POST['name'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        if ($name){
            $stmt = $pdo->prepare("INSERT INTO people(name, department, active) VALUES(?,?,1)");
            $stmt->execute([$name, $dept]);
            header("Location: people.php?ok=1"); exit;
        }
    } elseif ($action==='toggle'){
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE people SET active = 1-active WHERE id=?")->execute([$id]);
        header("Location: people.php"); exit;
    } elseif ($action==='delete'){
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM people WHERE id=?")->execute([$id]);
        header("Location: people.php"); exit;
    }
}

$q = $_GET['q'] ?? '';
$sql = "SELECT * FROM people";
$params = [];
if ($q){
    $sql .= " WHERE name LIKE ? OR department LIKE ?";
    $like = "%$q%";
    $params = [$like,$like];
}
$sql .= " ORDER BY active DESC, name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$totalPeople = count($rows);
$activePeople = 0;
foreach ($rows as $r) {
    if (!empty($r['active'])) {
        $activePeople++;
    }
}
$flashSuccess = isset($_GET['ok']) ? 'Yeni kişi başarıyla eklendi.' : '';

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kişiler</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php
  render_topbar('people', [
      'title'    => 'Kişi & Birim Yönetimi',
      'subtitle' => 'Ziyaretçi kayıt formunda kullanılacak kişi ve birimleri yönetin.',
      'chips'    => [
          ['icon' => '📇', 'label' => 'Toplam', 'value' => $totalPeople],
          ['icon' => '✅', 'label' => 'Aktif', 'value' => $activePeople],
      ],
  ]);
?>

<main class="app-container">
  <?php if($flashSuccess): ?><div class="alert alert--success"><?=htmlspecialchars($flashSuccess)?></div><?php endif; ?>

  <section class="card">
    <div class="card-header">
      <div>
        <h1 class="card-title">Yeni Kişi veya Birim Ekle</h1>
        <p class="card-subtitle">Ziyaretçi formundaki listede görünmesini istediğiniz kişi/birimleri ekleyin.</p>
      </div>
    </div>

    <form method="post" class="form-grid form-grid--two">
      <div class="field">
        <label for="name">Ad / Birim</label>
        <input type="text" id="name" name="name" required>
      </div>
      <div class="field">
        <label for="department">Departman</label>
        <input type="text" id="department" name="department" placeholder="Opsiyonel">
      </div>
      <input type="hidden" name="action" value="add">
      <div class="form-actions field full">
        <button type="submit" class="btn btn--primary">Ekle</button>
      </div>
    </form>
  </section>

  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Kayıtlı Kişiler</h2>
        <p class="card-subtitle">Ziyaretçilerin seçim yapabileceği kişi ve birim listesi.</p>
      </div>
      <form method="get" class="search-bar">
        <input type="text" name="q" value="<?=htmlspecialchars($q)?>" placeholder="İsim veya departman ara">
        <button type="submit" class="btn btn--ghost btn--small">Ara</button>
        <?php if($q): ?><a class="btn btn--ghost btn--small" href="people.php">Temizle</a><?php endif; ?>
      </form>
    </div>

    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Ad</th>
            <th>Departman</th>
            <th>Durum</th>
            <th>İşlem</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
          <tr>
            <td data-label="Ad"><?=htmlspecialchars($r['name'])?></td>
            <td data-label="Departman"><?=htmlspecialchars($r['department'])?></td>
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
            <td colspan="4" data-label="Bilgi" class="table-empty">Kayıt yok</td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
</body>
</html>
