<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit; // CORS preflight
}

require_once __DIR__ . '/db.php';
$pdo = get_db();

$path = trim($_SERVER['PATH_INFO'] ?? '', '/');
$parts = $path === '' ? [] : explode('/', $path);

if ($parts === ['provinces'] && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query('SELECT id, name, owner, color, population, resources, description FROM provinces');
    $rows = $stmt->fetchAll();
    echo json_encode($rows);
    exit;
}

if ($parts[0] === 'provinces' && isset($parts[1])) {
    $id = (int)$parts[1];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare('SELECT id, name, owner, color, population, resources, description FROM provinces WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            echo json_encode($row);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
        }
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            exit;
        }
        $stmt = $pdo->prepare('UPDATE provinces SET name = ?, owner = ?, color = ?, population = ?, resources = ?, description = ? WHERE id = ?');
        $stmt->execute([
            $input['name'] ?? null,
            $input['owner'] ?? null,
            $input['color'] ?? null,
            $input['population'] ?? 0,
            $input['resources'] ?? null,
            $input['description'] ?? null,
            $id
        ]);
        echo json_encode(['success' => true]);
        exit;
    }
}

http_response_code(404);
echo json_encode(['error' => 'Not found']);
?>
