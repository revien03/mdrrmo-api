<?php
/**
 * GET /api/incidents/list.php?limit=20&offset=0
 * Returns {"success":true,"count":N,"incidents":[...]} (photos are not included).
 * If ADMIN_API_KEY is set, send it in the X-API-Key header.
 */
require_once __DIR__ . '/../../../src/database.php';

send_cors_headers();
handle_preflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(405, ['success' => false, 'message' => 'Use GET to list reports.']);
}
require_read_key();

$limit  = max(1, min(100, (int)($_GET['limit'] ?? 20)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

try {
    $rows = list_incidents($limit, $offset);
} catch (Throwable $e) {
    error_log('list.php: ' . $e->getMessage());
    json_response(500, ['success' => false, 'message' => 'The server could not load reports.']);
}

json_response(200, ['success' => true, 'count' => count($rows), 'incidents' => $rows]);

