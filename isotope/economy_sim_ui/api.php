<?php

declare(strict_types=1);

require_once __DIR__ . '/php/simulator.php';

try {
    $sim = new EconomySimulator();
    $sim->ensureReady();

    $action = $_GET['action'] ?? 'summary';

    if ($action === 'meta') {
        respond_json(['ok' => true, 'data' => $sim->getMeta()]);
    }

    if ($action === 'summary') {
        respond_json(['ok' => true, 'data' => $sim->getSummary()]);
    }

    if ($action === 'tick' || $action === 'step') {
        $years = isset($_GET['years']) ? (int)$_GET['years'] : 1;
        respond_json(['ok' => true, 'data' => $sim->stepYear($years)]);
    }

    if ($action === 'province') {
        $pid = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
        $tier = isset($_GET['tier']) ? (string)$_GET['tier'] : 'all';
        $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'value';
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 80;
        $active = isset($_GET['active']) && (string)$_GET['active'] === '1';
        $report = $sim->provinceReport($pid, $tier, $sort, $limit, $active);
        if ($report === null) {
            respond_json(['ok' => false, 'error' => 'province_not_found'], 404);
        }
        respond_json(['ok' => true, 'data' => $report]);
    }

    if ($action === 'trade-balance') {
        respond_json(['ok' => true, 'data' => $sim->globalTradeBalance()]);
    }

    if ($action === 'snapshot') {
        respond_json(['ok' => true, 'data' => $sim->exportSnapshot()]);
    }


    if ($action === 'admin-provinces') {
        respond_json(['ok' => true, 'data' => $sim->adminProvinceList()]);
    }

    if ($action === 'admin-map') {
        respond_json(['ok' => true, 'data' => $sim->adminMapData()]);
    }

    if ($action === 'admin-province-save') {
        $pid = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
        $payloadRaw = file_get_contents('php://input');
        $payload = $payloadRaw ? json_decode($payloadRaw, true) : [];
        if (!is_array($payload)) {
            respond_json(['ok' => false, 'error' => 'invalid_payload'], 400);
        }
        respond_json(['ok' => true, 'data' => $sim->saveProvinceSettings($pid, $payload)]);
    }
    if ($action === 'reset') {
        $seed = isset($_GET['seed']) ? (int)$_GET['seed'] : null;
        $transport = isset($_GET['transportUnitCost']) ? (float)$_GET['transportUnitCost'] : null;
        $friction = isset($_GET['tradeFriction']) ? (float)$_GET['tradeFriction'] : null;
        respond_json(['ok' => true, 'data' => $sim->reset($seed, $transport, $friction)]);
    }

    respond_json(['ok' => false, 'error' => 'unknown_action'], 404);
} catch (Throwable $e) {
    respond_json(['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()], 500);
}
