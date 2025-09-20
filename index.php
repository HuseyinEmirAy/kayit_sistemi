<?php
require __DIR__.'/config.php';

/* --------- ZORUNLU GİRİŞ (AUTH GUARD) --------- */
if (empty($_SESSION['uid'])) {
    header('Location: login.php?next=index.php');
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
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ziyaretçi Kayıt Sistemi</title>
<link rel="stylesheet" href="assets/style.css">
<style>
:root{ --card-bg:#ffffff; --card-bd:#e2e8f0; --card-shadow:0 8px 24px rgba(0,0,0,.06); --muted:#64748b; --chip:#f1f5f9; --radius:14px; }
html,body{height:100%} body{background:#f9fafb;position:relative;min-height:100vh}
body::before{content:"";position:fixed;top:50%;left:50%;width:520px;height:520px;background:url('assets/logo.png') no-repeat center/contain;opacity:.12;transform:translate(-50%,-50%);z-index:0;pointer-events:none}
.container{position:relative;z-index:1;max-width:1100px;margin:24px auto;padding:0 12px}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.logo-wrap img{height:44px}
.userinfo{color:#334155;font-size:14px}
h1{text-align:center;margin:10px 0 20px 0}
.cards{display:grid;grid-template-columns:1fr 420px;gap:20px;align-items:start}
@media (max-width: 920px){ .cards{grid-template-columns:1fr} }
.card{background:var(--card-bg);border:1px solid var(--card-bd);border-radius:var(--radius);padding:16px;box-shadow:var(--card-shadow)}
.card h3{margin:0 0 12px;font-size:16px;border-bottom:1px solid #f1f5f9;padding-bottom:6px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.form-grid .full{grid-column:1/-1}
label{display:block;font-weight:600;margin-bottom:6px}
input[type=text],input[type=date],input[type=time],select{width:100%;height:40px;border:1px solid #d1d5db;border-radius:10px;padding:0 10px;background:#fff;outline:0}
.inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.badge{font-size:12px;padding:2px 8px;border-radius:999px;background:var(--chip)}
.meta{color:var(--muted);font-size:13px}
ul{list-style:none;padding:0;margin:0}
ul li{padding:10px 0;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center}
.btn-chip{padding:6px 10px;font-size:12px;border-radius:999px}

/* Modal */
.modal{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;visibility:hidden;opacity:0;transition:.2s}
.modal.active{visibility:visible;opacity:1}
.modal-content{background:#fff;padding:20px 30px;border-radius:12px;max-width:420px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,.2)}
.modal-content h3{margin:0 0 12px;font-size:18px}
.modal-content button{margin-top:12px;padding:8px 16px;border:none;border-radius:8px;background:#2563eb;color:#fff;font-weight:600;cursor:pointer}
@media (max-width: 820px){ .form-grid{grid-template-columns:1fr} }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="logo-wrap">
      <img src="assets/logo.png" alt="Logo" onerror="this.style.display='none'">
    </div>
    <div class="userinfo">
      Giriş yapan: <strong><?=htmlspecialchars($_SESSION['username'] ?? ($userMap[$_SESSION['uid']] ?? ''))?></strong>
      · İçeride: <strong><?=$insideCount?></strong> ziyaretçi
      · <a class="button ghost" href="logout.php" style="margin-left:6px">Çıkış</a>
      <?php if(($_SESSION['role'] ?? '')==='admin'): ?>
        · <a class="button ghost" href="admin.php" style="margin-left:6px">Yönetici</a>
      <?php endif; ?>
    </div>
  </div>

  <h1>Ziyaretçi Kayıt Sistemi</h1>

  <div class="cards">
    <!-- SOL: Giriş Kartı -->
    <form method="post" class="card">
      <h3>Giriş Kaydı</h3>
      <input type="hidden" name="action" value="enter">
      <div class="form-grid">
        <div>
          <label>Tarih</label>
          <input type="date" name="visit_date" value="<?=htmlspecialchars(date('Y-m-d'))?>" required>
        </div>
        <div>
          <label>Giriş Saati</label>
          <div class="inline">
            <input type="time" name="visit_time" value="<?=htmlspecialchars(date('H:i'))?>" required>
            <button type="button" id="btnNowEnter" class="button ghost btn-chip">Şimdi</button>
          </div>
        </div>
        <div>
          <label>Adı Soyadı</label>
          <input type="text" name="full_name" placeholder="Ad Soyad" required>
        </div>
        <div>
          <label>TC Kimlik No</label>
          <input type="text" name="tcno" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="Opsiyonel (11 haneli)">
        </div>
        <div class="full">
          <label>Kime Geldi</label>
          <div class="inline">
            <select name="to_whom" id="to_whom" required>
              <option value="">Seçiniz</option>
              <?php foreach($people as $p): ?>
                <option value="<?=$p?>"><?=$p?></option>
              <?php endforeach; ?>
              <option value="__OTHER__">Diğer</option>
            </select>
            <input type="text" id="to_whom_other" name="to_whom_other" placeholder="Ad Soyad (Diğer)" style="display:none; min-width:240px;">
          </div>
        </div>
        <div>
          <label>Ziyaret Nedeni</label>
          <select name="reason" required>
            <option value="">Seçiniz</option>
            <?php foreach($reasons as $r): ?>
              <option value="<?=$r?>"><?=$r?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Not (opsiyonel)</label>
          <input type="text" name="note" placeholder="Kısa not">
        </div>
        <div class="full" style="display:flex; justify-content:flex-end;">
          <button type="submit" class="button">Girişi Kaydet</button>
        </div>
      </div>
    </form>

    <!-- SAĞ: Çıkış Kartı + Son 5 -->
    <div class="card">
      <h3>Çıkış Kaydı</h3>
      <form method="post" style="display:grid; gap:12px; margin-bottom:16px;">
        <input type="hidden" name="action" value="exit">
        <div>
          <label>İçeride Olan Ziyaretçi</label>
          <select name="exit_id" id="exit_id" required>
            <option value="">Seçiniz…</option>
            <?php foreach($open_visits as $ov): ?>
              <option value="<?=$ov['id']?>">
                <?=htmlspecialchars(substr($ov['visit_time'],0,5).' • '.$ov['full_name'].' • '.$ov['to_whom'].' • '.$ov['reason'])?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Çıkış Saati</label>
          <div class="inline">
            <input type="time" name="exit_time" id="exit_time" value="<?=htmlspecialchars(date('H:i'))?>" required>
            <button type="button" id="btnNowExit" class="button ghost btn-chip">Şimdi</button>
            <button type="submit" class="button">Çıkışı Kaydet</button>
          </div>
        </div>
      </form>

      <h3>Son 5 Ziyaretçi</h3>
      <?php if($latest): ?>
        <ul>
          <?php foreach($latest as $v): ?>
          <li>
            <div>
              <strong><?=htmlspecialchars($v['full_name'])?></strong>
              <span class="badge"><?=htmlspecialchars($v['reason'])?></span>
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
            <a href="edit.php?id=<?=$v['id']?>" class="button ghost" style="font-size:12px; padding:4px 8px;">Güncelle</a>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="meta">Henüz kayıt yok.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal" id="statusModal">
  <div class="modal-content">
    <h3 id="modalMessage"></h3>
    <button onclick="closeModal()">Tamam</button>
  </div>
</div>

<script>
// Giriş: Şimdi
document.getElementById('btnNowEnter').addEventListener('click', function() {
  const now=new Date(), pad=n=>String(n).padStart(2,'0');
  document.querySelector('input[name="visit_date"]').value =
    now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate());
  document.querySelector('input[name="visit_time"]').value =
    pad(now.getHours())+':'+pad(now.getMinutes());
});
// Çıkış: Şimdi
document.getElementById('btnNowExit').addEventListener('click', function() {
  const now=new Date(), pad=n=>String(n).padStart(2,'0');
  document.getElementById('exit_time').value = pad(now.getHours())+':'+pad(now.getMinutes());
});
// "Diğer" seçimi
const sel=document.getElementById('to_whom'), other=document.getElementById('to_whom_other');
function toggleOther(){ if(!sel||!other) return; if(sel.value==='__OTHER__'){ other.style.display='inline-block'; other.required=true; other.focus(); } else { other.style.display='none'; other.required=false; other.value=''; } }
if(sel){ sel.addEventListener('change', toggleOther); document.addEventListener('DOMContentLoaded', toggleOther); }

// PRG sonrası modal
const success = "<?= htmlspecialchars($success) ?>";
const error   = "<?= htmlspecialchars($error) ?>";
if(success || error){
  document.getElementById('modalMessage').textContent = success || error;
  document.getElementById('statusModal').classList.add('active');
}
function closeModal(){
  document.getElementById('statusModal').classList.remove('active');
  history.replaceState(null,null,'index.php'); // URL temizle
}
</script>
</body>
</html>
