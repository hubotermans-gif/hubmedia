<?php
/**
 * HubMedia BV Boekhouding — REST API Backend
 * Alle data wordt opgeslagen in MySQL (hubmed01_boekhouding)
 */
require_once __DIR__ . '/auth.php';

// Content-Type wordt per actie gezet (JSON voor API, PDF/image voor bestanden)
$_action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($_action !== 'get_file') {
    header('Content-Type: application/json; charset=utf-8');
}

// Alleen ingelogde gebruikers
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Niet ingelogd']);
    exit;
}

// Database connectie
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=localhost;dbname=hubmed01_boekhouding;charset=utf8mb4',
            'hubmed01',
            'A3RliMu3BeWVQspBNZDVvIWtF',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

// Auto-migratie: voeg ontbrekende kolommen toe
function migrateDB() {
    $db = getDB();
    $cols = $db->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('mail_body', $cols)) {
        $db->exec("ALTER TABLE invoices ADD COLUMN mail_body MEDIUMTEXT DEFAULT NULL AFTER mail_id");
    }
    if (!in_array('mail_from', $cols)) {
        $db->exec("ALTER TABLE invoices ADD COLUMN mail_from VARCHAR(255) DEFAULT '' AFTER mail_body");
    }
    if (!in_array('mail_date', $cols)) {
        $db->exec("ALTER TABLE invoices ADD COLUMN mail_date VARCHAR(50) DEFAULT '' AFTER mail_from");
    }
    if (!in_array('bedrijf', $cols)) {
        $db->exec("ALTER TABLE invoices ADD COLUMN bedrijf VARCHAR(10) NOT NULL DEFAULT 'HML' AFTER type");
        $db->exec("CREATE INDEX idx_bedrijf ON invoices (bedrijf)");
    }
    // Transactions
    $txCols = $db->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('bedrijf', $txCols)) {
        $db->exec("ALTER TABLE transactions ADD COLUMN bedrijf VARCHAR(10) NOT NULL DEFAULT 'HML' AFTER type");
        $db->exec("CREATE INDEX idx_tx_bedrijf ON transactions (bedrijf)");
    }
}
migrateDB();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'load':
            handleLoad();
            break;
        case 'save_invoice':
            handleSaveInvoice();
            break;
        case 'update_invoice':
            handleUpdateInvoice();
            break;
        case 'delete_invoice':
            handleDeleteInvoice();
            break;
        case 'import_transactions':
            handleImportTransactions();
            break;
        case 'delete_transaction':
            handleDeleteTransaction();
            break;
        case 'update_transaction':
            handleUpdateTransaction();
            break;
        case 'save_relatie':
            handleSaveRelatie();
            break;
        case 'delete_relatie':
            handleDeleteRelatie();
            break;
        case 'save_grootboek':
            handleSaveGrootboek();
            break;
        case 'delete_grootboek':
            handleDeleteGrootboek();
            break;
        case 'import_grootboek':
            handleImportGrootboek();
            break;
        case 'upload_file':
            handleUploadFile();
            break;
        case 'get_file':
            handleGetFile();
            break;
        case 'delete_all_transactions':
            handleDeleteAllTransactions();
            break;
        case 'dedup_invoices':
            handleDedupInvoices();
            break;
        case 'load_archived':
            handleLoadArchived();
            break;
        case 'restore_invoice':
            handleRestoreInvoice();
            break;
        case 'permanent_delete_invoice':
            handlePermanentDeleteInvoice();
            break;
        case 'empty_trash':
            handleEmptyTrash();
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Onbekende actie: ' . $action]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server fout: ' . $e->getMessage()]);
}

// === HANDLERS ===

