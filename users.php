<?php
require __DIR__.'/config.php';
if (empty($_SESSION['uid']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: login.php'); exit;
}

/* ---------------- Helpers (config'te yoksa) ---------------- */
if (!function_exists('generate_strong_password')) {
  function generate_strong_password(int $length = 16): string {
    $length = max(12, $length);
    $U='ABCDEFGHJKLMNPQRSTUVWXYZ'; $L='abcdefghijkmnpqrstuvwxyz'; $D='23456789'; $S='!@#$%^&*()-_=+[]{};:,.?';
    $pick=function($p){return $p[random_int(0,strlen($p)-1)];};
    $chars=[$pick($U),$pick($L),$pick($D),$pick($S)];
    $all=$U.$L.$D.$S; for($i=count($chars);$i<$length;$i++) $chars[]=$pick($all);
    for($i=count($chars)-1;$i>0;$i--){$j=random_int(0,$i);[$chars[$i],$chars[$j]]=[$chars[$j],$chars[$i]];}
    return implode('',$chars);
  }
}
if (!function_exists('validate_password')) {
  function validate_password(string $password, string $username = '', array $blacklist = []): ?string {
    if (strlen($password) < 12) return 'Parola en az 12 karakter olmalı.';
    if (!preg_match('/[A-Z]/',$password)) return 'En az bir büyük harf olmalı.';
    if (!preg_match('/[a-z]/',$password)) return 'En az bir küçük harf olmalı.';
    if (!preg_match('/\d/',$password))   return 'En az bir rakam olmalı.';
    if (!preg_match('/[^A-Za-z0-9]/',$password)) return 'En az bir sembol olmalı.';
    $common=['123456','123456789','qwerty','password','111111','123123','abc123','iloveyou','admin','welcome'];
    $all=array_map('strtolower',array_unique(array_merge($common,$blacklist)));
    if (in_array(strtolower($password),$all,true)) return 'Parola çok yaygın/zayıf.';
    foreach (preg_split('/[^A-Za-z0-9]+/',strtolower($username)) as $n){
      if (strlen($n)>=3 && stripos(strtolower($password),$n)!==false) return 'Parola kullanıcı bilgilerinizi içermemeli.';
    }
    return null;
  }
}

/* ---------------- AJAX: Parola üret ---------------- */
if (($_GET['action'] ?? '') === 'genpass') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['password'=>generate_strong_password(16)]);
  exit;
}

/* ---------------- İşlemler ---------------- */
$roles = ['admin'=>'Yönetici','viewer'=>'Görüntüleyici','reception'=>'Resepsiyon','pii'=>'KV/Kritik'];
$info=''; $error='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $op = $_POST['op'] ?? '';
    if ($op==='delete') {
      $id=(int)($_POST['id']??0);
      if ($id<=0) throw new Exception('Geçersiz kullanıcı.');
      if ($id==($_SESSION['uid']??0)) throw new Exception('Kendi hesabınızı silemezsiniz.');
      $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
      $info='Kullanıcı silindi.';
    } else {
      // create/update tek form
      $id       = (int)($_POST['id'] ?? 0);
      $username = trim($_POST['username'] ?? '');
      $role     = $_POST['role'] ?? 'viewer';
      $password = $_POST['password'] ?? '';

      if ($id===0 && (!$username || !$password)) throw new Exception('Kullanıcı adı ve parola zorunlu.');
      if (!isset($roles[$role])) throw new Exception('Geçersiz rol.');

      if ($id===0) { // CREATE
        $chk=$pdo->prepare("SELECT 1 FROM users WHERE username=? LIMIT 1"); $chk->execute([$username]);
        if ($chk->fetch()) throw new Exception('Bu kullanıcı adı zaten mevcut.');

        if ($msg=validate_password($password,$username,['millicephe','turkiye','ziyaretci'])) throw new Exception($msg);
        $hash = defined('PASSWORD_ARGON2ID')
          ? password_hash($password,PASSWORD_ARGON2ID,['memory_cost'=>1<<17,'time_cost'=>3,'threads'=>2])
          : password_hash($password,PASSWORD_BCRYPT,['cost'=>12]);

        $pdo->prepare("INSERT INTO users(username,password_hash,role) VALUES (?,?,?)")->execute([$username,$hash,$role]);
        $info='Kullanıcı oluşturuldu.';
      } else { // UPDATE
        $u=$pdo->prepare("SELECT * FROM users WHERE id=?"); $u->execute([$id]); $user=$u->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new Exception('Kullanıcı bulunamadı.');
        if ($role!==$user['role']) $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role,$id]);
        if ($password!=='') {
          if ($msg=validate_password($password,$user['username'],['millicephe','turkiye','ziyaretci'])) throw new Exception($msg);
          $hash = defined('PASSWORD_ARGON2ID')
            ? password_hash($password,PASSWORD_ARGON2ID,['memory_cost'=>1<<17,'time_cost'=>3,'threads'=>2])
            : password_hash($password,PASSWORD_BCRYPT,['cost'=>12]);
          $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash,$id]);
        }
        $info='Kullanıcı güncellendi.';
      }
    }
  } catch(Exception $e){ $error=$e->getMessage(); }
}

