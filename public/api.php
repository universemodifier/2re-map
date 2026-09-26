<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];
$action = (string) ($_GET['action'] ?? '');

try {
    if ($method === 'POST') {
        check_csrf();
    }

    match ("$method $action") {
        'GET pins' => action_pins(),
        'GET me' => action_me(),
        'GET geocode' => action_geocode(),
        'GET reverse' => action_reverse(),
        'POST register' => action_register(),
        'POST login' => action_login(),
        'POST logout' => action_logout(),
        'POST profile' => action_profile(),
        'POST delete_account' => action_delete_account(),
        default => json_error('Unknown action.', 404),
    };
} catch (ValidationError $e) {
    json_error($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('[' . APP_NAME . '] ' . $e);
    json_error('Something went wrong on the server.', 500);
}

// ---------------------------------------------------------------------------
// Read actions
// ---------------------------------------------------------------------------

function action_pins(): never
{
    $rows = db()->query(
        'SELECT * FROM users WHERE lat IS NOT NULL AND lng IS NOT NULL ORDER BY updated_at DESC'
    )->fetchAll();

    json_response(['pins' => array_map('user_public', $rows)]);
}

function action_me(): never
{
    $user = current_user();
    json_response(['user' => $user ? user_public($user) : null]);
}

function action_geocode(): never
{
    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        json_response(['results' => []]);
    }

    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => mb_substr($q, 0, 120),
        'format' => 'jsonv2',
        'addressdetails' => 1,
        'limit' => 6,
    ]);
    $data = cached_http_get_json($url);
    if ($data === null) {
        json_error('Location search is currently unavailable.', 502);
    }

    $results = [];
    foreach ($data as $place) {
        if (!isset($place['lat'], $place['lon'])) {
            continue;
        }
        $results[] = [
            'label' => place_label($place),
            'detail' => (string) ($place['display_name'] ?? ''),
            'lat' => (float) $place['lat'],
            'lng' => (float) $place['lon'],
        ];
    }
    json_response(['results' => $results]);
}

function action_reverse(): never
{
    [$lat, $lng] = parse_coordinates($_GET['lat'] ?? '', $_GET['lng'] ?? '');
    if ($lat === null) {
        throw new ValidationError('Invalid coordinates.');
    }

    // Round so nearby clicks share a cache entry.
    $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
        'lat' => round($lat, 3),
        'lon' => round($lng, 3),
        'format' => 'jsonv2',
        'addressdetails' => 1,
        'zoom' => 10,
    ]);
    $data = cached_http_get_json($url);

    $label = ($data && empty($data['error'])) ? place_label($data) : '';
    if ($label === '') {
        $label = format_coordinates($lat, $lng);
    }
    json_response(['label' => $label, 'lat' => $lat, 'lng' => $lng]);
}

// ---------------------------------------------------------------------------
// Auth actions
// ---------------------------------------------------------------------------

function action_register(): never
{
    $name = clean_text($_POST['name'] ?? '', MAX_NAME_LENGTH);
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '') {
        throw new ValidationError('Please enter your name.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        throw new ValidationError('Please enter a valid email address.');
    }
    if (strlen($password) < 8) {
        throw new ValidationError('Your password needs at least 8 characters.');
    }

    $exists = db()->prepare('SELECT 1 FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetchColumn()) {
        json_error('An account with this email already exists.', 409);
    }

    db()->prepare('INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)')
        ->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);

    login_user((int) db()->lastInsertId());
    json_response(['ok' => true]);
}

function action_login(): never
{
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password_hash'])) {
        usleep(300_000);
        json_error('Email or password is incorrect.', 401);
    }

    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }

    login_user((int) $row['id']);
    json_response(['ok' => true]);
}

function action_logout(): never
{
    $_SESSION = [];
    session_destroy();
    json_response(['ok' => true]);
}

// ---------------------------------------------------------------------------
// Profile actions
// ---------------------------------------------------------------------------

function action_profile(): never
{
    $user = require_user();

    $name = clean_text($_POST['name'] ?? '', MAX_NAME_LENGTH);
    if ($name === '') {
        throw new ValidationError('Please enter your name.');
    }

    [$lat, $lng] = parse_coordinates($_POST['lat'] ?? '', $_POST['lng'] ?? '');
    if ($lat === null) {
        throw new ValidationError('Please choose a location - search for a city or pick a spot on the globe.');
    }

    $location = clean_text($_POST['location_label'] ?? '', MAX_LOCATION_LENGTH);
    if ($location === '') {
        $location = format_coordinates($lat, $lng);
    }

    $notes = str_replace("\r\n", "\n", trim((string) ($_POST['notes'] ?? '')));
    if (mb_strlen($notes) > MAX_NOTES_LENGTH) {
        throw new ValidationError('Notes can be at most ' . MAX_NOTES_LENGTH . ' characters.');
    }

    $needs = parse_list($_POST['needs'] ?? '[]');
    $skills = parse_list($_POST['skills'] ?? '[]');

    $picture = $user['picture'];
    $newPicture = handle_picture_upload();
    if ($newPicture !== null || !empty($_POST['remove_picture'])) {
        delete_picture($picture);
        $picture = $newPicture;
    }

    db()->prepare(<<<'SQL'
        UPDATE users
           SET name = ?, needs = ?, skills = ?, lat = ?, lng = ?, location_label = ?,
               notes = ?, picture = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?
        SQL)->execute([
        $name,
        json_encode($needs, JSON_UNESCAPED_UNICODE),
        json_encode($skills, JSON_UNESCAPED_UNICODE),
        $lat,
        $lng,
        $location,
        $notes,
        $picture,
        $user['id'],
    ]);

    json_response(['user' => user_public(current_user())]);
}

