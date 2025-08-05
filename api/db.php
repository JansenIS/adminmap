<?php
function get_db() {
    static $pdo;
    if ($pdo) {
        return $pdo;
    }
    $dsn = 'mysql:host=localhost;dbname=adminmap;charset=utf8mb4';
    $user = 'username'; // replace with your DB user
    $pass = 'password'; // replace with your DB password
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ];
    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'DB connection failed']);
        exit;
    }
    return $pdo;
}
?>
