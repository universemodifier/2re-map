<?php

declare(strict_types=1);

/*
 * Shared bootstrap: configuration, session, database and helpers.
 */

define('ROOT_DIR', dirname(__DIR__));
define('DATA_DIR', ROOT_DIR . '/data');
define('UPLOAD_DIR', ROOT_DIR . '/public/uploads');

const APP_NAME = '2re-map';
const UPLOAD_URL = 'uploads/';
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
const PICTURE_MAX_PX = 480;
const MAX_LIST_ITEMS = 20;
const MAX_ITEM_LENGTH = 60;
const MAX_NAME_LENGTH = 80;
const MAX_NOTES_LENGTH = 2000;
const MAX_LOCATION_LENGTH = 160;
const GEOCODE_CACHE_TTL = 30 * 24 * 3600;

final class ValidationError extends RuntimeException
{
}

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('twore_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }

    $pdo = new PDO('sqlite:' . DATA_DIR . '/app.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    migrate($pdo);

    return $pdo;
}

function migrate(PDO $pdo): void
{
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

    if ($version < 1) {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                email          TEXT    NOT NULL UNIQUE COLLATE NOCASE,
                password_hash  TEXT    NOT NULL,
                name           TEXT    NOT NULL,
                needs          TEXT    NOT NULL DEFAULT '[]',
                skills         TEXT    NOT NULL DEFAULT '[]',
                lat            REAL,
                lng            REAL,
                location_label TEXT    NOT NULL DEFAULT '',
                notes          TEXT    NOT NULL DEFAULT '',
                picture        TEXT,
                created_at     TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at     TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS geocode_cache (
                cache_key  TEXT    PRIMARY KEY,
                response   TEXT    NOT NULL,
                created_at INTEGER NOT NULL
            );
            PRAGMA user_version = 1;
            SQL);
    }
}

// ---------------------------------------------------------------------------
// Auth / CSRF
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        json_error('Invalid or expired session token. Please reload the page.', 419);
    }
}

function current_user_id(): ?int
{
    return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
}

function current_user(): ?array
{
    $id = current_user_id();
    if ($id === null) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        unset($_SESSION['uid']);
        return null;
    }
    return $row;
}

function require_user(): array
{
    return current_user() ?? json_error('Please log in first.', 401);
}

function login_user(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
}

// ---------------------------------------------------------------------------
// Presentation helpers
// ---------------------------------------------------------------------------

/** Public representation of a user (never includes email or password). */
function user_public(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'needs' => json_decode($row['needs'], true) ?: [],
        'skills' => json_decode($row['skills'], true) ?: [],
        'lat' => $row['lat'] !== null ? (float) $row['lat'] : null,
        'lng' => $row['lng'] !== null ? (float) $row['lng'] : null,
        'location' => $row['location_label'],
        'notes' => $row['notes'],
        'picture' => $row['picture'] ? UPLOAD_URL . $row['picture'] : null,
        'updated_at' => $row['updated_at'],
    ];
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): never
{
    json_response(['error' => $message], $status);
}

// ---------------------------------------------------------------------------
// Outbound HTTP (used for geocoding)
// ---------------------------------------------------------------------------

function http_get_json(string $url): ?array
{
    $contact = getenv('NOMINATIM_EMAIL') ?: '';
    $userAgent = APP_NAME . '/1.0' . ($contact !== '' ? " ($contact)" : '');

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: en'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $status !== 200) {
            return null;
        }
    } else {
        $context = stream_context_create(['http' => [
            'timeout' => 8,
            'header' => "User-Agent: $userAgent\r\nAccept: application/json\r\nAccept-Language: en\r\n",
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }
    }

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/** GET a JSON URL, caching successful responses in SQLite. */
function cached_http_get_json(string $url): ?array
{
    $key = sha1($url);
    $stmt = db()->prepare('SELECT response, created_at FROM geocode_cache WHERE cache_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if ($row && (time() - (int) $row['created_at']) < GEOCODE_CACHE_TTL) {
        return json_decode($row['response'], true);
    }

    $data = http_get_json($url);
    if ($data !== null) {
        db()->prepare('REPLACE INTO geocode_cache (cache_key, response, created_at) VALUES (?, ?, ?)')
            ->execute([$key, json_encode($data), time()]);
    }
    return $data;
}
