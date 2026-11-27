<?php
/**
 * GET /api/incidents/view.php?id=4
 * Returns {"success":true,"incident":{...}} or 404.
 * If ADMIN_API_KEY is set, send it in the X-API-Key header.
 */
require_once __DIR__ . '/../../../src/database.php';

send_cors_headers();
handle_preflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['success' => false, 'message' => 'Use GET to view a report.']);
}
require_read_key();

$id = $_GET['id'] ?? '';
if (!preg_match('/^\d+$/', (string)$id)) {
    json_response(422, ['success' => false, 'message' => 'A numeric id is required.']);
}

try {
    $row = get_incident((int)$id);
} catch (Throwable $e) {
    error_log('view.php: ' . $e->getMessage());
    json_response(500, ['success' => false, 'message' => 'The server could not load the report.']);
}

if (!$row) {
    json_response(404, ['success' => false, 'message' => 'Report not found.']);
}
json_response(200, ['success' => true, 'incident' => $row]);
