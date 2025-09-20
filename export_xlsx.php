<?php
require __DIR__.'/config.php';
if (empty($_SESSION['uid'])) { header('Location: login.php'); exit; }

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$q    = $_GET['q']    ?? '';
$by   = $_GET['by']   ?? '';   // Ekleyen filtre (opsiyonel)
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

// ---- Kullanıcı sözlüğü (id -> username)
$userMap = [];
try { $userMap = $pdo->query("SELECT id, username FROM users")->fetchAll(PDO::FETCH_KEY_PAIR); } catch(Exception $e){}

// ---- Veri sorgusu (created_by/exit_by dahil)
$sql = "SELECT id, visit_date, visit_time, exit_time, full_name, to_whom, reason, note, tc_enc,
               created_by, exit_by, created_at, updated_at
        FROM visits
        WHERE visit_date BETWEEN ? AND ?";
$params = [$from, $to];

if ($q){
    $sql .= " AND (full_name LIKE ? OR to_whom LIKE ? OR reason LIKE ? OR note LIKE ?)";
    $like = "%$q%"; array_push($params, $like,$like,$like,$like);
}
if ($by !== '') {
    $sql .= " AND created_by = ?";
    $params[] = (int)$by;
}
$sql .= " ORDER BY visit_date DESC, visit_time DESC, id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ------------ Basit XLSX üretici (sharedStrings) ------------ */
function x_cell($col, $row){ // 0-index -> Excel A1
    $c=''; $col++;
    while($col){ $m=($col-1)%26; $c=chr(65+$m).$c; $col=intval(($col-$m-1)/26); }
    return $c.($row+1);
}
function build_xlsx($headers, $data){
    $map=[]; $sst=[];
    $get=function($s)use(&$map,&$sst){
        $s = (string)$s;
        if(!isset($map[$s])){ $map[$s]=count($sst); $sst[]=$s; }
        return $map[$s];
    };

    // sheet1.xml
    $rowsxml = '<row r="1">';
    foreach($headers as $i=>$h){ $rowsxml .= '<c r="'.x_cell($i,0).'" t="s"><v>'.$get($h).'</v></c>'; }
    $rowsxml .= '</row>';

    $r = 1;
    foreach($data as $row){
        $rowsxml .= '<row r="'.($r+1).'">';
        foreach($row as $i=>$v){
            $rowsxml .= '<c r="'.x_cell($i,$r).'" t="s"><v>'.$get((string)$v).'</v></c>';
        }
        $rowsxml .= '</row>';
        $r++;
    }

    $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
               . '<sheetData>'.$rowsxml.'</sheetData></worksheet>';

    // sharedStrings.xml
    $sstxml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($sst).'" uniqueCount="'.count($sst).'">';
    foreach($sst as $s){
        $sstxml .= '<si><t>'.htmlspecialchars($s, ENT_XML1|ENT_QUOTES, "UTF-8").'</t></si>';
    }
    $sstxml .= '</sst>';

    // workbook / rels / content types
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
              . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
              . '<sheets><sheet name="Ziyaretler" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
          . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
          . '</Relationships>';

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '</Types>';

    $app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
         . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
         . '<Application>PHP</Application></Properties>';

    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
          . 'xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Ziyaretler</dc:title></cp:coreProperties>';

    $zip = new ZipArchive();
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $ct);
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('docProps/app.xml', $app);
    $zip->addFromString('docProps/core.xml', $core);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/worksheets/sheet1.xml', $worksheet);
    $zip->addFromString('xl/sharedStrings.xml', $sstxml);
    $zip->close();
    return $tmp;
}

/* ------------ Veriyi hazırla ------------
   Admin ise TC tam; değilse maskeli.
   Ekleyen / Çıkışı veren kullanıcı adları eklenecek.
------------------------------------------ */
$headers = $isAdmin
    ? ['TARİH','GİRİŞ','ÇIKIŞ','ADI SOYADI','KİME GELDİ','ZİYARET NEDENİ','TC NO','NOT','EKLEYEN','ÇIKIŞI VEREN','OLUŞTURMA ZAMANI','GÜNCELLEME ZAMANI']
    : ['TARİH','GİRİŞ','ÇIKIŞ','ADI SOYADI','KİME GELDİ','ZİYARET NEDENİ','TC NO (MASKELİ)','NOT','EKLEYEN','ÇIKIŞI VEREN','OLUŞTURMA ZAMANI','GÜNCELLEME ZAMANI'];

$data = [];
foreach($rows as $r){
    $tc_plain = $r['tc_enc'] ? tc_decrypt($r['tc_enc']) : '';
    $tc_out   = $isAdmin ? $tc_plain : tc_mask($tc_plain);

    $ekleyen = $userMap[$r['created_by']] ?? '';
    $cikisV  = $userMap[$r['exit_by']]     ?? '';

    $data[] = [
        (string)$r['visit_date'],
        substr((string)($r['visit_time'] ?? ''), 0, 5),
        $r['exit_time'] ? substr((string)$r['exit_time'], 0, 5) : '',
        (string)($r['full_name'] ?? ''),
        (string)($r['to_whom']   ?? ''),
        (string)($r['reason']    ?? ''),
        (string)$tc_out,
        (string)($r['note'] ?? ''),
        (string)$ekleyen,
        (string)$cikisV,
        (string)($r['created_at'] ?? ''),
        (string)($r['updated_at'] ?? ''),
    ];
}

/* ------------ Dosyayı gönder ------------ */
$tmp = build_xlsx($headers, $data);
$fname = 'ziyaretler_'.date('Ymd_His').'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$fname.'"');
header('Content-Length: '.filesize($tmp));
readfile($tmp);
unlink($tmp);
exit;
