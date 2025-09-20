<?php
require __DIR__.'/config.php';

$success = '';
$error = '';

// --- ID ---
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { die('Geçersiz ID'); }

// --- Seçenek listeleri ---
$people = [];
$reasons = [];
try { $people  = $pdo->query("SELECT name FROM people WHERE active=1 ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){}
try { $reasons = $pdo->query("SELECT name FROM reasons WHERE active=1 ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){}

// --- Kayıt ---
$stmt = $pdo->prepare("SELECT * FROM visits WHERE id=?");
$stmt->execute([$id]);
$rec = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rec) { die('Kayıt bulunamadı'); }

// --- Güncelleme ---
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'update') {
    $visit_date   = $_POST['visit_date'] ?? $rec['visit_date'];
    $visit_time   = $_POST['visit_time'] ?? substr($rec['visit_time'],0,5);
    $exit_time_in = trim($_POST['exit_time'] ?? '');
    $full_name    = trim($_POST['full_name'] ?? $rec['full_name']);
    $to_whom_sel  = trim($_POST['to_whom'] ?? '');
    $to_whom_other= trim($_POST['to_whom_other'] ?? '');
    $reason       = trim($_POST['reason'] ?? $rec['reason']);
    $note         = trim($_POST['note'] ?? $rec['note']);
    $tcno         = preg_replace('/\D/','', $_POST['tcno'] ?? ''); // opsiyonel

    $to_whom = ($to_whom_sel === '__OTHER__') ? $to_whom_other : $to_whom_sel;

    // Validasyon
    if (!$full_name || !$to_whom || !$reason) {
        $error = 'Lütfen zorunlu alanları doldurunuz.';
    } elseif ($tcno && !validate_tc($tcno)) {
        $error = 'TC Kimlik No geçersiz.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) {
        $error = 'Geçerli bir tarih giriniz (YYYY-AA-GG).';
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $visit_time)) {
        $error = 'Geçerli bir giriş saati giriniz (SS:dd).';
    } elseif ($exit_time_in && !preg_match('/^\d{2}:\d{2}$/', $exit_time_in)) {
        $error = 'Geçerli bir çıkış saati giriniz (SS:dd).';
    } else {
        // Aynı gün aynı TC ikinci giriş kontrolü (kendi kaydı hariç)
        if ($tcno) {
            $chk = $pdo->prepare('SELECT 1 FROM visits WHERE visit_date=? AND tc_hash=? AND id<>? LIMIT 1');
            $chk->execute([$visit_date, tc_hash($tcno), $id]);
            if ($chk->fetch()){
                $error = 'Bu TC ile aynı gün başka bir giriş kaydı var.';
            }
        }
    }

    if (!$error) {
        $exit_time = $exit_time_in ? ($exit_time_in . ':00') : null;
        $tc_enc = $tcno ? tc_encrypt($tcno) : null;
        $tc_hash= $tcno ? tc_hash($tcno)    : null;

        $upd = $pdo->prepare("UPDATE visits SET
            visit_date=?, visit_time=?, exit_time=?, full_name=?, to_whom=?, reason=?, note=?, tc_enc=?, tc_hash=?
            WHERE id=?");
        $upd->execute([
            $visit_date,
            $visit_time.':00',
            $exit_time,
            $full_name,
            $to_whom,
            $reason,
            $note ?: null,
            $tc_enc,
            $tc_hash,
            $id
        ]);

        // güncel veriyi tekrar çek
        $stmt = $pdo->prepare("SELECT * FROM visits WHERE id=?");
        $stmt->execute([$id]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);

        $success = 'Kayıt başarıyla güncellendi.';
    }
}

// --- Form değerleri ---
$cur_visit_date = $rec['visit_date'];
$cur_visit_time = substr($rec['visit_time'],0,5);
$cur_exit_time  = $rec['exit_time'] ? substr($rec['exit_time'],0,5) : '';
$cur_full_name  = $rec['full_name'];
$cur_to_whom    = $rec['to_whom'];
$cur_reason     = $rec['reason'];
$cur_note       = $rec['note'];
$cur_tc         = $rec['tc_enc'] ? tc_decrypt($rec['tc_enc']) : '';

$to_whom_is_list = in_array($cur_to_whom, $people, true);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kayıt Güncelle</title>
<link rel="stylesheet" href="assets/style.css">
<style>
/* Sayfa taşıyıcı */
.container { max-width: 960px; margin: 24px auto; padding: 0 12px; }

/* Üst header: logo | başlık | geri */
.header {
  display:grid; grid-template-columns: 140px 1fr 140px; align-items:center; gap:12px; margin-bottom:18px;
}
.header .title { text-align:center; font-size:22px; font-weight:700; margin:0; }
.logo-wrap { display:flex; align-items:center; }
.logo-wrap img { max-height:48px; }

/* Kart */
.card {
  background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:18px;
  box-shadow: 0 8px 20px rgba(0,0,0,.04);
}

/* Grid form: masaüstünde 2 sütun, mobil tek */
.form-grid { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
.form-grid .full { grid-column: 1 / -1; }

/* Alan stilleri (assets/style.css ile uyumlu çalışır) */
label { display:block; font-weight:600; margin-bottom:6px; }
input[type="text"], input[type="date"], input[type="time"], select {
  width:100%; height:38px; border:1px solid #d1d5db; border-radius:10px; padding:0 10px; outline:0;
}
input[type="text"]::placeholder { color:#9aa3af; }

.actions { display:flex; gap:10px; justify-content:flex-end; margin-top:14px; }

/* Küçük “Şimdi” çipleri */
.btn-chip { padding:6px 10px; font-size:12px; border-radius:999px; }

.meta { color:#64748b; font-size:13px; margin-top:8px; }

@media (max-width: 820px){
  .form-grid { grid-template-columns: 1fr; }
  .header { grid-template-columns: 1fr; text-align:center; }
  .header .title { order: 2; }
}
</style>
</head>
<body>
<div class="container">

  <!-- Header -->
  <div class="header">
    <div class="logo-wrap">
      <img src="assets/logo.png" alt="Logo" onerror="this.style.display='none'">
    </div>
    <h1 class="title">Kayıt Güncelle</h1>
    <div style="text-align:right;">
      <a class="button ghost" href="index.php">← Geri dön</a>
    </div>
  </div>

  <!-- Bildirimler -->
  <?php if($success): ?><div class="success"><?=$success?></div><?php endif; ?>
  <?php if($error): ?><div class="alert"><?=$error?></div><?php endif; ?>

  <!-- Kart / Form -->
  <form method="post" class="card">
    <input type="hidden" name="action" value="update">

    <div class="form-grid">
      <!-- Tarih / Saat -->
      <div>
        <label>Tarih</label>
        <input type="date" name="visit_date" value="<?=htmlspecialchars($cur_visit_date)?>" required>
      </div>

      <div>
        <label>Giriş Saati</label>
        <div style="display:flex; gap:8px;">
          <input type="time" name="visit_time" id="visit_time" value="<?=htmlspecialchars($cur_visit_time)?>" required>
          <button type="button" id="btnNowIn" class="button ghost btn-chip">Şimdi</button>
        </div>
      </div>

      <div>
        <label>Çıkış Saati</label>
        <div style="display:flex; gap:8px;">
          <input type="time" name="exit_time" id="exit_time" value="<?=htmlspecialchars($cur_exit_time)?>">
          <button type="button" id="btnNowOut" class="button ghost btn-chip">Şimdi</button>
        </div>
      </div>

      <div>
        <label>TC Kimlik No (opsiyonel)</label>
        <input type="text" name="tcno" value="<?=htmlspecialchars($cur_tc)?>" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="11 hane">
      </div>

      <!-- Ad Soyad -->
      <div class="full">
        <label>Adı Soyadı</label>
        <input type="text" name="full_name" value="<?=htmlspecialchars($cur_full_name)?>" required>
      </div>

      <!-- Kime Geldi -->
      <div>
        <label>Kime Geldi</label>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <select name="to_whom" id="to_whom" required>
            <option value="">Seçiniz</option>
            <?php foreach($people as $p): ?>
              <option value="<?=$p?>" <?=$to_whom_is_list && $cur_to_whom===$p ? 'selected':''?>><?=$p?></option>
            <?php endforeach; ?>
            <option value="__OTHER__" <?=!$to_whom_is_list ? 'selected':''?>>Diğer</option>
          </select>
          <input type="text" id="to_whom_other" name="to_whom_other"
                 placeholder="Ad Soyad (Diğer)"
                 style="min-width:240px; <?= $to_whom_is_list ? 'display:none;' : '' ?>"
                 value="<?= !$to_whom_is_list ? htmlspecialchars($cur_to_whom) : '' ?>">
        </div>
      </div>

      <!-- Ziyaret Nedeni -->
      <div>
        <label>Ziyaret Nedeni</label>
        <select name="reason" required>
          <option value="">Seçiniz</option>
          <?php foreach($reasons as $r): ?>
            <option value="<?=$r?>" <?=$cur_reason===$r ? 'selected':''?>><?=$r?></option>
          <?php endforeach; ?>
          <?php if($cur_reason && !in_array($cur_reason, $reasons, true)): ?>
            <option value="<?=$cur_reason?>" selected>(Listede yok) <?=$cur_reason?></option>
          <?php endif; ?>
        </select>
      </div>

      <!-- Not -->
      <div class="full">
        <label>Not (opsiyonel)</label>
        <input type="text" name="note" value="<?=htmlspecialchars($cur_note ?? '')?>" placeholder="Kısa not">
      </div>
    </div>

    <div class="actions">
      <a class="button ghost" href="index.php">İptal</a>
      <button type="submit" class="button">Kaydı Güncelle</button>
    </div>
    <div class="meta">ID: <?=$id?></div>
  </form>
</div>

<script>
// "Şimdi" butonları
function nowHM(){ const n=new Date(); const p=x=>String(x).padStart(2,'0'); return p(n.getHours())+':'+p(n.getMinutes()); }
document.getElementById('btnNowIn').addEventListener('click', ()=>{ document.getElementById('visit_time').value = nowHM(); });
document.getElementById('btnNowOut').addEventListener('click', ()=>{ document.getElementById('exit_time').value  = nowHM(); });

// "Diğer" seçimi
const sel = document.getElementById('to_whom');
const other = document.getElementById('to_whom_other');
function toggleOther(){
  if (sel.value === '__OTHER__') {
    other.style.display = 'inline-block';
    other.setAttribute('required','required');
    other.focus();
  } else {
    other.style.display = 'none';
    other.removeAttribute('required');
    other.value = '';
  }
}
sel.addEventListener('change', toggleOther);
</script>
</body>
</html>
