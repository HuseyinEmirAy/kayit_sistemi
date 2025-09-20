<?php
require __DIR__.'/config.php';

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','viewer','reception','pii') DEFAULT 'admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS visits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  visit_date DATE NOT NULL,
  visit_time TIME NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  to_whom VARCHAR(120) NOT NULL,
  reason VARCHAR(200) NOT NULL,
  note TEXT NULL,
  tc_enc VARBINARY(255) NULL,
  tc_hash CHAR(64) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (tc_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS people (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  department VARCHAR(120) NULL,
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reasons (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

$pdo->exec($sql);

// İlk admin hesabı
$username = 'admin';
$passplain = bin2hex(random_bytes(4));
$hash = password_hash($passplain, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("INSERT IGNORE INTO users(username, password_hash, role) VALUES(?,?, 'admin')");
$stmt->execute([$username, $hash]);

// Örnek kişiler
$pdo->exec("INSERT IGNORE INTO people(id, name, department, active) VALUES
 (1,'Genel Müdürlük','Yönetim',1),
 (2,'Muhasebe','Mali İşler',1),
 (3,'İK','İnsan Kaynakları',1),
 (4,'Editör Masası','Editoryal',1),
 (5,'Teknik','BT',1)
");

// Örnek nedenler
$pdo->exec("INSERT IGNORE INTO reasons(id, name, active) VALUES
 (1,'Görüşme',1),(2,'Kargo/Teslimat',1),(3,'İş Başvurusu',1),
 (4,'Servis',1),(5,'Toplantı',1),(6,'Diğer',1)
");

echo "<h2>Kurulum tamamlandı</h2>";
echo "<p><b>Admin:</b> {$username}<br><b>Geçici Şifre:</b> {$passplain}</p>";
echo '<p><a href="login.php">Girişe git</a></p>';
