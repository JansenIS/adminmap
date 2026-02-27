#!/usr/bin/env php
<?php

declare(strict_types=1);
require_once __DIR__ . '/../api/lib/state_api.php';

$once = in_array('--once', $argv, true);
$intervalMs = 1500;
foreach ($argv as $arg) {
  if (str_starts_with($arg, '--interval-ms=')) {
    $intervalMs = max(200, (int)substr($arg, strlen('--interval-ms=')));
  }
}

fwrite(STDOUT, "job_worker started\n");

$run = function (): int {
  $state = api_load_state();
  $res = api_run_next_job($state);
  if (!$res['ok']) {
    fwrite(STDERR, "job_worker error=" . ($res['error'] ?? 'unknown') . "\n");
    return 1;
  }
  if (!($res['processed'] ?? false)) {
    fwrite(STDOUT, "job_worker: no queued jobs\n");
    return 0;
  }
  $job = $res['job'] ?? [];
  fwrite(STDOUT, "job_worker: processed " . (string)($job['id'] ?? '-') . " status=" . (string)($job['status'] ?? '-') . "\n");
  return 0;
};

if ($once) {
  exit($run());
}

while (true) {
  $run();
  usleep($intervalMs * 1000);
}