function action_delete_account(): never
{
    $user = require_user();

    delete_picture($user['picture']);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);

    $_SESSION = [];
    session_destroy();
    json_response(['ok' => true]);
}

// ---------------------------------------------------------------------------
// Input helpers
// ---------------------------------------------------------------------------

function clean_text(mixed $value, int $maxLength): string
{
    $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
    return mb_substr($value, 0, $maxLength);
}

/** Decode a JSON list of strings into a trimmed, de-duplicated array. */
function parse_list(mixed $json): array
{
    $items = json_decode((string) $json, true);
    if (!is_array($items)) {
        return [];
    }

    $result = [];
    $seen = [];
    foreach ($items as $item) {
        if (!is_string($item)) {
            continue;
        }
        $item = clean_text($item, MAX_ITEM_LENGTH);
        $key = mb_strtolower($item);
        if ($item === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $result[] = $item;
        if (count($result) >= MAX_LIST_ITEMS) {
            break;
        }
    }
    return $result;
}

/** @return array{0: ?float, 1: ?float} */
function parse_coordinates(mixed $lat, mixed $lng): array
{
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return [null, null];
    }
    $lat = (float) $lat;
    $lng = (float) $lng;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return [null, null];
    }
    return [round($lat, 6), round($lng, 6)];
}

function format_coordinates(float $lat, float $lng): string
{
    return sprintf(
        '%.2f°%s, %.2f°%s',
        abs($lat),
        $lat >= 0 ? 'N' : 'S',
        abs($lng),
        $lng >= 0 ? 'E' : 'W'
    );
}

/** Build a short "City, Country" style label from a Nominatim result. */
function place_label(array $place): string
{
    $address = $place['address'] ?? [];
    $locality = $address['city'] ?? $address['town'] ?? $address['village']
        ?? $address['municipality'] ?? $address['county'] ?? $address['state']
        ?? ($place['name'] ?? '');
    $country = $address['country'] ?? '';

    $parts = array_filter([$locality, $country !== $locality ? $country : ''], fn ($p) => $p !== '');
    if ($parts) {
        return implode(', ', $parts);
    }
    return (string) ($place['display_name'] ?? '');
}

/**
 * Validate and store an uploaded picture. Returns the stored file name,
 * or null when no picture was uploaded.
 */
function handle_picture_upload(): ?string
{
    $file = $_FILES['picture'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
        || $file['size'] > MAX_UPLOAD_BYTES) {
        throw new ValidationError('The picture is too large (max ' . (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB).');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new ValidationError('The picture could not be uploaded.');
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
        throw new ValidationError('Please upload a JPG, PNG, WebP or GIF image.');
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }
    $base = bin2hex(random_bytes(16));

    // Re-encode through GD when available: shrinks the image and strips metadata (e.g. GPS EXIF).
    if (function_exists('imagecreatefromstring')) {
        $stored = resize_picture($file['tmp_name'], $base);
        if ($stored !== null) {
            return $stored;
        }
    }

    $name = $base . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        throw new ValidationError('The picture could not be saved.');
    }
    return $name;
}

function resize_picture(string $path, string $base): ?string
{
    $source = @imagecreatefromstring((string) file_get_contents($path));
    if ($source === false) {
        return null;
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min(1, PICTURE_MAX_PX / max($width, $height));
    $newWidth = max(1, (int) round($width * $scale));
    $newHeight = max(1, (int) round($height * $scale));

    $target = imagecreatetruecolor($newWidth, $newHeight);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    if (function_exists('imagewebp')) {
        $name = $base . '.webp';
        $ok = imagewebp($target, UPLOAD_DIR . '/' . $name, 85);
    } else {
        $name = $base . '.png';
        $ok = imagepng($target, UPLOAD_DIR . '/' . $name, 6);
    }

    imagedestroy($source);
    imagedestroy($target);
    return $ok ? $name : null;
}

function delete_picture(?string $name): void
{
    if ($name && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/', $name)) {
        @unlink(UPLOAD_DIR . '/' . $name);
    }
}
