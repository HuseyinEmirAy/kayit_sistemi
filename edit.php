<?php
require __DIR__.'/config.php';

$success = '';
$error = '';

// --- ID ---
$id = (int)($_GET['id'] ?? 0);

if (empty($_SESSION['uid'])) {
    $target = 'edit.php';
    if ($id > 0) {
        $target .= '?' . http_build_query(['id' => $id]);
    }
    header('Location: login.php?next=' . urlencode($target));
    exit;
}

if ($id <= 0) { die('Geçersiz ID'); }

$role = $_SESSION['role'] ?? 'user';
$canManageTc = ($role === 'admin');

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
    $tc_action    = 'keep';
    $tc_input     = null;
    if ($canManageTc) {
        $tc_input  = preg_replace('/\D/','', $_POST['tcno'] ?? '');
        $tc_action = ($tc_input === '') ? 'clear' : 'set';
    }

    $to_whom = ($to_whom_sel === '__OTHER__') ? $to_whom_other : $to_whom_sel;

    // Validasyon
    if (!$full_name || !$to_whom || !$reason) {
        $error = 'Lütfen zorunlu alanları doldurunuz.';
    } elseif ($tc_action === 'set' && !validate_tc($tc_input)) {
        $error = 'TC Kimlik No geçersiz.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) {
        $error = 'Geçerli bir tarih giriniz (YYYY-AA-GG).';
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $visit_time)) {
        $error = 'Geçerli bir giriş saati giriniz (SS:dd).';
    } elseif ($exit_time_in && !preg_match('/^\d{2}:\d{2}$/', $exit_time_in)) {
        $error = 'Geçerli bir çıkış saati giriniz (SS:dd).';
    } else {
        // Aynı gün aynı TC ikinci giriş kontrolü (kendi kaydı hariç)
        if ($tc_action === 'set') {
            $chk = $pdo->prepare('SELECT 1 FROM visits WHERE visit_date=? AND tc_hash=? AND id<>? LIMIT 1');
            $chk->execute([$visit_date, tc_hash($tc_input), $id]);
            if ($chk->fetch()){
                $error = 'Bu TC ile aynı gün başka bir giriş kaydı var.';
            }
        }
    }

    if (!$error) {
        $exit_time = $exit_time_in ? ($exit_time_in . ':00') : null;
        if ($tc_action === 'set') {
            $tc_enc  = tc_encrypt($tc_input);
            $tc_hash = tc_hash($tc_input);
        } elseif ($tc_action === 'clear') {
            $tc_enc = null;
            $tc_hash = null;
        } else {
            $tc_enc = $rec['tc_enc'];
            $tc_hash = $rec['tc_hash'];
        }

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
$cur_tc_plain   = $rec['tc_enc'] ? tc_decrypt($rec['tc_enc']) : '';
$cur_tc         = '';
if ($canManageTc) {
    $cur_tc = $cur_tc_plain;
} elseif ($cur_tc_plain !== '') {
    $cur_tc = tc_mask($cur_tc_plain);
}


$to_whom_is_list = in_array($cur_to_whom, $people, true);
$chips = [
    ['icon' => '#', 'label' => 'Kayıt', 'value' => $id],
    ['icon' => '📅', 'label' => 'Giriş', 'value' => $cur_visit_date . ' ' . $cur_visit_time],
];
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kayıt Güncelle</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php
  render_topbar('index', [
      'title'    => 'Kayıt Güncelle',
      'subtitle' => 'Ziyaretçi: ' . $cur_full_name,
      'chips'    => $chips,
  ]);
?>

<main class="app-container app-container--narrow">
  <?php if($success): ?><div class="alert alert--success"><?=htmlspecialchars($success)?></div><?php endif; ?>
  <?php if($error): ?><div class="alert alert--error"><?=htmlspecialchars($error)?></div><?php endif; ?>

  <form method="post" class="card">
    <div class="card-header">
      <div>
        <h1 class="card-title">Ziyaretçi Kaydını Düzenle</h1>
        <p class="card-subtitle">Giriş, çıkış ve kişi bilgilerini güncelleyin.</p>
      </div>
      <a class="btn btn--ghost btn--small" href="index.php">← Kayıtlara dön</a>
    </div>

    <input type="hidden" name="action" value="update">

    <div class="form-grid form-grid--two">
      <div class="field">
        <label for="visit_date">Tarih</label>
        <input type="date" id="visit_date" name="visit_date" value="<?=htmlspecialchars($cur_visit_date, ENT_QUOTES, 'UTF-8')?>" required>
      </div>

      <div class="field">
        <label for="visit_time">Giriş Saati</label>
        <div class="input-group">
          <input type="time" id="visit_time" name="visit_time" value="<?=htmlspecialchars($cur_visit_time, ENT_QUOTES, 'UTF-8')?>" required>
          <button type="button" id="btnNowIn" class="btn btn--ghost btn--small">Şimdi</button>
        </div>
      </div>

      <div class="field">
        <label for="exit_time">Çıkış Saati</label>
        <div class="input-group">
          <input type="time" id="exit_time" name="exit_time" value="<?=htmlspecialchars($cur_exit_time, ENT_QUOTES, 'UTF-8')?>">
          <button type="button" id="btnNowOut" class="btn btn--ghost btn--small">Şimdi</button>
        </div>
      </div>

      <div class="field">
        <label for="tcno">TC Kimlik No (opsiyonel)</label>
        <?php if($canManageTc): ?>
          <input type="text" id="tcno" name="tcno" value="<?=htmlspecialchars($cur_tc, ENT_QUOTES, 'UTF-8')?>" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="11 hane">
        <?php else: ?>
          <input type="text" id="tcno" value="<?=htmlspecialchars($cur_tc, ENT_QUOTES, 'UTF-8')?>" placeholder="Yetkiniz yok" disabled>
          <div class="meta">TC bilgisi yalnızca yetkili kullanıcılar tarafından görüntülenebilir ve güncellenebilir.</div>
        <?php endif; ?>
      </div>

      <div class="field full">
        <label for="full_name">Adı Soyadı</label>
        <input type="text" id="full_name" name="full_name" value="<?=htmlspecialchars($cur_full_name, ENT_QUOTES, 'UTF-8')?>" required>
      </div>

      <div class="field">
        <label for="to_whom">Kime Geldi</label>
        <select name="to_whom" id="to_whom">
          <option value="">Seçiniz</option>
          <?php foreach($people as $p): ?>
            <?php $esc = htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?>
            <option value="<?=$esc?>" <?= $cur_to_whom === $p ? 'selected' : '' ?>><?=$esc?></option>
          <?php endforeach; ?>
          <option value="__OTHER__" <?= $to_whom_is_list ? '' : 'selected' ?>>Diğer</option>
        </select>
        <input type="text" name="to_whom_other" id="to_whom_other" value="<?= !$to_whom_is_list ? htmlspecialchars($cur_to_whom, ENT_QUOTES, 'UTF-8') : '' ?>" placeholder="Ad Soyad" class="stacked-input <?= $to_whom_is_list ? 'is-hidden' : '' ?>">
      </div>

      <div class="field">
        <label for="reason">Ziyaret Nedeni</label>
        <select name="reason" id="reason" required>
          <option value="">Seçiniz</option>
          <?php foreach($reasons as $r): ?>
            <?php $esc = htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?>
            <option value="<?=$esc?>" <?= $cur_reason === $r ? 'selected' : '' ?>><?=$esc?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="note">Not</label>
        <input type="text" id="note" name="note" value="<?=htmlspecialchars((string)$cur_note, ENT_QUOTES, 'UTF-8')?>">
      </div>
    </div>

    <div class="form-actions">
      <a class="btn btn--neutral" href="index.php">İptal</a>
      <button type="submit" class="btn btn--primary">Kaydet</button>
    </div>
  </form>
</main>

<script>
const visitDate = document.getElementById('visit_date');
const visitTime = document.getElementById('visit_time');
const exitTime  = document.getElementById('exit_time');
const btnNowIn  = document.getElementById('btnNowIn');
const btnNowOut = document.getElementById('btnNowOut');
const toWhomSel = document.getElementById('to_whom');
const toWhomOther = document.getElementById('to_whom_other');

function pad(n){ return String(n).padStart(2,'0'); }

function setNowToFields(){
  const now = new Date();
  if (visitDate) {
    visitDate.value = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}`;
  }
  if (visitTime) {
    visitTime.value = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
  }
}

function setNowToExit(){
  const now = new Date();
  if (exitTime) {
    exitTime.value = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
  }
}

function toggleOther(){
  if (!toWhomSel || !toWhomOther) return;
  if (toWhomSel.value === '__OTHER__') {
    toWhomOther.classList.remove('is-hidden');
    toWhomOther.required = true;
    toWhomOther.focus();
  } else {
    toWhomOther.classList.add('is-hidden');
    toWhomOther.required = false;
    toWhomOther.value = '';
  }
}

btnNowIn?.addEventListener('click', setNowToFields);
btnNowOut?.addEventListener('click', setNowToExit);
document.addEventListener('DOMContentLoaded', toggleOther);
toggleOther();
</script>
</body>
</html>
