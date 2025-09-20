<?php

// Uygulama başlarken `.env` dosyasını okuyup eksik ortam değişkenlerini yükle
function load_env_file(string $path): void {
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos(ltrim($line), '#') === 0) {
            continue;
        }

        if (strncasecmp($line, 'export ', 7) === 0) {
            $line = substr($line, 7);
        }

        if (strpos($line, '=') === false) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        if ($name === '') {
            continue;
        }

        // Ortamda zaten tanımlıysa .env değerini kullanma
        if (array_key_exists($name, $_ENV) || getenv($name) !== false || array_key_exists($name, $_SERVER)) {
            continue;
        }

        $value = trim($value);
        if ($value !== '') {
            $firstChar = $value[0];
            $lastChar = substr($value, -1);
            if (in_array($firstChar, [chr(34), chr(39)], true) && $firstChar === $lastChar) {
                $value = substr($value, 1, -1);
            }
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }
}

load_env_file(__DIR__ . '/.env');

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

function app_nav_links(): array
{
    $links = [];

    if (!empty($_SESSION['uid'])) {
        $links[] = [
            'id'    => 'index',
            'href'  => 'index.php',
            'label' => 'Ana Ekran',
            'icon'  => '📝',
        ];

        if (($_SESSION['role'] ?? '') === 'admin') {
            $links[] = [
                'id'    => 'admin',
                'href'  => 'admin.php',
                'label' => 'Yönetici',
                'icon'  => '🖥️',
            ];
            $links[] = [
                'id'    => 'users',
                'href'  => 'users.php',
                'label' => 'Kullanıcılar',
                'icon'  => '👤',
            ];
        }

        $links[] = [
            'id'    => 'people',
            'href'  => 'people.php',
            'label' => 'Kişiler',
            'icon'  => '🧑',
        ];
        $links[] = [
            'id'    => 'reasons',
            'href'  => 'reasons.php',
            'label' => 'Nedenler',
            'icon'  => '❓',
        ];
        $links[] = [
            'id'    => 'logout',
            'href'  => 'logout.php',
            'label' => 'Çıkış',
            'icon'  => '🚪',
        ];
    } else {
        $links[] = [
            'id'    => 'login',
            'href'  => 'login.php',
            'label' => 'Giriş Yap',
            'icon'  => '🔐',
        ];
    }

    return $links;
}

function render_topbar(string $active = '', array $options = []): void
{
    $title    = $options['title']    ?? 'Ziyaretçi Kayıt Sistemi';
    $subtitle = $options['subtitle'] ?? null;
    $chips    = $options['chips']    ?? [];
    $links    = $options['links']    ?? app_nav_links();
    $logo     = $options['logo']     ?? 'assets/logo.png';
    $showLogo = $options['showLogo'] ?? true;

    echo '<header class="topbar">';
    echo   '<div class="topbar__inner">';
    echo     '<div class="brand">';
    if ($showLogo) {
        $logoEsc = htmlspecialchars($logo, ENT_QUOTES, 'UTF-8');
        echo     '<img src="' . $logoEsc . '" alt="Logo" class="brand__logo" onerror="this.style.display=\'none\'">';
    }
    echo       '<div class="brand__text">';
    echo         '<span class="brand__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>';
    if ($subtitle) {
        echo     '<span class="brand__subtitle">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    echo       '</div>';
    echo     '</div>';

    echo     '<div class="topbar__actions">';
    if (!empty($chips)) {
        echo   '<div class="topbar__chips">';
        foreach ($chips as $chip) {
            $icon  = $chip['icon']  ?? '';
            $label = $chip['label'] ?? '';
            $value = $chip['value'] ?? '';

            echo '<span class="chip chip--glass">';
            if ($icon !== '') {
                echo '<span class="chip__icon">' . htmlspecialchars((string)$icon, ENT_QUOTES, 'UTF-8') . '</span>';
            }
            if ($label !== '') {
                echo '<span class="chip__label">' . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '</span>';
            }
            if ($value !== '') {
                echo '<span class="chip__value">' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</span>';
            }
            echo '</span>';
        }
        echo   '</div>';
    }

    if (!empty($links)) {
        echo   '<nav class="topnav">';
        foreach ($links as $link) {
            $href   = $link['href']  ?? '#';
            $label  = $link['label'] ?? '';
            if ($label === '') {
                continue;
            }
            $id     = $link['id']    ?? $href;
            $icon   = $link['icon']  ?? '';
            $target = $link['target'] ?? '';

            $classes = 'btn btn--ghost';
            if ($id === $active) {
                $classes .= ' btn--active';
            }

            echo '<a class="' . $classes . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"';
            if ($target !== '') {
                echo ' target="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"';
            }
            echo '>';
            if ($icon !== '') {
                echo '<span class="btn__icon">' . htmlspecialchars((string)$icon, ENT_QUOTES, 'UTF-8') . '</span>';
            }
            echo '<span class="btn__label">' . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '</span>';
            echo '</a>';
        }
        echo   '</nav>';
    }
    echo     '</div>';
    echo   '</div>';
    echo '</header>';
}