function handleLoad() {
    $db = getDB();
    $invoices = $db->query("SELECT * FROM invoices WHERE archived = 0 ORDER BY datum DESC")->fetchAll();
    $transactions = $db->query("SELECT * FROM transactions ORDER BY datum DESC")->fetchAll();
    $relaties = $db->query("SELECT * FROM relaties ORDER BY naam")->fetchAll();
    $grootboek = $db->query("SELECT * FROM grootboek ORDER BY nummer")->fetchAll();

    // Cast numerieke velden
    foreach ($invoices as &$inv) {
        $inv['btw_pct'] = (float)$inv['btw_pct'];
        $inv['bedrag_excl'] = (float)$inv['bedrag_excl'];
        $inv['btw_bedrag'] = (float)$inv['btw_bedrag'];
        $inv['bedrag_incl'] = (float)$inv['bedrag_incl'];
        $inv['has_file'] = (bool)$inv['has_file'];
    }
    foreach ($transactions as &$tx) {
        $tx['bedrag'] = (float)$tx['bedrag'];
        $tx['ai_gekoppeld'] = (bool)$tx['ai_gekoppeld'];
        $tx['goedgekeurd'] = (bool)$tx['goedgekeurd'];
    }

    echo json_encode([
        'invoices' => $invoices,
        'transactions' => $transactions,
        'relaties' => $relaties,
        'grootboek' => $grootboek
    ]);
}

function handleSaveInvoice() {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) { http_response_code(400); echo json_encode(['error' => 'Geen data']); return; }

    $db = getDB();
    $id = $data['id'] ?? uniqid('inv', true);

    $stmt = $db->prepare("INSERT INTO invoices (id, type, bedrijf, leverancier, nummer, datum, vervaldatum, btw_pct, bedrag_excl, btw_bedrag, bedrag_incl, status, omschrijving, has_file, file_name, grootboek_id, mail_id, mail_body, mail_from, mail_date)
        VALUES (:id, :type, :bedrijf, :leverancier, :nummer, :datum, :vervaldatum, :btw_pct, :bedrag_excl, :btw_bedrag, :bedrag_incl, :status, :omschrijving, :has_file, :file_name, :grootboek_id, :mail_id, :mail_body, :mail_from, :mail_date)
        ON DUPLICATE KEY UPDATE
        type=VALUES(type), bedrijf=VALUES(bedrijf), leverancier=VALUES(leverancier), nummer=VALUES(nummer), datum=VALUES(datum),
        vervaldatum=VALUES(vervaldatum), btw_pct=VALUES(btw_pct), bedrag_excl=VALUES(bedrag_excl),
        btw_bedrag=VALUES(btw_bedrag), bedrag_incl=VALUES(bedrag_incl), status=VALUES(status),
        omschrijving=VALUES(omschrijving), has_file=VALUES(has_file), file_name=VALUES(file_name), grootboek_id=VALUES(grootboek_id), mail_id=VALUES(mail_id), mail_body=VALUES(mail_body), mail_from=VALUES(mail_from), mail_date=VALUES(mail_date)");

    $stmt->execute([
        ':id' => $id,
        ':type' => $data['type'] ?? 'inkoop',
        ':bedrijf' => $data['bedrijf'] ?? 'HML',
        ':leverancier' => $data['leverancier'] ?? '',
        ':nummer' => $data['nummer'] ?? '',
        ':datum' => $data['datum'] ?: null,
        ':vervaldatum' => ($data['vervaldatum'] ?? '') ?: null,
        ':btw_pct' => $data['btw_pct'] ?? 21,
        ':bedrag_excl' => $data['bedrag_excl'] ?? 0,
        ':btw_bedrag' => $data['btw_bedrag'] ?? 0,
        ':bedrag_incl' => $data['bedrag_incl'] ?? 0,
        ':status' => $data['status'] ?? 'onbetaald',
        ':omschrijving' => $data['omschrijving'] ?? '',
        ':has_file' => ($data['has_file'] ?? false) ? 1 : 0,
        ':file_name' => $data['file_name'] ?? '',
        ':grootboek_id' => ($data['grootboek_id'] ?? '') ?: null,
        ':mail_id' => ($data['mail_id'] ?? '') ?: null,
        ':mail_body' => ($data['mail_body'] ?? '') ?: null,
        ':mail_from' => $data['mail_from'] ?? '',
        ':mail_date' => $data['mail_date'] ?? ''
    ]);

    echo json_encode(['ok' => true, 'id' => $id]);
}

