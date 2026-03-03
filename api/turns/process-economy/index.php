<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/turn_api.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  turn_api_response(['error' => 'method_not_allowed', 'allowed' => ['POST']], 405);
}

$payload = turn_api_request_payload();

function sim_row_realm_entity_id(array $row): string {
  $free = trim((string)($row['free_city_id'] ?? ''));
  if ($free !== '') return 'free_cities:' . $free;
  $minor = trim((string)($row['minor_house_id'] ?? ''));
  if ($minor !== '') return 'minor_houses:' . $minor;
  $great = trim((string)($row['great_house_id'] ?? ''));
  if ($great !== '') return 'great_houses:' . $great;
  $king = trim((string)($row['kingdom_id'] ?? ''));
  if ($king !== '') return 'kingdoms:' . $king;
  return '';
}

function sim_build_computed_from_payload(array $turn, array $sim): array {
  $year = (int)($turn['year'] ?? 0);
  $preRows = [];
  foreach (($sim['pre']['provinces'] ?? []) as $row) {
    if (!is_array($row)) continue;
    $pid = (int)($row['pid'] ?? 0);
    if ($pid <= 0) continue;
    $preRows[$pid] = $row;
  }

  $postRows = [];
  foreach (($sim['post']['provinces'] ?? []) as $row) {
    if (!is_array($row)) continue;
    $pid = (int)($row['pid'] ?? 0);
    if ($pid <= 0) continue;
    $postRows[$pid] = $row;
  }

  $provinceRows = [];
  $entityAcc = [];
  $economyState = [];

  foreach ($postRows as $pid => $post) {
    $pre = $preRows[$pid] ?? [];
    $opening = round((float)($pre['treasury'] ?? 0.0), 2);
    $closing = round((float)($post['treasury'] ?? 0.0), 2);
    $delta = round($closing - $opening, 2);
    $income = $delta >= 0 ? $delta : 0.0;
    $expense = $delta < 0 ? abs($delta) : 0.0;

    $provinceRows[] = [
      'turn_year' => $year,
      'province_pid' => $pid,
      'province_name' => (string)($post['name'] ?? ('PID ' . $pid)),
      'owner_name' => '',
      'opening_balance' => $opening,
      'income' => $income,
      'expense' => $expense,
      'tax_paid_to_entity' => 0.0,
      'reserve_add' => 0.0,
      'closing_balance' => $closing,
      'terrain' => (string)($post['terrain'] ?? ''),
    ];

    $economyState[] = [
      'turn_year' => $year,
      'province_pid' => $pid,
      'income' => round((float)($post['effectiveGDP'] ?? $post['gdpTurnover'] ?? 0.0), 2),
      'expense' => round((float)($post['treasuryExpenseYear'] ?? 0.0), 2),
      'balance_delta' => round((float)($post['treasuryNetYear'] ?? 0.0), 2),
      'modifiers' => [
        'source' => 'economy_sim',
        'market_mode' => (string)($post['marketMode'] ?? 'normal'),
      ],
    ];

    $eid = sim_row_realm_entity_id($post);
    if ($eid === '') continue;
    if (!isset($entityAcc[$eid])) {
      $entityAcc[$eid] = [
        'turn_year' => $year,
        'entity_id' => $eid,
        'entity_name' => $eid,
        'opening_balance' => 0.0,
        'income_tax' => 0.0,
        'subsidies_out' => 0.0,
        'transfers_in' => 0.0,
        'transfers_out' => 0.0,
        'closing_balance' => 0.0,
      ];
    }
    $entityAcc[$eid]['opening_balance'] = round($entityAcc[$eid]['opening_balance'] + $opening, 2);
    $entityAcc[$eid]['closing_balance'] = round($entityAcc[$eid]['closing_balance'] + $closing, 2);
  }

  $entityRows = array_values($entityAcc);
  usort($provinceRows, static fn($a, $b) => ((int)$a['province_pid'] <=> (int)$b['province_pid']));
  usort($entityRows, static fn($a, $b) => strcmp((string)$a['entity_id'], (string)$b['entity_id']));

  $entityState = array_map(static function($row) use ($year) {
    return [
      'turn_year' => $year,
      'entity_id' => (string)$row['entity_id'],
      'entity_name' => (string)$row['entity_name'],
      'entity_type' => explode(':', (string)$row['entity_id'])[0] ?? 'unknown',
      'ruler' => '',
      'provinces_owned' => 0,
      'treasury_main' => round((float)($row['opening_balance'] ?? 0.0), 2),
      'treasury_reserve' => 0.0,
    ];
  }, $entityRows);

  $ledger = [];
  $economy = [
    'status' => 'processed',
    'checkpoint' => 'checkpoint:economy_applied',
    'records' => count($economyState),
    'income_total' => round(array_sum(array_map(static fn($r)=>(float)($r['income'] ?? 0.0), $economyState)), 2),
    'expense_total' => round(array_sum(array_map(static fn($r)=>(float)($r['expense'] ?? 0.0), $economyState)), 2),
    'balance_delta' => round(array_sum(array_map(static fn($r)=>(float)($r['balance_delta'] ?? 0.0), $economyState)), 2),
    'checksum' => hash('sha256', json_encode($economyState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    'source' => 'economy_sim',
    'sim_day' => (int)($sim['summary']['day'] ?? 0),
    'sim_year' => (int)($sim['summary']['year'] ?? 0),
    'sim_step_years' => (int)($sim['years'] ?? 1),
  ];

  return [
    'entity_state' => $entityState,
    'economy_state' => $economyState,
    'economy' => $economy,
    'entity_treasury_rows' => $entityRows,
    'province_treasury_rows' => $provinceRows,
    'ledger_rows' => $ledger,
    'treasury_summary' => [
      'entity_records' => count($entityRows),
      'province_records' => count($provinceRows),
      'ledger_records' => 0,
      'entity_treasury_checksum' => hash('sha256', json_encode($entityRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
      'province_treasury_checksum' => hash('sha256', json_encode($provinceRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
      'ledger_checksum' => hash('sha256', json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
      'ruleset_version' => (string)($turn['ruleset_version'] ?? ''),
      'source' => 'economy_sim',
    ],
  ];
}

$year = (int)($payload['turn_year'] ?? 0);
if ($year <= 0) {
  turn_api_response(['error' => 'invalid_payload', 'required' => ['turn_year:int>0']], 400);
}

$turn = turn_api_load_turn($year);
if (!is_array($turn)) {
  turn_api_response(['error' => 'turn_not_found', 'year' => $year], 404);
}

$ifMatch = turn_api_if_match($turn, $payload, true);
if (!$ifMatch['ok']) {
  $status = (($ifMatch['error'] ?? '') === 'if_match_required') ? 428 : 412;
  turn_api_response(['error' => $ifMatch['error'], 'expected_version' => $ifMatch['expected'], 'provided_if_match' => $ifMatch['provided']], $status);
}

$turn['status'] = 'processing';
$economySource = (string)($payload['economy_source'] ?? 'turn_engine');
if ($economySource === 'economy_sim' && is_array($payload['economy_sim'] ?? null)) {
  $computed = sim_build_computed_from_payload($turn, (array)$payload['economy_sim']);
  $turn['economy_external_payload'] = [
    'source' => 'economy_sim',
    'entity_state' => $computed['entity_state'],
    'economy_state' => $computed['economy_state'],
    'entity_treasury' => $computed['entity_treasury_rows'],
    'province_treasury' => $computed['province_treasury_rows'],
    'treasury_ledger' => $computed['ledger_rows'],
  ];
} else {
  $computed = turn_api_compute_economy_for_turn($turn);
  unset($turn['economy_external_payload']);
}
$turn['economy'] = $computed['economy'];
$turn['entity_state'] = [
  'status' => 'processed',
  'records' => count($computed['entity_state']),
  'checksum' => hash('sha256', json_encode($computed['entity_state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
];
$turn['economy_state'] = [
  'status' => 'processed',
  'records' => count($computed['economy_state']),
  'checksum' => hash('sha256', json_encode($computed['economy_state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
];

$turn['entity_treasury'] = [
  'status' => 'processed',
  'records' => (int)($computed['treasury_summary']['entity_records'] ?? 0),
  'checksum' => (string)($computed['treasury_summary']['entity_treasury_checksum'] ?? ''),
];
$turn['province_treasury'] = [
  'status' => 'processed',
  'records' => (int)($computed['treasury_summary']['province_records'] ?? 0),
  'checksum' => (string)($computed['treasury_summary']['province_treasury_checksum'] ?? ''),
];
$turn['treasury_ledger'] = [
  'status' => 'processed',
  'records' => (int)($computed['treasury_summary']['ledger_records'] ?? 0),
  'checksum' => (string)($computed['treasury_summary']['ledger_checksum'] ?? ''),
];
$turn['events'][] = [
  'category' => 'economy',
  'event_type' => 'economy_processed',
  'payload' => ['turn_year' => $year, 'records' => $turn['economy']['records'] ?? 0],
  'occurred_at' => gmdate('c'),
];

if (!turn_api_save_turn($turn)) {
  turn_api_response(['error' => 'write_failed'], 500);
}

$saved = turn_api_load_turn($year) ?? $turn;
turn_api_response([
  'result' => [
    'turn_year' => $year,
    'status' => $saved['status'] ?? 'processing',
    'economy_checkpoint' => $saved['economy']['checkpoint'] ?? null,
  ],
  'turn' => [
    'year' => $year,
    'status' => $saved['status'] ?? 'processing',
    'version' => $saved['version'] ?? turn_api_turn_version($saved),
  ],
  'entity_state' => $saved['entity_state'] ?? null,
  'economy_state' => $saved['economy_state'] ?? null,
  'entity_treasury' => $saved['entity_treasury'] ?? null,
  'province_treasury' => $saved['province_treasury'] ?? null,
  'treasury_ledger' => $saved['treasury_ledger'] ?? null,
], 200);
