<?php
require __DIR__.'/config.php';

/* --------- ZORUNLU GİRİŞ (AUTH GUARD) --------- */
if (empty($_SESSION['uid'])) {
    header('Location: login.php?next=' . urlencode('index.php'));
    exit;
}

$action  = $_POST['action'] ?? '';
$success = $_GET['success'] ?? '';
$error   = $_GET['error']   ?? '';

// ---- Kullanıcı sözlüğü: id -> username
$userMap = [];
try { $userMap = $pdo->query("SELECT id, username FROM users")->fetchAll(PDO::FETCH_KEY_PAIR); } catch(Exception $e){}

// ======================= GİRİŞ KAYDI =======================
if ($_SERVER['REQUEST_METHOD']==='POST' && $action === 'enter'){
    $visit_date    = $_POST['visit_date'] ?? date('Y-m-d');
    $visit_time    = $_POST['visit_time'] ?? date('H:i');
    $full_name     = trim($_POST['full_name'] ?? '');
    $to_whom_sel   = trim($_POST['to_whom'] ?? '');
    $to_whom_other = trim($_POST['to_whom_other'] ?? '');
    $reason        = trim($_POST['reason'] ?? '');
    $note          = trim($_POST['note'] ?? '');
    $tcno          = preg_replace('/\D/','', $_POST['tcno'] ?? '');

    $to_whom = ($to_whom_sel === '__OTHER__') ? $to_whom_other : $to_whom_sel;

    if (!$full_name || !$to_whom || !$reason) {
        header("Location: index.php?error=Lütfen+zorunlu+alanları+doldurunuz."); exit;
    } elseif ($tcno && !validate_tc($tcno)) {
        header("Location: index.php?error=TC+Kimlik+No+geçersiz."); exit;
    } else {
        if ($tcno) {
            $chk = $pdo->prepare('SELECT 1 FROM visits WHERE visit_date=? AND tc_hash=? LIMIT 1');
            $chk->execute([$visit_date, tc_hash($tcno)]);
            if ($chk->fetch()){ header("Location: index.php?error=Bu+TC+ile+bugün+zaten+giriş+var."); exit; }
        }
        $uid = $_SESSION['uid'] ?? null;
        $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        $stmt = $pdo->prepare("INSERT INTO visits
          (visit_date, visit_time, exit_time, full_name, to_whom, reason, note, tc_enc, tc_hash,
           created_by, created_ip, created_ua, created_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,?, ?, NOW())");
        $stmt->execute([
            $visit_date, $visit_time, null,
            $full_name, $to_whom, $reason, ($note ?: null),
            $tcno ? tc_encrypt($tcno) : null,
            $tcno ? tc_hash($tcno)    : null,
            $uid, $ip, $ua
        ]);
        $who = $userMap[$uid] ?? 'Sistem';
        header("Location: index.php?success=Giriş+kaydı+alındı+(ekleyen:+$who)"); exit;
    }
}

// ======================= ÇIKIŞ KAYDI =======================
if ($_SERVER['REQUEST_METHOD']==='POST' && $action === 'exit'){
    $exit_id   = (int)($_POST['exit_id'] ?? 0);
    $exit_time = $_POST['exit_time'] ?? date('H:i');

    if ($exit_id <= 0){
        header("Location: index.php?error=Ziyaretçi+seçiniz."); exit;
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $exit_time)) {
        header("Location: index.php?error=Geçerli+bir+çıkış+saati+giriniz."); exit;
    } else {
        $uid = $_SESSION['uid'] ?? null;
        $stmt = $pdo->prepare("UPDATE visits
                               SET exit_time = ?, exit_by = ?, updated_by = ?, updated_at = NOW()
                               WHERE id = ? AND exit_time IS NULL");
        $ok = $stmt->execute([$exit_time.':00', $uid, $uid, $exit_id]);
        if ($ok && $stmt->rowCount()>0){
            $who = $userMap[$uid] ?? 'Sistem';
            header("Location: index.php?success=Çıkış+kaydı+alındı+(veren:+$who)"); exit;
        } else {
            header("Location: index.php?error=Kayıt+bulunamadı+veya+çıkış+zaten+yapılmış."); exit;
        }
    }
}

// ======================= LİSTELER =======================
$people  = [];
$reasons = [];
try { $people  = $pdo->query("SELECT name FROM people  WHERE active=1 ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){}
try { $reasons = $pdo->query("SELECT name FROM reasons WHERE active=1 ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){}

// Bugün içeride olanlar
$open_visits = [];
try {
  $today = date('Y-m-d');
  $st = $pdo->prepare("SELECT id, full_name, to_whom, reason, visit_time
                       FROM visits
                       WHERE visit_date=? AND exit_time IS NULL
                       ORDER BY visit_time ASC, id ASC");
  $st->execute([$today]);
  $open_visits = $st->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}
$insideCount = count($open_visits);

// Son 5 kayıt (ekleyen/çıkışı veren)
$latest = [];
try {
  $latest = $pdo->query("SELECT id, visit_date, visit_time, exit_time, full_name, to_whom, reason, note,
                                created_by, exit_by
                         FROM visits
                         ORDER BY id DESC
                         LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

$currentUsername = $_SESSION['username'] ?? ($userMap[$_SESSION['uid']] ?? '');
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ziyaretçi Kayıt Sistemi</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php
  render_topbar('index', [
      'title'    => 'Ziyaretçi Kayıt Sistemi',
      'subtitle' => $currentUsername ? ('Giriş yapan: ' . $currentUsername) : null,
      'chips'    => [
          ['icon' => '👥', 'label' => 'İçeride', 'value' => $insideCount],
      ],
  ]);
?>

<main class="app-container">
  <?php if($success): ?><div class="alert alert--success"><?=htmlspecialchars($success)?></div><?php endif; ?>
  <?php if($error): ?><div class="alert alert--error"><?=htmlspecialchars($error)?></div><?php endif; ?>

  <div class="page-grid page-grid--aside">
    <section class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Ziyaretçi Girişi</h2>
          <p class="card-subtitle">Yeni bir misafir kaydı oluşturun.</p>
        </div>
      </div>

      <form method="post" class="form-grid form-grid--two">
        <input type="hidden" name="action" value="enter">

        <div class="field">
          <label for="visit_date">Tarih</label>
          <input type="date" id="visit_date" name="visit_date" value="<?=htmlspecialchars(date('Y-m-d'))?>" required>
        </div>

        <div class="field">
          <label for="visit_time">Giriş Saati</label>
          <div class="input-group">
            <input type="time" id="visit_time" name="visit_time" value="<?=htmlspecialchars(date('H:i'))?>" required>
            <button type="button" id="btnNowEnter" class="btn btn--ghost btn--small">Şimdi</button>
          </div>
        </div>

        <div class="field">
          <label for="full_name">Adı Soyadı</label>
          <input type="text" id="full_name" name="full_name" placeholder="Ad Soyad" required>
        </div>

        <div class="field">
          <label for="tcno">TC Kimlik No</label>
          <input type="text" id="tcno" name="tcno" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="Opsiyonel (11 haneli)">
        </div>

        <div class="field full">
          <label for="to_whom">Kime Geldi</label>
          <div class="input-group">
            <select name="to_whom" id="to_whom" required>
              <option value="">Seçiniz</option>
              <?php foreach($people as $p): ?>
                <?php $personEsc = htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?>
                <option value="<?=$personEsc?>"><?=$personEsc?></option>
              <?php endforeach; ?>
              <option value="__OTHER__">Diğer</option>
            </select>
            <input type="text" id="to_whom_other" name="to_whom_other" placeholder="Ad Soyad (Diğer)" class="is-hidden input-grow">
          </div>
        </div>

        <div class="field">
          <label for="reason">Ziyaret Nedeni</label>
          <select name="reason" id="reason" required>
            <option value="">Seçiniz</option>
            <?php foreach($reasons as $r): ?>
              <?php $reasonEsc = htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?>
              <option value="<?=$reasonEsc?>"><?=$reasonEsc?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label for="note">Not (opsiyonel)</label>
          <input type="text" id="note" name="note" placeholder="Kısa not">
        </div>

        <div class="form-actions field full">
          <button type="submit" class="btn btn--primary">Girişi Kaydet</button>
        </div>
      </form>
    </section>

    <section class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Çıkış İşlemi</h2>
          <p class="card-subtitle">İçerideki ziyaretçilerin çıkışını kaydedin.</p>
        </div>
      </div>

      <form method="post" class="stack">
        <input type="hidden" name="action" value="exit">
        <div class="field">
          <label for="exit_id">İçeride Olan Ziyaretçi</label>
          <select name="exit_id" id="exit_id" required>
            <option value="">Seçiniz…</option>
            <?php foreach($open_visits as $ov): ?>
              <option value="<?=$ov['id']?>">
                <?=htmlspecialchars(substr($ov['visit_time'],0,5).' • '.$ov['full_name'].' • '.$ov['to_whom'].' • '.$ov['reason'])?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label for="exit_time">Çıkış Saati</label>
          <div class="input-group">
            <input type="time" id="exit_time" name="exit_time" value="<?=htmlspecialchars(date('H:i'))?>" required>
            <button type="button" id="btnNowExit" class="btn btn--ghost btn--small">Şimdi</button>
            <button type="submit" class="btn btn--primary">Çıkışı Kaydet</button>
          </div>
        </div>
      </form>

      <div class="card-header card-header--sub">
        <div>
          <h2 class="card-title">Son Kayıtlar</h2>
          <p class="card-subtitle">En son beş ziyaretçi.</p>
        </div>
      </div>

      <?php if($latest): ?>
        <ul class="simple-list">
          <?php foreach($latest as $v): ?>
          <li>
            <div>
              <div class="input-group">
                <strong><?=htmlspecialchars($v['full_name'])?></strong>
                <span class="badge"><?=htmlspecialchars($v['reason'])?></span>
              </div>
              <div class="meta">
                <?=htmlspecialchars(tr_date($v['visit_date']))?>
                • Giriş: <?=htmlspecialchars(substr($v['visit_time'],0,5))?>
                • Çıkış: <?= $v['exit_time'] ? htmlspecialchars(substr($v['exit_time'],0,5)) : '-' ?>
                <?php if(!empty($v['to_whom'])): ?> • <?=htmlspecialchars('Kime: '.$v['to_whom'])?><?php endif; ?>
                <?php if(!empty($v['note'])): ?> • <?=htmlspecialchars('Not: '.$v['note'])?><?php endif; ?>
                <?php
                  $ekleyen = $userMap[$v['created_by']] ?? null;
                  $cikis   = $userMap[$v['exit_by']]     ?? null;
                  if ($ekleyen) echo ' • Girişi yapan: '.htmlspecialchars($ekleyen);
                  if ($cikis)   echo ' • Çıkışı veren: '.htmlspecialchars($cikis);
                ?>
              </div>
            </div>
            <a href="edit.php?id=<?=$v['id']?>" class="btn btn--ghost btn--small">Güncelle</a>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="meta">Henüz kayıt yok.</div>
      <?php endif; ?>
    </section>
  </div>
</main>

<div class="modal" id="statusModal">
  <div class="modal__content">
    <h3 id="modalMessage"></h3>
    <button type="button" class="btn btn--primary" onclick="closeModal()">Tamam</button>
  </div>
</div>

<script>
const visitDate   = document.getElementById('visit_date');
const visitTime   = document.getElementById('visit_time');
const exitTimeInp = document.getElementById('exit_time');
const nowBtnIn    = document.getElementById('btnNowEnter');
const nowBtnOut   = document.getElementById('btnNowExit');
const toWhomSel   = document.getElementById('to_whom');
const otherField  = document.getElementById('to_whom_other');

function pad(n){ return String(n).padStart(2,'0'); }

function setNowForVisit(){
  const now = new Date();
  if (visitDate) {
    visitDate.value = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}`;
  }
  if (visitTime) {
    visitTime.value = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
  }
}

function setNowForExit(){
  const now = new Date();
  if (exitTimeInp) {
    exitTimeInp.value = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
  }
}

function toggleOtherField(){
  if (!toWhomSel || !otherField) return;
  if (toWhomSel.value === '__OTHER__') {
    otherField.classList.remove('is-hidden');
    otherField.required = true;
    otherField.focus();
  } else {
    otherField.classList.add('is-hidden');
    otherField.required = false;
    otherField.value = '';
  }
}

nowBtnIn?.addEventListener('click', setNowForVisit);
nowBtnOut?.addEventListener('click', setNowForExit);
toWhomSel?.addEventListener('change', toggleOtherField);
document.addEventListener('DOMContentLoaded', toggleOtherField);

toggleOtherField();

const success = "<?= htmlspecialchars($success) ?>";
const error   = "<?= htmlspecialchars($error) ?>";
if (success || error) {
  document.getElementById('modalMessage').textContent = success || error;
  document.getElementById('statusModal').classList.add('active');
}

function closeModal(){
  document.getElementById('statusModal').classList.remove('active');
  history.replaceState(null,null,'index.php');
}
</script>
</body>
</html>
