<?php
// Ortam değişkenlerinden uygulama yapılandırmasını oku
function env_value(string $key): ?string {
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }
    if (array_key_exists($key, $_SERVER)) {
        return $_SERVER[$key];
    }
    return null;
}

function require_env(string $key): string {
    $value = env_value($key);
    if ($value === null || $value === '') {
        http_response_code(500);
        die('Konfigürasyon eksik: ' . $key . ' tanımlı değil.');
    }
    return $value;
}

$DB_HOST = require_env('VISIT_DB_HOST');
$DB_NAME = require_env('VISIT_DB_NAME');
$DB_USER = require_env('VISIT_DB_USER');
$DB_PASS = require_env('VISIT_DB_PASS');

if (!defined('ENC_METHOD')) {
    define('ENC_METHOD', 'aes-256-gcm');
}

$ENC_KEY = require_env('VISIT_APP_KEY');
if (strlen($ENC_KEY) < 32) {
    http_response_code(500);
    die('Konfigürasyon hatası: VISIT_APP_KEY en az 32 karakter olmalıdır.');
}

$ENC_SALT = require_env('VISIT_APP_SALT');
if (strlen($ENC_SALT) < 16) {
    http_response_code(500);
    die('Konfigürasyon hatası: VISIT_APP_SALT en az 16 karakter olmalıdır.');
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('Veritabanı bağlantı hatası: ' . htmlspecialchars($e->getMessage()));
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Türkçe tarih yazımı
function tr_date($dateYmd) {
    $ts = strtotime($dateYmd);
    $aylar = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
    $gunler = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
    return date('d ', $ts) . $aylar[(int)date('m', $ts) - 1] . date(' Y ', $ts) . $gunler[(int)date('w', $ts)];
}

// --- TC Kimlik için şifreleme/maskeleme fonksiyonları ---
function tc_encrypt($plain) {
    global $ENC_KEY;
    if ($plain === '' || $plain === null) {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, ENC_METHOD, $ENC_KEY, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
}

function tc_decrypt($blob) {
    global $ENC_KEY;
    if (!$blob) {
        return null;
    }
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 28) {
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    return openssl_decrypt($cipher, ENC_METHOD, $ENC_KEY, OPENSSL_RAW_DATA, $iv, $tag);
}

function tc_hash($plain) {
    global $ENC_SALT;
    return hash('sha256', $ENC_SALT . $plain);
}

function tc_mask($plain) {
    if (!$plain || strlen($plain) < 2) {
        return '***********';
    }
    return str_repeat('*', max(0, strlen($plain) - 2)) . substr($plain, -2);
}

function validate_tc($tc) {
    if (!preg_match('/^[1-9][0-9]{10}$/', $tc)) {
        return false;
    }
    $d = array_map('intval', str_split($tc));
    $odd = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
    $even = $d[1] + $d[3] + $d[5] + $d[7];
    $d10 = ((7 * $odd) - $even) % 10;
    if ($d[9] != $d10) {
        return false;
    }
    $d11 = (array_sum(array_slice($d, 0, 10))) % 10;
    return $d[10] == $d11;
}
