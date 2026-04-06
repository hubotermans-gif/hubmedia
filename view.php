<?php
/**
 * Directe PDF/image viewer — serveert bestanden met correcte headers
 */
require_once __DIR__ . '/auth.php';
if (!isLoggedIn()) { http_response_code(401); exit('Niet ingelogd'); }

$id = $_GET['id'] ?? '';
if (!$id) { http_response_code(400); exit('Geen id'); }

$uploadDir = __DIR__ . '/facturen/';
$files = glob($uploadDir . $id . '.*');
if (empty($files)) { http_response_code(404); exit('Niet gevonden'); }

$filePath = $files[0];
$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimeTypes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

// Wis alle headers en output buffers
while (ob_get_level()) { ob_end_clean(); }
if (function_exists('header_remove')) { header_remove(); }

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline');
header('Cache-Control: public, max-age=86400');
readfile($filePath);
exit;
