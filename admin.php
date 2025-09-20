<?php
require __DIR__.'/config.php';

/* ---- Sadece admin erişebilir ---- */
if (empty($_SESSION['uid']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: index.php'); exit;
}

/* ---- Güvenli tarih normalizasyonu ---- */
function norm_date(?string $s, string $fallback): string {
  $s = trim((string)$s);
  if ($s === '') return $fallback;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  return $fallback;
}

/* ---- Filtre parametreleri (boşsa geniş aralık) ---- */
$from = norm_date($_GET['from'] ?? '', '1970-01-01');
$to   = norm_date($_GET['to']   ?? '', '2099-12-31');
$q    = trim($_GET['q']  ?? '');
$by   = trim($_GET['by'] ?? '');   // Ekleyen filtre (opsiyonel)

/* from > to ise yer değiştir */
if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }

/* ---- Kullanıcı sözlüğü (id -> username) ---- */
$userMap = [];
try {
  $userMap = $pdo->query("SELECT id, username FROM users")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch(Exception $e){}

/* ---- Liste sorgusu (ek sütunlarla) ---- */
$sql = "SELECT id, visit_date, visit_time, exit_time, full_name, to_whom, reason, note, tc_enc,
               created_by, exit_by, created_at, updated_by, updated_at
        FROM visits
        WHERE visit_date BETWEEN ? AND ?";
$params = [$from, $to];

if ($q !== '') {
    $sql .= " AND (full_name LIKE ? OR to_whom LIKE ? OR reason LIKE ? OR note LIKE ?)";
    $like = "%$q%"; array_push($params,$like,$like,$like,$like);
}
if ($by !== '') {
    $sql .= " AND created_by = ?";
    $params[] = (int)$by;
}

$sql .= " ORDER BY visit_date DESC, visit_time DESC, id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($rows);

/* ---- Ziyaretçi dağılımı (kime kaç ziyaretçi) ---- */
$sqlStat = "SELECT COALESCE(NULLIF(TRIM(to_whom),''),'(Belirtilmemiş)') AS who, COUNT(*) AS cnt
            FROM visits
            WHERE visit_date BETWEEN ? AND ?";
$statParams = [$from,$to];
if ($q !== '') {
  $sqlStat .= " AND (full_name LIKE ? OR to_whom LIKE ? OR reason LIKE ? OR note LIKE ?)";
  $like = "%$q%"; array_push($statParams,$like,$like,$like,$like);
}
if ($by !== '') { $sqlStat .= " AND created_by = ?"; $statParams[] = (int)$by; }
$sqlStat .= " GROUP BY who ORDER BY cnt DESC, who ASC";
$st = $pdo->prepare($sqlStat); $st->execute($statParams);
$whoStats = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Yönetici Paneli</title>
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

<!-- ÜST MENÜ -->
<div class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <img src="assets/logo.png" alt="Logo" onerror="this.style.display='none'">
      <strong>Yönetici Paneli</strong>
    </div>
   <div class="top-actions"> 
   <a class="button primary" href="index.php">📝 Ziyaretçi Kayıt Ekranı</a> 
   <a class="button ghost" href="admin.php">🖥️ Admin Paneli</a> 
   <a class="button ghost" href="people.php">🧑 Kişiler</a> 
   <a class="button ghost" href="reasons.php">❓ Nedenler</a> 
   <a class="button ghost" href="users.php">👤 Kullanıcılar</a> 
   <a class="button ghost" href="logout.php">🚪 Çıkış</a> 
   </div>

  </div>
</div>

<div class="container">
  <h1 class="page-title">Ziyaretçi Listesi</h1>

  <!-- ZİYARETÇİ DAĞILIMI -->
  <div class="section-title">Ziyaretçi Dağılımı</div>
  <div class="card stat-card">
    <div class="stat-grid">
      <div class="stat-chip" title="Toplam ziyaretçi">
        <span class="label"><strong>Toplam</strong></span>
        <span class="cnt"><?= (int)$total ?></span>
      </div>
      <?php foreach($whoStats as $ws): ?>
        <div class="stat-chip" title="<?= htmlspecialchars($ws['who']) ?>">
          <span class="label"><?= htmlspecialchars($ws['who']) ?></span>
          <span class="cnt"><?= (int)$ws['cnt'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- FİLTRELER -->
  <form method="get" class="card filters">
    <div class="segmented">
      <button type="button" class="chip" data-range="today">Bugün</button>
      <button type="button" class="chip" data-range="yesterday">Dün</button>
      <button type="button" class="chip" data-range="thisWeek">Bu Hafta</button>
      <button type="button" class="chip" data-range="lastWeek">Geçen Hafta</button>
      <button type="button" class="chip" data-range="thisMonth">Bu Ay</button>
      <button type="button" class="chip" data-range="lastMonth">Geçen Ay</button>
      <button type="button" class="chip" data-range="last7">Son 7 Gün</button>
      <button type="button" class="chip" data-range="last30">Son 30 Gün</button>
      <button type="button" class="chip" data-range="clear">Temizle</button>
    </div>

    <div class="filter-grid">
      <div class="field">
        <label>Başlangıç</label>
        <input type="date" id="from" name="from" value="<?=htmlspecialchars($from)?>">
      </div>
      <div class="field">
        <label>Bitiş</label>
        <input type="date" id="to" name="to" value="<?=htmlspecialchars($to)?>">
      </div>
      <div class="field">
        <label>Ekleyen</label>
        <select name="by">
          <option value="">Hepsi</option>
          <?php foreach($userMap as $uid=>$uname): ?>
            <option value="<?=$uid?>" <?= ($by!=='' && (int)$by===(int)$uid ? 'selected' : '') ?>>
              <?= htmlspecialchars($uname) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="search">
        <label>Ara</label>
        <input type="text" name="q" value="<?=htmlspecialchars($q)?>" placeholder="İsim, birim, neden, not...">
      </div>
      <div class="actions">
        <button type="submit" class="button primary">Uygula</button>
        <a class="button neutral" id="xlsxBtn">XLSX</a>
      </div>
    </div>
  </form>

  <div class="meta">Toplam kayıt: <?=$total?></div>

  <!-- LİSTE -->
  <div class="card table-wrap">
    <table>
      <thead>
        <tr>
          <th>Tarih</th><th>Giriş</th><th>Çıkış</th><th>Adı Soyadı</th>
          <th>Kime Geldi</th><th>Neden</th><th>TC No</th><th>Not</th>
          <th>Ekleyen</th><th>Çıkışı Veren</th>
          <th style="text-align:right;">Aksiyon</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td data-label="Tarih"><?=htmlspecialchars(tr_date($r['visit_date']))?></td>
          <td data-label="Giriş"><?=htmlspecialchars(substr($r['visit_time'],0,5))?></td>
          <td data-label="Çıkış"><?= $r['exit_time'] ? htmlspecialchars(substr($r['exit_time'],0,5)) : '-' ?></td>
          <td data-label="Adı Soyadı"><?=htmlspecialchars($r['full_name'])?></td>
          <td data-label="Kime Geldi"><?=htmlspecialchars($r['to_whom'])?></td>
          <td data-label="Neden"><?=htmlspecialchars($r['reason'])?></td>
          <td data-label="TC No">
            <?php
              $tc = $r['tc_enc'] ? tc_decrypt($r['tc_enc']) : null;
              echo (($_SESSION['role'] ?? '')==='admin') ? htmlspecialchars($tc) : htmlspecialchars(tc_mask($tc));
            ?>
          </td>
          <?php $note = (string)$r['note']; ?>
          <td class="note-cell" data-label="Not" title="<?=htmlspecialchars($note)?>"><?=htmlspecialchars(mb_strimwidth($note,0,200,''))?></td>
          <td data-label="Ekleyen" title="<?=htmlspecialchars($r['created_at'])?>">
            <?= htmlspecialchars($userMap[$r['created_by']] ?? '-') ?>
          </td>
          <td data-label="Çıkışı Veren" title="<?=htmlspecialchars($r['updated_at'] ?? '')?>">
            <?= htmlspecialchars($userMap[$r['exit_by']] ?? '-') ?>
          </td>
          <td class="actions" data-label="Aksiyon">
            <a href="edit.php?id=<?=$r['id']?>" class="button ghost" style="padding:6px 10px;font-size:12px;">Düzenle</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="11" style="text-align:center;padding:18px;">Kayıt yok</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// tarih yardımcıları
function toISODate(d){const z=d.getTimezoneOffset();const d2=new Date(d.getTime()-z*60000);return d2.toISOString().slice(0,10)}
function startOfWeek(d){const t=new Date(d);const day=(t.getDay()+6)%7;t.setDate(t.getDate()-day);return t}
function endOfWeek(d){const s=startOfWeek(d);const e=new Date(s);e.setDate(e.getDate()+6);return e}
function startOfMonth(d){return new Date(d.getFullYear(),d.getMonth(),1)}
function endOfMonth(d){return new Date(d.getFullYear(),d.getMonth()+1,0)}

function setRange(range){
  const today=new Date();const from=document.getElementById('from');const to=document.getElementById('to');let a=null,b=null;
  switch(range){
    case 'today':a=today;b=today;break;
    case 'yesterday':a=new Date(today);a.setDate(a.getDate()-1);b=a;break;
    case 'thisWeek':a=startOfWeek(today);b=endOfWeek(today);break;
    case 'lastWeek':{const t=new Date(today);t.setDate(t.getDate()-7);a=startOfWeek(t);b=endOfWeek(t);}break;
    case 'thisMonth':a=startOfMonth(today);b=endOfMonth(today);break;
    case 'lastMonth':{const t=new Date(today.getFullYear(),today.getMonth()-1,15);a=startOfMonth(t);b=endOfMonth(t);}break;
    case 'last7':a=new Date(today);a.setDate(a.getDate()-6);b=today;break;
    case 'last30':a=new Date(today);a.setDate(a.getDate()-29);b=today;break;
    case 'clear':from.value='';to.value='';return;
  }
  from.value=toISODate(a); to.value=toISODate(b);
}

document.querySelectorAll('.chip').forEach(btn=>{
  btn.addEventListener('click',()=>{
    const r=btn.getAttribute('data-range');
    setRange(r);
    if(r!=='clear') btn.closest('form').submit();
  });
});

// xlsx (mevcut filtrelerle)
document.getElementById('xlsxBtn').addEventListener('click', ()=>{
  const params=new URLSearchParams(new FormData(document.querySelector('.filters')));
  window.location.href=`export_xlsx.php?${params.toString()}`;
});
</script>
</body>
</html>
