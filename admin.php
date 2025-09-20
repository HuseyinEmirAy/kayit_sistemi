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
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Yönetici Paneli</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php
  render_topbar('admin', [
      'title'    => 'Yönetici Paneli',
      'subtitle' => 'Ziyaret kayıtlarını filtreleyin, raporlayın ve dışa aktarın.',
      'chips'    => [
          ['icon' => '📊', 'label' => 'Toplam Kayıt', 'value' => $total],
      ],
  ]);
?>

<main class="app-container">
  <section class="card">
    <div class="card-header">
      <div>
        <h1 class="card-title">Ziyaretçi Dağılımı</h1>
        <p class="card-subtitle">Seçili tarih aralığında kime kaç ziyaret gerçekleşti.</p>
      </div>
    </div>
    <div class="stat-grid">
      <div class="chip chip--stat">
        <span class="chip__label"><strong>Toplam</strong></span>
        <span class="chip__value"><?= (int)$total ?></span>
      </div>
      <?php foreach($whoStats as $ws): ?>
        <div class="chip chip--stat" title="<?= htmlspecialchars($ws['who']) ?>">
          <span class="chip__label"><?= htmlspecialchars($ws['who']) ?></span>
          <span class="chip__value"><?= (int)$ws['cnt'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Filtreler</h2>
        <p class="card-subtitle">Tarih aralıklarını ve metin aramasını kullanarak kayıtları daraltın.</p>
      </div>
    </div>

    <div class="chip-group">
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

    <form method="get" id="filterForm" class="form-grid form-grid--filters">
      <div class="field">
        <label for="from">Başlangıç</label>
        <input type="date" id="from" name="from" value="<?=htmlspecialchars($from)?>">
      </div>
      <div class="field">
        <label for="to">Bitiş</label>
        <input type="date" id="to" name="to" value="<?=htmlspecialchars($to)?>">
      </div>
      <div class="field">
        <label for="by">Ekleyen</label>
        <select name="by" id="by">
          <option value="">Hepsi</option>
          <?php foreach($userMap as $uid=>$uname): ?>
            <option value="<?=$uid?>" <?= ($by!=='' && (int)$by===(int)$uid ? 'selected' : '') ?>><?= htmlspecialchars($uname) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field full">
        <label for="q">Arama</label>
        <input type="text" id="q" name="q" value="<?=htmlspecialchars($q)?>" placeholder="İsim, birim, neden veya not">
      </div>
      <div class="form-actions field full">
        <button type="submit" class="btn btn--primary">Uygula</button>
        <button type="button" class="btn btn--ghost" id="xlsxBtn">XLSX Dışa Aktar</button>
      </div>
    </form>
  </section>

  <section class="card card--table">
    <div class="card-header">
      <div>
        <h2 class="card-title">Ziyaretçi Kayıtları</h2>
        <p class="card-subtitle">Filtrelenen sonuçlar listelenir. Ayrıntıları düzenleyebilirsiniz.</p>
      </div>
    </div>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Tarih</th>
            <th>Giriş</th>
            <th>Çıkış</th>
            <th>Adı Soyadı</th>
            <th>Kime Geldi</th>
            <th>Neden</th>
            <th>TC No</th>
            <th>Not</th>
            <th>Ekleyen</th>
            <th>Çıkışı Veren</th>
            <th class="text-right">Aksiyon</th>
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
            <td data-label="TC No"><?php
              $tc = $r['tc_enc'] ? tc_decrypt($r['tc_enc']) : null;
              echo (($_SESSION['role'] ?? '')==='admin') ? htmlspecialchars($tc) : htmlspecialchars(tc_mask($tc));
            ?></td>
            <?php $note = (string)$r['note']; ?>
            <td class="note-cell" data-label="Not" title="<?=htmlspecialchars($note)?>"><?=htmlspecialchars(mb_strimwidth($note,0,200,''))?></td>
            <td data-label="Ekleyen" title="<?=htmlspecialchars($r['created_at'])?>"><?= htmlspecialchars($userMap[$r['created_by']] ?? '-') ?></td>
            <td data-label="Çıkışı Veren" title="<?=htmlspecialchars($r['updated_at'] ?? '')?>"><?= htmlspecialchars($userMap[$r['exit_by']] ?? '-') ?></td>
            <td class="actions" data-label="Aksiyon">
              <a href="edit.php?id=<?=$r['id']?>" class="btn btn--ghost btn--small">Düzenle</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$rows): ?>
          <tr>
            <td colspan="11" data-label="Bilgi" class="table-empty">Kayıt yok</td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<script>
function toISODate(d){const z=d.getTimezoneOffset();const d2=new Date(d.getTime()-z*60000);return d2.toISOString().slice(0,10)}
function startOfWeek(d){const t=new Date(d);const day=(t.getDay()+6)%7;t.setDate(t.getDate()-day);return t}
function endOfWeek(d){const s=startOfWeek(d);const e=new Date(s);e.setDate(e.getDate()+6);return e}
function startOfMonth(d){return new Date(d.getFullYear(),d.getMonth(),1)}
function endOfMonth(d){return new Date(d.getFullYear(),d.getMonth()+1,0)}

function setRange(range){
  const today=new Date();
  const from=document.getElementById('from');
  const to=document.getElementById('to');
  let a=null,b=null;
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

const filterForm=document.getElementById('filterForm');
document.querySelectorAll('[data-range]').forEach(btn=>{
  btn.addEventListener('click',()=>{
    const r=btn.getAttribute('data-range');
    setRange(r);
    if(r!=='clear'){ filterForm?.submit(); }
  });
});

const xlsxBtn=document.getElementById('xlsxBtn');
if(xlsxBtn){
  xlsxBtn.addEventListener('click', ()=>{
    if(!filterForm) return;
    const params=new URLSearchParams(new FormData(filterForm));
    window.location.href=`export_xlsx.php?${params.toString()}`;
  });
}
</script>
</body>
</html>