function handleUpdateInvoice() {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !($data['id'] ?? '')) { http_response_code(400); echo json_encode(['error' => 'Geen id']); return; }

    $db = getDB();
    $allowed = ['bedrijf','leverancier','nummer','datum','vervaldatum','btw_pct','bedrag_excl','btw_bedrag','bedrag_incl','status','omschrijving','has_file','file_name','grootboek_id','mail_body','mail_from','mail_date'];
    $sets = [];
    $params = [':id' => $data['id']];

    foreach ($data as $key => $val) {
        if ($key === 'id') continue;
        if (!in_array($key, $allowed)) continue;
        $sets[] = "$key = :$key";
        if (in_array($key, ['vervaldatum','grootboek_id'])) {
            $params[":$key"] = $val ?: null;
        } else {
            $params[":$key"] = $val;
        }
    }

    if (empty($sets)) { echo json_encode(['ok' => true]); return; }

    $sql = "UPDATE invoices SET " . implode(', ', $sets) . " WHERE id = :id";
    $db->prepare($sql)->execute($params);

    echo json_encode(['ok' => true]);
}

function handleDeleteInvoice() {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? '';
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Geen id']); return; }

    $db = getDB();

    // Verwijder gekoppeld bestand
    $filePath = __DIR__ . '/facturen/' . $id . '.*';
    foreach (glob($filePath) as $f) { unlink($f); }

    // Ontkoppel transacties
    $db->prepare("UPDATE transactions SET factuur_id = NULL, ai_gekoppeld = 0, goedgekeurd = 0 WHERE factuur_id = :id")->execute([':id' => $id]);

    $db->prepare("DELETE FROM invoices WHERE id = :id")->execute([':id' => $id]);
    echo json_encode(['ok' => true]);
}

function handleImportTransactions() {
    $data = json_decode(file_get_contents('php://input'), true);
    $rows = $data['transactions'] ?? [];
    if (empty($rows)) { echo json_encode(['ok' => true, 'added' => 0]); return; }

    $db = getDB();
    $bedrijf = $data['bedrijf'] ?? 'HML';
    $stmt = $db->prepare("INSERT IGNORE INTO transactions (id, datum, naam, bedrag, type, bedrijf) VALUES (:id, :datum, :naam, :bedrag, :type, :bedrijf)");
    $added = 0;

    // Haal bestaande transacties op voor duplicate check
    $existing = $db->query("SELECT datum, naam, bedrag FROM transactions")->fetchAll();
    $existingSet = [];
    foreach ($existing as $ex) {
        $key = $ex['datum'] . '|' . $ex['naam'] . '|' . round((float)$ex['bedrag'], 2);
        $existingSet[$key] = true;
    }

    foreach ($rows as $row) {
        $key = $row['datum'] . '|' . $row['naam'] . '|' . round((float)$row['bedrag'], 2);
        if (isset($existingSet[$key])) continue;

        $stmt->execute([
            ':id' => $row['id'],
            ':datum' => $row['datum'],
            ':naam' => $row['naam'] ?? '',
            ':bedrag' => $row['bedrag'] ?? 0,
            ':type' => $row['type'] ?? '',
            ':bedrijf' => $row['bedrijf'] ?? $bedrijf
        ]);
        $added++;
        $existingSet[$key] = true;
    }

    echo json_encode(['ok' => true, 'added' => $added]);
}

function handleDeleteTransaction() {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? '';
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Geen id']); return; }

    $db = getDB();
    $db->prepare("DELETE FROM transactions WHERE id = :id")->execute([':id' => $id]);
    echo json_encode(['ok' => true]);
}

function handleUpdateTransaction() {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !($data['id'] ?? '')) { http_response_code(400); echo json_encode(['error' => 'Geen id']); return; }

    $db = getDB();
    $allowed = ['factuur_id','ai_gekoppeld','goedgekeurd'];
    $sets = [];
    $params = [':id' => $data['id']];

    foreach ($data as $key => $val) {
        if ($key === 'id') continue;
        if (!in_array($key, $allowed)) continue;
        $sets[] = "$key = :$key";
        if ($key === 'factuur_id') {
            $params[":$key"] = $val ?: null;
        } else {
            $params[":$key"] = $val ? 1 : 0;
        }
    }

    if (empty($sets)) { echo json_encode(['ok' => true]); return; }

    $sql = "UPDATE transactions SET " . implode(', ', $sets) . " WHERE id = :id";
    $db->prepare($sql)->execute($params);

    // Als factuur_id gezet wordt, update factuur status naar betaald
    if (isset($data['factuur_id']) && $data['factuur_id']) {
        $db->prepare("UPDATE invoices SET status = 'betaald' WHERE id = :id")
           ->execute([':id' => $data['factuur_id']]);
    }

    echo json_encode(['ok' => true]);
}