/* ---------------- Liste ---------------- */
$users = $pdo->query("SELECT id, username, role FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kullanıcılar</title>
<style>
:root{--bg:#f6f8fb;--card:#fff;--stroke:#e6ebf2;--muted:#64748b;--primary:#2563eb;--radius:12px}
*{box-sizing:border-box} body{margin:0;background:var(--bg);font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto}
.container{max-width:900px;margin:24px auto;padding:0 16px}
h1{margin:0 0 14px 0;text-align:center;font-size:24px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
a.button,button.button{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #dbe3ef;background:#fff;color:#0f172a;text-decoration:none;cursor:pointer;font-weight:700}
.button.primary{background:var(--primary);border-color:var(--primary);color:#fff}
.notice{padding:10px 14px;border-left:4px solid #22c55e;background:#ecfdf5;border:1px solid #bbf7d0;border-radius:8px;margin:10px 0}
.error{padding:10px 14px;border-left:4px solid #ef4444;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;margin:10px 0}
.card{background:var(--card);border:1px solid var(--stroke);border-radius:var(--radius);padding:12px}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;border-bottom:1px solid #eef2f7;text-align:left}
thead th{background:#f8fafc;font-size:12px}
.actions{white-space:nowrap;text-align:right}
.badge{display:inline-block;background:#eef2ff;border:1px solid #c7d2fe;border-radius:999px;padding:2px 8px;font-size:12px}
.section-title{margin:18px 0 8px 0;font-weight:800}
label{display:block;font-size:12px;color:var(--muted);margin-bottom:6px}
input[type=text],input[type=password],select{width:100%;height:42px;border:1px solid #dbe3ef;border-radius:10px;padding:0 12px;background:#fff}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:720px){.form-row{grid-template-columns:1fr}}
.form-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:10px}
.small{font-size:12px;color:var(--muted)}
</style>
</head>
<body>
    
<div class="container">
  <div class="top">
    <h1>Kullanıcılar</h1>
    <div></div>
   
  </div>

  <?php if($info): ?><div class="notice"><?=htmlspecialchars($info)?></div><?php endif; ?>
  <?php if($error): ?><div class="error"><?=htmlspecialchars($error)?></div><?php endif; ?>
 <div class="top-actions"> 
   <a class="button primary" href="index.php">📝 Ziyaretçi Kayıt Ekranı</a> 
   <a class="button ghost" href="admin.php">🖥️ Admin Paneli</a> 
   <a class="button ghost" href="people.php">🧑 Kişiler</a> 
   <a class="button ghost" href="reasons.php">❓ Nedenler</a> 
   <a class="button ghost" href="users.php">👤 Kullanıcılar</a> 
   <a class="button ghost" href="logout.php">🚪 Çıkış</a> 
   </div>
  <!-- Liste -->
  <div class="card">
    <div class="section-title">Kayıtlı Kullanıcılar</div>
    <table>
      <thead><tr><th>ID</th><th>Kullanıcı Adı</th><th>Rol</th><th class="actions">İşlem</th></tr></thead>
      <tbody>
        <?php foreach($users as $u): ?>
        <tr>
          <td><?=$u['id']?></td>
          <td><?=htmlspecialchars($u['username'])?></td>
          <td><span class="badge"><?=htmlspecialchars($roles[$u['role']] ?? $u['role'])?></span></td>
          <td class="actions">
            <button class="button" onclick='fillForm(<?=json_encode($u,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'>Düzenle</button>
            <?php if($u['id'] != ($_SESSION['uid'] ?? 0)): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Silinsin mi?');">
              <input type="hidden" name="op" value="delete">
              <input type="hidden" name="id" value="<?=$u['id']?>">
              <button type="submit" class="button" style="background:#ef4444;border-color:#ef4444;color:#fff;">Sil</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(!$users): ?><tr><td colspan="4">Kullanıcı yok</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Tek Form: Yeni / Düzenle -->
  <div class="card" style="margin-top:16px">
    <div class="section-title" id="formTitle">Yeni Kullanıcı</div>
    <form method="post" id="userForm">
      <input type="hidden" name="id" id="f_id" value="0">
      <input type="hidden" name="op" id="f_op" value="">
      <div class="form-row">
        <div>
          <label>Kullanıcı Adı</label>
          <input type="text" name="username" id="f_username" placeholder="ornek.kullanici">
          <div class="small" id="unameHint">Düzenleme modunda değiştirilemez.</div>
        </div>
        <div>
          <label>Rol</label>
          <select name="role" id="f_role">
            <?php foreach($roles as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div>
          <label id="pwdLabel">Parola</label>
          <input type="password" name="password" id="f_password" placeholder="En az 12 karakter">
          <div class="small" id="pwdHint">Yeni kullanıcıda zorunlu; düzenlemede boş bırakırsanız değişmez.</div>
        </div>
        <div>
          <label>Hızlı İşlem</label>
          <div class="form-actions" style="justify-content:flex-start;margin:0">
            <button type="button" class="button" id="btnGen">Parola Üret (Sunucu)</button>
            <button type="button" class="button" id="btnShow">Göster</button>
          </div>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="button" onclick="resetForm()">Temizle → Yeni Kullanıcı</button>
        <button type="submit" class="button primary" id="submitBtn">Kaydet</button>
      </div>
    </form>
  </div>
</div>

<script>
function resetForm(){
  document.getElementById('f_id').value = 0;
  document.getElementById('f_op').value = ''; // create
  document.getElementById('f_username').value = '';
  document.getElementById('f_username').readOnly = false;
  document.getElementById('f_role').value = 'viewer';
  document.getElementById('f_password').value = '';
  document.getElementById('formTitle').textContent = 'Yeni Kullanıcı';
  document.getElementById('submitBtn').textContent = 'Kaydet';
  document.getElementById('unameHint').style.visibility = 'hidden';
  document.getElementById('pwdLabel').textContent = 'Parola';
  document.getElementById('pwdHint').textContent = 'Yeni kullanıcıda zorunlu; düzenlemede boş bırakırsanız değişmez.';
}
function fillForm(u){
  document.getElementById('f_id').value = u.id;
  document.getElementById('f_op').value = 'update';
  document.getElementById('f_username').value = u.username;
  document.getElementById('f_username').readOnly = true;
  document.getElementById('f_role').value = u.role;
  document.getElementById('f_password').value = '';
  document.getElementById('formTitle').textContent = 'Kullanıcı Düzenle';
  document.getElementById('submitBtn').textContent = 'Değişiklikleri Kaydet';
  document.getElementById('unameHint').style.visibility = 'visible';
  document.getElementById('pwdLabel').textContent = 'Yeni Parola (opsiyonel)';
  document.getElementById('pwdHint').textContent = 'Boş bırakırsanız parola değişmez.';
  window.scrollTo({top:document.body.scrollHeight,behavior:'smooth'});
}
resetForm();

// Sunucuda parola üret
document.getElementById('btnGen').addEventListener('click', async ()=>{
  const btn=this.event?.target || document.getElementById('btnGen');
  btn.disabled=true; btn.textContent='Üretiliyor...';
  try{
    const r = await fetch('users.php?action=genpass'); const j=await r.json();
    document.getElementById('f_password').value = j.password || '';
  }catch(e){ alert('Parola üretilemedi'); }
  btn.disabled=false; btn.textContent='Parola Üret (Sunucu)';
});
// Göster/Gizle
document.getElementById('btnShow').addEventListener('click', ()=>{
  const el=document.getElementById('f_password');
  el.type = (el.type==='password') ? 'text' : 'password';
});

// Tek formu POST etmeden önce op değerini ayarla
document.getElementById('userForm').addEventListener('submit', function(){
  const id = parseInt(document.getElementById('f_id').value,10)||0;
  document.getElementById('f_op').value = id===0 ? 'create' : 'update';
});
</script>
</body>
</html>
