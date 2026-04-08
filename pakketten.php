<?php
require_once __DIR__ . '/includes/config.php';
if (function_exists('opcache_reset')) opcache_reset();

// Admin fix: remove duplicate transport
if (isset($_GET['fix_transport_nl52']) && $_GET['fix_transport_nl52'] === '1') {
    $res = func_dbsi_qry("SELECT transport FROM magazijn_rayon_transport WHERE rayon='NL52' AND seizoen='VJ' AND jaar=2026");
    if ($res && $row = $res->fetch_assoc()) {
        $current = intval($row['transport']);
        $new = $current & ~(1 << 1);
        func_dbsi_qry("UPDATE magazijn_rayon_transport SET transport=$new WHERE rayon='NL52' AND seizoen='VJ' AND jaar=2026");
        echo "Transport 2 verwijderd (was: $current, nu: $new)";
        exit;
    }
}

@func_dbsi_qry("CREATE TABLE IF NOT EXISTS magazijn_voorraad (id INT AUTO_INCREMENT PRIMARY KEY, klantnaam VARCHAR(255) NOT NULL, seizoen VARCHAR(10) NOT NULL, jaar INT NOT NULL, op_voorraad TINYINT(1) DEFAULT 0, bijgewerkt DATETIME DEFAULT NULL, UNIQUE KEY uk_kv (klantnaam,seizoen,jaar))");
@func_dbsi_qry("ALTER TABLE magazijn_voorraad ADD COLUMN IF NOT EXISTS in_druk TINYINT(1) DEFAULT 0");
@func_dbsi_qry("ALTER TABLE magazijn_voorraad ADD COLUMN IF NOT EXISTS wordt_gemaakt TINYINT(1) DEFAULT 0");
@func_dbsi_qry("CREATE TABLE IF NOT EXISTS magazijn_rayon_versies (rayon VARCHAR(20) NOT NULL, seizoen VARCHAR(10) NOT NULL, jaar INT NOT NULL, versies INT DEFAULT 0, PRIMARY KEY (rayon, seizoen, jaar))");
@func_dbsi_qry("CREATE TABLE IF NOT EXISTS magazijn_rayon_transport (rayon VARCHAR(20) NOT NULL, seizoen VARCHAR(10) NOT NULL, jaar INT NOT NULL, transport INT DEFAULT 0, PRIMARY KEY (rayon, seizoen, jaar))");
@func_dbsi_qry("CREATE TABLE IF NOT EXISTS magazijn_rayon_transport_fotos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rayon VARCHAR(20) NOT NULL,
    seizoen VARCHAR(10) NOT NULL,
    jaar INT NOT NULL,
    transport_nr TINYINT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rayon_scan (rayon, seizoen, jaar, transport_nr)
)");
@func_dbsi_qry("ALTER TABLE magazijn_rayon_transport ADD COLUMN IF NOT EXISTS gewicht_1 DECIMAL(8,1) DEFAULT NULL");
@func_dbsi_qry("ALTER TABLE magazijn_rayon_transport ADD COLUMN IF NOT EXISTS gewicht_2 DECIMAL(8,1) DEFAULT NULL");
@func_dbsi_qry("ALTER TABLE magazijn_rayon_transport ADD COLUMN IF NOT EXISTS gewicht_3 DECIMAL(8,1) DEFAULT NULL");
@func_dbsi_qry("ALTER TABLE magazijn_rayon_transport ADD COLUMN IF NOT EXISTS gewicht_4 DECIMAL(8,1) DEFAULT NULL");
@func_dbsi_qry("ALTER TABLE magazijn_rayon_transport ADD COLUMN IF NOT EXISTS gewicht_5 DECIMAL(8,1) DEFAULT NULL");
@func_dbsi_qry("ALTER TABLE magazijn_rayon_transport ADD COLUMN IF NOT EXISTS folders_1 INT DEFAULT NULL");
@func_dbsi_qry("ALTER TABLE magazijn_rayon_transport ADD COLUMN IF NOT EXISTS folders_2 INT DEFAULT NULL");
@func_dbsi_qry("ALTER TABLE magazijn_rayon_transport ADD COLUMN IF NOT EXISTS folders_3 INT DEFAULT NULL");
@func_dbsi_qry("ALTER TABLE magazijn_rayon_transport ADD COLUMN IF NOT EXISTS folders_4 INT DEFAULT NULL");
@func_dbsi_qry("ALTER TABLE magazijn_rayon_transport ADD COLUMN IF NOT EXISTS folders_5 INT DEFAULT NULL");
@func_dbsi_qry("CREATE TABLE IF NOT EXISTS magazijn_rayon_batch (rayon VARCHAR(20) NOT NULL, seizoen VARCHAR(10) NOT NULL, jaar INT NOT NULL, selected INT DEFAULT 0, gedrukt INT DEFAULT 0, PRIMARY KEY (rayon, seizoen, jaar))");

