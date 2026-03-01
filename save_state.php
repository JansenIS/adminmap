<?php
// save_state.php
//
// Simple JSON save endpoint for admin page.
//
// SECURITY WARNING:
// - This is intentionally minimal and not hardened.
// - Use it only on a trusted/private server.
// - Recommended: restrict by server auth, IP allowlist, or a proper session.
//
// Usage (admin.js):
// POST JSON: { "token": "...", "state": { ...map_state... } }
//
// It writes to: data/map_state.json

declare(strict_types=1);

header("Content-Type: text/plain; charset=utf-8");

function decode_data_url_image(string $src): ?array {
  if (!preg_match('#^data:image/(png|webp|jpeg|jpg);base64,(.+)$#i', $src, $m)) return null;
  $fmt = strtolower((string)$m[1]);
  $ext = ($fmt === "jpeg" || $fmt === "jpg") ? "jpg" : $fmt;
  $raw = base64_decode($m[2], true);
  if ($raw === false || $raw === "") return null;
  return ["ext" => $ext, "bytes" => $raw];
}

function persist_people_profile_images(array &$state): void {
  $profiles = $state['people_profiles'] ?? null;
  if (!is_array($profiles)) return;

  $peopleDir = __DIR__ . DIRECTORY_SEPARATOR . 'people';
  if (!is_dir($peopleDir) && !mkdir($peopleDir, 0775, true) && !is_dir($peopleDir)) return;

  foreach ($profiles as $name => $profile) {
    if (!is_array($profile)) continue;
    $photo = isset($profile['photo_url']) ? trim((string)$profile['photo_url']) : '';
    if ($photo === '' || !str_starts_with($photo, 'data:image/')) continue;
    $img = decode_data_url_image($photo);
    if ($img === null) continue;

    $slug = preg_replace('/[^a-z0-9_\-]+/i', '_', (string)$name);
    $slug = trim((string)$slug, '_');
    if ($slug === '') $slug = 'person';
    $hash = substr(sha1((string)$name), 0, 8);
    $filename = $slug . '_' . $hash . '.' . $img['ext'];
    $path = $peopleDir . DIRECTORY_SEPARATOR . $filename;
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $img['bytes']) === false || !rename($tmp, $path)) {
      @unlink($tmp);
      continue;
    }

    $profile['photo_url'] = 'people/' . $filename;
    $profiles[$name] = $profile;
  }

  $state['people_profiles'] = $profiles;
}


$raw = file_get_contents("php://input");
if ($raw === false || $raw === "") {
  http_response_code(400);
  echo "Empty body";
  exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo "Invalid JSON";
  exit;
}

// Configure a token (set non-empty to enable basic protection)
$TOKEN = ""; // e.g. "CHANGE_ME"

$token = isset($data["token"]) ? (string)$data["token"] : "";
if ($TOKEN !== "" && $token !== $TOKEN) {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

$state = $data["state"] ?? null;
if (!is_array($state) || !isset($state["provinces"])) {
  http_response_code(400);
  echo "Missing state/provinces";
  exit;
}

persist_people_profile_images($state);

// Basic sanity limit: prevent huge writes by mistake.
// Can be overridden from web-server config if needed, e.g.:
//   SetEnv ADMINMAP_MAX_STATE_BYTES 15000000
$MAX_STATE_BYTES = (int)($_SERVER["ADMINMAP_MAX_STATE_BYTES"] ?? 15_000_000);
$MAX_STATE_BYTES = max(1_000_000, $MAX_STATE_BYTES);

// NOTE: payload also depends on web server / php.ini limits
// (client_max_body_size, post_max_size, upload_max_filesize).
$encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($encoded === false) {
  http_response_code(500);
  echo "Encode failed";
  exit;
}
if (strlen($encoded) > $MAX_STATE_BYTES) {
  http_response_code(413);
  echo "Payload too large (max {$MAX_STATE_BYTES} bytes)";
  exit;
}

$outPath = __DIR__ . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "map_state.json";
$tmpPath = $outPath . ".tmp";

if (file_put_contents($tmpPath, $encoded) === false) {
  http_response_code(500);
  echo "Write failed";
  exit;
}

if (!rename($tmpPath, $outPath)) {
  @unlink($tmpPath);
  http_response_code(500);
  echo "Rename failed";
  exit;
}

$provinceCards = $data["province_cards"] ?? null;
if (is_array($provinceCards)) {
  $provincesDir = __DIR__ . DIRECTORY_SEPARATOR . "provinces";
  if (!is_dir($provincesDir) && !mkdir($provincesDir, 0775, true) && !is_dir($provincesDir)) {
    http_response_code(500);
    echo "Cannot create provinces dir";
    exit;
  }
  foreach ($provinceCards as $pidRaw => $dataUrl) {
    $pid = (int)$pidRaw;
    if ($pid <= 0 || !is_string($dataUrl) || $dataUrl === "") continue;
    $img = decode_data_url_image($dataUrl);
    if ($img === null) continue;
    $name = sprintf("province_%04d.%s", $pid, $img["ext"]);
    $path = $provincesDir . DIRECTORY_SEPARATOR . $name;
    $tmp = $path . ".tmp";
    if (file_put_contents($tmp, $img["bytes"]) === false || !rename($tmp, $path)) {
      @unlink($tmp);
      http_response_code(500);
      echo "Failed to write province card";
      exit;
    }
  }
}

echo "OK";