function handleSaveRelatie() {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) { http_response_code(400); echo json_encode(['error' => 'Geen data']); return; }

    $db = getDB();
    $id = $data['id'] ?? uniqid('rel', true);

    $stmt = $db->prepare("INSERT INTO relaties (id, type, naam, contact, email, tel, adres, plaats, btwnr, kvk, iban, term, notitie)
        VALUES (:id, :type, :naam, :contact, :email, :tel, :adres, :plaats, :btwnr, :kvk, :iban, :term, :notitie)
        ON DUPLICATE KEY UPDATE
        naam=VALUES(naam), contact=VALUES(contact), email=VALUES(email), tel=VALUES(tel),
        adres=VALUES(adres), plaats=VALUES(plaats), btwnr=VALUES(btwnr), kvk=VALUES(kvk),
        iban=VALUES(iban), term=VALUES(term), notitie=VALUES(notitie)");

    $stmt->execute([
        ':id' => $id,
        ':type' => $data['type'] ?? 'klant',
        ':naam' => $data['naam'] ?? '',
        ':contact' => $data['contact'] ?? '',
        ':email' => $data['email'] ?? '',
        ':tel' => $data['tel'] ?? '',
        ':adres' => $data['adres'] ?? '',
        ':plaats' => $data['plaats'] ?? '',
        ':btwnr' => $data['btwnr'] ?? '',
        ':kvk' => $data['kvk'] ?? '',
        ':iban' => $data['iban'] ?? '',
        ':term' => $data['term'] ?? '30',
        ':notitie' => $data['notitie'] ?? ''
    ]);

    echo json_encode(['ok' => true, 'id' => $id]);
}

function handleDeleteRelatie() {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? '';
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Geen id']); return; }

    $db = getDB();
    $db->prepare("DELETE FROM relaties WHERE id = :id")->execute([':id' => $id]);
    echo json_encode(['ok' => true]);
}

function handleSaveGrootboek() {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) { http_response_code(400); echo json_encode(['error' => 'Geen data']); return; }

    $db = getDB();
    $id = $data['id'] ?? uniqid('gb', true);

    $stmt = $db->prepare("INSERT INTO grootboek (id, nummer, naam, categorie, btwcode, omschrijving)
        VALUES (:id, :nummer, :naam, :categorie, :btwcode, :omschrijving)
        ON DUPLICATE KEY UPDATE
        naam=VALUES(naam), categorie=VALUES(categorie), btwcode=VALUES(btwcode), omschrijving=VALUES(omschrijving)");

    $stmt->execute([
        ':id' => $id,
        ':nummer' => $data['nummer'] ?? '',
        ':naam' => $data['naam'] ?? '',
        ':categorie' => $data['categorie'] ?? 'overig',
        ':btwcode' => $data['btwcode'] ?? '',
        ':omschrijving' => $data['omschrijving'] ?? ''
    ]);

    echo json_encode(['ok' => true, 'id' => $id]);
}

function handleDeleteGrootboek() {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? '';
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Geen id']); return; }

    $db = getDB();
    $db->prepare("DELETE FROM grootboek WHERE id = :id")->execute([':id' => $id]);
    echo json_encode(['ok' => true]);
}

