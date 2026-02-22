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

// Basic sanity limit: prevent huge writes by mistake
$encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($encoded === false) {
  http_response_code(500);
  echo "Encode failed";
  exit;
}
if (strlen($encoded) > 5_000_000) {
  http_response_code(413);
  echo "Payload too large";
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

echo "OK";
