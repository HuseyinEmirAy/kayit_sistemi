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
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ziyaret Nedenleri</title>
<link rel="stylesheet" href="assets/style.css">
<style>
:root{
  --bg:#f6f8fb; --card:rgba(255,255,255,.9); --stroke:#e6ebf2;
  --shadow:0 10px 30px rgba(15,23,42,.06); --muted:#64748b;
  --primary:#2563eb; --primary-600:#1d4ed8; --primary-700:#1e40af;
  --chip:#eef2ff; --chip-br:#c7d2fe; --radius:14px;
}
*{box-sizing:border-box} html,body{height:100%}
body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue";
  color:#0f172a;background:radial-gradient(1200px 600px at 50% -10%,#e8efff 0%,transparent 55%),var(--bg)}

/* Üst mini bar */
.topbar{position:sticky;top:0;z-index:50;backdrop-filter:saturate(180%) blur(8px);
  background:rgba(246,248,251,.8);border-bottom:1px solid var(--stroke)}
.topbar-inner{max-width:1200px;margin:0 auto;padding:8px 16px;display:flex;align-items:center;gap:12px;justify-content:space-between}
.brand{display:flex;align-items:center;gap:10px}
.brand img{height:40px}
.top-actions a{margin-left:6px; min-width:120px; text-align:center}

/* Konteyner ve kartlar */
.container{max-width:1200px;margin:18px auto;padding:0 16px}
.card{background:var(--card);border:1px solid var(--stroke);border-radius:var(--radius);box-shadow:var(--shadow)}

/* Başlıklar */
.page-title{font-size:26px;font-weight:900;margin:18px 0;text-align:center}
.section-title{font-size:18px;font-weight:800;margin:10px 0 8px 6px;color:#0f172a}

/* Dağılım rozetleri */
.stat-card{padding:12px;margin-bottom:16px}
.stat-grid{display:grid; gap:8px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));}
.stat-chip{
  display:flex; align-items:center; justify-content:space-between; gap:10px;
  background:#fff; border:1px solid var(--stroke); border-radius:999px; padding:6px 12px;
  box-shadow:0 4px 10px rgba(15,23,42,.04); font-size:13px; height:36px;
}
.stat-chip .label{overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:80%}
.stat-chip .cnt{ background:var(--chip); border:1px solid var(--chip-br); border-radius:999px; padding:2px 10px; font-weight:700; color:#3730a3 }

/* Filtreler */
.filters{padding:18px;margin-bottom:18px}
.segmented{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.chip{padding:6px 12px;border-radius:999px;border:1px solid var(--chip-br);background:var(--chip);color:#3730a3;font-weight:700;font-size:12px;cursor:pointer;transition:.15s}
.chip:hover{filter:brightness(.97);transform:translateY(-1px)}
.filter-grid{display:grid;gap:12px;grid-template-columns:repeat(12,1fr);align-items:end}
.filter-grid .field{grid-column:span 3;min-width:180px}
.filter-grid .search{grid-column:span 4}
.filter-grid .actions{grid-column:span 2;display:flex;gap:10px;justify-content:flex-end}
@media (max-width:980px){
  .topbar-inner{flex-direction:column;align-items:stretch;gap:10px}
  .top-actions{display:flex;flex-wrap:wrap;gap:8px}
  .top-actions a{margin-left:0}
  .filter-grid{grid-template-columns:1fr}
  .filter-grid .field,.filter-grid .search,.filter-grid .actions{grid-column:1/-1}
  .filter-grid .actions{justify-content:flex-start}
}
label{display:block;font-size:12px;font-weight:800;color:var(--muted);margin-bottom:6px}
input[type="date"],input[type="text"],select{width:100%;height:42px;padding:0 12px;border-radius:10px;border:1px solid var(--stroke);background:#fff;outline:0}

/* Butonlar */
.button{display:inline-block;padding:9px 14px;border-radius:10px;cursor:pointer;text-decoration:none;border:1px solid transparent;transition:.18s;font-weight:700;font-size:14px}
.button.primary{background:var(--primary);color:#fff}.button.primary:hover{background:var(--primary-600)}
.button.ghost{background:#fff;color:var(--primary-700);border-color:var(--primary-700)}.button.ghost:hover{background:var(--primary);color:#fff;border-color:var(--primary)}
.button.neutral{background:#fff;border-color:var(--stroke);color:#0f172a}.button.neutral:hover{background:#f8fafc}

/* Tablo */
.table-wrap{overflow:hidden}
table{width:100%;border-collapse:collapse}
thead th{background:#f8fafc;border-bottom:1px solid var(--stroke);padding:10px;text-align:left;font-size:12px;letter-spacing:.02em;color:#0f172a}
tbody td{padding:12px 10px;border-bottom:1px solid #f1f5f9;font-size:14px;vertical-align:middle}
tbody tr:nth-child(odd){background:#fff} tbody tr:nth-child(even){background:#fbfdff} tbody tr:hover{background:#eef5ff}
td.actions{text-align:right;white-space:nowrap}
.note-cell{max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap}
.meta{color:#64748b;font-size:13px;margin:10px 2px}

/* Mobil */
@media (max-width:860px){
  table,thead,tbody,th,td,tr{display:block}
  thead{display:none}
  tbody tr{margin:10px 0;border:1px solid var(--stroke);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
  tbody td{border:none;padding:8px 12px}
  tbody td::before{content:attr(data-label);display:block;font-size:11px;color:#64748b;font-weight:700;margin-bottom:2px}
  .note-cell{max-width:100%}
  td.actions{text-align:right;padding-top:4px}
}
</style>
</head>
<body>
<div class="container">
  <div class="logo-wrap">
    <img src="assets/logo.png" alt="Logo" onerror="this.style.display='none'">
  </div>

  <div class="header">
    <h1>Ziyaret Nedenleri</h1>
    <div class="top-actions"> 
   <a class="button primary" href="index.php">📝 Ziyaretçi Kayıt Ekranı</a> 
   <a class="button ghost" href="admin.php">🖥️ Admin Paneli</a> 
   <a class="button ghost" href="people.php">🧑 Kişiler</a> 
 
   <a class="button ghost" href="users.php">👤 Kullanıcılar</a> 
   <a class="button ghost" href="logout.php">🚪 Çıkış</a> 
   </div>
  </div>

  <form method="post" class="grid">
    <div class="col-2"><h3>Yeni Neden Ekle</h3></div>
    <div class="col-2">
      <label>Neden Adı</label>
      <input type="text" name="name" required>
    </div>
    <div class="col-2">
      <input type="hidden" name="action" value="add">
      <button type="submit">Ekle</button>
    </div>
  </form>

  <form method="get" class="filters">
    <label>Ara</label><input type="text" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Neden adı">
    <button type="submit">Ara</button>
  </form>

  <table>
    <thead><tr><th>Neden</th><th>Durum</th><th>İşlem</th></tr></thead>
    <tbody>
      <?php foreach($rows as $r): ?>
      <tr>
        <td><?=htmlspecialchars($r['name'])?></td>
        <td><?= $r['active'] ? 'Aktif' : 'Pasif' ?></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?=$r['id']?>">
            <button type="submit" class="button"><?= $r['active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Silinsin mi?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?=$r['id']?>">
            <button type="submit" class="button ghost">Sil</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="3">Kayıt yok</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>