function handleImportGrootboek() {
    $data = json_decode(file_get_contents('php://input'), true);
    $rows = $data['rows'] ?? [];
    if (empty($rows)) { echo json_encode(['ok' => true, 'added' => 0]); return; }

    $db = getDB();
    $stmt = $db->prepare("INSERT IGNORE INTO grootboek (id, nummer, naam, categorie, btwcode, omschrijving)
        VALUES (:id, :nummer, :naam, :categorie, :btwcode, :omschrijving)");
    $added = 0;

    foreach ($rows as $row) {
        try {
            $stmt->execute([
                ':id' => $row['id'],
                ':nummer' => $row['nummer'],
                ':naam' => $row['naam'],
                ':categorie' => $row['categorie'] ?? 'overig',
                ':btwcode' => $row['btwcode'] ?? '',
                ':omschrijving' => $row['omschrijving'] ?? ''
            ]);
            $added++;
        } catch (Exception $e) {
            // Duplicate nummer, skip
        }
    }

    echo json_encode(['ok' => true, 'added' => $added]);
}

function handleUploadFile() {
    $id = $_POST['id'] ?? '';
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Geen id']); return; }

    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Geen bestand']);
        return;
    }

    $file = $_FILES['file'];
    $uploadDir = __DIR__ . '/facturen/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    // Bepaal extensie
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Bestandstype niet toegestaan']);
        return;
    }

    // Verwijder eventueel oud bestand
    foreach (glob($uploadDir . $id . '.*') as $old) { unlink($old); }

    $destPath = $uploadDir . $id . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Upload mislukt']);
        return;
    }

    // Update invoice record
    $db = getDB();
    $db->prepare("UPDATE invoices SET has_file = 1, file_name = :fname WHERE id = :id")
       ->execute([':fname' => $file['name'], ':id' => $id]);

    echo json_encode(['ok' => true, 'path' => '/facturen/' . $id . '.' . $ext]);
}

function handleGetFile() {
    $id = $_GET['id'] ?? '';
    if (!$id) { http_response_code(400); echo 'Geen id'; return; }

    $uploadDir = __DIR__ . '/facturen/';
    $files = glob($uploadDir . $id . '.*');
    if (empty($files)) { http_response_code(404); echo 'Bestand niet gevonden'; return; }

    $filePath = $files[0];
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    $mime = $mimeTypes[$ext] ?? 'application/octet-stream';

    // Wis ALLE eerdere headers en zet correcte PDF/image headers
    if (function_exists('header_remove')) { header_remove(); }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: inline');
    header('Cache-Control: public, max-age=86400');
    header('Accept-Ranges: bytes');
    ob_end_clean(); // Wis eventuele output buffers
    readfile($filePath);
    exit;
}

function handleDeleteAllTransactions() {
    $db = getDB();
    $db->exec("DELETE FROM transactions");
    echo json_encode(['ok' => true]);
}

