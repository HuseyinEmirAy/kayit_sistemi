<?php
// cPanel MySQL bilgilerinizi girin
$DB_HOST = 'localhost';
$DB_NAME = 'gurpacka_visit';
$DB_USER = 'gurpacka_visit_user';
$DB_PASS = 'NPh;FodcPqdwF3o^';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('Veritabanı bağlantı hatası: ' . htmlspecialchars($e->getMessage()));
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Türkçe tarih yazımı
function tr_date($dateYmd){
    $ts = strtotime($dateYmd);
    $aylar = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
    $gunler = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
    return date('d ', $ts) . $aylar[(int)date('m',$ts)-1] . date(' Y ', $ts) . $gunler[(int)date('w',$ts)];
}

// --- TC Kimlik için şifreleme/maskeleme fonksiyonları ---
if (!defined('ENC_METHOD')) { define('ENC_METHOD', 'aes-256-gcm'); }
$ENC_KEY = getenv('VISIT_APP_KEY') ?: 'degistir-bu-32-baytlik-anahtar'; // PROD: cPanel Env değişkeni

function tc_encrypt($plain){
    global $ENC_KEY;
    if ($plain === '' || $plain === null) return null;
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($plain, ENC_METHOD, $ENC_KEY, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv.$tag.$cipher);
}
function tc_decrypt($blob){
    global $ENC_KEY;
    if (!$blob) return null;
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 28) return null;
    $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $cipher = substr($raw, 28);
    return openssl_decrypt($cipher, ENC_METHOD, $ENC_KEY, OPENSSL_RAW_DATA, $iv, $tag);
}
function tc_hash($plain){
    $salt = getenv('VISIT_APP_SALT') ?: 'degistir-bunu-salt';
    return hash('sha256', $salt.$plain);
}
function tc_mask($plain){
    if (!$plain || strlen($plain)<2) return '***********';
    return str_repeat('*', max(0, strlen($plain)-2)) . substr($plain, -2);
}
function validate_tc($tc){
    if (!preg_match('/^[1-9][0-9]{10}$/', $tc)) return false;
    $d = array_map('intval', str_split($tc));
    $odd = $d[0]+$d[2]+$d[4]+$d[6]+$d[8];
    $even= $d[1]+$d[3]+$d[5]+$d[7];
    $d10 = ((7*$odd)-$even) % 10;
    if ($d[9] != $d10) return false;
    $d11 = (array_sum(array_slice($d,0,10))) % 10;
    return $d[10] == $d11;
}
