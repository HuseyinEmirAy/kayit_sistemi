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
$totalUsers = count($users);

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kullanıcı Yönetimi</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php
  render_topbar('users', [
      'title'    => 'Kullanıcı Yönetimi',
      'subtitle' => 'Yetkili hesapları yönetin, roller atayın ve parolaları güncelleyin.',
      'chips'    => [
          ['icon' => '👤', 'label' => 'Toplam Kullanıcı', 'value' => $totalUsers],
      ],
  ]);
?>

<main class="app-container">
  <?php if($info): ?><div class="alert alert--success"><?=htmlspecialchars($info)?></div><?php endif; ?>
  <?php if($error): ?><div class="alert alert--error"><?=htmlspecialchars($error)?></div><?php endif; ?>

  <section class="card card--table">
    <div class="card-header">
      <div>
        <h1 class="card-title">Kayıtlı Kullanıcılar</h1>
        <p class="card-subtitle">Sisteme erişimi olan tüm kullanıcı hesapları ve roller.</p>
      </div>
    </div>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Kullanıcı Adı</th>
            <th>Rol</th>
            <th>İşlem</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($users as $u): ?>
          <tr>
            <td data-label="ID"><?=$u['id']?></td>
            <td data-label="Kullanıcı Adı"><?=htmlspecialchars($u['username'])?></td>
            <td data-label="Rol"><span class="badge"><?=htmlspecialchars($roles[$u['role']] ?? $u['role'])?></span></td>
            <td class="actions" data-label="İşlem">
              <button class="btn btn--ghost btn--small" onclick='fillForm(<?=json_encode($u,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>)'>Düzenle</button>
              <?php if($u['id'] != ($_SESSION['uid'] ?? 0)): ?>
              <form method="post" class="inline-form" onsubmit="return confirm('Silinsin mi?');">
                <input type="hidden" name="op" value="delete">
                <input type="hidden" name="id" value="<?=$u['id']?>">
                <button type="submit" class="btn btn--danger btn--small">Sil</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(!$users): ?>
          <tr>
            <td colspan="4" data-label="Bilgi" class="table-empty">Kullanıcı yok</td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title" id="formTitle">Yeni Kullanıcı</h2>
        <p class="card-subtitle">Yeni bir hesap oluşturun veya mevcut bir hesabı güncelleyin.</p>
      </div>
    </div>
    <form method="post" id="userForm" class="form-grid form-grid--two">
      <input type="hidden" name="id" id="f_id" value="0">
      <input type="hidden" name="op" id="f_op" value="">
      <div class="field">
        <label for="f_username">Kullanıcı Adı</label>
        <input type="text" name="username" id="f_username" placeholder="ornek.kullanici">
        <div class="small-text" id="unameHint">Düzenleme modunda değiştirilemez.</div>
      </div>
      <div class="field">
        <label for="f_role">Rol</label>
        <select name="role" id="f_role">
          <?php foreach($roles as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label id="pwdLabel" for="f_password">Parola</label>
        <input type="password" name="password" id="f_password" placeholder="En az 12 karakter">
        <div class="small-text" id="pwdHint">Yeni kullanıcıda zorunlu; düzenlemede boş bırakırsanız değişmez.</div>
      </div>
      <div class="field">
        <label>Hızlı İşlem</label>
        <div class="chip-group">
          <button type="button" class="btn btn--ghost btn--small" id="btnGen">Parola Üret (Sunucu)</button>
          <button type="button" class="btn btn--ghost btn--small" id="btnShow">Göster</button>
        </div>
      </div>
      <div class="form-actions field full">
        <button type="button" class="btn btn--neutral" onclick="resetForm()">Temizle → Yeni Kullanıcı</button>
        <button type="submit" class="btn btn--primary" id="submitBtn">Kaydet</button>
      </div>
    </form>
  </section>
</main>

<script>
function resetForm(){
  document.getElementById('f_id').value = 0;
  document.getElementById('f_op').value = '';
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

const btnGen=document.getElementById('btnGen');
if(btnGen){
  btnGen.addEventListener('click', async ()=>{
    btnGen.disabled=true; btnGen.textContent='Üretiliyor...';
    try{
      const r = await fetch('users.php?action=genpass');
      const j = await r.json();
      document.getElementById('f_password').value = j.password || '';
    }catch(e){ alert('Parola üretilemedi'); }
    btnGen.disabled=false; btnGen.textContent='Parola Üret (Sunucu)';
  });
}

const btnShow=document.getElementById('btnShow');
if(btnShow){
  btnShow.addEventListener('click', ()=>{
    const el=document.getElementById('f_password');
    el.type = (el.type==='password') ? 'text' : 'password';
    btnShow.textContent = el.type==='password' ? 'Göster' : 'Gizle';
  });
}

document.getElementById('userForm').addEventListener('submit', function(){
  const id = parseInt(document.getElementById('f_id').value,10)||0;
  document.getElementById('f_op').value = id===0 ? 'create' : 'update';
});
</script>
</body>
</html>