// Retroactief rayon_status invullen - alleen op dashboard (niet bij AJAX/POST/print/wb)
if (!isset($_GET['action']) && !isset($_GET['wb']) && !isset($_GET['print']) && !isset($_GET['loodswb']) && !isset($_POST['action'])) {
    try {
        $retRes = func_dbsi_qry("SELECT wb.werkbon_code, wb.rayons, wb.medewerker, wb.eind_tijd
            FROM magazijn_werkbonnen wb
            WHERE wb.status = 'klaar' AND wb.rayons != ''
            AND NOT EXISTS (SELECT 1 FROM magazijn_rayon_status rs WHERE rs.werkbon_code = wb.werkbon_code)
            LIMIT 50");
        if ($retRes) { while ($rw = $retRes->fetch_assoc()) {
            $retRayons = array_filter(array_map('trim', explode(',', $rw['rayons'])));
            $retMw = safe($rw['medewerker'] ?? 'systeem');
            $retDt = $rw['eind_tijd'] ? safe($rw['eind_tijd']) : date('Y-m-d H:i:s');
            $retC = [];
            foreach ($retRayons as $retRy) {
                $retIdx = isset($retC[$retRy]) ? $retC[$retRy] : 0;
                try { func_dbsi_qry("INSERT IGNORE INTO magazijn_rayon_status (werkbon_code, rayon, rayon_idx, klaar, medewerker, klaar_op)
                    VALUES ('".safe($rw['werkbon_code'])."','".safe($retRy)."',$retIdx,1,'$retMw','$retDt')");
                } catch (Exception $e) {}
                $retC[$retRy] = $retIdx + 1;
            }
        }}
    } catch (Exception $e) {}
}


$limburgRayons = ['NL58','NL59','NL60','NL61','NL62','NL63','NL64'];

function pakRegio($rayonsStr, $lRayons) {
    $ra = array_filter(array_map('trim', explode(',', $rayonsStr)));
    $l=false; $b=false; $r=false;
    foreach ($ra as $x) {
        if (in_array($x,$lRayons)) $l=true;
        elseif (substr($x,0,2)==='BE'||substr($x,0,2)==='DE') $b=true;
        else $r=true;
    }
    if ($l&&!$b&&!$r) return ['Limburg'=>$ra];
    if ($b&&!$l&&!$r) return ['Buitenland'=>$ra];
    if ($r&&!$l&&!$b) return ['RestNL'=>$ra];
    $out=[];
    $rL=array_values(array_filter($ra,function($x)use($lRayons){return in_array($x,$lRayons);}));
    $rR=array_values(array_filter($ra,function($x)use($lRayons){return !in_array($x,$lRayons)&&substr($x,0,2)!=='BE'&&substr($x,0,2)!=='DE';}));
    $rB=array_values(array_filter($ra,function($x){return substr($x,0,2)==='BE'||substr($x,0,2)==='DE';}));
    if($rL) $out['Limburg']=$rL;
    if($rR) $out['RestNL']=$rR;
    if($rB) $out['Buitenland']=$rB;
    return $out?:['RestNL'=>$ra];
}

// ============================================================
// AJAX: weekdata
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'toggle_voorraad') {
    header('Content-Type: application/json');
    $kn = trim($_POST['klantnaam'] ?? '');
    $sz = trim($_POST['seizoen'] ?? '');
    $jr = intval($_POST['jaar'] ?? date('Y'));
    $vw = intval($_POST['waarde'] ?? 0);
    if ($kn && $sz) {
        func_dbsi_qry("INSERT INTO magazijn_voorraad (klantnaam, seizoen, jaar, op_voorraad, bijgewerkt)
            VALUES ('".safe($kn)."','".safe($sz)."',$jr,$vw,NOW())
            ON DUPLICATE KEY UPDATE op_voorraad=$vw, bijgewerkt=NOW()");
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'toggle_in_druk') {
    header('Content-Type: application/json');
    $kn = trim($_POST['klantnaam'] ?? '');
    $sz = trim($_POST['seizoen'] ?? '');
    $jr = intval($_POST['jaar'] ?? date('Y'));
    $vw = intval($_POST['waarde'] ?? 0);
    if ($kn && $sz) {
        func_dbsi_qry("INSERT INTO magazijn_voorraad (klantnaam, seizoen, jaar, in_druk, bijgewerkt)
            VALUES ('".safe($kn)."','".safe($sz)."',$jr,$vw,NOW())
            ON DUPLICATE KEY UPDATE in_druk=$vw, bijgewerkt=NOW()");
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'toggle_wordt_gemaakt') {
    header('Content-Type: application/json');
    $kn = trim($_POST['klantnaam'] ?? '');
    $sz = trim($_POST['seizoen'] ?? '');
    $jr = intval($_POST['jaar'] ?? date('Y'));
    $vw = intval($_POST['waarde'] ?? 0);
    if ($kn && $sz) {
        func_dbsi_qry("INSERT INTO magazijn_voorraad (klantnaam, seizoen, jaar, wordt_gemaakt, bijgewerkt)
            VALUES ('".safe($kn)."','".safe($sz)."',$jr,$vw,NOW())
            ON DUPLICATE KEY UPDATE wordt_gemaakt=$vw, bijgewerkt=NOW()");
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'nieuw_batch') {
    header('Content-Type: application/json');
    $sz = trim($_POST['seizoen'] ?? '');
    $jr = intval($_POST['jaar'] ?? date('Y'));
    if ($sz) {
        // Reset selected maar bewaar gedrukt (groene vakjes blijven staan)
        func_dbsi_qry("UPDATE magazijn_rayon_batch SET selected=0 WHERE seizoen='".safe($sz)."' AND jaar=$jr");
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'bevestig_batch') {
    header('Content-Type: application/json');
    $sz = trim($_POST['seizoen'] ?? '');
    $jr = intval($_POST['jaar'] ?? date('Y'));
    if ($sz) {
        func_dbsi_qry("UPDATE magazijn_rayon_batch SET gedrukt=selected WHERE seizoen='".safe($sz)."' AND jaar=$jr AND selected>0");
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'toggle_rayon_batch') {
    header('Content-Type: application/json');
    $ry = trim($_POST['rayon'] ?? '');
    $sz = trim($_POST['seizoen'] ?? '');
    $jr = intval($_POST['jaar'] ?? date('Y'));
    $bn = intval($_POST['batch_nr'] ?? 0); // 1-5
    $vw = intval($_POST['waarde'] ?? 0);
    if ($ry && $sz && $bn >= 1 && $bn <= 5) {
        $bit = 1 << ($bn - 1);
        if ($vw) {
            func_dbsi_qry("INSERT INTO magazijn_rayon_batch (rayon,seizoen,jaar,selected) VALUES ('".safe($ry)."','".safe($sz)."',$jr,$bit) ON DUPLICATE KEY UPDATE selected=selected|$bit");
        } else {
            func_dbsi_qry("INSERT INTO magazijn_rayon_batch (rayon,seizoen,jaar,selected) VALUES ('".safe($ry)."','".safe($sz)."',$jr,0) ON DUPLICATE KEY UPDATE selected=selected&~$bit");
        }
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'reset_rayon_batch_enkel') {
    header('Content-Type: application/json');
    $ry = trim($_POST['rayon'] ?? '');
    $sz = trim($_POST['seizoen'] ?? '');
    $jr = intval($_POST['jaar'] ?? date('Y'));
    if ($ry && $sz) {
        func_dbsi_qry("UPDATE magazijn_rayon_batch SET selected=0, gedrukt=0 WHERE rayon='".safe($ry)."' AND seizoen='".safe($sz)."' AND jaar=$jr");
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'reset_rayon_batch') {
    header('Content-Type: application/json');
    $ry = trim($_POST['rayon'] ?? '');
    $sz = trim($_POST['seizoen'] ?? '');
    $jr = intval($_POST['jaar'] ?? date('Y'));
    if ($ry && $sz) {
        func_dbsi_qry("INSERT INTO magazijn_rayon_batch (rayon,seizoen,jaar,selected,gedrukt) VALUES ('".safe($ry)."','".safe($sz)."',$jr,0,0) ON DUPLICATE KEY UPDATE selected=0, gedrukt=0");
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'reset_rayon_transport') {
    header('Content-Type: application/json');
    $ry = trim($_POST['rayon'] ?? '');
    $sz = trim($_POST['seizoen'] ?? '');
    $jr = intval($_POST['jaar'] ?? date('Y'));
    if ($ry && $sz) {
        func_dbsi_qry("INSERT INTO magazijn_rayon_transport (rayon,seizoen,jaar,transport,gewicht_1,gewicht_2,gewicht_3,gewicht_4,gewicht_5)
            VALUES ('".safe($ry)."','".safe($sz)."',$jr,0,NULL,NULL,NULL,NULL,NULL)
            ON DUPLICATE KEY UPDATE transport=0,gewicht_1=NULL,gewicht_2=NULL,gewicht_3=NULL,gewicht_4=NULL,gewicht_5=NULL");
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'set_versie_select') {
    header('Content-Type: application/json');
    $ry = trim($_POST['rayon'] ?? '');
    $sz = trim($_POST['seizoen'] ?? '');
    $jr = intval($_POST['jaar'] ?? date('Y'));
    $vn = intval($_POST['versie'] ?? 0); // 0=geen, 1-5
    if ($ry && $sz) {
        $bits = ($vn >= 1 && $vn <= 5) ? (1 << ($vn - 1)) : 0;
        func_dbsi_qry("INSERT INTO magazijn_rayon_versies (rayon, seizoen, jaar, versies) VALUES ('".safe($ry)."','".safe($sz)."',$jr,$bits) ON DUPLICATE KEY UPDATE versies=$bits");
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'toggle_rayon_versie') {
    header('Content-Type: application/json');
    $ry = trim($_POST['rayon'] ?? '');
    $sz = trim($_POST['seizoen'] ?? '');
    $jr = intval($_POST['jaar'] ?? date('Y'));
    $vn = intval($_POST['versie_nr'] ?? 1);
    $vw = intval($_POST['waarde'] ?? 0);
    if ($ry && $sz && $vn >= 1 && $vn <= 5) {
        $bit = 1 << ($vn - 1);
        if ($vw) {
            func_dbsi_qry("INSERT INTO magazijn_rayon_versies (rayon, seizoen, jaar, versies) VALUES ('".safe($ry)."','".safe($sz)."',$jr,$bit) ON DUPLICATE KEY UPDATE versies = versies | $bit");
        } else {
            func_dbsi_qry("INSERT INTO magazijn_rayon_versies (rayon, seizoen, jaar, versies) VALUES ('".safe($ry)."','".safe($sz)."',$jr,0) ON DUPLICATE KEY UPDATE versies = versies & ~$bit");
        }
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'weekdata') {
    header('Content-Type: application/json');
    $wd=[]; $mwList=[];
    $res=func_dbsi_qry("SELECT medewerker,DATE(klaar_op) as dag,YEAR(klaar_op) as yr,WEEK(klaar_op,1) as wk,COUNT(*) as n FROM magazijn_rayon_status WHERE klaar=1 AND medewerker IS NOT NULL AND klaar_op IS NOT NULL AND klaar_op>=DATE_SUB(NOW(),INTERVAL 10 WEEK) GROUP BY medewerker,DATE(klaar_op),YEAR(klaar_op),WEEK(klaar_op,1) ORDER BY dag");
    if($res){while($r=$res->fetch_assoc()){$wk=$r['yr'].'-W'.str_pad($r['wk'],2,'0',STR_PAD_LEFT);$wd[$wk][$r['medewerker']][$r['dag']]=$r['n'];if(!in_array($r['medewerker'],$mwList))$mwList[]=$r['medewerker'];}}
    $gd=[];$gdagen=[];
    $res2=func_dbsi_qry("SELECT medewerker,DATE(klaar_op) as dag,COUNT(*) as n FROM magazijn_rayon_status WHERE klaar=1 AND medewerker IS NOT NULL AND klaar_op IS NOT NULL AND klaar_op>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY medewerker,DATE(klaar_op) ORDER BY dag");
    if($res2){while($r=$res2->fetch_assoc()){if(!in_array($r['dag'],$gdagen))$gdagen[]=$r['dag'];$gd[$r['medewerker']][$r['dag']]=$r['n'];}}
    sort($mwList);sort($gdagen);$weken=array_keys($wd);rsort($weken);
    echo json_encode(['weeks'=>$wd,'medewerkers'=>$mwList,'huidig'=>$weken[0]??date('Y').'-W'.date('W'),'grafiek'=>$gd,'grafiekDagen'=>$gdagen]);
    exit;
}

// ============================================================
// POST HANDLERS
// ============================================================
if (isset($_POST['action']) && $_POST['action']==='start_werkbon') {
    header('Content-Type: application/json');
    $code=trim($_POST['code']??'');$mw=trim($_POST['medewerker']??'');
    if($code&&$mw){
        $fp=null;
        if(isset($_FILES['foto'])&&$_FILES['foto']['error']===0){$ud=__DIR__.'/uploads/werkbon/';if(!is_dir($ud))mkdir($ud,0755,true);$ext=strtolower(pathinfo($_FILES['foto']['name'],PATHINFO_EXTENSION));$fp='uploads/werkbon/'.safe($code).'_start_'.time().'.'.$ext;move_uploaded_file($_FILES['foto']['tmp_name'],__DIR__.'/'.$fp);}
        $fq=$fp?",foto_start='".safe($fp)."'":'';
        func_dbsi_qry("UPDATE magazijn_werkbonnen SET status='bezig',medewerker='".safe($mw)."',start_tijd=NOW()$fq WHERE werkbon_code='".safe($code)."' AND status='nieuw'");
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false,'error'=>'Verplicht']);
    exit;
}

if (isset($_POST['action']) && $_POST['action']==='klaar_werkbon') {
    header('Content-Type: application/json');
    $code=trim($_POST['code']??'');$notitie=trim($_POST['notitie']??'');
    if($code){
        $fp=null;
        if(isset($_FILES['foto'])&&$_FILES['foto']['error']===0){$ud=__DIR__.'/uploads/werkbon/';if(!is_dir($ud))mkdir($ud,0755,true);$ext=strtolower(pathinfo($_FILES['foto']['name'],PATHINFO_EXTENSION));$fp='uploads/werkbon/'.safe($code).'_eind_'.time().'.'.$ext;move_uploaded_file($_FILES['foto']['tmp_name'],__DIR__.'/'.$fp);}
        $fq=$fp?",foto_eind='".safe($fp)."'":'';$nq=$notitie?",notitie='".safe($notitie)."'":'';
        func_dbsi_qry("UPDATE magazijn_werkbonnen SET status='klaar',eind_tijd=NOW()$fq$nq WHERE werkbon_code='".safe($code)."'");
        $res=func_dbsi_qry("SELECT klantnaam,seizoen,jaar,medewerker FROM magazijn_werkbonnen WHERE werkbon_code='".safe($code)."'");
        if($res&&$r=$res->fetch_assoc()){$rf=func_dbsi_qry("SELECT foldernaam FROM klant_folders WHERE klantnaam='".safe($r['klantnaam'])."' AND actief=1");if($rf){while($f=$rf->fetch_assoc()){func_dbsi_qry("INSERT INTO pakketten_folder_verwerkt (foldernaam,klantnaam,seizoen,jaar,verwerkt,verwerkt_door,verwerkt_op) VALUES ('".safe($f['foldernaam'])."','".safe($r['klantnaam'])."','".safe($r['seizoen'])."',{$r['jaar']},1,'".safe($r['medewerker'])."',NOW()) ON DUPLICATE KEY UPDATE verwerkt=1,verwerkt_door='".safe($r['medewerker'])."',verwerkt_op=NOW()");}}}
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false,'error'=>'Code verplicht']);
    exit;
}

if (isset($_POST['action']) && $_POST['action']==='reset_werkbon') {
    header('Content-Type: application/json');
    $code=trim($_POST['code']??'');
    if($code){func_dbsi_qry("UPDATE magazijn_werkbonnen SET status='nieuw',medewerker=NULL,start_tijd=NULL,eind_tijd=NULL,foto_start=NULL,foto_eind=NULL,notitie=NULL WHERE werkbon_code='".safe($code)."'");echo json_encode(['success'=>true]);}
    exit;
}

if (isset($_POST['action']) && $_POST['action']==='quick_afvinken') {
    header('Content-Type: application/json');
    $code=trim($_POST['code']??'');$mw=trim($_POST['medewerker']??'');$datum=trim($_POST['datum']??'');$vw=intval($_POST['verwerkt']??1);
    if($code&&$mw){
        $dtVal=$datum?safe($datum).' '.date('H:i:s'):date('Y-m-d H:i:s');
        if($vw){
            func_dbsi_qry("UPDATE magazijn_werkbonnen SET status='klaar',medewerker='".safe($mw)."',start_tijd=COALESCE(start_tijd,'$dtVal'),eind_tijd='$dtVal' WHERE werkbon_code='".safe($code)."'");
            $resR=func_dbsi_qry("SELECT rayons FROM magazijn_werkbonnen WHERE werkbon_code='".safe($code)."'");
            if($resR&&$rr=$resR->fetch_assoc()){$ar=array_filter(array_map('trim',explode(',',$rr['rayons'])));$rc=[];foreach($ar as $ry){$idx=isset($rc[$ry])?$rc[$ry]:0;func_dbsi_qry("INSERT INTO magazijn_rayon_status (werkbon_code,rayon,rayon_idx,klaar,medewerker,klaar_op) VALUES ('".safe($code)."','".safe($ry)."',$idx,1,'".safe($mw)."','$dtVal') ON DUPLICATE KEY UPDATE klaar=1,medewerker='".safe($mw)."',klaar_op='$dtVal'");$rc[$ry]=$idx+1;}}
            $res=func_dbsi_qry("SELECT klantnaam,seizoen,jaar FROM magazijn_werkbonnen WHERE werkbon_code='".safe($code)."'");
            if($res&&$r=$res->fetch_assoc()){$rf=func_dbsi_qry("SELECT foldernaam FROM klant_folders WHERE klantnaam='".safe($r['klantnaam'])."' AND actief=1");if($rf){while($f=$rf->fetch_assoc()){func_dbsi_qry("INSERT INTO pakketten_folder_verwerkt (foldernaam,klantnaam,seizoen,jaar,verwerkt,verwerkt_door,verwerkt_op) VALUES ('".safe($f['foldernaam'])."','".safe($r['klantnaam'])."','".safe($r['seizoen'])."',{$r['jaar']},1,'".safe($mw)."','$dtVal') ON DUPLICATE KEY UPDATE verwerkt=1,verwerkt_door='".safe($mw)."',verwerkt_op='$dtVal'");}}}
        } else {
            func_dbsi_qry("UPDATE magazijn_werkbonnen SET status='nieuw',medewerker=NULL,start_tijd=NULL,eind_tijd=NULL WHERE werkbon_code='".safe($code)."'");
            func_dbsi_qry("DELETE FROM magazijn_rayon_status WHERE werkbon_code='".safe($code)."'");
        }
        $qd=0;$qdk=0;$resQA=func_dbsi_qry("SELECT rayons,jaar FROM magazijn_werkbonnen WHERE werkbon_code='".safe($code)."'");
        if($resQA&&$qaR=$resQA->fetch_assoc()){$ql=array_filter(array_map('trim',explode(',',$qaR['rayons'])));$qLP=[];$resQL=func_dbsi_qry("SELECT Rayon,COUNT(*) as n FROM hubmedia_locaties WHERE (Status='0' OR Status='Actief' OR Status='".$qaR['jaar']."') GROUP BY Rayon");if($resQL){while($rL=$resQL->fetch_assoc())$qLP[$rL['Rayon']]=$rL['n'];}$qRS=[];$resQRS=func_dbsi_qry("SELECT rayon,rayon_idx,klaar FROM magazijn_rayon_status WHERE werkbon_code='".safe($code)."'");if($resQRS){while($rRS=$resQRS->fetch_assoc())$qRS[$rRS['rayon'].'-'.$rRS['rayon_idx']]=$rRS['klaar'];}$qC=[];foreach($ql as $qr){$qi=isset($qC[$qr])?$qC[$qr]:0;$ql2=isset($qLP[$qr])?$qLP[$qr]:0;$qd+=$ql2;if(isset($qRS[$qr.'-'.$qi])&&$qRS[$qr.'-'.$qi])$qdk+=$ql2;$qC[$qr]=$qi+1;}}
        echo json_encode(['success'=>true,'doosjes'=>$qd,'doosjes_klaar'=>$qdk]);
    } else echo json_encode(['success'=>false,'error'=>'Verplicht']);
    exit;
}

if (isset($_POST['action']) && $_POST['action']==='toggle_rayon') {
    header('Content-Type: application/json');
    $code=trim($_POST['code']??'');$rayon=trim($_POST['rayon']??'');$rayonIdx=intval($_POST['rayon_idx']??0);$mw=trim($_POST['medewerker']??'');$datum=trim($_POST['datum']??'');$vw=intval($_POST['verwerkt']??1);
    if($code&&$rayon&&$mw){
        $dtVal=$datum?safe($datum).' '.date('H:i:s'):date('Y-m-d H:i:s');
        if($vw){func_dbsi_qry("INSERT INTO magazijn_rayon_status (werkbon_code,rayon,rayon_idx,klaar,medewerker,klaar_op) VALUES ('".safe($code)."','".safe($rayon)."',$rayonIdx,1,'".safe($mw)."','$dtVal') ON DUPLICATE KEY UPDATE klaar=1,medewerker='".safe($mw)."',klaar_op='$dtVal'");}
        else{func_dbsi_qry("UPDATE magazijn_rayon_status SET klaar=0,medewerker=NULL,klaar_op=NULL WHERE werkbon_code='".safe($code)."' AND rayon='".safe($rayon)."' AND rayon_idx=$rayonIdx");}
        $resAll=func_dbsi_qry("SELECT rayons FROM magazijn_werkbonnen WHERE werkbon_code='".safe($code)."'");
        $alleKlaar=false;$aantalKlaar=0;$totaalRayons=0;
        if($resAll&&$rr=$resAll->fetch_assoc()){$ar=array_filter(array_map('trim',explode(',',$rr['rayons'])));$totaalRayons=count($ar);$resC=func_dbsi_qry("SELECT COUNT(*) as n FROM magazijn_rayon_status WHERE werkbon_code='".safe($code)."' AND klaar=1");$aantalKlaar=($resC&&$rc=$resC->fetch_assoc())?$rc['n']:0;$alleKlaar=($aantalKlaar>=$totaalRayons);}
        if($alleKlaar){func_dbsi_qry("UPDATE magazijn_werkbonnen SET status='klaar',medewerker=COALESCE(medewerker,'".safe($mw)."'),start_tijd=COALESCE(start_tijd,'$dtVal'),eind_tijd='$dtVal' WHERE werkbon_code='".safe($code)."'");}
        else{func_dbsi_qry("UPDATE magazijn_werkbonnen SET status='bezig',medewerker=COALESCE(medewerker,'".safe($mw)."'),start_tijd=COALESCE(start_tijd,'$dtVal'),eind_tijd=NULL WHERE werkbon_code='".safe($code)."'");}
        $d=0;$dk=0;
        if(isset($ar)){$lpR=[];$resLP=func_dbsi_qry("SELECT Rayon,COUNT(*) as n FROM hubmedia_locaties WHERE (Status='0' OR Status='Actief') GROUP BY Rayon");if($resLP){while($rLP=$resLP->fetch_assoc())$lpR[$rLP['Rayon']]=$rLP['n'];}$rsA=[];$resRS2=func_dbsi_qry("SELECT rayon,rayon_idx,klaar FROM magazijn_rayon_status WHERE werkbon_code='".safe($code)."'");if($resRS2){while($r2=$resRS2->fetch_assoc())$rsA[$r2['rayon'].'-'.$r2['rayon_idx']]=$r2['klaar'];}$rcT=[];foreach($ar as $aRy){$aIx=isset($rcT[$aRy])?$rcT[$aRy]:0;$aL=isset($lpR[$aRy])?$lpR[$aRy]:0;$d+=$aL;if(isset($rsA[$aRy.'-'.$aIx])&&$rsA[$aRy.'-'.$aIx])$dk+=$aL;$rcT[$aRy]=$aIx+1;}}
        echo json_encode(['success'=>true,'alle_klaar'=>$alleKlaar,'klaar'=>$aantalKlaar,'totaal'=>$totaalRayons,'doosjes'=>$d,'doosjes_klaar'=>$dk]);
    } else echo json_encode(['success'=>false,'error'=>'Verplicht']);
    exit;
}

if (isset($_POST['action']) && $_POST['action']==='create_bulk_werkbonnen') {
    header('Content-Type: application/json');
    $items=json_decode($_POST['items']??'[]',true);$created=0;
    $lpR=[];$rL=func_dbsi_qry("SELECT Rayon,COUNT(*) as n FROM hubmedia_locaties WHERE (Status='0' OR Status='Actief') GROUP BY Rayon");
    if($rL){while($r=$rL->fetch_assoc())$lpR[$r['Rayon']]=$r['n'];}
    foreach($items as $item){
        $kn=$item['kn']??'';$sz=$item['sz']??'';$jr=intval($item['jr']??date('Y'));$rayons=$item['rayons']??'';
        if(!$kn||!$sz||!$rayons)continue;
        $chk=func_dbsi_qry("SELECT id FROM magazijn_werkbonnen WHERE klantnaam='".safe($kn)."' AND seizoen='".safe($sz)."' AND jaar=$jr");
        if($chk&&$chk->fetch_assoc())continue;
        $rys=explode(',',$rayons);$tl=0;foreach($rys as $ry)$tl+=isset($lpR[trim($ry)])?$lpR[trim($ry)]:0;
        $code='WB-'.strtoupper(substr(md5($kn.$sz.$jr.microtime()),0,6)).'-'.rand(100,999);
        func_dbsi_qry("INSERT INTO magazijn_werkbonnen (werkbon_code,klantnaam,seizoen,jaar,rayons,totaal_locaties,status) VALUES ('".safe($code)."','".safe($kn)."','".safe($sz)."',$jr,'".safe($rayons)."',$tl,'nieuw')");
        $created++;
    }
    echo json_encode(['success'=>true,'created'=>$created]);
    exit;
}

// ============================================================
// WERKBON VIEW (mobiel / QR)
// ============================================================
if (isset($_GET['wb'])) {
    $code=trim($_GET['wb']);
    // Support lookup by klantnaam+seizoen+jaar+regio
    if (strpos($code, 'KLANT:') === 0) {
        $parts = explode('|', substr($code, 6));
        $kn = $parts[0] ?? ''; $sz = $parts[1] ?? ''; $jr = intval($parts[2] ?? date('Y'));
        $res = func_dbsi_qry("SELECT * FROM magazijn_werkbonnen WHERE klantnaam='".safe($kn)."' AND seizoen='".safe($sz)."' AND jaar=$jr LIMIT 1");
    } else {
        $res=func_dbsi_qry("SELECT * FROM magazijn_werkbonnen WHERE werkbon_code='".safe($code)."'");
    }
    if(!$res||!($wb=$res->fetch_assoc())){echo "<h1>Niet gevonden</h1>";exit;}
    $mws=[];$resMw=func_dbsi_qry("SELECT DISTINCT naam FROM hubmedia_chauffeurs WHERE actief=1 ORDER BY naam");
    if($resMw){while($r=$resMw->fetch_assoc())$mws[]=$r['naam'];}
    if(empty($mws))$mws=['Hub','Rob','Maikel','Nigel'];
    $folders=[];$resF=func_dbsi_qry("SELECT foldernaam FROM klant_folders WHERE klantnaam='".safe($wb['klantnaam'])."' AND actief=1 ORDER BY foldernaam");
    if($resF){while($r=$resF->fetch_assoc())$folders[]=$r['foldernaam'];}
    $filterRegioW = trim($_GET['regio'] ?? '');
    $rayonsParamW = trim($_GET['rayons'] ?? '');
    if ($rayonsParamW) {
        $rayonList = array_filter(array_map('trim', explode(',', $rayonsParamW)));
    } else {
        $allRayonsW = [];
        $allWbResW = func_dbsi_qry("SELECT rayons FROM magazijn_werkbonnen WHERE klantnaam='".safe($wb['klantnaam'])."' AND seizoen='".safe($wb['seizoen'])."' AND jaar=".intval($wb['jaar']));
        if ($allWbResW) { while ($allWbRW=$allWbResW->fetch_assoc()) {
            foreach (array_filter(array_map('trim', explode(',', $allWbRW['rayons']))) as $ry) {
                if ($ry && !in_array($ry, $allRayonsW)) $allRayonsW[] = $ry;
            }
        }}
        if ($filterRegioW) {
            $limburgRayonsW = ['NL58','NL59','NL60','NL61','NL62','NL63','NL64'];
            $rayonList = array_values(array_filter($allRayonsW, function($r) use ($filterRegioW, $limburgRayonsW) {
                $isL = in_array($r, $limburgRayonsW);
                $isB = substr($r,0,2)==='BE'||substr($r,0,2)==='DE';
                if ($filterRegioW==='Limburg') return $isL;
                if ($filterRegioW==='Buitenland') return $isB;
                return !$isL && !$isB;
            }));
        } else {
            $rayonList = $allRayonsW;
        }
    }
    $wbRS=[];$resRS=func_dbsi_qry("SELECT rayon,rayon_idx,klaar,medewerker FROM magazijn_rayon_status WHERE werkbon_code='".safe($code)."'");
    if($resRS){while($r=$resRS->fetch_assoc())$wbRS[$r['rayon'].'-'.$r['rayon_idx']]=$r;}
    $wbRyC=[];$nRK=0;$mD=0;$mDK=0;
    $mLP=[];$mRL=func_dbsi_qry("SELECT Rayon,COUNT(*) as n FROM hubmedia_locaties WHERE (Status='0' OR Status='Actief' OR Status='".$wb['jaar']."') GROUP BY Rayon");
    if($mRL){while($r=$mRL->fetch_assoc())$mLP[$r['Rayon']]=$r['n'];}
    foreach($rayonList as $tmpR){$tmpR=trim($tmpR);$ti=isset($wbRyC[$tmpR])?$wbRyC[$tmpR]:0;$tl=isset($mLP[$tmpR])?$mLP[$tmpR]:0;$mD+=$tl;if(isset($wbRS[$tmpR.'-'.$ti])&&$wbRS[$tmpR.'-'.$ti]['klaar']){$nRK++;$mDK+=$tl;}$wbRyC[$tmpR]=$ti+1;}
?><!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no"><title>Werkbon <?=$wb['werkbon_code']?></title>
<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:-apple-system,sans-serif;background:#f0f2f5}
.c{max-width:500px;margin:0 auto;padding:12px}.hdr{background:linear-gradient(135deg,#1e3a5f,#2d5a8e);color:#fff;border-radius:16px;padding:20px;margin-bottom:16px;text-align:center}
.hdr h1{font-size:18px}.hdr .code{font-size:13px;opacity:.7;font-family:monospace}.card{background:#fff;border-radius:16px;padding:20px;margin-bottom:12px;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.card h2{font-size:15px;color:#64748b;margin-bottom:12px;font-weight:600}.inf{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9}.inf:last-child{border:none}
.il{color:#94a3b8;font-size:13px}.iv{font-weight:600;font-size:14px;color:#1e293b;text-align:right}
.sts{text-align:center;padding:12px;border-radius:12px;font-weight:700;font-size:16px;margin-bottom:12px}
.sts.nieuw{background:#fef3c7;color:#92400e}.sts.bezig{background:#dbeafe;color:#1e40af}.sts.klaar{background:#d1fae5;color:#065f46}
.btn{width:100%;padding:16px;border:none;border-radius:14px;font-size:17px;font-weight:700;cursor:pointer;margin-bottom:10px;display:flex;align-items:center;justify-content:center;gap:8px}
.btn-start{background:linear-gradient(135deg,#10b981,#059669);color:#fff}.btn-klaar{background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff}
.btn-reset{background:#fee2e2;color:#dc2626;font-size:14px;padding:10px}
.sel{width:100%;padding:14px;border:2px solid #e2e8f0;border-radius:12px;font-size:16px;margin-bottom:12px;background:#fff}
.sel:focus{border-color:#3b82f6;outline:none}
.timer{text-align:center;margin:16px 0}.tv{font-size:42px;font-weight:800;color:#1e40af;font-family:monospace}.tl{font-size:13px;color:#64748b;margin-top:4px}
.fbt{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;border:2px dashed #cbd5e1;border-radius:12px;background:#f8fafc;color:#64748b;font-size:14px;cursor:pointer;margin-bottom:12px}
.fbt.ok{border-color:#10b981;background:#f0fdf4;color:#059669}.fp{width:100%;border-radius:12px;margin-bottom:12px}
.ry{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.rb{background:#e0e7ff;color:#3730a3;padding:4px 10px;border-radius:8px;font-size:12px;font-weight:600}
.rb.ok{background:#d1fae5;color:#065f46}.fld{background:#fef3c7;color:#92400e;padding:4px 10px;border-radius:8px;font-size:12px;font-weight:600;display:inline-block;margin:2px 4px 2px 0}
textarea{width:100%;padding:12px;border:2px solid #e2e8f0;border-radius:12px;font-size:14px;resize:none;height:80px;font-family:inherit}
</style></head><body>
<div class="c">
<div class="hdr"><h1>&#x1F4E6; HubMedia Werkbon</h1><div class="code"><?=$wb['werkbon_code']?></div></div>
<div class="sts <?=$wb['status']?>"><?php if($wb['status']=='nieuw'):?>&#x23F3; Wacht op verwerking<?php elseif($wb['status']=='bezig'):?>&#x1F528; Bezig &mdash; <?=htmlspecialchars($wb['medewerker'])?>  <?php else:?>&#x2705; Afgerond<?php endif;?></div>
<div class="card"><h2>&#x1F4CB; Details</h2>
<div class="inf"><span class="il">Klant</span><span class="iv"><?=htmlspecialchars($wb['klantnaam'])?></span></div>
<div class="inf"><span class="il">Seizoen</span><span class="iv"><?=$wb['seizoen']?> <?=$wb['jaar']?></span></div>
<div class="inf"><span class="il">Stapeltjes</span><span class="iv" style="color:#2563eb;font-size:18px"><?=$mDK?>/<?=$mD?></span></div>
<?php if(!empty($folders)):?><div class="inf" style="flex-direction:column;gap:6px"><span class="il">Folders</span><div style="margin-top:4px"><?php foreach($folders as $fn):?><span class="fld"><?=htmlspecialchars($fn)?></span><?php endforeach;?></div></div><?php endif;?>
<div class="inf" style="flex-direction:column;gap:6px;border:none"><span class="il">Rayons (<?=$nRK?>/<?=count($rayonList)?> klaar)</span>
<?php if($wb['status']!=='nieuw'): // Kan afvinken als gestart of klaar ?>
<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">
<?php $wbRyC2=[];foreach($rayonList as $r):$r=trim($r);$i=isset($wbRyC2[$r])?$wbRyC2[$r]:0;$ok=isset($wbRS[$r.'-'.$i])&&$wbRS[$r.'-'.$i]['klaar'];$wbRyC2[$r]=$i+1;?>
<label style="display:flex;align-items:center;gap:6px;background:<?=$ok?'#d1fae5':'#e0e7ff'?>;border:2px solid <?=$ok?'#6ee7b7':'#c7d2fe'?>;padding:8px 12px;border-radius:10px;cursor:pointer;font-weight:700;font-size:13px;color:<?=$ok?'#065f46':'#3730a3'?>">
<input type="checkbox" style="width:18px;height:18px;accent-color:#059669;cursor:pointer" <?=$ok?'checked':''?>
    data-rayon="<?=htmlspecialchars($r,ENT_QUOTES)?>" data-idx="<?=$i?>" onchange="togRayon(this)">
<?=$ok?'&#x2705; ':''?><?=htmlspecialchars($r)?> <span style="font-size:10px;opacity:.7"><?=isset($mLP[$r])?$mLP[$r]:'?'?> loc.</span>
</label>
<?php endforeach;?>
</div>
<?php else: ?>
<div class="ry"><?php $wbRyC2=[];foreach($rayonList as $r):$r=trim($r);$i=isset($wbRyC2[$r])?$wbRyC2[$r]:0;$ok=isset($wbRS[$r.'-'.$i])&&$wbRS[$r.'-'.$i]['klaar'];$wbRyC2[$r]=$i+1;?><span class="rb<?=$ok?' ok':''?>"><?=$ok?'&#x2705; ':''?><?=htmlspecialchars($r)?></span><?php endforeach;?></div>
<?php endif;?>
</div>
</div>
<?php if($wb['status']=='nieuw'):?>
<div class="card"><h2>&#x25B6;&#xFE0F; Starten</h2><select class="sel" id="mwS"><option value="">&#x2014; Medewerker &#x2014;</option><?php foreach($mws as $m):?><option><?=htmlspecialchars($m)?></option><?php endforeach;?></select>
<label class="fbt" id="fbS">&#x1F4F7; Foto (optioneel)<input type="file" accept="image/*" capture="environment" id="fiS" style="display:none"></label>
<img id="fpS" class="fp" style="display:none">
<button class="btn btn-start" onclick="startW()">&#x25B6;&#xFE0F; Start</button></div>
<?php elseif($wb['status']=='bezig'):?>
<div class="card" style="padding:12px 16px;margin-bottom:8px">
<label style="font-size:13px;font-weight:600;color:#1e3a5f;display:block;margin-bottom:6px">&#x1F464; Medewerker (voor afvinken)</label>
<select id="mobMw" class="sel" style="margin-bottom:0" onchange="localStorage.setItem('mobMw',this.value)">
<option value="">-- Kies medewerker --</option>
<?php foreach($mws as $m):?><option value="<?=htmlspecialchars($m)?>"<?=$wb['medewerker']===$m?' selected':''?>><?=htmlspecialchars($m)?></option><?php endforeach;?>
</select>
</div>
<div class="card"><h2>&#x23F1;&#xFE0F; Bezig</h2><div class="timer"><div class="tv" id="tmr">00:00:00</div><div class="tl">Gestart: <?=date('H:i',strtotime($wb['start_tijd']))?></div></div>
<textarea id="ntt" placeholder="Opmerkingen..."></textarea>
<label class="fbt" id="fbE" style="margin-top:12px">&#x1F4F7; Foto afronden<input type="file" accept="image/*" capture="environment" id="fiE" style="display:none"></label>
<img id="fpE" class="fp" style="display:none">
<button class="btn btn-klaar" onclick="klaarW()">&#x2705; Afronden</button>
<button class="btn btn-reset" onclick="resetW()">&#x21A9;&#xFE0F; Reset</button></div>
<script>var st=new Date('<?=$wb['start_tijd']?>').getTime();function ut(){var d=Math.floor((Date.now()-st)/1000),h=Math.floor(d/3600),m=Math.floor((d%3600)/60),s=d%60;document.getElementById('tmr').textContent=String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0')}ut();setInterval(ut,1000)</script>
<?php else:?>
<div class="card" style="text-align:center"><div style="font-size:64px;margin:20px 0">&#x2705;</div><h2 style="color:#059669;font-size:18px">Afgerond!</h2>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:16px 0">
<div style="text-align:center;background:#f8fafc;border-radius:12px;padding:12px"><div style="font-size:24px;font-weight:800;color:#1e40af"><?=$mD?></div><div style="font-size:11px;color:#64748b">Stapeltjes</div></div>
<div style="text-align:center;background:#f8fafc;border-radius:12px;padding:12px"><div style="font-size:24px;font-weight:800;color:#1e40af"><?=htmlspecialchars($wb['medewerker']??'-')?></div><div style="font-size:11px;color:#64748b">Medewerker</div></div>
</div></div>
<?php endif;?>
</div>
<script>
var wbC='<?=$wb['werkbon_code']?>';
['S','E'].forEach(function(p){var i=document.getElementById('fi'+p),pr=document.getElementById('fp'+p),b=document.getElementById('fb'+p);if(i)i.addEventListener('change',function(){if(this.files&&this.files[0]){var r=new FileReader();r.onload=function(e){pr.src=e.target.result;pr.style.display='block';b.classList.add('ok')};r.readAsDataURL(this.files[0])}})});
function startW(){var m=document.getElementById('mwS').value;if(!m){alert('Kies medewerker');return}var fd=new FormData();fd.append('action','start_werkbon');fd.append('code',wbC);fd.append('medewerker',m);var fi=document.getElementById('fiS');if(fi&&fi.files[0])fd.append('foto',fi.files[0]);fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success)location.reload();else alert(d.error||'Fout')})}
function klaarW(){if(!confirm('Afronden?'))return;var fd=new FormData();fd.append('action','klaar_werkbon');fd.append('code',wbC);fd.append('notitie',document.getElementById('ntt')?.value||'');var fi=document.getElementById('fiE');if(fi&&fi.files[0])fd.append('foto',fi.files[0]);fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success)location.reload();else alert(d.error||'Fout')})}
function resetW(){if(!confirm('Reset?'))return;var fd=new FormData();fd.append('action','reset_werkbon');fd.append('code',wbC);fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success)location.reload()})}

function getMobMw(){
    var sel=document.getElementById('mobMw');
    var v=sel?sel.value:localStorage.getItem('mobMw')||'';
    if(!v){alert('Selecteer eerst een medewerker!');if(sel)sel.focus();return null;}
    return v;
}
function togRayon(cb){
    var mw=getMobMw();if(!mw){cb.checked=!cb.checked;return;}
    var rayon=cb.dataset.rayon,idx=parseInt(cb.dataset.idx||0),vw=cb.checked?1:0;
    var lbl=cb.closest('label');
    cb.disabled=true;
    var fd=new FormData();
    fd.append('action','toggle_rayon');fd.append('code',wbC);
    fd.append('rayon',rayon);fd.append('rayon_idx',idx);
    fd.append('medewerker',mw);fd.append('verwerkt',vw);
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        cb.disabled=false;
        if(d.success){
            if(vw){lbl.style.background='#d1fae5';lbl.style.borderColor='#6ee7b7';lbl.style.color='#065f46';}
            else{lbl.style.background='#e0e7ff';lbl.style.borderColor='#c7d2fe';lbl.style.color='#3730a3';}
            // Update teller
            var spans=document.querySelectorAll('.il');
            spans.forEach(function(s){if(s.textContent.includes('Rayons'))s.textContent='Rayons ('+d.klaar+'/'+d.totaal+' klaar)';});
            if(d.alle_klaar){setTimeout(function(){location.reload();},800);}
        }else{cb.checked=!cb.checked;alert(d.error||'Fout');}
    }).catch(function(){cb.disabled=false;cb.checked=!cb.checked;});
}
// Herstel medewerker uit localStorage
window.addEventListener('load',function(){
    var saved=localStorage.getItem('mobMw');
    var sel=document.getElementById('mobMw');
    if(sel&&saved&&!sel.value){
        for(var i=0;i<sel.options.length;i++){if(sel.options[i].value===saved){sel.selectedIndex=i;break;}}
    }
});
</script></body></html>
<?php exit; }

// ============================================================
// PRINT
// ============================================================
if (isset($_GET['print'])) {
    $code=trim($_GET['print']);
    $res=func_dbsi_qry("SELECT * FROM magazijn_werkbonnen WHERE werkbon_code='".safe($code)."'");
    if(!$res||!($wb=$res->fetch_assoc())){echo "Niet gevonden";exit;}
    $filterRegioP = trim($_GET['regio'] ?? '');
    $rayonsParam = trim($_GET['rayons'] ?? '');
    if ($rayonsParam) {
        // Gebruik de doorgegeven rayons (al gefilterd op regio)
        $rayonList = array_filter(array_map('trim', explode(',', $rayonsParam)));
    } else {
        // Laad alle rayons van alle werkbonnen voor deze klant+seizoen
        $allRayonsP = [];
        $allWbRes = func_dbsi_qry("SELECT rayons FROM magazijn_werkbonnen WHERE klantnaam='".safe($wb['klantnaam'])."' AND seizoen='".safe($wb['seizoen'])."' AND jaar=".intval($wb['jaar']));
        if ($allWbRes) { while ($allWbR=$allWbRes->fetch_assoc()) {
            foreach (array_filter(array_map('trim', explode(',', $allWbR['rayons']))) as $ry) {
                if ($ry && !in_array($ry, $allRayonsP)) $allRayonsP[] = $ry;
            }
        }}
        if ($filterRegioP) {
            $limburgRayonsP = ['NL58','NL59','NL60','NL61','NL62','NL63','NL64'];
            $rayonList = array_values(array_filter($allRayonsP, function($r) use ($filterRegioP, $limburgRayonsP) {
                $isL = in_array($r, $limburgRayonsP);
                $isB = substr($r,0,2)==='BE'||substr($r,0,2)==='DE';
                if ($filterRegioP==='Limburg') return $isL;
                if ($filterRegioP==='Buitenland') return $isB;
                return !$isL && !$isB;
            }));
        } else {
            $rayonList = $allRayonsP;
        }
    }
    $folders=[];$resF=func_dbsi_qry("SELECT foldernaam FROM klant_folders WHERE klantnaam='".safe($wb['klantnaam'])."' AND actief=1 ORDER BY foldernaam");
    if($resF){while($r=$resF->fetch_assoc())$folders[]=$r['foldernaam'];}
    $locPR=[];$resL=func_dbsi_qry("SELECT Rayon,COUNT(*) as n FROM hubmedia_locaties WHERE (Status='0' OR Status='Actief' OR Status='".$wb['jaar']."') GROUP BY Rayon");
    if($resL){while($r=$resL->fetch_assoc())$locPR[$r['Rayon']]=$r['n'];}
    $pRS=[];$resRS=func_dbsi_qry("SELECT rayon,rayon_idx,klaar,medewerker FROM magazijn_rayon_status WHERE werkbon_code='".safe($code)."'");
    if($resRS){while($r=$resRS->fetch_assoc())$pRS[$r['rayon'].'-'.$r['rayon_idx']]=$r;}
    $pD=0;$pDK=0;$pC=[];foreach($rayonList as $r){$r=trim($r);$i=isset($pC[$r])?$pC[$r]:0;$l=isset($locPR[$r])?$locPR[$r]:0;$pD+=$l;if(isset($pRS[$r.'-'.$i])&&$pRS[$r.'-'.$i]['klaar'])$pDK+=$l;$pC[$r]=$i+1;}
    $baseUrl=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'];
    $filterRegio = trim($_GET['regio'] ?? '');
    $qrBase = $baseUrl.'/pakketten.php?wb='.$wb['werkbon_code'].($filterRegio?'&regio='.urlencode($filterRegio):'');
    $qrUrl='https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='.urlencode($qrBase);
?><!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8"><title>Werkbon <?=$wb['werkbon_code']?></title>
<style>@media print{@page{margin:12mm}body{-webkit-print-color-adjust:exact;print-color-adjust:exact}.np{display:none!important}}
*{box-sizing:border-box;margin:0;padding:0}body{font-family:Arial,sans-serif;padding:15px;max-width:800px;margin:0 auto;font-size:13px}
.ph{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #1e3a5f;padding-bottom:12px;margin-bottom:15px}
.ph h1{font-size:20px;color:#1e3a5f}.ph .code{font-size:12px;color:#64748b;font-family:monospace}
.qr{text-align:right}.qr img{width:100px;height:100px}.qr small{display:block;font-size:8px;color:#94a3b8;margin-top:2px}
.pg{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:15px}
.pb{padding:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px}
.pbl{font-size:9px;color:#64748b;text-transform:uppercase;font-weight:600}.pbv{font-size:15px;font-weight:700;color:#1e293b;margin-top:1px}
.pf{margin-bottom:12px;padding:8px;background:#fffbeb;border:1px solid #fde68a;border-radius:4px}
.pft{font-size:10px;color:#92400e;text-transform:uppercase;font-weight:600;margin-bottom:4px}.pfl{font-size:12px;color:#92400e;font-weight:600}
.prg{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:15px}
.prb{border:2px solid #1e3a5f;border-radius:4px;padding:8px 6px;text-align:center;position:relative}
.prb .pn{font-weight:700;font-size:13px;color:#1e3a5f}.prb .pl{font-size:10px;color:#64748b;margin-top:1px}
.prb .pc{position:absolute;top:3px;right:4px;width:14px;height:14px;border:2px solid #cbd5e1;border-radius:2px}
.prb.done{background:#d1fae5;border-color:#059669}.prb.done .pc{background:#059669;border-color:#059669;display:flex;align-items:center;justify-content:center;color:#fff;font-size:10px}
.pftr{margin-top:20px;border-top:2px solid #e2e8f0;padding-top:12px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:15px}
.psign{border-bottom:1px solid #1e293b;height:40px;margin-top:4px}.psignl{font-size:10px;color:#64748b;margin-top:3px}
.pbtn{background:#1e3a5f;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-size:13px;cursor:pointer;margin-bottom:15px}
</style></head><body>
<button class="pbtn np" onclick="window.print()">&#x1F5A8;&#xFE0F; Printen</button>
<div class="ph"><div><h1>&#x1F4E6; WERKBON</h1><div class="code"><?=$wb['werkbon_code']?></div></div><div class="qr"><img src="<?=$qrUrl?>" alt="QR"><small>Scan om te starten</small></div></div>
<div class="pg">
<div class="pb"><div class="pbl">Klant</div><div class="pbv"><?=htmlspecialchars($wb['klantnaam'])?></div></div>
<div class="pb"><div class="pbl">Seizoen / Jaar</div><div class="pbv"><?=$wb['seizoen']?> <?=$wb['jaar']?></div></div>
<div class="pb"><div class="pbl">Stapeltjes</div><div class="pbv" style="font-size:22px;color:#2563eb"><?=$pDK?>/<?=$pD?></div></div>
</div>
<?php if(!empty($folders)):?><div class="pf"><div class="pft">Folders (<?=count($folders)?>)</div><div class="pfl"><?=htmlspecialchars(implode(' &middot; ',$folders))?></div></div><?php endif;?>
<div><h3 style="font-size:12px;color:#64748b;margin-bottom:8px">RAYONS</h3><div class="prg">
<?php $pC2=[];foreach($rayonList as $r):$r=trim($r);$i=isset($pC2[$r])?$pC2[$r]:0;$l=isset($locPR[$r])?$locPR[$r]:'?';$ok=isset($pRS[$r.'-'.$i])&&$pRS[$r.'-'.$i]['klaar'];$pC2[$r]=$i+1;?>
<div class="prb<?=$ok?' done':''?>"><div class="pc"><?=$ok?'&#x2713;':''?></div><div class="pn"><?=htmlspecialchars($r)?></div><div class="pl"><?=$l?> loc.</div></div>
<?php endforeach;?></div></div>
<div class="pftr">
<div><strong>Medewerker:</strong><div class="psign"></div><div class="psignl">Naam</div></div>
<div><strong>Start tijd:</strong><div class="psign"></div><div class="psignl">Datum + tijd</div></div>
<div><strong>Eind tijd:</strong><div class="psign"></div><div class="psignl">Datum + tijd</div></div>
</div></body></html>
<?php exit; }

// ============================================================
// LOODS WERKBON (per rayon - alle klanten + folders)
// ============================================================
if (isset($_GET['loodswb'])) {
    ob_start();
    $lRayon = trim($_GET['loodswb']);
    $lSz    = trim($_GET['szf'] ?? '');
    $lJaar  = intval($_GET['jaar'] ?? date('Y'));
    $lDatum = date('d-m-Y');

    if (!$lRayon) { ob_end_clean(); echo "<h1>Geen rayon opgegeven</h1>"; exit; }

    // Locaties voor dit rayon
    $lLoc = 0;
    $resLL = func_dbsi_qry("SELECT COUNT(*) as n FROM hubmedia_locaties WHERE Rayon='".safe($lRayon)."' AND (Status='0' OR Status='Actief' OR Status='$lJaar')");
    if ($resLL && ($rLL = $resLL->fetch_assoc())) { $lLoc = intval($rLL['n']); }

    // Alle klanten die dit rayon hebben in dit seizoen
    $lKlanten = [];
    $szWhere  = $lSz ? "AND wb.seizoen='".safe($lSz)."'" : '';
    $resLK = func_dbsi_qry("SELECT DISTINCT wb.klantnaam, wb.seizoen, wb.status, wb.medewerker, wb.werkbon_code
        FROM magazijn_werkbonnen wb
        WHERE wb.jaar=$lJaar $szWhere
        AND FIND_IN_SET('".safe($lRayon)."', REPLACE(wb.rayons,' ',''))
        ORDER BY wb.klantnaam");
    if ($resLK) {
        while ($r = $resLK->fetch_assoc()) {
            $kn = $r['klantnaam'];
            if (!isset($lKlanten[$kn])) {
                $lKlanten[$kn] = ['klantnaam'=>$kn,'seizoen'=>$r['seizoen'],'status'=>$r['status'],'medewerker'=>$r['medewerker'],'werkbon_code'=>$r['werkbon_code']];
            }
        }
    }

    ob_end_clean();
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8">';
    echo '<title>Loods Werkbon &mdash; Rayon '.htmlspecialchars($lRayon).'</title>';
    echo '<style>';
    echo '@media print{@page{margin:12mm}body{-webkit-print-color-adjust:exact;print-color-adjust:exact}.np{display:none!important}}';
    echo '*{box-sizing:border-box;margin:0;padding:0}';
    echo 'body{font-family:Arial,sans-serif;padding:16px;max-width:900px;margin:0 auto;font-size:13px;color:#1e293b}';
    echo '.ph{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #1e3a5f;padding-bottom:12px;margin-bottom:14px}';
    echo '.ph h1{font-size:20px;color:#1e3a5f;font-weight:800;margin-bottom:3px}';
    echo '.ph .sub{font-size:12px;color:#64748b}';
    echo '.ph .logo{text-align:right;font-size:12px;color:#64748b}';
    echo '.ph .logo strong{display:block;font-size:16px;color:#1e3a5f;font-weight:800}';
    echo 'table{width:100%;border-collapse:collapse;margin-bottom:16px}';
    echo 'thead tr{background:#1e3a5f;color:#fff}';
    echo 'thead th{padding:8px 10px;text-align:left;font-size:11px;font-weight:700}';
    echo 'thead th.c{text-align:center}';
    echo 'tbody tr{border-bottom:1px solid #e2e8f0}';
    echo 'tbody tr:nth-child(even){background:#f8fafc}';
    echo 'tbody tr.kl{background:#f0fdf4}';
    echo 'tbody td{padding:9px 10px;vertical-align:middle}';
    echo '.kn{font-weight:700;font-size:13px}';
    echo '.mw{font-size:10px;color:#94a3b8;margin-top:1px}';
    echo '.sts{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700}';
    echo '.s-kl{background:#d1fae5;color:#065f46}.s-bz{background:#dbeafe;color:#1e40af}.s-nw{background:#fef3c7;color:#92400e}';
    echo '.cb{width:16px;height:16px;border:2px solid #94a3b8;display:inline-block;border-radius:3px;vertical-align:middle}';
    echo '.ft{margin-top:20px;border-top:2px solid #e2e8f0;padding-top:12px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px}';
    echo '.sg{border-bottom:1px solid #1e293b;height:40px;margin-top:4px}';
    echo '.sl{font-size:10px;color:#64748b;margin-top:3px}';
    echo '.pbtn{background:#1e3a5f;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-size:13px;cursor:pointer;margin-bottom:14px}';
    echo '</style></head><body>';

    echo '<button class="pbtn np" onclick="window.print()">&#x1F5A8;&#xFE0F; Printen / PDF</button>';
    echo '<div class="ph">';
    echo '<div><h1>&#x1F4E6; Loods Werkbon &mdash; Rayon '.htmlspecialchars($lRayon).'</h1>';
    echo '<div class="sub">Seizoen: '.($lSz ?: 'Alle').' &nbsp;&middot;&nbsp; Jaar: '.$lJaar.' &nbsp;&middot;&nbsp; '.$lLoc.' locaties</div></div>';
    echo '<div class="logo"><strong>HubMedia</strong>'.$lDatum.'</div>';
    echo '</div>';

    if (empty($lKlanten)) {
        echo '<p style="color:#94a3b8;text-align:center;padding:30px">Geen klanten gevonden voor rayon '.htmlspecialchars($lRayon).'</p>';
    } else {
        echo '<table><thead><tr>';
        echo '<th class="c" style="width:22px">&#x2713;</th>';
        echo '<th>Klant</th>';
        echo '<th class="c" style="width:70px">Stapels</th>';
        echo '</tr></thead><tbody>';
        foreach ($lKlanten as $lk) {
            $isKl   = ($lk['status'] === 'klaar');
            $stsCls = $isKl ? 's-kl' : ($lk['status'] === 'bezig' ? 's-bz' : 's-nw');
            $stsLbl = $isKl ? '&#x2705; Klaar' : ($lk['status'] === 'bezig' ? '&#x1F528; Bezig' : '&#x23F3; Te doen');
            echo '<tr class="'.($isKl ? 'kl' : '').'">';
            echo '<td style="text-align:center"><div class="cb">'.($isKl ? '&#x2713;' : '').'</div></td>';
            echo '<td><div class="kn">'.htmlspecialchars($lk['klantnaam']).'</div>';
            if ($lk['medewerker']) { echo '<div class="mw">'.htmlspecialchars($lk['medewerker']).'</div>'; }
            echo '</td>';
            echo '<td style="text-align:center"><div style="border:1px solid #e2e8f0;border-radius:4px;width:50px;height:28px;margin:0 auto"></div></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '<div class="ft">';
    echo '<div><strong>Medewerker:</strong><div class="sg"></div><div class="sl">Naam + handtekening</div></div>';
    echo '<div><strong>Datum:</strong><div class="sg"></div><div class="sl">Datum</div></div>';
    echo '<div><strong>Opmerkingen:</strong><div class="sg"></div><div class="sl">&nbsp;</div></div>';
    echo '</div>';
    echo '</body></html>';
    exit;
}

// ============================================================
// TRANSPORT GEWICHT OPSLAAN
// ============================================================
if (isset($_POST['action']) && $_POST['action'] === 'save_transport_gewicht') {
    header('Content-Type: application/json');
    $ry = trim($_POST['rayon'] ?? '');
    $sz = trim($_POST['seizoen'] ?? '');
    $jr = intval($_POST['jaar'] ?? date('Y'));
    $vn = intval($_POST['transport_nr'] ?? 1);
    $gw = floatval(str_replace(',','.', $_POST['gewicht'] ?? ''));
    if ($ry && $sz && $vn >= 1 && $vn <= 5 && $gw > 0) {
        $col = 'gewicht_'.$vn;
        func_dbsi_qry("INSERT INTO magazijn_rayon_transport (rayon,seizoen,jaar,$col) VALUES ('".safe($ry)."','".safe($sz)."',$jr,$gw) ON DUPLICATE KEY UPDATE $col=$gw");
        echo json_encode(['success'=>true,'gewicht'=>$gw]);
    } else echo json_encode(['success'=>false]);
    exit;
}

// ============================================================
// TRANSPORT SCAN (QR code gescand → volgende transport-bit zetten)
// ============================================================
if (isset($_GET['transport_scan'])) {
    $ry = trim($_GET['rayon'] ?? '');
    $sz = trim($_GET['seizoen'] ?? '');
    $jr = intval($_GET['jaar'] ?? date('Y'));
    $locs = 0;
    $nextBit = 0; $bits = 0; $msg = ''; $bevestigd = false;

    if ($ry && $sz) {
        // Bevestiging via POST? Dan pas registreren
        if (isset($_POST['bevestig_transport']) && $_POST['bevestig_transport'] === '1') {
            $resT = func_dbsi_qry("SELECT transport FROM magazijn_rayon_transport WHERE rayon='".safe($ry)."' AND seizoen='".safe($sz)."' AND jaar=$jr");
            $bits = ($resT && $rT=$resT->fetch_assoc()) ? intval($rT['transport']) : 0;
            for ($i=0;$i<5;$i++) { if (!($bits>>$i&1)) { $nextBit=$i+1; break; } }
            if ($nextBit > 0) {
                $bit = 1 << ($nextBit-1);
                func_dbsi_qry("INSERT INTO magazijn_rayon_transport (rayon,seizoen,jaar,transport) VALUES ('".safe($ry)."','".safe($sz)."',$jr,$bit) ON DUPLICATE KEY UPDATE transport=transport|$bit");
                $bits |= $bit;
                // Gewicht meteen opslaan als meegegeven
                $gwPost = floatval(str_replace(',','.', $_POST['gewicht'] ?? '0'));
                if ($gwPost > 0) {
                    $col = 'gewicht_'.$nextBit;
                    func_dbsi_qry("UPDATE magazijn_rayon_transport SET $col=$gwPost WHERE rayon='".safe($ry)."' AND seizoen='".safe($sz)."' AND jaar=$jr");
                }
                // Aantal folders opslaan als meegegeven
                $flPost = intval($_POST['folders'] ?? 0);
                if ($flPost > 0) {
                    $fcol = 'folders_'.$nextBit;
                    func_dbsi_qry("UPDATE magazijn_rayon_transport SET $fcol=$flPost WHERE rayon='".safe($ry)."' AND seizoen='".safe($sz)."' AND jaar=$jr");
                }
                $fotoStatus = '';
                $fotoFiles = [];
                if (isset($_FILES['transport_fotos']) && is_array($_FILES['transport_fotos']['name'])) {
                    $n = count($_FILES['transport_fotos']['name']);
                    for ($ix = 0; $ix < $n; $ix++) {
                        $err = intval($_FILES['transport_fotos']['error'][$ix] ?? UPLOAD_ERR_NO_FILE);
                        if ($err === UPLOAD_ERR_NO_FILE) continue;
                        $fotoFiles[] = [
                            'name' => $_FILES['transport_fotos']['name'][$ix] ?? '',
                            'tmp_name' => $_FILES['transport_fotos']['tmp_name'][$ix] ?? '',
                            'size' => intval($_FILES['transport_fotos']['size'][$ix] ?? 0),
                            'error' => $err
                        ];
                    }
                } elseif (isset($_FILES['transport_foto']) && intval($_FILES['transport_foto']['error']) !== UPLOAD_ERR_NO_FILE) {
                    // Backward compatibility met eerder enkelvoudig veld.
                    $fotoFiles[] = [
                        'name' => $_FILES['transport_foto']['name'] ?? '',
                        'tmp_name' => $_FILES['transport_foto']['tmp_name'] ?? '',
                        'size' => intval($_FILES['transport_foto']['size'] ?? 0),
                        'error' => intval($_FILES['transport_foto']['error'])
                    ];
                }

                if ($fotoFiles) {
                    $upDir = __DIR__.'/uploads/transport_scan/';
                    if (!is_dir($upDir)) @mkdir($upDir, 0755, true);
                    $ryTag = preg_replace('/[^A-Za-z0-9_-]/', '', $ry);
                    $szTag = preg_replace('/[^A-Za-z0-9_-]/', '', $sz);
                    $okFotos = 0;
                    $badFotos = 0;

                    foreach ($fotoFiles as $f) {
                        if (intval($f['error']) !== UPLOAD_ERR_OK) {
                            $badFotos++;
                            continue;
                        }
                        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic'])) {
                            $badFotos++;
                            continue;
                        }
                        if (intval($f['size']) > 20 * 1024 * 1024) {
                            $badFotos++;
                            continue;
                        }

                        $newName = 'tr_' . $ryTag . '_' . $szTag . '_' . $jr . '_' . $nextBit . '_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
                        $relPath = 'uploads/transport_scan/' . $newName;
                        $absPath = __DIR__ . '/' . $relPath;

                        if (move_uploaded_file($f['tmp_name'], $absPath)) {
                            try {
                                $dbFoto = new PDO(
                                    'mysql:host=localhost;dbname=hubmed01_boekhouding;charset=utf8mb4',
                                    'hubmed01',
                                    'A3RliMu3BeWVQspBNZDVvIWtF',
                                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                                );
                                $stmtIns = $dbFoto->prepare("INSERT INTO magazijn_rayon_transport_fotos (rayon,seizoen,jaar,transport_nr,file_name,file_path) VALUES (?,?,?,?,?,?)");
                                $stmtIns->execute([$ry, $sz, $jr, $nextBit, $f['name'], $relPath]);
                                $okFotos++;
                            } catch (Exception $e) {
                                $badFotos++;
                                @unlink($absPath);
                            }
                        } else {
                            $badFotos++;
                        }
                    }

                    if ($okFotos > 0 && $badFotos === 0) {
                        $fotoStatus = ' + ' . $okFotos . ' foto' . ($okFotos === 1 ? '' : "'s") . ' geupload';
                    } elseif ($okFotos > 0 && $badFotos > 0) {
                        $fotoStatus = ' + ' . $okFotos . ' foto' . ($okFotos === 1 ? '' : "'s") . ' geupload (' . $badFotos . ' mislukt)';
                    } elseif ($badFotos > 0) {
                        $fotoStatus = ' (foto upload mislukt)';
                    }
                }
                $msg = "Transport $nextBit geregistreerd ✅" . $fotoStatus;
                $bevestigd = true;
                // Redirect to prevent double submission on page refresh
                header('Location: pakketten.php?transport_scan=1&rayon='.urlencode($ry).'&seizoen='.urlencode($sz).'&jaar='.$jr);
                exit;
            } else {
                $msg = "Alle 5 transport-momenten al geregistreerd.";
                $bevestigd = true;
                // Redirect to prevent double submission on page refresh
                header('Location: pakketten.php?transport_scan=1&rayon='.urlencode($ry).'&seizoen='.urlencode($sz).'&jaar='.$jr);
                exit;
            }
        } else {
            // Alleen tonen, nog niet registreren
            $resT = func_dbsi_qry("SELECT transport FROM magazijn_rayon_transport WHERE rayon='".safe($ry)."' AND seizoen='".safe($sz)."' AND jaar=$jr");
            $bits = ($resT && $rT=$resT->fetch_assoc()) ? intval($rT['transport']) : 0;
            for ($i=0;$i<5;$i++) { if (!($bits>>$i&1)) { $nextBit=$i+1; break; } }
        }
        $resL = func_dbsi_qry("SELECT COUNT(*) as n FROM hubmedia_locaties WHERE Rayon='".safe($ry)."' AND (Status='0' OR Status='Actief' OR Status='$jr')");
        if ($resL && $rL=$resL->fetch_assoc()) $locs = intval($rL['n']);
        
        // Laad alle foto's voor deze rayon/seizoen
        $galleriFotos = [];
        $resGal = func_dbsi_qry("SELECT id, file_path, transport_nr FROM magazijn_rayon_transport_fotos WHERE rayon='".safe($ry)."' AND seizoen='".safe($sz)."' AND jaar=$jr ORDER BY uploaded_at DESC LIMIT 50");
        if ($resGal) {
            while ($gf = $resGal->fetch_assoc()) {
                $galleriFotos[] = $gf;
            }
        }
        
        // Vinkjes renderen
        $vinkjes = '';
        for ($i=1;$i<=5;$i++) {
            $done = ($bits>>($i-1))&1;
            $isNext = (!$bevestigd && $i === $nextBit);
            $vinkjes .= '<div style="display:flex;flex-direction:column;align-items:center;gap:3px">
                <div style="width:28px;height:28px;border:2px solid '.($done?'#059669':($isNext?'#2563eb':'#cbd5e1')).';border-radius:4px;background:'.($done?'#059669':($isNext?'#dbeafe':'#fff')).';display:flex;align-items:center;justify-content:center;font-size:16px;color:'.($done?'#fff':($isNext?'#2563eb':'#cbd5e1')).'">'.($done?'✓':($isNext?$i:'')).'</div>
                <span style="font-size:10px;color:#64748b">'.$i.'</span></div>';
        }
    ?><!DOCTYPE html><html lang="nl" dir="ltr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Transport <?=htmlspecialchars($ry)?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:100%;min-height:100vh}
body{font-family:Arial,sans-serif;background:#f0fdf4;display:flex;align-items:flex-start;justify-content:center;padding:54px 10px 20px;overflow-x:hidden}
.lang-btn{position:fixed;top:10px;right:10px;background:#1e3a5f;color:#fff;border:none;padding:8px 12px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;z-index:100;touch-action:manipulation}
.card{background:#fff;border-radius:16px;padding:20px 16px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1);width:100%;max-width:420px}
.icon{font-size:40px;margin-bottom:6px}
.rayon{font-size:32px;font-weight:900;color:#1e3a5f;margin-bottom:2px}
.sub{font-size:12px;color:#64748b;margin-bottom:14px}
.vinkjes{display:flex;justify-content:center;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.msg{font-size:15px;font-weight:700;color:#059669;margin-bottom:14px;padding:10px;background:#d1fae5;border-radius:8px}
.next-info{background:#eff6ff;border:2px solid #bfdbfe;border-radius:10px;padding:10px;margin-bottom:12px;font-size:13px;color:#1e40af;font-weight:600}
.gw-wrap{background:#f8fafc;border-radius:12px;padding:14px;margin-bottom:12px;text-align:left;width:100%}
.gw-lbl{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.gw-row{display:flex;gap:8px;align-items:center;width:100%}
.gw-inp{flex:1;min-width:0;padding:14px 6px;font-size:26px;font-weight:700;border:2px solid #e2e8f0;border-radius:8px;text-align:center;color:#1e3a5f;width:100%;appearance:none;-webkit-appearance:none}
.gw-inp:focus{outline:none;border-color:#2563eb}
.gw-unit{font-size:18px;font-weight:700;color:#64748b;flex-shrink:0;white-space:nowrap}
.foto-inp{width:100%;padding:10px;border:2px dashed #cbd5e1;border-radius:10px;background:#fff;color:#1e3a5f;font-size:13px}
.foto-inputs{display:flex;flex-direction:column;gap:8px}
.foto-hint{display:block;font-size:11px;color:#64748b;margin-top:6px}
.foto-count{display:block;font-size:11px;color:#1e40af;margin-top:4px;font-weight:700}
.foto-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:8px;margin-top:12px;padding:12px;background:#fafafa;border-radius:8px;max-height:250px;overflow-y:auto}
.foto-thumb{width:100%;aspect-ratio:1;background:#e5e5e5;object-fit:cover;border-radius:6px;cursor:pointer;border:2px solid #e2e8f0;transition:all .2s;position:relative}
.foto-thumb:hover{border-color:#2563eb;box-shadow:0 2px 8px rgba(37,99,235,.2)}
.foto-badge{position:absolute;top:3px;right:3px;background:#059669;color:#fff;padding:2px 5px;border-radius:3px;font-size:9px;font-weight:700}
.gw-warn{display:none;font-size:14px;font-weight:700;color:#dc2626;margin-top:10px;padding:10px 12px;background:#fee2e2;border-radius:8px;border:2px solid #dc2626;animation:pulse 1s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.75}}
.bevestig-btn{background:#059669;color:#fff;border:none;padding:16px 12px;border-radius:12px;font-size:17px;font-weight:800;cursor:pointer;width:100%;margin-bottom:10px;touch-action:manipulation;-webkit-tap-highlight-color:transparent;line-height:1.2}
.bevestig-btn:active{background:#047857}
.terug{display:block;background:#1e3a5f;color:#fff;padding:12px;border-radius:8px;text-decoration:none;font-size:14px;font-weight:600;width:100%;text-align:center;touch-action:manipulation}
</style></head><body>
<button class="lang-btn" onclick="toggleLang()" id="langBtn">🌐 عربي</button>
<div class="card">
    <div class="icon">🚚</div>
    <div class="rayon">Rayon <?=htmlspecialchars($ry)?></div>
    <div class="sub"><?=$locs?> <span data-nl="locaties" data-ar="مواقع">locaties</span> &mdash; <?=htmlspecialchars($sz)?> <?=$jr?></div>
    <div class="vinkjes"><?=$vinkjes?></div>

    <?php if($bevestigd): ?>
    <!-- Na bevestiging: toon resultaat -->
    <?php if($nextBit > 0): ?>
    <div class="msg" data-nl="Transport <?=$nextBit?> geregistreerd ✅" data-ar="تم تسجيل النقل <?=$nextBit?> ✅"><?=htmlspecialchars($msg)?></div>
    <?php else: ?>
    <div style="font-size:14px;color:#64748b;margin-bottom:16px;padding:10px;background:#f1f5f9;border-radius:8px"><?=htmlspecialchars($msg)?></div>
    <?php endif; ?>

    <?php else: ?>
    <!-- Vóór bevestiging: toon gewicht + bevestigknop samen -->
    <?php if($nextBit > 0): ?>
    <div class="next-info" data-nl="Volgende: Transport <?=$nextBit?> registreren" data-ar="التالي: تسجيل النقل <?=$nextBit?>">Volgende: Transport <?=$nextBit?> registreren</div>
    <form method="POST" enctype="multipart/form-data" action="pakketten.php?transport_scan=1&rayon=<?=urlencode($ry)?>&seizoen=<?=urlencode($sz)?>&jaar=<?=$jr?>">
        <input type="hidden" name="bevestig_transport" value="1">
        <!-- Gewicht invullen vóór bevestigen -->
        <div class="gw-wrap">
            <div class="gw-lbl" data-nl="⚖️ Gewicht transport <?=$nextBit?>" data-ar="⚖️ وزن النقل <?=$nextBit?>">⚖️ Gewicht transport <?=$nextBit?></div>
            <div class="gw-row">
                <input class="gw-inp" type="number" name="gewicht" id="gwInp" step="1" min="0" placeholder="0" inputmode="numeric" oninput="checkGewicht(this.value)">
                <span class="gw-unit">gram</span>
            </div>
            <div class="gw-warn" id="gwWarn"
                 data-nl="⚠️ Gewicht overschrijdt 2000 gram! Controleer de doos."
                 data-ar="⚠️ الوزن يتجاوز 2000 جرام! تحقق من الصندوق.">
                ⚠️ Gewicht overschrijdt 2000 gram! Controleer de doos.
            </div>
            <div style="margin-top:12px;border-top:1px solid #e2e8f0;padding-top:12px">
                <div class="gw-lbl" data-nl="📂 Aantal folders" data-ar="📂 عدد المناشير">📂 Aantal folders</div>
                <div class="gw-row">
                    <input class="gw-inp" type="number" name="folders" id="flInp" step="1" min="0" placeholder="0" inputmode="numeric" style="font-size:22px">
                    <span class="gw-unit">st.</span>
                </div>
            </div>
            <div style="margin-top:12px;border-top:1px solid #e2e8f0;padding-top:12px">
                <div class="gw-lbl" data-nl="📷 Foto dozen (optioneel)" data-ar="📷 صورة الصناديق (اختياري)">📷 Foto dozen (optioneel)</div>
                <div id="fotoInputs" class="foto-inputs">
                    <input class="foto-inp" type="file" name="transport_fotos[]" accept="image/*" capture="environment">
                </div>
                <small class="foto-hint" data-nl="Na elke gekozen foto verschijnt automatisch een extra veld" data-ar="بعد اختيار كل صورة يظهر حقل إضافي تلقائيا">Na elke gekozen foto verschijnt automatisch een extra veld</small>
                <small class="foto-count" id="fotoCount">0 foto's geselecteerd</small>
            </div>
        </div>
        <button type="submit" class="bevestig-btn" id="bevestigBtn"
                data-nl="✅ Bevestig transport <?=$nextBit?>"
                data-ar="✅ تأكيد النقل <?=$nextBit?>">✅ Bevestig transport <?=$nextBit?></button>
    </form>
    
    <!-- Foto gallerij van alle uploads voor deze rayon -->
    <?php if ($galleriFotos && count($galleriFotos) > 0) { ?>
    <div style="margin-top:20px;padding:14px;background:#f0fdf4;border-radius:10px;border:1px solid #bbf7d0">
        <div class="gw-lbl" style="color:#059669">📸 Foto's (<?=count($galleriFotos)?>)</div>
        <div class="foto-gallery" id="fotoGallery">
            <?php foreach ($galleriFotos as $f) { 
                $fid = intval($f['id'] ?? 0);
                $fpath = $f['file_path'] ?? '';
                $trn = intval($f['transport_nr'] ?? 0);
                if (!$fpath) continue;
            ?>
            <div style="position:relative;cursor:pointer" onclick="openFotoPreview('<?=htmlspecialchars($fpath)?>','Transport #<?=$trn?>')" title="Transport #<?=$trn?>">
                <img src="<?=htmlspecialchars($fpath)?>" alt="Foto" class="foto-thumb" loading="lazy">
                <span class="foto-badge">#<?=$trn?></span>
            </div>
            <?php } ?>
        </div>
    </div>
    <?php } ?>
    <?php else: ?>
    <div style="font-size:14px;color:#64748b;margin-bottom:16px;padding:10px;background:#f1f5f9;border-radius:8px"
         data-nl="Alle transport-momenten al geregistreerd." data-ar="تم تسجيل جميع لحظات النقل.">
        Alle transport-momenten al geregistreerd.
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>
<script>
var isAr=false;

function checkGewicht(val){
    var gw=parseInt(String(val).replace(',','.'));
    var warn=document.getElementById('gwWarn');
    var inp=document.getElementById('gwInp');
    if(!warn||!inp)return;
    if(gw>2000){
        warn.style.display='block';
        inp.style.borderColor='#dc2626';
        warn.style.animation='pulse 1s infinite';
    } else {
        warn.style.display='none';
        inp.style.borderColor=gw>0?'#2563eb':'#e2e8f0';
    }
}

function toggleLang(){
    isAr=!isAr;
    document.documentElement.lang=isAr?'ar':'nl';
    document.documentElement.dir=isAr?'rtl':'ltr';
    document.getElementById('langBtn').textContent=isAr?'🌐 Nederlands':'🌐 عربي';
    document.querySelectorAll('[data-nl]').forEach(function(el){
        el.textContent=isAr?el.dataset.ar:el.dataset.nl;
    });
    var inp=document.getElementById('gwInp');
    if(inp) inp.placeholder=isAr?'٠٫٠':'0.0';
}

function openFotoPreview(url, label){
    var modal=document.createElement('div');
    modal.style.cssText='position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.92);display:flex;align-items:center;justify-content:center;z-index:9999;cursor:pointer;padding:20px';
    var img=document.createElement('img');
    img.src=url;
    img.style.cssText='max-width:95%;max-height:90vh;border-radius:8px;object-fit:contain';
    img.onerror=function(){img.style.background='#333';img.style.padding='20px'};
    var lbl=document.createElement('div');
    lbl.textContent=label;
    lbl.style.cssText='position:absolute;top:20px;left:20px;color:#fff;font-size:13px;background:rgba(0,0,0,.7);padding:8px 14px;border-radius:6px;font-weight:700';
    var closeBtn=document.createElement('button');
    closeBtn.textContent='✕';
    closeBtn.style.cssText='position:absolute;top:20px;right:20px;width:40px;height:40px;background:#dc2626;color:#fff;border:none;border-radius:50%;font-size:20px;cursor:pointer;font-weight:bold';
    closeBtn.onclick=function(e){e.stopPropagation();document.body.removeChild(modal);};
    modal.appendChild(lbl);
    modal.appendChild(img);
    modal.appendChild(closeBtn);
    modal.onclick=function(){document.body.removeChild(modal);};
    img.onclick=function(e){e.stopPropagation();};
    document.body.appendChild(modal);
}

function initFotoInputs(){
    var wrap=document.getElementById('fotoInputs');
    if(!wrap) return;

    function selectedCount(){
        var c=0;
        wrap.querySelectorAll('input[type="file"]').forEach(function(el){ if(el.files&&el.files.length)c++; });
        return c;
    }

    function updateCount(){
        var el=document.getElementById('fotoCount');
        if(!el) return;
        var n=selectedCount();
        el.textContent=n+" foto"+(n===1?"":"'s")+" geselecteerd";
    }

    function addEmptyInput(){
        var inp=document.createElement('input');
        inp.type='file';
        inp.name='transport_fotos[]';
        inp.accept='image/*';
        inp.setAttribute('capture','environment');
        inp.className='foto-inp';
        bindInput(inp);
        wrap.appendChild(inp);
    }

    function ensureTrailingEmptyInput(){
        var inputs=wrap.querySelectorAll('input[type="file"]');
        if(!inputs.length){ addEmptyInput(); return; }
        var last=inputs[inputs.length-1];
        if(last.files && last.files.length){
            addEmptyInput();
        }
    }

    function bindInput(inp){
        inp.addEventListener('change', function(){
            updateCount();
            ensureTrailingEmptyInput();
        });
    }

    wrap.querySelectorAll('input[type="file"]').forEach(bindInput);
    ensureTrailingEmptyInput();
    updateCount();
}

var inp=document.getElementById('gwInp');
if(inp) setTimeout(function(){inp.focus();},300);
initFotoInputs();
</script>
</body></html>
<?php exit; } exit; }

// ============================================================
// RAYON A4 PRINT
// ============================================================
if (isset($_GET['rayon_print'])) {
    $ry = trim($_GET['rayon_print']);
    $sz = trim($_GET['szf'] ?? '');
    $jr = intval($_GET['jaar'] ?? date('Y'));
    $locs = 0;
    $resL = func_dbsi_qry("SELECT COUNT(*) as n FROM hubmedia_locaties WHERE Rayon='".safe($ry)."' AND (Status='0' OR Status='Actief' OR Status='$jr')");
    if ($resL && $rL=$resL->fetch_assoc()) $locs = intval($rL['n']);
    $baseUrl = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'];
    $scanUrl = $baseUrl.'/pakketten.php?transport_scan=1&rayon='.urlencode($ry).'&seizoen='.urlencode($sz).'&jaar='.$jr;
    $qrUrl   = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data='.urlencode($scanUrl);
?><!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8">
<title>Rayon <?=htmlspecialchars($ry)?> &mdash; Transport</title>
<style>
@media print{@page{margin:15mm;size:A4}body{-webkit-print-color-adjust:exact;print-color-adjust:exact}.np{display:none!important}}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;padding:30px;max-width:700px;margin:0 auto}
.np{margin-bottom:20px}
.btn{background:#1e3a5f;color:#fff;border:none;padding:9px 22px;border-radius:6px;font-size:13px;cursor:pointer}
.header{border-bottom:4px solid #1e3a5f;padding-bottom:20px;margin-bottom:30px;display:flex;justify-content:space-between;align-items:flex-end}
.logo{font-size:13px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px}
.rayon-naam{font-size:64px;font-weight:900;color:#1e3a5f;line-height:1}
.rayon-sub{font-size:18px;color:#64748b;margin-top:6px}
.locaties{font-size:22px;font-weight:700;color:#1e3a5f;margin-top:4px}
.qr-section{text-align:center;margin-top:40px;padding:30px;border:3px dashed #e2e8f0;border-radius:16px}
.qr-section img{width:260px;height:260px;display:block;margin:0 auto 16px}
.qr-label{font-size:16px;font-weight:700;color:#1e3a5f;margin-bottom:6px}
.qr-sub{font-size:12px;color:#94a3b8}
.footer{margin-top:40px;display:flex;justify-content:space-between;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:10px}
.transport-vinkjes{display:flex;gap:16px;justify-content:center;margin-top:30px}
.tv{width:48px;height:48px;border:2px solid #cbd5e1;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:4px}
.tv-n{font-size:10px;color:#94a3b8;font-weight:600}
</style></head><body>
<div class="np"><button class="btn" onclick="window.print()">🖨️ Printen / PDF</button></div>
<div class="header">
    <div>
        <div class="logo">HubMedia &mdash; Transport</div>
        <div class="rayon-naam"><?=htmlspecialchars($ry)?></div>
        <div class="rayon-sub"><?=htmlspecialchars($sz)?> <?=$jr?></div>
        <div class="locaties">📦 <?=number_format($locs,0,',','.')?> locaties</div>
    </div>
</div>
<div class="qr-section">
    <img src="<?=htmlspecialchars($qrUrl)?>" alt="QR code transport <?=htmlspecialchars($ry)?>">
    <div class="qr-label">Scan bij transport</div>
    <div class="qr-sub">Scan deze code als de dozen van rayon <?=htmlspecialchars($ry)?> op transport gaan.</div>
</div>
<div class="transport-vinkjes">
<?php for($i=1;$i<=5;$i++):?>
<div class="tv"><div style="width:30px;height:30px;border:2px solid #e2e8f0;border-radius:4px"></div><div class="tv-n"><?=$i?></div></div>
<?php endfor;?>
</div>
<div style="text-align:center;font-size:11px;color:#94a3b8;margin-top:10px">Vinkjes worden automatisch bijgehouden via de QR-scan</div>
<div class="footer">
    <span>HubMedia &mdash; Magazijn systeem</span>
    <span>Rayon <?=htmlspecialchars($ry)?> &mdash; <?=htmlspecialchars($sz)?> <?=$jr?></span>
    <span><?=date('d-m-Y')?></span>
</div>
</body></html>
<?php exit; }

// ============================================================
// BATCH RESET (reset gedrukt-bits voor alle rayons in seizoen)
// ============================================================
if (isset($_GET['batch_reset_all'])) {
    $sz = trim($_GET['szf'] ?? '');
    $jr = intval($_GET['jaar'] ?? date('Y'));
    if ($sz) {
        func_dbsi_qry("UPDATE magazijn_rayon_batch SET gedrukt=0, selected=0 WHERE seizoen='".safe($sz)."' AND jaar=$jr");
    }
    header('Location: pakketten.php?view=rayons&szf='.urlencode($sz).'&jaar='.$jr);
    exit;
}

// ============================================================
// BATCH PRINT (alle geselecteerde rayons als één printrun)
// ============================================================
if (isset($_GET['batch_print'])) {
    $sz = trim($_GET['szf'] ?? '');
    $jr = intval($_GET['jaar'] ?? date('Y'));
    if (!$sz) { echo "<h1>Geen seizoen opgegeven</h1>"; exit; }
    // Alle rayons met minstens één geselecteerd bit
    $batchRayons = [];
    $resB = func_dbsi_qry("SELECT rayon, selected FROM magazijn_rayon_batch WHERE seizoen='".safe($sz)."' AND jaar=$jr AND selected > 0");
    if ($resB) { while ($rB=$resB->fetch_assoc()) {
        if (intval($rB['selected'])) $batchRayons[$rB['rayon']] = intval($rB['selected']);
    }}
    natsort($batchRayons);
    // Expand naar individuele (rayon, batch_nr) paren
    $batchItems = []; // ['rayon'=>..., 'bn'=>..., 'locs'=>...]
    foreach (array_keys($batchRayons) as $bRy) {
        $bits = $batchRayons[$bRy];
        for ($bn=1;$bn<=5;$bn++) {
            if (($bits>>($bn-1))&1) $batchItems[] = ['rayon'=>$bRy,'bn'=>$bn];
        }
    }
    natsort($batchRayons);
    if (empty($batchItems)) {
        echo "<!DOCTYPE html><html><body style='font-family:Arial;padding:40px;text-align:center'><h2>Geen rayons geselecteerd</h2><p><a href='pakketten.php?view=rayons&szf=".urlencode($sz)."&jaar=$jr'>← Terug</a></p></body></html>";
        exit;
    }
    // Locaties per rayon ophalen
    $batchLocs = [];
    foreach (array_keys($batchRayons) as $bRy) {
        $resL2 = func_dbsi_qry("SELECT COUNT(*) as n FROM hubmedia_locaties WHERE Rayon='".safe($bRy)."' AND (Status='0' OR Status='Actief' OR Status='$jr')");
        $batchLocs[$bRy] = ($resL2 && $rL2=$resL2->fetch_assoc()) ? intval($rL2['n']) : 0;
    }
    $totalLocs = 0;
    foreach ($batchItems as $bi) $totalLocs += $batchLocs[$bi['rayon']] ?? 0;
    $baseUrl   = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'];
    $resetUrl  = $baseUrl.'/pakketten.php?batch_reset_all=1&szf='.urlencode($sz).'&jaar='.$jr;
    $drukDatum = date('d-m-Y H:i');
?><!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8">
<title>Batch &mdash; <?=htmlspecialchars($sz)?> <?=$jr?></title>
<style>
@media print{@page{margin:12mm;size:A4}.np{display:none!important}body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
*{box-sizing:border-box;margin:0;padding:0}body{font-family:Arial,sans-serif}
.pgbreak{page-break-after:always}
.ov{padding:30px;max-width:700px;margin:0 auto}
.ov-hdr{border-bottom:4px solid #1e3a5f;padding-bottom:16px;margin-bottom:24px}
.ov-titel{font-size:26px;font-weight:900;color:#1e3a5f}
.ov-sub{font-size:13px;color:#64748b;margin-top:4px}
.ov-tbl{width:100%;border-collapse:collapse;font-size:12px}
.ov-tbl th{background:#1e3a5f;color:#fff;padding:8px 12px;text-align:left}
.ov-tbl td{padding:8px 12px;border-bottom:1px solid #e2e8f0}
.ov-tbl tr:nth-child(even) td{background:#f8fafc}
.rp{padding:30px;max-width:700px;margin:0 auto}
.rp-hdr{border-bottom:4px solid #1e3a5f;padding-bottom:20px;margin-bottom:30px}
.rp-logo{font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px}
.rp-naam{font-size:72px;font-weight:900;color:#1e3a5f;line-height:1}
.rp-sub{font-size:16px;color:#64748b;margin-top:6px}
.rp-locs{font-size:20px;font-weight:700;color:#1e3a5f;margin-top:4px}
.rp-run{font-size:14px;background:#e0e7ff;color:#3730a3;padding:4px 12px;border-radius:6px;display:inline-block;margin-top:8px;font-weight:700}
.rp-datum{font-size:12px;color:#94a3b8;margin-top:6px}
.qr-wrap{text-align:center;margin:36px 0;padding:30px;border:3px dashed #e2e8f0;border-radius:16px}
.qr-wrap img{width:280px;height:280px;display:block;margin:0 auto 14px}
.qr-lbl{font-size:15px;font-weight:700;color:#1e3a5f}
.qr-lbl2{font-size:11px;color:#94a3b8;margin-top:4px}
.ft{margin-top:36px;border-top:1px solid #e2e8f0;padding-top:10px;display:flex;justify-content:space-between;font-size:10px;color:#94a3b8}
.np{margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.btn{background:#1e3a5f;color:#fff;border:none;padding:9px 22px;border-radius:6px;font-size:13px;cursor:pointer}
.btn-reset{background:#dc2626;color:#fff;border:none;padding:9px 18px;border-radius:6px;font-size:13px;cursor:pointer;font-weight:700}
</style></head><body>

<!-- Overzichtspagina -->
<div class="ov pgbreak">
    <div class="np">
        <button class="btn" onclick="window.print()">🖨️ Alles printen</button>
        <button class="btn-reset" onclick="if(confirm('Alle batch-vinkjes resetten?'))location.href='<?=htmlspecialchars($resetUrl)?>'">↺ Reset batch-vinkjes</button>
        <a href="pakketten.php?view=rayons&szf=<?=urlencode($sz)?>&jaar=<?=$jr?>" style="color:#64748b;font-size:13px;margin-left:4px">← Terug</a>
    </div>
    <div class="ov-hdr">
        <div class="ov-titel">HubMedia &mdash; Transportbatch</div>
        <div class="ov-sub"><?=htmlspecialchars($sz)?> <?=$jr?> &mdash; <?=count($batchItems)?> items &mdash; <?=number_format($totalLocs,0,',','.')?> doosjes &mdash; <?=$drukDatum?></div>
    </div>
    <table class="ov-tbl">
        <thead><tr><th>#</th><th>Rayon</th><th>Run</th><th>Locaties</th><th style="width:50px;text-align:center">✓</th></tr></thead>
        <tbody>
        <?php foreach ($batchItems as $i => $bi):
            $bRy = $bi['rayon']; $bn = $bi['bn'];
        ?>
        <tr>
            <td style="font-weight:700;color:#94a3b8"><?=$i+1?></td>
            <td style="font-size:15px;font-weight:800;color:#1e3a5f"><?=htmlspecialchars($bRy)?></td>
            <td><span style="background:#e0e7ff;color:#3730a3;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700">Run <?=$bn?></span></td>
            <td style="font-size:13px;font-weight:700">📦 <?=number_format($batchLocs[$bRy] ?? 0,0,',','.')?></td>
            <td style="text-align:center"><div style="width:26px;height:26px;border:2px solid #1e3a5f;border-radius:4px;margin:0 auto"></div></td>
        </tr>
        <?php endforeach;?>
        <tr style="background:#f0fdf4"><td colspan="3" style="font-weight:800;color:#1e3a5f">Totaal</td><td style="font-size:15px;font-weight:900;color:#059669">📦 <?=number_format($totalLocs,0,',','.')?></td><td></td></tr>
        </tbody>
    </table>
</div>

<!-- Individuele rayon-pagina's: één per (rayon, run) -->
<?php foreach ($batchItems as $bi):
    $bRy = $bi['rayon']; $bn = $bi['bn'];
    $scanUrlR = $baseUrl.'/pakketten.php?transport_scan=1&rayon='.urlencode($bRy).'&seizoen='.urlencode($sz).'&jaar='.$jr;
    $qrUrlR   = 'https://api.qrserver.com/v1/create-qr-code/?size=560x560&data='.urlencode($scanUrlR);
    $locsR    = $batchLocs[$bRy] ?? 0;
?>
<div class="rp pgbreak">
    <div class="np">
        <button class="btn-reset" onclick="if(confirm('Reset batch-vinkjes?'))location.href='<?=htmlspecialchars($resetUrl)?>'">↺ Reset batch</button>
        <a href="pakketten.php?view=rayons&szf=<?=urlencode($sz)?>&jaar=<?=$jr?>" style="color:#64748b;font-size:13px">← Terug</a>
    </div>
    <div class="rp-hdr">
        <div class="rp-logo">HubMedia &mdash; Magazijn &mdash; Transportbatch</div>
        <div class="rp-naam"><?=htmlspecialchars($bRy)?></div>
        <div class="rp-sub"><?=htmlspecialchars($sz)?> <?=$jr?></div>
        <div class="rp-locs">📦 <?=number_format($locsR,0,',','.')?> locaties</div>
        <div class="rp-run">Run <?=$bn?></div>
        <div class="rp-datum"><?=$drukDatum?></div>
    </div>
    <div class="qr-wrap">
        <img src="<?=htmlspecialchars($qrUrlR)?>" alt="QR <?=htmlspecialchars($bRy)?>">
        <div class="qr-lbl">Scan bij transport</div>
        <div class="qr-lbl2">Rayon <?=htmlspecialchars($bRy)?> &mdash; Run <?=$bn?></div>
    </div>
    <div class="ft">
        <span>HubMedia &mdash; Magazijn</span>
        <span>Rayon <?=htmlspecialchars($bRy)?> &mdash; Run <?=$bn?> &mdash; <?=htmlspecialchars($sz)?> <?=$jr?></span>
        <span><?=$drukDatum?></span>
    </div>
</div>
<?php endforeach;?>
</body></html>
<?php exit; }

// ============================================================
// DASHBOARD
// ============================================================
require_once __DIR__ . '/includes/header.php';

$filterJaar = isset($_GET['jaar']) ? intval($_GET['jaar']) : date('Y');

// Werkbonnen laden: per klantnaam+seizoen ALLE werkbonnen samenvoegen
$wbPerSzRegio = [];
$klantData = [];
$statusScore = ['klaar'=>3,'bezig'=>2,'nieuw'=>1];
$resWb = func_dbsi_qry("SELECT * FROM magazijn_werkbonnen WHERE jaar=$filterJaar ORDER BY klantnaam");
if ($resWb) { while ($r = $resWb->fetch_assoc()) {
    $dk = $r['klantnaam'].'|'.$r['seizoen'];
    $score = $statusScore[$r['status']] ?? 1;
    if (!isset($klantData[$dk])) {
        $klantData[$dk] = ['klantnaam'=>$r['klantnaam'],'seizoen'=>$r['seizoen'],'jaar'=>$r['jaar'],
            'status'=>$r['status'],'medewerker'=>$r['medewerker'],'werkbon_code'=>$r['werkbon_code'],
            'alle_codes'=>[$r['werkbon_code']], // ALLE werkbon_codes voor rayon_status lookup
            'rayons'=>[],'score'=>$score];
    } else {
        // Voeg werkbon_code toe aan de lijst
        if (!in_array($r['werkbon_code'], $klantData[$dk]['alle_codes'])) {
            $klantData[$dk]['alle_codes'][] = $r['werkbon_code'];
        }
        // Bewaar beste status
        if ($score > $klantData[$dk]['score']) {
            $klantData[$dk]['status'] = $r['status'];
            $klantData[$dk]['medewerker'] = $r['medewerker'];
            $klantData[$dk]['werkbon_code'] = $r['werkbon_code'];
            $klantData[$dk]['score'] = $score;
        }
    }
    // Voeg alle rayons toe (union)
    $rys = array_filter(array_map('trim', explode(',', $r['rayons'])));
    foreach ($rys as $ry) {
        if ($ry && !in_array($ry, $klantData[$dk]['rayons'])) {
            $klantData[$dk]['rayons'][] = $ry;
        }
    }
}}

// Rayon status
$rayonStatus = [];
$resRS = func_dbsi_qry("SELECT rs.werkbon_code,rs.rayon,rs.rayon_idx,rs.klaar,rs.medewerker,rs.klaar_op FROM magazijn_rayon_status rs JOIN magazijn_werkbonnen wb ON wb.werkbon_code=rs.werkbon_code WHERE wb.jaar=$filterJaar");
if ($resRS) { while ($r=$resRS->fetch_assoc()) $rayonStatus[$r['werkbon_code']][$r['rayon'].'-'.$r['rayon_idx']] = $r; }

// Locaties per rayon
$locPerRayon = [];
$resLoc = func_dbsi_qry("SELECT Rayon,COUNT(*) as n FROM hubmedia_locaties WHERE (Status='0' OR Status='Actief' OR Status='$filterJaar') GROUP BY Rayon");
if ($resLoc) { while ($r=$resLoc->fetch_assoc()) $locPerRayon[$r['Rayon']] = $r['n']; }

// Bouw merged rayon_status per klant+seizoen over ALLE werkbon_codes
$mergedRayonStatus = [];
$klantIsKlaar = [];
foreach ($klantData as $dk => $kd) {
    $mergedRayonStatus[$dk] = [];
    foreach ($kd['alle_codes'] as $wbCode) {
        if (!isset($rayonStatus[$wbCode])) continue;
        foreach ($rayonStatus[$wbCode] as $key => $rsRow) {
            if (!isset($mergedRayonStatus[$dk][$key]) || $rsRow['klaar']) {
                $mergedRayonStatus[$dk][$key] = $rsRow;
            }
        }
    }
    if ($kd['status'] === 'klaar') $klantIsKlaar[$dk] = true;
}
// Extra DB check: klanten die ooit status='klaar' hebben gehad
$resKC = func_dbsi_qry("SELECT CONCAT(klantnaam,'|',seizoen) as dk FROM magazijn_werkbonnen WHERE jaar=$filterJaar AND status='klaar' GROUP BY klantnaam, seizoen");
if ($resKC) { while ($rk=$resKC->fetch_assoc()) $klantIsKlaar[$rk['dk']] = true; }

// Nu per klant de rayons splitsen per regio en in wbPerSzRegio zetten
foreach ($klantData as $dk => $kd) {
    $allRayons = implode(',', $kd['rayons']);
    $regioParts = pakRegio($allRayons, $limburgRayons);
    foreach ($regioParts as $rg => $rgRayons) {
        $rc = $kd;
        $rc['regio'] = $rg;
        $rc['rayons'] = implode(',', $rgRayons);
        $rc['_uid'] = md5($kd['klantnaam'].$kd['seizoen'].$rg).'-'.$rg;
        $rc['_dk'] = $dk; // sleutel voor mergedRayonStatus
        // Zoek de werkbon_code die DEZE regio-rayons bevat in de DB
        $rc['_regio_rayons'] = implode(',', $rgRayons);
        $wbPerSzRegio[$kd['seizoen']][$rg][] = $rc;
    }
}

// Seizoenen
$seizoenen = [];
$resSz = func_dbsi_qry("SELECT DISTINCT seizoen FROM folder_verkopen WHERE jaar=$filterJaar AND fase='Aanvaard' AND rayon!='' ORDER BY FIELD(seizoen,'VJ','ZO','HR','TOP')");
if ($resSz) { while ($r=$resSz->fetch_assoc()) $seizoenen[] = $r['seizoen']; }
foreach (array_keys($wbPerSzRegio) as $sz) { if (!in_array($sz,$seizoenen)) $seizoenen[] = $sz; }

// (rayonStatus + locPerRayon moved up)

$activeTab = isset($_GET['tab']) ? trim($_GET['tab']) : (count($seizoenen)>0 ? $seizoenen[0] : 'VJ');
$szLabels = ['VJ'=>'Voorjaar','ZO'=>'Zomer','HR'=>'Herfst','TOP'=>'Topweek'];
// Stapeltjes worden berekend vanuit klantData (consistent met kolom-weergave)
$doosjesPerSz = [];
foreach ($seizoenen as $szQ) $doosjesPerSz[$szQ] = ['klaar'=>0,'totaal'=>0];
// Wordt ingevuld na de kolom-berekening hieronder
$preDoosjesKlaarAll = 0;
$preDoosjesAll = 0;
$pctDoosjes = $preDoosjesAll > 0 ? round(($preDoosjesKlaarAll/$preDoosjesAll)*100,1) : 0;

// Stats per seizoen per regio
$statsPerSzRegio = [];
foreach ($wbPerSzRegio as $sz => $rgArr) { foreach ($rgArr as $rg => $wbs) {
    if (!isset($statsPerSzRegio[$sz][$rg])) $statsPerSzRegio[$sz][$rg] = ['nieuw'=>0,'bezig'=>0,'klaar'=>0,'totaal'=>0];
    foreach ($wbs as $w) { $st=$w['status']??'nieuw'; $statsPerSzRegio[$sz][$rg][$st]++; $statsPerSzRegio[$sz][$rg]['totaal']++; }
}}
$statsAll = ['nieuw'=>0,'bezig'=>0,'klaar'=>0,'totaal'=>0];
foreach ($statsPerSzRegio as $szD) { foreach ($szD as $rgD) { foreach($rgD as $k=>$v) { if(isset($statsAll[$k])) $statsAll[$k]+=$v; } } }

// Deals zonder werkbon
$dealItemsAll = [];
$dealsZonderWb = [];
$resDA = func_dbsi_qry("SELECT klantnaam,seizoen,GROUP_CONCAT(DISTINCT rayon ORDER BY rayon) as rayons FROM folder_verkopen WHERE jaar=$filterJaar AND fase='Aanvaard' AND rayon!='' GROUP BY klantnaam,seizoen");
if ($resDA) { while ($d=$resDA->fetch_assoc()) {
    $chk = func_dbsi_qry("SELECT id FROM magazijn_werkbonnen WHERE klantnaam='".safe($d['klantnaam'])."' AND seizoen='".safe($d['seizoen'])."' AND jaar=$filterJaar");
    if ($chk && $chk->fetch_assoc()) continue;
    $rys=explode(',',$d['rayons']);$tl=0;foreach($rys as $ry)$tl+=isset($locPerRayon[trim($ry)])?$locPerRayon[trim($ry)]:0;
    $dealItemsAll[] = ['kn'=>$d['klantnaam'],'sz'=>$d['seizoen'],'jr'=>$filterJaar,'rayons'=>$d['rayons'],'loc'=>$tl];
    if(!isset($dealsZonderWb[$d['seizoen']]))$dealsZonderWb[$d['seizoen']]=0;
    $dealsZonderWb[$d['seizoen']]++;
}}

// Voorraad status laden
$voorraadStatus = [];
$inDrukStatus = [];
$wordtGemaaktStatus = [];
$resVr = func_dbsi_qry("SELECT klantnaam, seizoen, op_voorraad, in_druk, wordt_gemaakt FROM magazijn_voorraad WHERE jaar=$filterJaar");
if ($resVr) { while ($r=$resVr->fetch_assoc()) {
    $voorraadStatus[$r['klantnaam'].'|'.$r['seizoen']] = $r['op_voorraad'];
    $inDrukStatus[$r['klantnaam'].'|'.$r['seizoen']] = $r['in_druk'];
    $wordtGemaaktStatus[$r['klantnaam'].'|'.$r['seizoen']] = $r['wordt_gemaakt'];
}}

// Rayon-klaar lookup (gebouwd in dashboard voor gebruik in rayon-panel)
$rayonKlaarDB = [];
$wbCodeKlaar = [];
$rkRes = func_dbsi_qry("SELECT DISTINCT wb.klantnaam, wb.seizoen, rs.rayon, rs.werkbon_code
    FROM magazijn_rayon_status rs
    JOIN magazijn_werkbonnen wb ON wb.werkbon_code = rs.werkbon_code
    WHERE rs.klaar = 1 AND wb.jaar = $filterJaar AND rs.rayon != ''");
if ($rkRes) { while ($rk = $rkRes->fetch_assoc()) {
    if (!$rk['rayon']) continue;
    $rayonKlaarDB[$rk['klantnaam'].'|'.$rk['seizoen'].'|'.$rk['rayon']] = true;
    $wbCodeKlaar[$rk['werkbon_code'].'|'.$rk['rayon']] = true;
}}

// Medewerkers
$medewerkers=[];$resMw=func_dbsi_qry("SELECT DISTINCT naam FROM hubmedia_chauffeurs WHERE actief=1 ORDER BY naam");
if($resMw){while($r=$resMw->fetch_assoc())$medewerkers[]=$r['naam'];}
if(empty($medewerkers))$medewerkers=['Hub','Rob','Maikel','Nigel'];

// (activeTab moved up)
$regios = [
    'Limburg'    => ['label'=>'&#x1F3D8;&#xFE0F; Limburg',    'sub'=>'NL58&ndash;NL64','color'=>'#6d28d9','bg'=>'#f5f3ff','border'=>'#c4b5fd'],
    'RestNL'     => ['label'=>'&#x1F1F3;&#x1F1F1; Rest NL',   'sub'=>'overige NL','color'=>'#1d4ed8','bg'=>'#eff6ff','border'=>'#bfdbfe'],
    'Buitenland' => ['label'=>'&#x1F30D; Belgi&euml;+DE','sub'=>'BE&middot; en DE&middot;','color'=>'#b45309','bg'=>'#fffbeb','border'=>'#fcd34d']
];
?>
<style>
:root{--pr:#1e3a5f;--gr:#059669;--or:#d97706;--bl:#2563eb}
.mgc{max-width:1400px;margin:0 auto;padding:20px}
.mgt{font-size:24px;font-weight:800;color:var(--pr);margin-bottom:20px}
.kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:16px}
.kpi{background:#fff;border-radius:12px;padding:12px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.kpi-v{font-size:24px;font-weight:800}.kpi-l{font-size:11px;color:#64748b;margin-top:3px}
.prg{height:24px;background:#e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:20px;position:relative}
.prg-fill{height:100%;background:linear-gradient(90deg,var(--gr),#34d399);border-radius:12px;transition:width .5s}
.prg-txt{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
.tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid #e2e8f0;flex-wrap:wrap}
.tab{padding:10px 20px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:600;color:#64748b;border-bottom:3px solid transparent;margin-bottom:-2px;transition:.2s}
.tab.active{color:var(--pr);border-bottom-color:var(--pr);background:#f0f4ff;border-radius:8px 8px 0 0}
.tab .badge{display:inline-block;background:#e2e8f0;color:#64748b;font-size:10px;padding:2px 6px;border-radius:10px;margin-left:6px;font-weight:700}
.tab.active .badge{background:var(--pr);color:#fff}
.tab .bdone{background:#d1fae5;color:#059669}
.panel{display:none}.panel.active{display:block}
.col3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;align-items:start}
@media(max-width:900px){.col3{grid-template-columns:1fr}}
.col-card{border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.col-hdr{padding:14px 16px;color:#fff}
.col-hdr h3{font-size:15px;font-weight:700;margin-bottom:2px}
.col-hdr small{font-size:11px;opacity:.8}
.col-stats{display:grid;grid-template-columns:1fr 1fr 1fr;background:#fff;border-bottom:1px solid #e2e8f0}
.col-stat{padding:8px;text-align:center;border-right:1px solid #e2e8f0}
.col-stat:last-child{border-right:none}
.col-stat-v{font-size:18px;font-weight:800}.col-stat-l{font-size:9px;color:#64748b;text-transform:uppercase;margin-top:1px}
.col-prog{height:6px;background:#e2e8f0}
.col-prog-fill{height:100%;transition:width .4s}
.col-body{background:#fff}
.mgtbl{width:100%;border-collapse:collapse;table-layout:fixed}
.mgtbl th{background:#f8fafc;padding:6px 4px;text-align:left;font-size:10px;color:#64748b;text-transform:uppercase;font-weight:600;border-bottom:1px solid #e2e8f0}
.mgtbl td{padding:5px 4px;border-bottom:1px solid #f1f5f9;font-size:12px;vertical-align:middle}
.mgtbl th.chk-col,.mgtbl td.chk-col{width:22px;padding:2px 1px;text-align:center}
.mgtbl tr:last-child td{border:none}
.mgtbl tr.done td{color:#6b7280}
.mgtbl tr.done{background:#f0fdf4}
.sec-hdr td{font-size:10px;font-weight:700;padding:4px 10px;text-transform:uppercase;letter-spacing:.5px}
.chk{width:16px;height:16px;cursor:pointer;accent-color:var(--gr)}
.mw-bar{background:#fff;border-radius:12px;padding:12px 16px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.06);display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.mw-bar label{font-size:13px;font-weight:600;color:var(--pr)}
.mw-bar select,.mw-bar input[type=date],.mw-bar input[type=text]{padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px}
.klant{cursor:pointer;user-select:none}.klant:hover{color:var(--bl)}
.arr{display:inline-block;transition:transform .2s;font-size:10px;margin-right:4px;color:#94a3b8}
.klant.open .arr{transform:rotate(90deg)}
.ry-row{display:none}.ry-row.open{display:table-row}
.sh{display:none!important}
.ry-cell{padding:4px 10px 10px 32px!important;background:#f8fafc}
.ry-list{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px}
.ry-item{display:flex;align-items:center;gap:5px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:4px 8px;font-size:11px}
.ry-item.done{background:#d1fae5;border-color:#6ee7b7}
.ry-name{font-weight:700;color:#1e3a5f;min-width:36px}.ry-loc{color:#64748b;font-size:10px}
.chk-all{font-size:10px;color:var(--bl);cursor:pointer;border:none;background:none;font-weight:600;padding:3px 6px;border-radius:4px}
.chk-all:hover{background:#dbeafe}
.btn-gen{background:var(--pr);color:#fff;padding:7px 12px;border-radius:8px;font-size:12px;border:none;cursor:pointer;font-weight:600}
.act-btn{padding:4px 8px;border:none;border-radius:6px;cursor:pointer;font-size:11px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:3px}
.act-prn{background:#e0e7ff;color:#3730a3}.act-mob{background:#dbeafe;color:#1e40af}
.col-empty{padding:20px;text-align:center;color:#94a3b8;font-size:12px;background:#fff}
.wk-card{background:#fff;border-radius:12px;padding:16px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:20px}
.wk-card h3{font-size:16px;font-weight:700;color:var(--pr);margin-bottom:12px}
.wk-bar{display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap}
.wk-bar select,.wk-bar button{padding:6px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;cursor:pointer}
.wk-bar button{background:var(--pr);color:#fff;border:none;font-weight:600}
.wk-tbl{width:100%;border-collapse:collapse}
.wk-tbl th{background:#1e3a5f;color:#fff;padding:7px 10px;text-align:center;font-size:11px}
.wk-tbl th:nth-child(2){text-align:left}
.wk-tbl td{padding:6px 10px;border-bottom:1px solid #f1f5f9;font-size:13px;text-align:center}
.wk-tbl td:nth-child(2){text-align:left;font-weight:600}
.wk-tbl tr:last-child td{font-weight:700;background:#1e3a5f;color:#fff;border:none}
.wk-tbl .today{background:#dbeafe;font-weight:700}
.wk-tbl .r1{background:#fef9c3}
.chart-wrap{background:#fff;border-radius:12px;padding:16px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:20px}
.chart-wrap h3{font-size:16px;font-weight:700;color:var(--pr);margin-bottom:12px}
</style>

<div class="mgc">
<div class="mgt">&#x1F4E6; Magazijn Dashboard <?=$filterJaar?></div>

<div style="margin-bottom:16px">
    <select onchange="location.href='pakketten.php?jaar='+this.value">
        <?php for($y=date('Y');$y>=date('Y')-2;$y--):?><option value="<?=$y?>"<?=$y==$filterJaar?' selected':''?>><?=$y?></option><?php endfor;?>
    </select>
</div>

<div class="kpi-row">
    <div class="kpi"><div class="kpi-v"><?=$statsAll['totaal']?></div><div class="kpi-l">Werkbonnen</div></div>
    <div class="kpi"><div class="kpi-v" style="color:var(--or)"><?=$statsAll['nieuw']?></div><div class="kpi-l">Wachtend</div></div>
    <div class="kpi"><div class="kpi-v" style="color:var(--gr)"><?=$statsAll['klaar']?></div><div class="kpi-l">Afgerond</div></div>
    <div class="kpi"><div class="kpi-v" id="kpi-stap-totaal"><?=number_format($preDoosjesAll,0,',','.')?></div><div class="kpi-l">Stapeltjes totaal</div></div>
    <div class="kpi"><div class="kpi-v" style="color:var(--gr)" id="kpi-stap-klaar"><?=number_format($preDoosjesKlaarAll,0,',','.')?></div><div class="kpi-l">Stapeltjes klaar</div></div>
</div>
<div class="prg"><div class="prg-fill" id="prg-fill" style="width:<?=$pctDoosjes?>%"></div><div class="prg-txt" id="prg-txt"><?=$pctDoosjes?>% stapeltjes klaar (<?=number_format($preDoosjesKlaarAll,0,',','.')?>/<?=number_format($preDoosjesAll,0,',','.')?>)</div></div>

<div class="mw-bar">
    <label>&#x1F464; Medewerker:</label>
    <select id="mgMw"><option value="">&#x2014; Kies &#x2014;</option><?php foreach($medewerkers as $mw):?><option value="<?=htmlspecialchars($mw)?>"><?=htmlspecialchars($mw)?></option><?php endforeach;?></select>
    <label>&#x1F4C5; Datum:</label>
    <input type="date" id="mgDt" value="<?=date('Y-m-d')?>">
    <input type="text" id="mgZoek" placeholder="&#x1F50D; Zoek klant..." oninput="zoekKlant(this.value)" style="min-width:180px">
    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;font-weight:700;color:#dc2626;background:#fff;border:2px solid #dc2626;padding:5px 12px;border-radius:8px">
        <input type="checkbox" id="filterOpenstaand" onchange="togFilterOpenstaand(this)" style="width:16px;height:16px;accent-color:#dc2626;cursor:pointer">
        Openstaand
    </label>
</div>

<div class="tabs">
<?php foreach($seizoenen as $sz):
    $szK=0;$szT=0;
    if(isset($statsPerSzRegio[$sz])){foreach($statsPerSzRegio[$sz] as $d){$szK+=$d['klaar'];$szT+=$d['totaal'];}}
?>
<button class="tab<?=$sz==$activeTab?' active':''?>" onclick="switchTab('<?=$sz?>')" id="tab-<?=$sz?>">
    <?=isset($szLabels[$sz])?$szLabels[$sz]:$sz?> <?=$filterJaar?>
    <span class="badge<?=$szK==$szT&&$szT>0?' bdone':''?>"><?=$szK?>/<?=$szT?></span>
</button>
<?php endforeach;?>
<?php $isRayonView = (isset($_GET['view']) && $_GET['view']==='rayons'); ?>
<button class="tab<?=$isRayonView?' active':''?>" onclick="switchToRayons()" id="tab-rayons" style="border-left:2px solid #e2e8f0;margin-left:8px">
    &#x1F4CD; Per rayon
</button>
</div>

<?php foreach($seizoenen as $sz):
    $totZonder = isset($dealsZonderWb[$sz]) ? $dealsZonderWb[$sz] : 0;
?>
<div class="panel<?=(!$isRayonView && $sz==$activeTab)?' active':''?>" id="panel-<?=$sz?>">
<?php if($totZonder>0):?><div style="margin-bottom:12px"><button class="btn-gen" onclick="genWb('<?=$sz?>')">&#x1F4CB; +<?=$totZonder?> deals toevoegen</button></div><?php endif;?>
<div class="col3">
<?php foreach($regios as $rgKey=>$rgInfo):
    $rgWbs = isset($wbPerSzRegio[$sz][$rgKey]) ? $wbPerSzRegio[$sz][$rgKey] : [];
    $rgSt = isset($statsPerSzRegio[$sz][$rgKey]) ? $statsPerSzRegio[$sz][$rgKey] : ['nieuw'=>0,'bezig'=>0,'klaar'=>0,'totaal'=>0];
    $rgPct = $rgSt['totaal']>0 ? round(($rgSt['klaar']/$rgSt['totaal'])*100) : 0;
    // Bereken doosjes per kolom
    $rgD=0;$rgDK=0;
    foreach($rgWbs as &$w){
        $ryL=array_filter(array_map('trim',explode(',',$w['rayons'])));
        $nR=count($ryL);
        // Gebruik merged rayon status over alle werkbon_codes van deze klant
        $wRS=isset($mergedRayonStatus[$w['_dk']])?$mergedRayonStatus[$w['_dk']]:[];
        $nRK=0;$rcT=[];$wD=0;$wDK=0;
        foreach($ryL as $ry){
            $ix=isset($rcT[$ry])?$rcT[$ry]:0;
            $locs=isset($locPerRayon[$ry])?$locPerRayon[$ry]:0;
            $wD+=$locs;
            $rOk=(isset($wRS[$ry.'-'.$ix])&&$wRS[$ry.'-'.$ix]['klaar'])||isset($klantIsKlaar[$w['_dk']]);
            if($rOk){$nRK++;$wDK+=$locs;}
            $rcT[$ry]=$ix+1;
        }
        $w['_nR']=$nR;$w['_nRK']=$nRK;$w['_d']=$wD;$w['_d2']=$wDK;
        $rgD+=$wD;$rgDK+=$wDK;
    }
    unset($w);
    $rgDPct=$rgD>0?round(($rgDK/$rgD)*100):0;
    // Accumuleer voor globale KPI
    if(!isset($doosjesPerSz[$sz])) $doosjesPerSz[$sz]=['klaar'=>0,'totaal'=>0];
    $doosjesPerSz[$sz]['totaal'] += $rgD;
    $doosjesPerSz[$sz]['klaar'] += $rgDK;
    // Sorteer: 0=te doen, 1=deels, 2=klaar
    // Sorteer: 0=te doen, 1=deels, 2=klaar; binnen groep alfabetisch
    foreach($rgWbs as &$tmp){ 
        $tmp['_groep']=(isset($klantIsKlaar[$tmp['_dk']])||($tmp['_nRK']>=$tmp['_nR']&&$tmp['_nR']>0))?2:($tmp['_nRK']>0?1:0);
    } unset($tmp);
    usort($rgWbs,function($a,$b){
        return $a['_groep']!=$b['_groep']?$a['_groep']-$b['_groep']:strcasecmp($a['klantnaam'],$b['klantnaam']);
    });
?>
<div class="col-card">
<div class="col-hdr" style="background:<?=$rgInfo['color']?>"><h3><?=$rgInfo['label']?></h3><small><?=$rgInfo['sub']?></small></div>
<div class="col-stats">
    <div class="col-stat"><div class="col-stat-v" style="color:<?=$rgInfo['color']?>"><?=$rgSt['totaal']?></div><div class="col-stat-l">Werkbonnen</div></div>
    <div class="col-stat"><div class="col-stat-v" style="color:var(--gr)"><?=$rgSt['klaar']?></div><div class="col-stat-l">Klaar</div></div>
    <div class="col-stat"><div class="col-stat-v" style="color:<?=$rgPct>=100?'#059669':($rgPct>50?'#2563eb':'#d97706')?>"><?=$rgPct?>%</div><div class="col-stat-l">WB gedaan</div></div>
</div>
<div style="background:<?=$rgInfo['bg']?>;padding:5px 12px;font-size:11px;color:<?=$rgInfo['color']?>;border-bottom:1px solid <?=$rgInfo['border']?>">
    &#x1F4E6; <?=number_format($rgDK,0,',','.')?>/<?=number_format($rgD,0,',','.')?> stapeltjes &mdash; <?=$rgDPct?>%
</div>
<div class="col-prog"><div class="col-prog-fill" style="width:<?=$rgDPct?>%;background:<?=$rgInfo['color']?>"></div></div>
<div class="col-body">
<?php if(empty($rgWbs)):?><div class="col-empty">Geen werkbonnen</div><?php else:?>
<table class="mgtbl">
<tr><th style="width:22px">&#x2713;</th><th>Klant</th><th style="width:40px">Rayons</th><th style="width:48px">Stapel.</th><th class="chk-col" title="Wordt gemaakt">&#x1F528;</th><th class="chk-col" title="Op voorraad">&#x1F4E6;</th><th class="chk-col" title="In druk">&#x1F5A8;</th><th style="width:48px">Acties</th></tr>
<?php
$prevG=null;$gL=[0=>'&#x23F3; Te doen',1=>'&#x1F528; Deels',2=>'&#x2705; Klaar'];$gC=[0=>'#92400e',1=>'#1e40af',2=>'#065f46'];$gB=[0=>'#fef3c7',1=>'#dbeafe',2=>'#d1fae5'];
foreach($rgWbs as $w):
    // isKlaar: klant+seizoen heeft ooit klaar gehad OF alle rayons afgevinkt
$groep = $w['_groep'];
$isKlaar = ($groep === 2);
    $rayons=array_filter(array_map('trim',explode(',',$w['rayons'])));
    $wRS=isset($mergedRayonStatus[$w['_dk']])?$mergedRayonStatus[$w['_dk']]:[];
    $uid=$w['_uid'];$wCode=$w['werkbon_code'];
    if($groep!==$prevG):$prevG=$groep;?>
<tr><td colspan="7" class="sec-hdr" style="background:<?=$gB[$groep]?>;color:<?=$gC[$groep]?>"><?=$gL[$groep]?></td></tr>
<?php endif;?>
<?php $vrKey=$w['klantnaam'].'|'.$w['seizoen']; $vrWaarde=isset($voorraadStatus[$vrKey])&&$voorraadStatus[$vrKey]?1:0; $idWaarde=isset($inDrukStatus[$vrKey])&&$inDrukStatus[$vrKey]?1:0; $wgWaarde=isset($wordtGemaaktStatus[$vrKey])&&$wordtGemaaktStatus[$vrKey]?1:0; ?>
<tr class="<?=$isKlaar?'done':''?>" id="row-<?=$uid?>" data-klaar="<?=$isKlaar?1:0?>" data-indruk="<?=$idWaarde?>" data-voorraad="<?=$vrWaarde?>" data-wordtgemaakt="<?=$wgWaarde?>">
<td><?php $uesc=htmlspecialchars($uid,ENT_QUOTES);$cesc=htmlspecialchars($wCode,ENT_QUOTES);?><input type="checkbox" class="chk" data-code="<?=$cesc?>" data-uid="<?=$uesc?>" <?=$isKlaar?'checked':''?> onchange="togWb(this)"></td>
<td><span class="klant" onclick="togRayons('<?=$uesc?>')"><span class="arr">&#x25B6;</span><?=htmlspecialchars($w['klantnaam'])?></span>
<?php if($w['medewerker']):?><br><small style="color:#94a3b8;font-size:10px"><?=htmlspecialchars($w['medewerker'])?></small><?php endif;?></td>
<td style="text-align:center"><?=$w['_nR']?> <span id="rp-<?=$uesc?>" style="font-size:10px;color:#94a3b8">(<?=$w['_nRK']?>/<?=$w['_nR']?>)</span></td>
<td style="text-align:center;font-weight:700;font-size:11px" id="dj-<?=$uesc?>"><?=$w['_d2']?>/<?=$w['_d']?></td>
<?php 
    $rgEsc=htmlspecialchars($rgKey,ENT_QUOTES);
    $klantRayonsEsc=htmlspecialchars($w['_regio_rayons']??'',ENT_QUOTES);
?>
<td class="chk-col">
<input type="checkbox" class="chk" title="Wordt gemaakt" id="wordtgemaakt-<?=$uesc?>" <?=$wgWaarde?'checked':''?>
    onchange="togWordtGemaakt(this,'<?=htmlspecialchars($w['klantnaam'],ENT_QUOTES)?>','<?=htmlspecialchars($w['seizoen'],ENT_QUOTES)?>',<?=$filterJaar?>,'<?=$uesc?>')"
    style="accent-color:#7c3aed;width:14px;height:14px">
</td>
<td class="chk-col">
<input type="checkbox" class="chk" title="Op voorraad" <?=$vrWaarde?'checked':''?>
    onchange="togVoorraad(this,'<?=htmlspecialchars($w['klantnaam'],ENT_QUOTES)?>','<?=htmlspecialchars($w['seizoen'],ENT_QUOTES)?>',<?=$filterJaar?>)"
    style="accent-color:#f59e0b;width:14px;height:14px">
</td>
<td class="chk-col">
<input type="checkbox" class="chk" title="In druk" id="indruk-<?=$uesc?>" <?=$idWaarde?'checked':''?>
    onchange="togInDruk(this,'<?=htmlspecialchars($w['klantnaam'],ENT_QUOTES)?>','<?=htmlspecialchars($w['seizoen'],ENT_QUOTES)?>',<?=$filterJaar?>,'<?=$uesc?>')"
    style="accent-color:#dc2626;width:14px;height:14px">
</td>
<td style="white-space:nowrap">
<a href="pakketten.php?print=<?=$cesc?>&regio=<?=$rgEsc?>&rayons=<?=$klantRayonsEsc?>" target="_blank" class="act-btn act-prn">&#x1F5A8;</a>
<a href="pakketten.php?wb=<?=$cesc?>&regio=<?=$rgEsc?>&rayons=<?=$klantRayonsEsc?>" target="_blank" class="act-btn act-mob">&#x1F4F1;</a>
</td>
</tr>
<tr class="ry-row" id="rayons-<?=$uesc?>">
<td colspan="7" class="ry-cell">
<?php $wU2=htmlspecialchars($uid,ENT_QUOTES);$wC2=htmlspecialchars($wCode,ENT_QUOTES);?>
<button class="chk-all" onclick="togAlRayons('<?=$wC2?>','<?=$wU2?>',true)">&#x2713; Alles</button>
<button class="chk-all" onclick="togAlRayons('<?=$wC2?>','<?=$wU2?>',false)" style="color:var(--or)">&#x2715; Wis</button>
<div class="ry-list">
<?php $ryC2=[];foreach($rayons as $ry):$ry=trim($ry);$idx=isset($ryC2[$ry])?$ryC2[$ry]:0;$key=$ry.'-'.$idx;$wDkKlaar=isset($klantIsKlaar[$w['_dk']]);
$ryK=(isset($wRS[$key])&&$wRS[$key]['klaar'])||$wDkKlaar;
$ryM=isset($wRS[$key])?($wRS[$key]['medewerker']??''):($wDkKlaar?($w['medewerker']??''):'');
$ryLoc=isset($locPerRayon[$ry])?$locPerRayon[$ry]:'?';$ryC2[$ry]=$idx+1;$ryesc=htmlspecialchars($ry,ENT_QUOTES);?>
<div class="ry-item<?=$ryK?' done':''?>" id="ri-<?=$uesc?>-<?=$ryesc?>-<?=$idx?>">
<input type="checkbox" class="chk" <?=$ryK?'checked':''?> data-code="<?=$cesc?>" data-uid="<?=$uesc?>" data-rayon="<?=$ryesc?>" data-rayon-idx="<?=$idx?>" onchange="togRayon(this)">
<span class="ry-name"><?=htmlspecialchars($ry)?></span><span class="ry-loc"><?=$ryLoc?> loc.</span>
<?php if($ryM):?><span style="color:#059669;font-size:10px;font-style:italic"><?=htmlspecialchars($ryM)?></span><?php endif;?>
</div>
<?php endforeach;?>
</div></td></tr>
<?php endforeach;?>
</table>
<?php endif;?>
</div></div>
<?php endforeach;// regios ?>
</div></div>
<?php endforeach;// seizoenen ?>

<div id="panel-rayons" class="panel" style="display:<?php echo (isset($_GET['view'])&&$_GET['view']=='rayons')? 'block':'none'; ?>">
<?php
// Rayon overzicht: alle unieke rayons met klanten
$rayonFilter = trim($_GET['rayon'] ?? '');
$seizoenFilter = trim($_GET['szf'] ?? $activeTab);





// Alle rayons verzamelen
$alleRayons = [];
foreach ($klantData as $dk => $kd) {
    foreach ($kd['rayons'] as $ry) {
        if (!isset($alleRayons[$ry])) $alleRayons[$ry] = [];
        $seizoenKlant = $kd['seizoen'];
        if ($seizoenKlant === $seizoenFilter || !$seizoenFilter) {
            $alleRayons[$ry][] = $kd;
        }
    }
}


ksort($alleRayons);

// Laad versies (5 aanvinkvakjes) per rayon voor huidig seizoen+jaar
$rayonVersies = [];
$szSafeV = safe($seizoenFilter ?: $activeTab);
$resRV = func_dbsi_qry("SELECT rayon, versies FROM magazijn_rayon_versies WHERE seizoen='$szSafeV' AND jaar=$filterJaar");
if ($resRV) { while ($rvR = $resRV->fetch_assoc()) {
    $rayonVersies[$rvR['rayon']] = intval($rvR['versies']);
}}
// Laad transport-bits per rayon
$rayonTransport = [];
$resRT = func_dbsi_qry("SELECT rayon, transport FROM magazijn_rayon_transport WHERE seizoen='$szSafeV' AND jaar=$filterJaar");
if ($resRT) { while ($rtR = $resRT->fetch_assoc()) {
    $rayonTransport[$rtR['rayon']] = intval($rtR['transport']);
}}
// Laad batch-bits per rayon
$rayonBatchSelected = [];
$rayonBatchGedrukt  = [];
$resRB = func_dbsi_qry("SELECT rayon, selected, gedrukt FROM magazijn_rayon_batch WHERE seizoen='$szSafeV' AND jaar=$filterJaar");
if ($resRB) { while ($rbR = $resRB->fetch_assoc()) {
    $rayonBatchSelected[$rbR['rayon']] = intval($rbR['selected']);
    $rayonBatchGedrukt[$rbR['rayon']]  = intval($rbR['gedrukt']);
}}
// Tel per batch-nummer hoeveel rayons geselecteerd zijn (zonder gedrukt-filter)
$batchAantallen = array_fill(1, 5, 0);
foreach ($rayonBatchSelected as $ryB => $bSel) {
    $bGedB = isset($rayonBatchGedrukt[$ryB]) ? $rayonBatchGedrukt[$ryB] : 0;
    $activeBits = $bSel & ~$bGedB;
    for ($bn=1;$bn<=5;$bn++) {
        if (($activeBits >> ($bn-1)) & 1) $batchAantallen[$bn]++;
    }
}

// Versie doosjes samenvatting: per versie-bit sum van locaties van aangevinkte rayons
$versieDoosjesSom = array_fill(1, 5, 0);
// Klaar doosjes: distinct rayons die minstens 1x klaar zijn in magazijn_rayon_status
$klaarRayons = [];
$resKR = func_dbsi_qry("SELECT DISTINCT rs.rayon
    FROM magazijn_rayon_status rs
    JOIN magazijn_werkbonnen wb ON wb.werkbon_code = rs.werkbon_code
    WHERE rs.klaar = 1 AND wb.jaar = $filterJaar AND wb.seizoen = '$szSafeV'");
if ($resKR) { while ($rKR = $resKR->fetch_assoc()) {
    $klaarRayons[$rKR['rayon']] = true;
}}
$klaarDoosjesSomRayon = 0;
foreach (array_keys($alleRayons) as $ry) {
    $locs = isset($locPerRayon[$ry]) ? $locPerRayon[$ry] : 0;
    $bits = isset($rayonVersies[$ry]) ? $rayonVersies[$ry] : 0;
    for ($vn = 1; $vn <= 5; $vn++) {
        if (($bits >> ($vn - 1)) & 1) $versieDoosjesSom[$vn] += $locs;
    }
    // Rayon klaar = dat rayon is minstens 1x klaar gemeld → tel de dozen (locaties) 1x mee
    if (isset($klaarRayons[$ry])) {
        $klaarDoosjesSomRayon += $locs;
    }
}

// Groepeer per regio voor de rayon-lijst
$rayonGroepen = ['Limburg'=>[], 'RestNL'=>[], 'Buitenland'=>[]];
foreach (array_keys($alleRayons) as $ry) {
    if (in_array($ry, $limburgRayons)) $rayonGroepen['Limburg'][] = $ry;
    elseif (substr($ry,0,2)==='BE'||substr($ry,0,2)==='DE') $rayonGroepen['Buitenland'][] = $ry;
    else $rayonGroepen['RestNL'][] = $ry;
}
$rayonGroepLabels = ['Limburg'=>'Limburg (NL58-NL64)', 'RestNL'=>'Rest Nederland', 'Buitenland'=>'Belgie + Duitsland'];
$rayonGroepKleuren = ['Limburg'=>'#6d28d9', 'RestNL'=>'#1d4ed8', 'Buitenland'=>'#b45309'];
?>

<!-- Versie doosjes overzicht -->
<div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:14px 20px;margin-bottom:16px">
    <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">
        &#x1F4E6; Doosjes per versie &mdash; <?=htmlspecialchars($seizoenFilter ?: $activeTab)?> <?=$filterJaar?>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:stretch">
        <?php
        $versieKleuren = ['#2563eb','#059669','#d97706','#7c3aed','#dc2626'];
        $versieBg     = ['#eff6ff','#f0fdf4','#fffbeb','#f5f3ff','#fff1f2'];
        for ($vn = 1; $vn <= 5; $vn++):
            $som = $versieDoosjesSom[$vn];
            $kleur = $versieKleuren[$vn-1];
            $bg    = $versieBg[$vn-1];
        ?>
        <div style="flex:1;min-width:100px;background:<?=$bg?>;border:1px solid <?=$kleur?>33;border-radius:10px;padding:10px 14px;text-align:center">
            <div style="font-size:10px;font-weight:700;color:<?=$kleur?>;text-transform:uppercase;margin-bottom:4px">Versie <?=$vn?></div>
            <div style="font-size:22px;font-weight:800;color:<?=$kleur?>"><?=number_format($som,0,',','.')?></div>
            <div style="font-size:10px;color:#94a3b8;margin-top:2px">doosjes</div>
        </div>
        <?php endfor; ?>
        <div style="flex:1;min-width:120px;background:#f0fdf4;border:2px solid #059669;border-radius:10px;padding:10px 14px;text-align:center">
            <div style="font-size:10px;font-weight:700;color:#059669;text-transform:uppercase;margin-bottom:4px">&#x2705; Klaar</div>
            <div style="font-size:22px;font-weight:800;color:#059669"><?=number_format($klaarDoosjesSomRayon,0,',','.')?></div>
            <div style="font-size:10px;color:#94a3b8;margin-top:2px">doosjes totaal</div>
        </div>
    </div>
</div>

<?php
// Pre-render klant data als JSON voor de modal (JS)
$modalData = [];
foreach ($alleRayons as $ry => $klanten) {
    $locs = isset($locPerRayon[$ry]) ? $locPerRayon[$ry] : 0;
    $ryRegio = in_array($ry, $limburgRayons) ? 'Limburg' : (substr($ry,0,2)==='BE'||substr($ry,0,2)==='DE' ? 'Buitenland' : 'RestNL');
    $klRows = [];
    foreach ($klanten as $kd) {
        $dk  = $kd['klantnaam'].'|'.$kd['seizoen'];
        $vrW = isset($voorraadStatus[$dk]) && $voorraadStatus[$dk] ? 1 : 0;
        $isKl = isset($klantIsKlaar[$dk]) ? 1 : 0;
        $klRows[] = [
            'naam'      => $kd['klantnaam'],
            'seizoen'   => $kd['seizoen'],
            'status'    => $kd['status'],
            'medewerker'=> $kd['medewerker'] ?? '',
            'voorraad'  => $vrW,
            'klaar'     => $isKl,
            'wb'        => $kd['werkbon_code'],
            'regio'     => $ryRegio,
        ];
    }
    
    // Foto's ophalen voor dit rayon
    $fotoRij = [];
    $resFoto = func_dbsi_qry("SELECT id, file_path, transport_nr FROM magazijn_rayon_transport_fotos WHERE rayon='" . safe($ry) . "' AND seizoen='" . safe($seizoenFilter ?: $activeTab) . "' AND jaar=$filterJaar ORDER BY uploaded_at DESC LIMIT 50");
    if ($resFoto) {
        while ($f = $resFoto->fetch_assoc()) {
            $fotoRij[] = [
                'id' => intval($f['id']),
                'path' => $f['file_path'],
                'transport_nr' => intval($f['transport_nr']),
            ];
        }
    }
    
    $loodswbUrl = 'pakketten.php?loodswb='.urlencode($ry).'&szf='.urlencode($seizoenFilter).'&jaar='.$filterJaar;
    $printUrl2  = 'pakketten.php?rayon_print='.urlencode($ry).'&szf='.urlencode($seizoenFilter).'&jaar='.$filterJaar;
    $modalData[$ry] = ['locs'=>$locs,'klanten'=>$klRows,'fotos'=>$fotoRij,'loodswb'=>$loodswbUrl,'print'=>$printUrl2];
}
?>
<script>var modalDataRayon=<?=json_encode($modalData, JSON_UNESCAPED_UNICODE)?>;
<?php
// Locaties per rayon voor de JS-teller
$rayonLocsJs = [];
foreach (array_keys($alleRayons) as $ry) {
    $rayonLocsJs[$ry] = isset($locPerRayon[$ry]) ? $locPerRayon[$ry] : 0;
}
// Huidige batch-selectie voor initiële teller — gedrukte bits uitsluiten
$batchSelJs = [];
foreach ($rayonBatchSelected as $ry => $bits) {
    $gedBits = isset($rayonBatchGedrukt[$ry]) ? $rayonBatchGedrukt[$ry] : 0;
    $activeBits = $bits & ~$gedBits;
    if ($activeBits) $batchSelJs[$ry] = $activeBits;
}
?>
var rayonLocaties=<?=json_encode($rayonLocsJs)?>;
var batchSelState=<?=json_encode($batchSelJs)?>;
</script>

<!-- Batch teller (zwevend) -->
<div id="batchTellerWrap" style="position:fixed;bottom:20px;right:20px;z-index:8000;background:#1e3a5f;border-radius:14px;padding:14px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;box-shadow:0 6px 24px rgba(0,0,0,.25);max-width:520px">
    <div>
        <div style="font-size:11px;font-weight:600;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Geselecteerde doosjes</div>
        <div style="display:flex;align-items:baseline;gap:8px">
            <span id="batchTellerDoosjes" style="font-size:36px;font-weight:900;color:#fff;font-variant-numeric:tabular-nums">0</span>
            <span style="font-size:14px;color:rgba(255,255,255,.55)">doosjes &mdash; <span id="batchTellerRayons">0</span> rayons</span>
        </div>
    </div>
    <div id="batchPerNr" style="display:flex;gap:8px;flex-wrap:wrap"></div>
</div>

<!-- Batch print knoppen - altijd zichtbaar, dynamisch via JS -->
<?php
    $totaalGeselecteerd = array_sum($batchAantallen);
    $totaalBevestigd = 0;
    $bevestigdeLijst = [];
    foreach ($rayonBatchGedrukt as $ryG => $gBits) {
        if ($gBits > 0) {
            $lB = isset($locPerRayon[$ryG]) ? $locPerRayon[$ryG] : 0;
            for ($bn=1;$bn<=5;$bn++) {
                if (($gBits>>($bn-1))&1) {
                    $bevestigdeLijst[] = ['rayon'=>$ryG,'locs'=>$lB,'batch_nr'=>$bn];
                    $totaalBevestigd++;
                }
            }
        }
    }
    usort($bevestigdeLijst, function($a,$b){
        $c=strnatcmp($a['rayon'],$b['rayon']); return $c!==0?$c:$a['batch_nr']-$b['batch_nr'];
    });
    $batchPrintUrl = 'pakketten.php?batch_print=1&szf='.urlencode($seizoenFilter ?: $activeTab).'&jaar='.$filterJaar;
    $batchResetUrl = 'pakketten.php?batch_reset_all=1&szf='.urlencode($seizoenFilter ?: $activeTab).'&jaar='.$filterJaar;
?>
<script>
var bevestigdRayons=<?=json_encode($bevestigdeLijst,JSON_UNESCAPED_UNICODE)?>;
var batchPrintUrl='<?=addslashes($batchPrintUrl)?>';
var batchResetUrl='<?=addslashes($batchResetUrl)?>';
var szFilter='<?=htmlspecialchars(addslashes($seizoenFilter ?: $activeTab))?>';
var jrFilter=<?=$filterJaar?>;
var initTotaalBevestigd=<?=$totaalBevestigd?>;
</script>
<div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:12px 20px;margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap" id="batchBar">
    <span style="font-size:13px;font-weight:700;color:#1e3a5f">🖨️ Batch:</span>
    <span id="batchBarActies">
        <?php if($totaalGeselecteerd > 0): ?>
        <a href="<?=htmlspecialchars($batchPrintUrl)?>" target="_blank"
           style="background:#1e3a5f;color:#fff;padding:8px 18px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:8px">
            Alles printen
            <span style="background:rgba(255,255,255,.25);padding:2px 8px;border-radius:10px;font-size:11px"><?=$totaalGeselecteerd?> rayons</span>
        </a>
        <button onclick="bevestigBatch()" style="background:#059669;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;border:none;cursor:pointer;margin-left:8px">✅ Bevestig batch</button>
        <?php elseif($totaalBevestigd > 0): ?>
        <button id="btnBevestigdDetails" style="background:#d1fae5;color:#065f46;padding:6px 14px;border-radius:8px;font-size:13px;font-weight:700;border:1px solid #6ee7b7;cursor:pointer">✅ <?=$totaalBevestigd?> bevestigde runs — klik voor details</button>
        <button id="btnNieuwBatch" style="background:#2563eb;color:#fff;padding:6px 14px;border-radius:8px;font-size:13px;font-weight:700;border:none;cursor:pointer;margin-left:4px">➕ Nieuwe batch</button>
        <?php else: ?>
        <span style="font-size:12px;color:#64748b">Selecteer rayons via de Batch-rij op de kaartjes</span>
        <?php endif; ?>
    </span>
    <a href="<?=htmlspecialchars($batchResetUrl)?>"
       onclick="return confirm('Alle batch-vinkjes resetten?')"
       style="background:#dc2626;color:#fff;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;margin-left:auto;white-space:nowrap">
        ↺ Reset alle vinkjes
    </a>
</div>

<!-- Bevestigd rayons modal -->
<div id="bevestigdModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9100;align-items:center;justify-content:center;padding:20px">
<div style="background:#fff;border-radius:16px;max-width:560px;width:100%;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="padding:14px 20px;background:#059669;color:#fff;border-radius:16px 16px 0 0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
        <div style="font-size:16px;font-weight:800">✅ Bevestigde batch-rayons</div>
        <button onclick="document.getElementById('bevestigdModal').style.display='none';document.body.style.overflow=''"
                style="background:rgba(255,255,255,.2);border:none;color:#fff;width:30px;height:30px;border-radius:6px;font-size:18px;cursor:pointer">×</button>
    </div>
    <div style="overflow-y:auto;flex:1;padding:0">
        <table style="width:100%;border-collapse:collapse" id="bevestigdTbl"></table>
    </div>
    <div style="padding:12px 20px;border-top:1px solid #e2e8f0;display:flex;gap:10px;flex-shrink:0">
        <button onclick="resetAllesBevestigd()"
                style="background:#dc2626;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer">
            ↺ Reset alles
        </button>
        <button onclick="document.getElementById('bevestigdModal').style.display='none';document.body.style.overflow=''"
                style="background:#e2e8f0;color:#374151;border:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">
            Sluiten
        </button>
    </div>
</div>
</div>

<!-- Modal overlay -->
<div id="rayonModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;padding:20px">
<div style="background:#fff;border-radius:16px;max-width:780px;width:100%;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div id="modalHdr" style="padding:16px 20px;background:#1e3a5f;color:#fff;border-radius:16px 16px 0 0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
        <div>
            <div id="modalTitel" style="font-size:20px;font-weight:800"></div>
            <div id="modalSub" style="font-size:12px;opacity:.8;margin-top:2px"></div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a id="modalLoodswb" href="#" target="_blank" style="background:#fff;color:#1e3a5f;text-decoration:none;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:700">📋 Loods WB</a>
            <a id="modalPrint" href="#" target="_blank" style="background:#e0e7ff;color:#1e3a5f;text-decoration:none;padding:6px 12px;border-radius:8px;font-size:12px;font-weight:700">🖨️ A4</a>
            <button onclick="sluitModal()" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:32px;height:32px;border-radius:8px;font-size:18px;cursor:pointer;line-height:1">×</button>
        </div>
    </div>
    <div id="modalBody" style="overflow-y:auto;flex:1;padding:0"></div>
</div>
</div>

<!-- Rayon kaarten grid -->
<?php foreach ($rayonGroepen as $rgNaam => $rgRayons): if(empty($rgRayons)) continue;
    $rgKleur = $rayonGroepKleuren[$rgNaam]; ?>
<div style="margin-bottom:24px">
<div style="padding:6px 14px;background:<?=$rgKleur?>;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;border-radius:8px;display:inline-block;margin-bottom:10px"><?=$rayonGroepLabels[$rgNaam]?></div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px">
<?php foreach ($rgRayons as $ry):
    $nKlanten = count($alleRayons[$ry] ?? []);
    $nOpVoorraad = 0;
    foreach (($alleRayons[$ry] ?? []) as $kd2) {
        $vrK3 = $kd2['klantnaam'].'|'.$kd2['seizoen'];
        if (isset($voorraadStatus[$vrK3]) && $voorraadStatus[$vrK3]) $nOpVoorraad++;
    }
    $nKlaar2 = 0;
    $rySafe3 = safe($ry); $szSafe2 = safe($seizoenFilter ?: $activeTab);
    $resNK2 = func_dbsi_qry("SELECT COUNT(DISTINCT wb.klantnaam) as n FROM magazijn_rayon_status rs JOIN magazijn_werkbonnen wb ON wb.werkbon_code=rs.werkbon_code WHERE rs.rayon='$rySafe3' AND rs.klaar=1 AND wb.jaar=$filterJaar AND wb.seizoen='$szSafe2'");
    if ($resNK2 && $rNK2=$resNK2->fetch_assoc()) $nKlaar2=intval($rNK2['n']);
    $nNietVrd   = $nKlanten - $nOpVoorraad;
    $allesKlaar2= ($nKlanten > 0 && $nKlaar2 === $nKlanten);
    $vollKlaar2 = ($nKlanten > 0 && $nNietVrd === 0 && $allesKlaar2);
    $locs2      = isset($locPerRayon[$ry]) ? $locPerRayon[$ry] : 0;
    $ryVBits    = isset($rayonVersies[$ry])       ? $rayonVersies[$ry]       : 0;
    $ryTBits    = isset($rayonTransport[$ry])     ? $rayonTransport[$ry]     : 0;
    $ryBSel     = isset($rayonBatchSelected[$ry]) ? $rayonBatchSelected[$ry] : 0;
    $ryBGed     = isset($rayonBatchGedrukt[$ry])  ? $rayonBatchGedrukt[$ry]  : 0;
    $ryEscQ2    = htmlspecialchars($ry, ENT_QUOTES);
    $szEscQ2    = htmlspecialchars($seizoenFilter ?: $activeTab, ENT_QUOTES);
    $printUrl3  = 'pakketten.php?rayon_print='.urlencode($ry).'&szf='.urlencode($seizoenFilter ?: $activeTab).'&jaar='.$filterJaar;
    
    // Aantal foto's voor dit rayon tellen
    $fotoCount2 = 0;
    $resFotoCount = func_dbsi_qry("SELECT COUNT(*) as n FROM magazijn_rayon_transport_fotos WHERE rayon='".safe($ry)."' AND seizoen='".safe($seizoenFilter ?: $activeTab)."' AND jaar=$filterJaar");
    if ($resFotoCount && $fotoRow = $resFotoCount->fetch_assoc()) {
        $fotoCount2 = intval($fotoRow['n']);
    }
    
    // Kaart achtergrondkleur
    $cardBg = $vollKlaar2 ? '#d1fae5' : ($allesKlaar2 ? '#f0fdf4' : '#fff');
    $cardBorder = $vollKlaar2 ? '#6ee7b7' : ($allesKlaar2 ? '#a7f3d0' : '#e2e8f0');
?>
<div style="background:<?=$cardBg?>;border:2px solid <?=$cardBorder?>;border-radius:12px;padding:12px;cursor:default;transition:.15s" onmouseenter="this.style.boxShadow='0 4px 16px rgba(0,0,0,.12)'" onmouseleave="this.style.boxShadow=''">
    <!-- Koptekst: naam + locaties + klik voor modal -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;cursor:pointer" onclick="openModal('<?=$ryEscQ2?>')">
        <div>
            <div style="font-size:18px;font-weight:800;color:<?=$vollKlaar2?'#059669':'#1e3a5f'?>"><?=$vollKlaar2?'✅ ':''?><?=htmlspecialchars($ry)?></div>
            <div style="font-size:11px;color:#64748b;margin-top:1px">📦 <?=$locs2?> locaties</div>
        </div>
        <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-end">
            <?php if($nNietVrd>0):?><span style="background:#fee2e2;color:#dc2626;font-size:10px;padding:1px 6px;border-radius:8px;font-weight:700"><?=$nNietVrd?> mist</span><?php endif;?>
            <?php if(!$allesKlaar2 && $nKlaar2>0):?><span style="background:#fef3c7;color:#92400e;font-size:10px;padding:1px 6px;border-radius:8px;font-weight:700"><?=($nKlanten-$nKlaar2)?> open</span><?php endif;?>
            <span style="background:<?=$vollKlaar2?'#059669':'#e2e8f0'?>;color:<?=$vollKlaar2?'#fff':'#64748b'?>;font-size:10px;padding:1px 6px;border-radius:8px;font-weight:700"><?=$nKlanten?> klanten</span>
            <?php if($fotoCount2>0):?><span style="background:#dbeafe;color:#1e40af;font-size:10px;padding:1px 6px;border-radius:8px;font-weight:700">📸 <?=$fotoCount2?></span><?php endif;?>
        </div>
    </div>
    <!-- Versie vinkjes (5 checkboxen, vergrendeld als batch bevestigd) -->
    <div style="display:flex;align-items:center;gap:4px;margin-bottom:4px" onclick="event.stopPropagation()">
        <span style="font-size:9px;color:#94a3b8;font-weight:600;flex-shrink:0;width:36px">Versies</span>
        <?php for($vn=1;$vn<=5;$vn++):
            $vC    = ($ryVBits>>($vn-1))&1;
            $vLock = $vC && ($ryBGed > 0); // vergrendeld als aangevinkt én batch bevestigd
            $vId2  = 'rv-'.preg_replace('/[^a-z0-9]/i','_',$ry).'-'.$vn;
        ?>
        <label style="display:flex;flex-direction:column;align-items:center;cursor:<?=$vLock?'default':'pointer'?>;gap:1px" title="Versie <?=$vn?><?=$vLock?' (vergrendeld)':''?>">
            <input type="checkbox" id="<?=$vId2?>" <?=$vC?'checked':''?> <?=$vLock?'disabled':''?>
                <?php if(!$vLock):?>onchange="togRayonVersie(this,'<?=$ryEscQ2?>','<?=$szEscQ2?>',<?=$filterJaar?>,<?=$vn?>)"<?php endif;?>
                style="width:15px;height:15px;cursor:<?=$vLock?'default':'pointer'?>;accent-color:<?=$vLock?'#059669':'#2563eb'?>">
            <span style="font-size:8px;color:<?=$vLock?'#059669':'#94a3b8'?>"><?=$vn?></span>
        </label>
        <?php endfor;?>
    </div>
    <!-- Transport vinkjes + print -->
    <div style="display:flex;gap:3px;align-items:center;justify-content:space-between;margin-bottom:6px" onclick="event.stopPropagation()">
        <div style="display:flex;gap:3px;align-items:center">
            <span style="font-size:9px;color:#059669;font-weight:600;width:40px;flex-shrink:0">🚚 Trans</span>
            <?php for($tn=1;$tn<=5;$tn++):
                $tC=($ryTBits>>($tn-1))&1;
            ?>
            <div style="display:flex;flex-direction:column;align-items:center;gap:1px" title="Transport <?=$tn?> <?=$tC?'✓':'(scan QR)'?>">
                <div class="trans-box" style="width:15px;height:15px;border:2px solid <?=$tC?'#059669':'#cbd5e1'?>;border-radius:3px;background:<?=$tC?'#059669':'#f8fafc'?>;display:flex;align-items:center;justify-content:center;font-size:9px;color:#fff"><?=$tC?'✓':''?></div>
                <span style="font-size:8px;color:#94a3b8"><?=$tn?></span>
            </div>
            <?php endfor;?>
        </div>
        <div style="display:flex;gap:4px;align-items:center">
            <button onclick="resetTransport(this,'<?=htmlspecialchars($ry,ENT_QUOTES)?>','<?=htmlspecialchars($seizoenFilter ?: $activeTab,ENT_QUOTES)?>')"
                    style="font-size:9px;color:#059669;background:#d1fae5;border:none;border-radius:4px;padding:2px 5px;cursor:pointer;font-weight:700"
                    title="Reset transport vinkjes voor <?=htmlspecialchars($ry)?>">↺</button>
            <a href="<?=htmlspecialchars($printUrl3)?>" target="_blank"
               style="font-size:9px;color:#1e3a5f;background:#e0e7ff;border-radius:4px;padding:2px 7px;text-decoration:none;white-space:nowrap;font-weight:700">🖨️ A4</a>
        </div>
    </div>
    <!-- Batch vinkjes (rij 3) -->
    <div style="display:flex;gap:3px;align-items:center;border-top:1px solid #f1f5f9;padding-top:6px" onclick="event.stopPropagation()">
        <span style="font-size:9px;color:#7c3aed;font-weight:600;width:40px;flex-shrink:0">📦 Batch</span>
        <?php for($bn=1;$bn<=5;$bn++):
            $bSel = ($ryBSel>>($bn-1))&1;
            $bGed = ($ryBGed>>($bn-1))&1;
            if ($bGed) {
                $bBg='#059669'; $bBorder='#059669'; $bColor='#fff'; $bText='&#x2713;';
            } elseif ($bSel) {
                $bBg='#2563eb'; $bBorder='#2563eb'; $bColor='#fff'; $bText=$bn;
            } else {
                $bBg='#f8fafc'; $bBorder='#cbd5e1'; $bColor='#94a3b8'; $bText=$bn;
            }
        ?>
        <div style="display:flex;flex-direction:column;align-items:center;gap:1px"
             <?=$bGed?'':'onclick="togBatch(this,\''.htmlspecialchars($ry,ENT_QUOTES).'\',\''.htmlspecialchars($seizoenFilter ?: $activeTab,ENT_QUOTES).'\','.$filterJaar.','.$bn.')"'?>
             style="cursor:<?=$bGed?'default':'pointer'?>">
            <div id="batch-<?=preg_replace('/[^a-z0-9]/i','_',$ry)?>-<?=$bn?>"
                 data-selected="<?=$bSel&&!$bGed?'1':'0'?>"
                 style="width:18px;height:18px;border:2px solid <?=$bBorder?>;border-radius:4px;background:<?=$bBg?>;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:<?=$bColor?>;transition:.15s">
                <?=$bText?>
            </div>
        </div>
        <?php endfor;?>
        <button onclick="resetBatch(this,'<?=htmlspecialchars($ry,ENT_QUOTES)?>','<?=htmlspecialchars($seizoenFilter ?: $activeTab,ENT_QUOTES)?>')"
                style="margin-left:auto;font-size:9px;color:#dc2626;background:#fee2e2;border:none;border-radius:4px;padding:2px 6px;cursor:pointer;font-weight:700"
                title="Reset batch vinkjes voor <?=htmlspecialchars($ry)?>">↺ Reset</button>
    </div>
</div>
<?php endforeach;?>
</div>
</div>
<?php endforeach;?>

</div><!-- panel-rayons -->

<script>
function openModal(ry){
    var d=modalDataRayon[ry];if(!d)return;
    document.getElementById('modalTitel').textContent='Rayon '+ry;
    document.getElementById('modalSub').textContent=d.locs+' locaties \u2014 '+d.klanten.length+' klanten';
    document.getElementById('modalLoodswb').href=d.loodswb;
    document.getElementById('modalPrint').href=d.print;
    
    var bodyHtml='';
    
    // Foto-gallerij toevoegen als er foto's zijn
    if(d.fotos && d.fotos.length>0){
        bodyHtml+='<div style="padding:12px;background:#f8fafc;margin-bottom:12px;border-radius:10px;border-left:4px solid #2563eb">';
        bodyHtml+='<div style="font-size:13px;font-weight:700;color:#1e3a5f;margin-bottom:8px">📸 Transport Foto\'s ('+d.fotos.length+')</div>';
        bodyHtml+='<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:8px;max-height:240px;overflow-y:auto;padding-right:4px">';
        d.fotos.forEach(function(f){
            var label='Transport #'+(f.transport_nr||'?');
            var imgHtml='<img src="'+f.path+'" style="width:100%;height:80px;object-fit:cover;border-radius:8px;cursor:pointer;border:2px solid #e2e8f0;transition:.2s" onclick="openFotoPreview_Modal(\''+f.path.replace(/'/g,"\\'")+'\',' + (f.transport_nr ? 'Transport #' + f.transport_nr : 'Foto') + ')" title="'+label+'">';
            bodyHtml+=imgHtml;
        });
        bodyHtml+='</div></div>';
    }
    
    var statusHtml={klaar:'<span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">✅ Klaar</span>',bezig:'<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">🔧 Bezig</span>',nieuw:'<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">⏳ Te doen</span>'};
    var rows='';
    d.klanten.forEach(function(k){
        var st=k.klaar?statusHtml.klaar:(statusHtml[k.status]||statusHtml.nieuw);
        var vr=k.voorraad?'<span style="color:#059669;font-weight:600;font-size:11px">✅ Op voorraad</span>':'<span style="color:#94a3b8;font-size:11px">Niet op voorraad</span>';
        var acties='<a href="pakketten.php?print='+encodeURIComponent(k.wb)+'&regio='+encodeURIComponent(k.regio)+'" target="_blank" style="background:#e0e7ff;color:#3730a3;padding:3px 8px;border-radius:5px;text-decoration:none;font-size:11px;font-weight:700;margin-right:4px">🖨️</a>'
                  +'<a href="pakketten.php?wb='+encodeURIComponent(k.wb)+'&regio='+encodeURIComponent(k.regio)+'" target="_blank" style="background:#dbeafe;color:#1e40af;padding:3px 8px;border-radius:5px;text-decoration:none;font-size:11px;font-weight:700">📱</a>';
        rows+='<tr style="border-bottom:1px solid #f1f5f9;'+(k.klaar?'background:#f0fdf4;':'')+'"><td style="padding:9px 14px;font-weight:600;font-size:13px">'+k.naam+(k.medewerker?'<br><small style="color:#94a3b8;font-weight:400">'+k.medewerker+'</small>':'')+'</td><td style="padding:9px 14px;text-align:center">'+st+'</td><td style="padding:9px 14px;text-align:center">'+vr+'</td><td style="padding:9px 14px;text-align:center;white-space:nowrap">'+acties+'</td></tr>';
    });
    if(!rows) rows='<tr><td colspan="4" style="padding:20px;text-align:center;color:#94a3b8">Geen klanten</td></tr>';
    bodyHtml+='<table style="width:100%;border-collapse:collapse"><thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0"><th style="padding:8px 14px;text-align:left;font-size:11px;color:#64748b">Klant</th><th style="padding:8px 14px;font-size:11px;color:#64748b;text-align:center">Status</th><th style="padding:8px 14px;font-size:11px;color:#64748b;text-align:center">Voorraad</th><th style="padding:8px 14px;font-size:11px;color:#64748b;text-align:center">Acties</th></tr></thead><tbody>'+rows+'</tbody></table>';
    document.getElementById('modalBody').innerHTML=bodyHtml;
    var m=document.getElementById('rayonModal');
    m.style.display='flex';
    document.body.style.overflow='hidden';
}

function openFotoPreview_Modal(url, label){
    var modal=document.createElement('div');
    modal.style.cssText='position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.92);display:flex;align-items:center;justify-content:center;z-index:9999;cursor:pointer;padding:20px';
    var img=document.createElement('img');
    img.src=url;
    img.style.cssText='max-width:90%;max-height:90%;object-fit:contain;border-radius:12px';
    var lbl=document.createElement('div');
    lbl.style.cssText='position:absolute;bottom:20px;left:20px;right:20px;color:#fff;font-size:13px;font-weight:700;text-align:center;background:rgba(0,0,0,.6);padding:8px 12px;border-radius:8px;max-width:300px';
    lbl.textContent=label||'Foto';
    var closeBtn=document.createElement('button');
    closeBtn.innerHTML='×';
    closeBtn.style.cssText='position:absolute;top:20px;right:20px;background:rgba(255,255,255,.2);border:none;color:#fff;width:40px;height:40px;border-radius:8px;font-size:24px;cursor:pointer;line-height:1';
    closeBtn.onclick=function(e){e.stopPropagation();document.body.removeChild(modal);};
    modal.appendChild(img);
    modal.appendChild(lbl);
    modal.appendChild(closeBtn);
    modal.onclick=function(){document.body.removeChild(modal);};
    document.body.appendChild(modal);
}
function sluitModal(){
    document.getElementById('rayonModal').style.display='none';
    document.body.style.overflow='';
}
document.getElementById('rayonModal').addEventListener('click',function(e){if(e.target===this)sluitModal();});
document.addEventListener('keydown',function(e){if(e.key==='Escape')sluitModal();});
</script>

<div class="chart-wrap"><h3>&#x1F4CA; Rayons per medewerker &mdash; 30 dagen</h3><canvas id="mgChart" height="80"></canvas></div>
<div class="wk-card"><h3>&#x1F4C5; Weekrapport</h3>
<div class="wk-bar"><select id="wkSel" onchange="toonWeek(this.value)"><option>Laden...</option></select><button onclick="dlWeek()">&#x2B07;&#xFE0F; Download PDF</button></div>
<div id="wkWrap"></div></div>

</div><!-- .mgc -->

<script>
var weekData={},weekMw=[],grafiekData={},grafiekDagen=[];

// Laad weekdata async
fetch('pakketten.php?action=weekdata').then(r=>r.json()).then(function(d){
    weekData=d.weeks||{};weekMw=d.medewerkers||[];grafiekData=d.grafiek||{};grafiekDagen=d.grafiekDagen||[];
    var sel=document.getElementById('wkSel');if(sel){sel.innerHTML='';
    var wk=Object.keys(weekData);wk.sort().reverse();
    if(!wk.length)wk=[d.huidig||''];
    wk.forEach(function(w){var o=document.createElement('option');o.value=w;o.textContent=w;sel.appendChild(o);});
    // Gebruik de eerste week MET data (wk is al gesorteerd hoog->laag)
    var eersteMetData = wk[0] || '';
    sel.value = eersteMetData;
    toonWeek(eersteMetData);}
    initChart();
}).catch(function(){});

function getMw(){return document.getElementById('mgMw').value}
function getDt(){return document.getElementById('mgDt').value}
function chkMw(){if(!getMw()){alert('Selecteer een medewerker!');document.getElementById('mgMw').focus();return false}return true}
function fmtDt(d){if(!d)return'';var p=d.split('-');return p[2]+'-'+p[1]+'-'+p[0]}

var doosjesPerSz = <?=json_encode($doosjesPerSz)?>;

// Init KPI voor actief seizoen
(function(){
    var sz='<?=$activeTab?>';
    var d=doosjesPerSz[sz]||{klaar:0,totaal:0};
    var pct=d.totaal>0?Math.round(d.klaar/d.totaal*1000)/10:0;
    var et=document.getElementById('kpi-stap-totaal');
    var ek=document.getElementById('kpi-stap-klaar');
    var fill=document.getElementById('prg-fill');
    var txt=document.getElementById('prg-txt');
    if(et)et.textContent=d.totaal.toLocaleString('nl-NL');
    if(ek)ek.textContent=d.klaar.toLocaleString('nl-NL');
    if(fill)fill.style.width=Math.min(pct,100)+'%';
    if(txt)txt.textContent=pct+'% stapeltjes klaar ('+d.klaar.toLocaleString('nl-NL')+'/'+d.totaal.toLocaleString('nl-NL')+')';
})();

function togVoorraad(cb, kn, sz, jr){
    var fd=new FormData();
    fd.append('action','toggle_voorraad');
    fd.append('klantnaam',kn);
    fd.append('seizoen',sz);
    fd.append('jaar',jr);
    fd.append('waarde',cb.checked?1:0);
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
        if(!d.success){cb.checked=!cb.checked;return;}
        // Zoek de bijbehorende rij via de checkbox positie
        var row=cb.closest('tr');
        if(row&&row.id&&row.id.startsWith('row-')){
            row.dataset.voorraad=cb.checked?'1':'0';
            if(document.getElementById('filterOpenstaand').checked){
                var hide=row.dataset.klaar==='1'||row.dataset.indruk==='1'||cb.checked;
                var uid=row.id.replace('row-','');
                var rrow=document.getElementById('rayons-'+uid);
                row.classList.toggle('sh',hide);
                if(rrow) rrow.classList.toggle('sh',hide);
            }
        }
        // Update label text if present (rayon-panel)
        var lbl=cb.closest('label');
        if(lbl){
            lbl.style.color=cb.checked?'#059669':'#94a3b8';
            var txt=lbl.childNodes[lbl.childNodes.length-1];
            if(txt && txt.nodeType===3) txt.textContent=cb.checked?' Op voorraad':' Niet op voorraad';
        }
    }).catch(function(){cb.checked=!cb.checked;});
}
function switchToRayons(){
    document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.panel').forEach(p=>{p.style.display='none';p.classList.remove('active');});
    document.getElementById('tab-rayons').classList.add('active');
    document.getElementById('panel-rayons').style.display='block';
    history.replaceState(null,'','pakketten.php?jaar=<?=$filterJaar?>&view=rayons');
}
function kiesRayon(ry){
    location.href='pakketten.php?jaar=<?=$filterJaar?>&view=rayons&rayon='+encodeURIComponent(ry);
}
function filterRayons(q){
    q=q.toLowerCase();
    document.querySelectorAll('.rayon-item').forEach(function(el){
        var txt=el.querySelector('span').textContent.toLowerCase();
        el.style.display=txt.includes(q)?'':'none';
    });
}
function switchTab(sz){
    document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
    document.getElementById('tab-'+sz).classList.add('active');
    document.getElementById('panel-'+sz).classList.add('active');
    history.replaceState(null,'','pakketten.php?jaar=<?=$filterJaar?>&tab='+sz);
    // Update KPI's voor dit seizoen
    var d = doosjesPerSz[sz] || {klaar:0,totaal:0};
    var pct = d.totaal > 0 ? Math.round(d.klaar/d.totaal*1000)/10 : 0;
    document.getElementById('kpi-stap-totaal').textContent = d.totaal.toLocaleString('nl-NL');
    document.getElementById('kpi-stap-klaar').textContent = d.klaar.toLocaleString('nl-NL');
    var fill = document.getElementById('prg-fill');
    var txt = document.getElementById('prg-txt');
    if(fill) fill.style.width = Math.min(pct,100)+'%';
    if(txt) txt.textContent = pct+'% stapeltjes klaar ('+d.klaar.toLocaleString('nl-NL')+'/'+d.totaal.toLocaleString('nl-NL')+')';
}

function togRayons(uid){
    var row=document.getElementById('rayons-'+uid);
    if(row)row.classList.toggle('open');
    var klant=document.querySelector('[onclick*="togRayons(\''+uid+'\')"]');
    if(klant)klant.classList.toggle('open');
}

function togRayon(cb){
    if(!chkMw()){cb.checked=!cb.checked;return}
    var code=cb.dataset.code,uid=cb.dataset.uid||code,rayon=cb.dataset.rayon,idx=cb.dataset.rayonIdx||'0',vw=cb.checked?1:0;
    var fd=new FormData();fd.append('action','toggle_rayon');fd.append('code',code);fd.append('rayon',rayon);fd.append('rayon_idx',idx);fd.append('medewerker',getMw());fd.append('datum',getDt());fd.append('verwerkt',vw);
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.success){
            var item=document.getElementById('ri-'+uid+'-'+rayon+'-'+idx);if(item){if(vw)item.classList.add('done');else item.classList.remove('done');}
            var rp=document.getElementById('rp-'+uid);if(rp)rp.textContent='('+d.klaar+'/'+d.totaal+')';
            var dj=document.getElementById('dj-'+uid);if(dj)dj.textContent=d.doosjes_klaar+'/'+d.doosjes;
            var row=document.getElementById('row-'+uid);if(row){var mc=row.querySelector('.chk');if(d.alle_klaar){row.classList.add('done');if(mc)mc.checked=true}else{row.classList.remove('done');if(mc)mc.checked=false}}
        }else{cb.checked=!cb.checked;alert(d.error||'Fout')}
    });
}

function togAlRayons(code,uid,aan){
    if(!chkMw())return;
    var items=document.querySelectorAll('#rayons-'+uid+' .chk');
    var promises=[];
    items.forEach(function(cb){
        if(cb.checked!==aan){cb.checked=aan;
            var item=document.getElementById('ri-'+uid+'-'+cb.dataset.rayon+'-'+(cb.dataset.rayonIdx||'0'));
            if(item){if(aan)item.classList.add('done');else item.classList.remove('done');}
            var fd=new FormData();fd.append('action','toggle_rayon');fd.append('code',code);fd.append('rayon',cb.dataset.rayon);fd.append('rayon_idx',cb.dataset.rayonIdx||'0');fd.append('medewerker',getMw());fd.append('datum',getDt());fd.append('verwerkt',aan?1:0);
            promises.push(fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()));
        }
    });
    Promise.all(promises).then(function(results){
        var last=results[results.length-1];if(!last)return;
        var rp=document.getElementById('rp-'+uid);if(rp)rp.textContent='('+last.klaar+'/'+last.totaal+')';
        var dj=document.getElementById('dj-'+uid);if(dj)dj.textContent=last.doosjes_klaar+'/'+last.doosjes;
        var row=document.getElementById('row-'+uid);if(row){var mc=row.querySelector('.chk');if(last.alle_klaar){row.classList.add('done');if(mc)mc.checked=true}else{row.classList.remove('done');if(mc)mc.checked=false}}
    });
}

function togWb(cb){
    if(!chkMw()){cb.checked=!cb.checked;return}
    var code=cb.dataset.code,uid=cb.dataset.uid||code,vw=cb.checked?1:0;
    var fd=new FormData();fd.append('action','quick_afvinken');fd.append('code',code);fd.append('medewerker',getMw());fd.append('datum',getDt());fd.append('verwerkt',vw);
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.success){
            var row=document.getElementById('row-'+uid);if(!row)return;
            row.dataset.klaar=vw?'1':'0';
            var items=document.querySelectorAll('#rayons-'+uid+' .chk');
            if(vw){row.classList.add('done');items.forEach(function(rc){rc.checked=true;var item=document.getElementById('ri-'+uid+'-'+rc.dataset.rayon+'-'+(rc.dataset.rayonIdx||'0'));if(item)item.classList.add('done')});var rp=document.getElementById('rp-'+uid);if(rp)rp.textContent='('+items.length+'/'+items.length+')';}
            else{row.classList.remove('done');items.forEach(function(rc){rc.checked=false;var item=document.getElementById('ri-'+uid+'-'+rc.dataset.rayon+'-'+(rc.dataset.rayonIdx||'0'));if(item)item.classList.remove('done')});var rp=document.getElementById('rp-'+uid);if(rp)rp.textContent='(0/'+items.length+')';}
            if(d.doosjes!==undefined){var dj=document.getElementById('dj-'+uid);if(dj)dj.textContent=d.doosjes_klaar+'/'+d.doosjes;}
        }else{cb.checked=!cb.checked;alert(d.error||'Fout')}
    });
}

function zoekKlant(q){
    q=q.toLowerCase().trim();
    document.querySelectorAll('tr[id^="row-"]').forEach(function(row){
        var kl=row.querySelector('.klant');var nm=kl?kl.textContent.toLowerCase():'';
        var uid=row.id.replace('row-','');var rrow=document.getElementById('rayons-'+uid);
        var show=!q||nm.indexOf(q)!==-1;
        if(show){row.classList.remove('sh')}else{row.classList.add('sh')}
        if(rrow){if(!show)rrow.classList.add('sh');else rrow.classList.remove('sh')}
    });
}

function togInDruk(cb, kn, sz, jr, uid){
    var fd=new FormData();
    fd.append('action','toggle_in_druk');
    fd.append('klantnaam',kn);
    fd.append('seizoen',sz);
    fd.append('jaar',jr);
    fd.append('waarde',cb.checked?1:0);
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
        if(d.success){
            var row=document.getElementById('row-'+uid);
            if(row) row.dataset.indruk=cb.checked?'1':'0';
            // Filter hertoepassen als actief
            if(document.getElementById('filterOpenstaand').checked){
                var klaar=row&&row.dataset.klaar==='1';
                var hide=klaar||cb.checked;
                var rrow=document.getElementById('rayons-'+uid);
                if(row) row.classList.toggle('sh',hide);
                if(rrow) rrow.classList.toggle('sh',hide);
            }
        } else { cb.checked=!cb.checked; }
    }).catch(function(){cb.checked=!cb.checked;});
}

function togWordtGemaakt(cb, kn, sz, jr, uid){
    var fd=new FormData();
    fd.append('action','toggle_wordt_gemaakt');
    fd.append('klantnaam',kn);
    fd.append('seizoen',sz);
    fd.append('jaar',jr);
    fd.append('waarde',cb.checked?1:0);
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
        if(d.success){
            var row=document.getElementById('row-'+uid);
            if(row) row.dataset.wordtgemaakt=cb.checked?'1':'0';
            if(document.getElementById('filterOpenstaand').checked){
                var klaar=row&&row.dataset.klaar==='1';
                var hide=klaar||cb.checked;
                var rrow=document.getElementById('rayons-'+uid);
                if(row) row.classList.toggle('sh',hide);
                if(rrow) rrow.classList.toggle('sh',hide);
            }
        } else { cb.checked=!cb.checked; }
    }).catch(function(){cb.checked=!cb.checked;});
}

function togFilterOpenstaand(cb){
    document.querySelectorAll('tr[id^="row-"]').forEach(function(row){
        var uid=row.id.replace('row-','');
        var rrow=document.getElementById('rayons-'+uid);
        if(cb.checked){
            var hide=row.dataset.klaar==='1'||row.dataset.indruk==='1'||row.dataset.voorraad==='1'||row.dataset.wordtgemaakt==='1';
            row.classList.toggle('sh',hide);
            if(rrow) rrow.classList.toggle('sh',hide);
        } else {
            row.classList.remove('sh');
            if(rrow) rrow.classList.remove('sh');
        }
    });
}

function togRayonVersie(cb, ry, sz, jr, vn){
    var fd=new FormData();
    fd.append('action','toggle_rayon_versie');
    fd.append('rayon',ry);
    fd.append('seizoen',sz);
    fd.append('jaar',jr);
    fd.append('versie_nr',vn);
    fd.append('waarde',cb.checked?1:0);
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
        if(!d.success){ cb.checked=!cb.checked; }
    }).catch(function(){ cb.checked=!cb.checked; });
}

function togBatch(el, ry, sz, jr, bn){
    var id='batch-'+ry.replace(/[^a-z0-9]/gi,'_')+'-'+bn;
    var box=document.getElementById(id);if(!box)return;
    var isSelected=box.dataset.selected==='1';
    var waarde=isSelected?0:1;
    var fd=new FormData();
    fd.append('action','toggle_rayon_batch');
    fd.append('rayon',ry);fd.append('seizoen',sz);fd.append('jaar',jr);
    fd.append('batch_nr',bn);fd.append('waarde',waarde);
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
        if(d.success){
            box.dataset.selected=waarde?'1':'0';
            if(waarde){
                box.style.background='#2563eb';box.style.borderColor='#2563eb';
                box.style.color='#fff';box.textContent=bn;
                if(!batchSelState[ry])batchSelState[ry]=0;
                batchSelState[ry]|=(1<<(bn-1));
            } else {
                box.style.background='#f8fafc';box.style.borderColor='#cbd5e1';
                box.style.color='#94a3b8';box.textContent=bn;
                if(batchSelState[ry])batchSelState[ry]&=~(1<<(bn-1));
            }
            updateBatchCounter();
        }
    });
}

function bevestigBatch(){
    if(!confirm('Batch bevestigen? Alle geselecteerde vinkjes worden groen vergrendeld.'))return;
    var fd=new FormData();
    fd.append('action','bevestig_batch');
    fd.append('seizoen','<?=htmlspecialchars($seizoenFilter ?: $activeTab,ENT_QUOTES)?>');
    fd.append('jaar',<?=$filterJaar?>);
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
        if(d.success){ location.reload(); }
        else alert('Fout bij bevestigen');
    });
}

function setVersieSelect(sel, ry, sz, jr){
    var fd=new FormData();
    fd.append('action','set_versie_select');
    fd.append('rayon',ry);fd.append('seizoen',sz);fd.append('jaar',jr);
    fd.append('versie',sel.value);
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
        if(d.success){
            sel.style.color=sel.value==='0'?'#94a3b8':'#2563eb';
            // Update versie doosjes teller
            updateVersieDoosjes();
        } else { sel.value=sel.dataset.prev||'0'; }
    });
    sel.dataset.prev=sel.value;
}

function toonBevestigdModal(){
    var data=bevestigdRayons||[];
    var sz=szFilter, jr=jrFilter;
    var totalLocs=0;
    data.forEach(function(r){totalLocs+=parseInt(r.locs)||0;});
    var rows='<thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0">'
        +'<th style="padding:8px 14px;text-align:left;font-size:11px;color:#64748b">Rayon</th>'
        +'<th style="padding:8px 14px;font-size:11px;color:#64748b;text-align:center">Run</th>'
        +'<th style="padding:8px 14px;font-size:11px;color:#64748b;text-align:center">Locaties</th>'
        +'<th style="padding:8px 14px;font-size:11px;color:#64748b;text-align:center">Reset</th>'
        +'</tr></thead><tbody>';
    if(!data.length){rows+='<tr><td colspan="4" style="padding:20px;text-align:center;color:#94a3b8">Geen bevestigde rayons</td></tr>';}
    data.forEach(function(r){
        rows+='<tr style="border-bottom:1px solid #f1f5f9">'
            +'<td style="padding:8px 14px;font-weight:700;font-size:14px;color:#1e3a5f">'+r.rayon+'</td>'
            +'<td style="padding:8px 14px;text-align:center"><span style="background:#e0e7ff;color:#3730a3;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700">Run '+r.batch_nr+'</span></td>'
            +'<td style="padding:8px 14px;text-align:center;font-size:13px">📦 '+r.locs+'</td>'
            +'<td style="padding:8px 14px;text-align:center"><button onclick="resetEnkelBevestigd(this,\''+r.rayon+'\',\''+sz+'\','+jr+')" style="background:#fee2e2;color:#dc2626;border:none;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer">↺ Reset</button></td>'
            +'</tr>';
    });
    rows+='<tr style="background:#f0fdf4;border-top:2px solid #6ee7b7">'
        +'<td colspan="2" style="padding:10px 14px;font-weight:800;color:#1e3a5f">Totaal</td>'
        +'<td style="padding:10px 14px;text-align:center;font-size:15px;font-weight:900;color:#059669">📦 '+totalLocs.toLocaleString('nl-NL')+'</td>'
        +'<td></td></tr>';
    rows+='</tbody>';
    document.getElementById('bevestigdTbl').innerHTML=rows;
    var m=document.getElementById('bevestigdModal');
    m.style.display='flex';
    document.body.style.overflow='hidden';
}

function nieuwBatch(){
    if(!confirm('Nieuwe batch starten? De huidige selectie (blauwe vinkjes) wordt gewist. Groene bevestigde vinkjes blijven staan.'))return;
    var fd=new FormData();
    fd.append('action','nieuw_batch');
    fd.append('seizoen',szFilter);
    fd.append('jaar',jrFilter);
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
        if(d.success) location.reload();
        else alert('Fout');
    });
}

function resetEnkelBevestigd(btn, ry, sz, jr){
    var fd=new FormData();
    fd.append('action','reset_rayon_batch_enkel');
    fd.append('rayon',ry);fd.append('seizoen',sz);fd.append('jaar',jr);
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
        if(d.success){
            var row=btn.closest('tr');
            if(row) row.style.opacity='.3';
            btn.disabled=true;btn.textContent='✓';
            // Verwijder uit lokale data
            window.bevestigdRayons=(window.bevestigdRayons||[]).filter(function(r){return r.rayon!==ry;});
        }
    });
}

function resetAllesBevestigd(){
    if(!confirm('Alle bevestigde batch-vinkjes resetten?'))return;
    location.href='<?=htmlspecialchars($batchResetUrl??'pakketten.php?batch_reset_all=1&szf='.urlencode($seizoenFilter ?: $activeTab).'&jaar='.$filterJaar)?>';
}

function updateVersieDoosjes(){
    // Herbereken versie doosjes via page data - simpele reload of skip
}

function resetTransport(btn, ry, sz){
    if(!confirm('Transport vinkjes resetten voor rayon '+ry+'?'))return;
    var fd=new FormData();
    fd.append('action','reset_rayon_transport');
    fd.append('rayon',ry);fd.append('seizoen',sz);fd.append('jaar',<?=$filterJaar?>);
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
        if(d.success){
            // Visueel resetten: alle transport-vakjes leegmaken
            var card=btn.closest('div[style*="border-radius:12px"]');
            if(card){
                card.querySelectorAll('.trans-box').forEach(function(b){
                    b.style.background='#f8fafc';b.style.borderColor='#cbd5e1';b.textContent='';
                });
            }
        }
    });
}

function resetBatch(btn, ry, sz){
    if(!confirm('Batch-vinkjes resetten voor rayon '+ry+'?'))return;
    var fd=new FormData();
    fd.append('action','reset_rayon_batch');
    fd.append('rayon',ry);fd.append('seizoen',sz);fd.append('jaar',<?=$filterJaar?>);
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(function(d){
        if(d.success){
            batchSelState[ry]=0;
            // Reset alle batch-vakjes voor dit rayon visueel
            for(var i=1;i<=5;i++){
                var id='batch-'+ry.replace(/[^a-z0-9]/gi,'_')+'-'+i;
                var box=document.getElementById(id);
                if(box){box.style.background='#f8fafc';box.style.borderColor='#cbd5e1';box.style.color='#94a3b8';box.textContent=i;box.dataset.selected='0';box.parentElement.onclick=null;}
            }
            // Herstel onclick handlers
            var card=btn.closest('div[style*="border-radius:12px"]');
            if(card){
                card.querySelectorAll('[id^="batch-"]').forEach(function(b){
                    var parts=b.id.split('-');var bN=parseInt(parts[parts.length-1]);
                    b.parentElement.setAttribute('onclick','togBatch(this,\''+ry+'\',\''+sz+'\',<?=$filterJaar?>,'+bN+')');
                    b.parentElement.style.cursor='pointer';
                });
            }
            updateBatchCounter();
        }
    });
}

function updateBatchCounter(){
    var totalDoos=0, totalRayons=0;
    var perBatch={1:0,2:0,3:0,4:0,5:0};
    var perBatchRayons={1:0,2:0,3:0,4:0,5:0};
    var countedRayons={};
    Object.keys(batchSelState).forEach(function(ry){
        var bits=parseInt(batchSelState[ry])||0;
        if(!bits)return;
        var locs=parseInt(rayonLocaties[ry])||0;
        for(var i=1;i<=5;i++){
            if(bits&(1<<(i-1))){
                perBatch[i]+=locs;
                perBatchRayons[i]++;
                totalDoos+=locs;
            }
        }
        if(!countedRayons[ry]){countedRayons[ry]=true;totalRayons++;}
    });
    document.getElementById('batchTellerDoosjes').textContent=totalDoos.toLocaleString('nl-NL');
    document.getElementById('batchTellerRayons').textContent=totalRayons;
    var html='';
    for(var bn=1;bn<=5;bn++){
        if(perBatch[bn]>0){
            html+='<div style="background:rgba(255,255,255,.12);border-radius:8px;padding:6px 14px;text-align:center">'
                +'<div style="font-size:9px;color:rgba(255,255,255,.6);font-weight:700;text-transform:uppercase">Batch '+bn+'</div>'
                +'<div style="font-size:18px;font-weight:800;color:#fff">'+perBatch[bn].toLocaleString('nl-NL')+'</div>'
                +'<div style="font-size:9px;color:rgba(255,255,255,.45)">'+perBatchRayons[bn]+' rayons</div>'
                +'</div>';
        }
    }
    document.getElementById('batchPerNr').innerHTML=html;
    // Update batch balk dynamisch
    var balk=document.getElementById('batchBarActies');
    if(!balk)return;
    if(totalRayons>0){
        balk.innerHTML='<a href="'+batchPrintUrl+'" target="_blank" style="background:#1e3a5f;color:#fff;padding:8px 18px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:8px">Alles printen <span style=\'background:rgba(255,255,255,.25);padding:2px 8px;border-radius:10px;font-size:11px\'>'+totalRayons+' rayons</span></a>'
            +' <button onclick="bevestigBatch()" style="background:#059669;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;border:none;cursor:pointer;margin-left:8px">\u2705 Bevestig batch</button>';
    } else if(initTotaalBevestigd>0){
        balk.innerHTML='<button onclick="toonBevestigdModal()" style="background:#d1fae5;color:#065f46;padding:6px 14px;border-radius:8px;font-size:13px;font-weight:700;border:1px solid #6ee7b7;cursor:pointer">\u2705 '+initTotaalBevestigd+' rayons bevestigd \u2014 klik voor details</button>';
    } else {
        balk.innerHTML='<span style="font-size:12px;color:#94a3b8">Selecteer rayons via de Batch-rij op de kaartjes</span>';
    }
}
// Init teller bij laden
updateBatchCounter();
// Event listeners voor dynamische knoppen
document.addEventListener('DOMContentLoaded', function(){
    var b1=document.getElementById('btnBevestigdDetails');
    if(b1) b1.addEventListener('click', toonBevestigdModal);
    var b2=document.getElementById('btnNieuwBatch');
    if(b2) b2.addEventListener('click', nieuwBatch);
});
// Fallback: ook direct na script parse (voor geval DOMContentLoaded al geweest is)
(function(){
    var b1=document.getElementById('btnBevestigdDetails');
    if(b1) b1.addEventListener('click', toonBevestigdModal);
    var b2=document.getElementById('btnNieuwBatch');
    if(b2) b2.addEventListener('click', nieuwBatch);
})();

function genWb(sz){
    if(!confirm('Werkbonnen genereren?'))return;
    var items=<?=json_encode($dealItemsAll)?>;
    var sel=items.filter(function(i){return i.sz===sz});
    var fd=new FormData();fd.append('action','create_bulk_werkbonnen');fd.append('items',JSON.stringify(sel));
    fetch('pakketten.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){alert(d.created+' aangemaakt');location.reload()}else alert('Fout: '+d.error)});
}

// Weekrapport
function getDagenVanWeek(wk){
    var p=wk.split('-W');var yr=parseInt(p[0]),wn=parseInt(p[1]);
    // ISO week: Jan 4 is always in W1. Monday of that week: subtract (getDay()+6)%7 days
    var jan4=new Date(yr,0,4);
    var jan4dow=(jan4.getDay()+6)%7; // 0=Mon, 6=Sun
    var ma=new Date(jan4.getTime()+(wn-1)*7*86400000-jan4dow*86400000);
    var d=[];for(var i=0;i<5;i++){var dt=new Date(ma.getTime()+i*86400000);d.push(dt.getFullYear()+'-'+String(dt.getMonth()+1).padStart(2,'0')+'-'+String(dt.getDate()).padStart(2,'0'));}
    return d;
}
function fmtDag(ds){var dn=['zo','ma','di','wo','do','vr','za'];var d=new Date(ds);return dn[d.getDay()]+' '+String(d.getDate()).padStart(2,'0')+'-'+String(d.getMonth()+1).padStart(2,'0');}

function toonWeek(wk){
    var wrap=document.getElementById('wkWrap');if(!wrap)return;
    var wd=weekData[wk]||{};var dagen=getDagenVanWeek(wk);var vd=new Date().toISOString().split('T')[0];
    var mwT={};weekMw.forEach(function(mw){var t=0;dagen.forEach(function(d){t+=(wd[mw]&&wd[mw][d])?parseInt(wd[mw][d]):0;});mwT[mw]=t;});
    var gs=weekMw.slice().sort(function(a,b){return mwT[b]-mwT[a];});
    var html='<table class="wk-tbl" id="wkTbl"><thead><tr><th>#</th><th>Medewerker</th>';
    dagen.forEach(function(d){html+='<th class="'+(d===vd?'today':'')+'">'+ fmtDag(d)+'</th>';});
    html+='<th>Totaal</th></tr></thead><tbody>';
    gs.forEach(function(mw,i){
        html+='<tr class="'+(i===0?'r1':'')+'"><td style="color:#94a3b8">'+(i+1)+'</td><td>'+mw+'</td>';
        var t=0;
        dagen.forEach(function(d){var n=(wd[mw]&&wd[mw][d])?parseInt(wd[mw][d]):0;t+=n;html+='<td class="'+(d===vd?'today':'')+'">'+(n||'')+'</td>';});
        html+='<td style="color:#1e40af">'+t+'</td></tr>';
    });
    var wt=0;html+='<tr><td></td><td>Totaal dag</td>';
    dagen.forEach(function(d){var dt=0;weekMw.forEach(function(mw){dt+=(wd[mw]&&wd[mw][d])?parseInt(wd[mw][d]):0;});wt+=dt;html+='<td>'+(dt||'')+'</td>';});
    html+='<td>'+wt+'</td></tr></tbody></table>';
    wrap.innerHTML=html;
}

function dlWeek(){
    var wk=document.getElementById('wkSel').value;
    var tbl=document.getElementById('wkTbl');if(!tbl){alert('Geen data');return;}
    var ci='';try{var c=document.getElementById('mgChart');if(c)ci=c.toDataURL('image/png');}catch(e){}
    var html='<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Weekrapport '+wk+'</title>'
        +'<style>body{font-family:Arial,sans-serif;padding:20px;max-width:900px;margin:0 auto}'
        +'h1{color:#1e3a5f;font-size:20px;margin-bottom:16px}'
        +'table{width:100%;border-collapse:collapse;margin-bottom:20px}'
        +'th{background:#1e3a5f;color:#fff;padding:8px 12px;text-align:center;font-size:12px}'
        +'th:nth-child(2){text-align:left}'
        +'td{padding:7px 12px;border-bottom:1px solid #e2e8f0;text-align:center;font-size:13px}'
        +'td:nth-child(2){text-align:left;font-weight:600}'
        +'tr:last-child td{font-weight:700;background:#1e3a5f;color:#fff;border:none}'
        +'.r1{background:#fef9c3}.np{display:none}'
        +'@media print{@page{margin:12mm}body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}'
        +'img{width:100%;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:20px}'
        +'</style></head><body>'
        +'<button onclick="window.print()" class="np" style="background:#1e3a5f;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-size:13px;cursor:pointer;margin-bottom:16px">Printen / PDF</button>'
        +'<h1>HubMedia &mdash; Weekrapport '+wk+'</h1>';
    if(ci)html+='<img src="'+ci+'" alt="Grafiek">';
    html+=tbl.outerHTML+'</body></html>';
    var w=window.open('','_blank');w.document.write(html);w.document.close();
}

// Grafiek
var mwKleuren=['#2563eb','#059669','#d97706','#7c3aed','#dc2626','#0891b2','#65a30d','#c2410c'];
function initChart(){
    var ctx=document.getElementById('mgChart');if(!ctx||!window.Chart)return;
    var mwN=Object.keys(grafiekData);
    var ds=mwN.map(function(mw,i){return{label:mw,data:grafiekDagen.map(function(d){return grafiekData[mw][d]||0;}),borderColor:mwKleuren[i%mwKleuren.length],backgroundColor:mwKleuren[i%mwKleuren.length]+'22',fill:false,tension:.3,pointRadius:4};});
    new Chart(ctx,{type:'line',data:{labels:grafiekDagen,datasets:ds},options:{responsive:true,plugins:{legend:{position:'top'},tooltip:{mode:'index',intersect:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>