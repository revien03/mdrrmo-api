<?php
/**
 * Shared helpers for the MDRRMO API: JSON responses, CORS, validation
 * and the database queries used by create.php, list.php and view.php.
 */
require_once __DIR__ . '/db_connection.php';

/** Emergency types the API accepts. Keep in sync with your website's dropdown. */
const EMERGENCY_TYPES = [
    'fire', 'flood', 'landslide', 'earthquake', 'typhoon',
    'medical', 'road_accident', 'other',
];

function send_cors_headers(): void
{
    // Set ALLOWED_ORIGIN to your Hatchable site URL in Render. "*" is for testing only.
    $origin = getenv('ALLOWED_ORIGIN') ?: '*';
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, X-API-Key');
    header('Content-Type: application/json; charset=utf-8');
}

function handle_preflight(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function json_response(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** If ADMIN_API_KEY is set, list.php and view.php require it in the X-API-Key header. */
function require_read_key(): void
{
    $key = getenv('ADMIN_API_KEY');
    if (!$key) {
        return;
    }
    $sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals($key, $sent)) {
        json_response(401, ['success' => false, 'message' => 'A valid API key is required.']);
    }
}

/** Read JSON body or form fields into one array. */
function read_input(): array
{
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $data = json_decode(file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

/** Returns [cleanValues, errors]. */
function validate_incident(array $in): array
{
    $errors = [];

    $type = trim((string)($in['emergency_type'] ?? ''));
    if (!in_array($type, EMERGENCY_TYPES, true)) {
        $errors['emergency_type'] = 'Please select a valid emergency type.';
    }

    $desc = trim((string)($in['description'] ?? ''));
    $len  = mb_strlen($desc);
    if ($len < 10 || $len > 2000) {
        $errors['description'] = 'Description must be 10 to 2000 characters.';
    }

    $persons = $in['persons_affected'] ?? '';
    if (!preg_match('/^\d+$/', (string)$persons) || (int)$persons > 100000) {
        $errors['persons_affected'] = 'Persons affected must be a whole number, 0 or more.';
    }

    $lat = $in['latitude'] ?? '';
    if (!is_numeric($lat) || $lat < -90 || $lat > 90) {
        $errors['latitude'] = 'Latitude must be between -90 and 90.';
    }
    $lng = $in['longitude'] ?? '';
    if (!is_numeric($lng) || $lng < -180 || $lng > 180) {
        $errors['longitude'] = 'Longitude must be between -180 and 180.';
    }

    $acc = $in['gps_accuracy'] ?? '';
    if ($acc !== '' && $acc !== null && (!is_numeric($acc) || $acc < 0)) {
        $errors['gps_accuracy'] = 'GPS accuracy must be 0 or more meters.';
    }

    $clean = [
        'emergency_type'   => $type,
        'description'      => $desc,
        'persons_affected' => (int)$persons,
        'latitude'         => (float)$lat,
        'longitude'        => (float)$lng,
        'gps_accuracy'     => ($acc === '' || $acc === null) ? null : (float)$acc,
    ];
    return [$clean, $errors];
}

/** Validate an optional uploaded photo (max 3 MB, jpg/png/webp). Returns [photo|null, error|null]. */
function read_photo(): array
{
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    $f = $_FILES['photo'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return [null, 'The photo could not be uploaded.'];
    }
    if ($f['size'] > 3 * 1024 * 1024) {
        return [null, 'Photo must be 3 MB or smaller.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return [null, 'Photo must be a JPG, PNG or WebP image.'];
    }
    return [['mime' => $mime, 'data' => file_get_contents($f['tmp_name'])], null];
}

function insert_incident(array $c, ?array $photo): int
{
    $sql = 'INSERT INTO incidents
              (emergency_type, description, persons_affected, gps_accuracy,
               latitude, longitude, geom, photo_mime, photo_data)
            VALUES
              (:type, :desc, :persons, :acc, :lat, :lng,
               ST_SetSRID(ST_MakePoint(:lng2, :lat2), 4326)::geography,
               :pmime, :pdata)
            RETURNING id';
    $st = db()->prepare($sql);
    $st->bindValue(':type', $c['emergency_type']);
    $st->bindValue(':desc', $c['description']);
    $st->bindValue(':persons', $c['persons_affected'], PDO::PARAM_INT);
    $st->bindValue(':acc', $c['gps_accuracy']);
    $st->bindValue(':lat', $c['latitude']);
    $st->bindValue(':lng', $c['longitude']);
    $st->bindValue(':lat2', $c['latitude']);
    $st->bindValue(':lng2', $c['longitude']);
    $st->bindValue(':pmime', $photo['mime'] ?? null);
    $st->bindValue(':pdata', $photo['data'] ?? null, $photo ? PDO::PARAM_LOB : PDO::PARAM_NULL);
    $st->execute();
    return (int)$st->fetchColumn();
}

const INCIDENT_COLUMNS = 'id, emergency_type, description, persons_affected, gps_accuracy,
                          latitude, longitude, (photo_data IS NOT NULL) AS has_photo,
                          status, created_at';

function list_incidents(int $limit, int $offset): array
{
    $st = db()->prepare('SELECT ' . INCIDENT_COLUMNS . '
                         FROM incidents ORDER BY created_at DESC LIMIT :l OFFSET :o');
    $st->bindValue(':l', $limit, PDO::PARAM_INT);
    $st->bindValue(':o', $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function get_incident(int $id): ?array
{
    $st = db()->prepare('SELECT ' . INCIDENT_COLUMNS . ' FROM incidents WHERE id = :id');
    $st->bindValue(':id', $id, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch();
    return $row ?: null;
}