function handleDedupInvoices() {
    $db = getDB();
    $uploadDir = __DIR__ . '/facturen/';
    $removed = 0;
    $deletedIds = [];

    // Helper: archiveer een factuur (niet permanent verwijderen)
    $deleteInvoice = function($delId) use ($db, &$removed, &$deletedIds) {
        if (in_array($delId, $deletedIds)) return;
        $db->prepare("UPDATE transactions SET factuur_id = NULL, ai_gekoppeld = 0, goedgekeurd = 0 WHERE factuur_id = :id")->execute([':id' => $delId]);
        $db->prepare("UPDATE invoices SET archived = 1 WHERE id = :id")->execute([':id' => $delId]);
        $deletedIds[] = $delId;
        $removed++;
    };

    // Stap 1: Duplicaten op leverancier + bedrag_incl + datum
    // (zelfde leverancier, zelfde bedrag, zelfde datum = duplicaat)
    $dupes1 = $db->query("
        SELECT leverancier, bedrag_incl, datum, type, GROUP_CONCAT(id ORDER BY
            CASE WHEN has_file = 1 THEN 0 ELSE 1 END,
            CASE WHEN bedrag_excl > 0 THEN 0 ELSE 1 END,
            created_at ASC
        ) as ids, COUNT(*) as aantal
        FROM invoices
        WHERE leverancier != '' AND bedrag_incl > 0
        GROUP BY LOWER(TRIM(leverancier)), bedrag_incl, datum, type
        HAVING COUNT(*) > 1
    ")->fetchAll();

    foreach ($dupes1 as $dup) {
        $ids = explode(',', $dup['ids']);
        array_shift($ids); // Bewaar de eerste (met bestand + data)
        foreach ($ids as $delId) { $deleteInvoice($delId); }
    }

    // Stap 2: Duplicaten op factuurnummer + leverancier
    // (zelfde factuurnummer bij zelfde leverancier = duplicaat)
    $dupes2 = $db->query("
        SELECT leverancier, nummer, type, GROUP_CONCAT(id ORDER BY
            CASE WHEN has_file = 1 THEN 0 ELSE 1 END,
            CASE WHEN bedrag_excl > 0 THEN 0 ELSE 1 END,
            created_at ASC
        ) as ids, COUNT(*) as aantal
        FROM invoices
        WHERE leverancier != '' AND nummer != ''
        GROUP BY LOWER(TRIM(leverancier)), LOWER(TRIM(nummer)), type
        HAVING COUNT(*) > 1
    ")->fetchAll();

    foreach ($dupes2 as $dup) {
        $ids = explode(',', $dup['ids']);
        array_shift($ids);
        foreach ($ids as $delId) { $deleteInvoice($delId); }
    }

    // Stap 3: Duplicaten op file_name + leverancier
    // (zelfde bestand bij zelfde leverancier = duplicaat)
    $dupes3 = $db->query("
        SELECT file_name, leverancier, GROUP_CONCAT(id ORDER BY
            CASE WHEN bedrag_excl > 0 THEN 0 ELSE 1 END,
            created_at ASC
        ) as ids, COUNT(*) as aantal
        FROM invoices
        WHERE file_name != ''
        GROUP BY file_name, LOWER(TRIM(leverancier))
        HAVING COUNT(*) > 1
    ")->fetchAll();

    foreach ($dupes3 as $dup) {
        $ids = explode(',', $dup['ids']);
        array_shift($ids);
        foreach ($ids as $delId) { $deleteInvoice($delId); }
    }

    // Stap 4: Duplicaten op omschrijving + datum (voor facturen zonder leverancier)
    $dupes4 = $db->query("
        SELECT omschrijving, datum, type, GROUP_CONCAT(id ORDER BY
            CASE WHEN bedrag_excl > 0 THEN 0 ELSE 1 END,
            created_at ASC
        ) as ids, COUNT(*) as aantal
        FROM invoices
        WHERE leverancier = '' AND omschrijving != ''
        GROUP BY LOWER(TRIM(omschrijving)), datum, type
        HAVING COUNT(*) > 1
    ")->fetchAll();

    foreach ($dupes4 as $dup) {
        $ids = explode(',', $dup['ids']);
        array_shift($ids);
        foreach ($ids as $delId) { $deleteInvoice($delId); }
    }

    $remaining = $db->query("SELECT COUNT(*) FROM invoices WHERE archived = 0")->fetchColumn();
    echo json_encode(['ok' => true, 'removed' => $removed, 'remaining' => $remaining]);
}

function handleLoadArchived() {
    $db = getDB();
    $invoices = $db->query("SELECT * FROM invoices WHERE archived = 1 ORDER BY created_at DESC")->fetchAll();
    foreach ($invoices as &$inv) {
        $inv['btw_pct'] = (float)$inv['btw_pct'];
        $inv['bedrag_excl'] = (float)$inv['bedrag_excl'];
        $inv['btw_bedrag'] = (float)$inv['btw_bedrag'];
        $inv['bedrag_incl'] = (float)$inv['bedrag_incl'];
        $inv['has_file'] = (bool)$inv['has_file'];
    }
    echo json_encode(['invoices' => $invoices]);
}

function handleRestoreInvoice() {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? '';
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Geen id']); return; }
    $db = getDB();
    $db->prepare("UPDATE invoices SET archived = 0 WHERE id = :id")->execute([':id' => $id]);
    echo json_encode(['ok' => true]);
}

function handlePermanentDeleteInvoice() {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? '';
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'Geen id']); return; }
    $db = getDB();
    $uploadDir = __DIR__ . '/facturen/';
    foreach (glob($uploadDir . $id . '.*') as $f) { unlink($f); }
    $db->prepare("DELETE FROM invoices WHERE id = :id")->execute([':id' => $id]);
    echo json_encode(['ok' => true]);
}

function handleEmptyTrash() {
    $db = getDB();
    $uploadDir = __DIR__ . '/facturen/';
    $archived = $db->query("SELECT id FROM invoices WHERE archived = 1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($archived as $id) {
        foreach (glob($uploadDir . $id . '.*') as $f) { unlink($f); }
    }
    $count = count($archived);
    $db->exec("DELETE FROM invoices WHERE archived = 1");
    echo json_encode(['ok' => true, 'removed' => $count]);
}
