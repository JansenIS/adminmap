<?php

declare(strict_types=1);

function economy_root_path(): string
{
    return dirname(__DIR__);
}

function project_root_path(): string
{
    return dirname(__DIR__, 3);
}

function storage_path(string $file = ''): string
{
    $base = economy_root_path() . '/storage';
    if (!is_dir($base)) {
        mkdir($base, 0775, true);
    }

    return $file === '' ? $base : $base . '/' . $file;
}

function data_files(): array
{
    return [
        'routing' => project_root_path() . '/isotope/province_routing_data.json',
        'map_state' => project_root_path() . '/data/map_state.json',
        'hexmap' => project_root_path() . '/hexmap/data.js',
    ];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . storage_path('economy.sqlite'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    return $pdo;
}

function respond_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
