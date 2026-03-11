#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/telegraph/bootstrap.php';

$once = in_array('--once', $argv, true);
$intervalMs = 30000;
$limit = 50;
$lockPath = api_repo_root() . '/data/telegraph/relay_daemon.lock';
foreach ($argv as $arg) {
  if (str_starts_with($arg, '--interval-ms=')) $intervalMs = max(500, (int)substr($arg, strlen('--interval-ms=')));
  if (str_starts_with($arg, '--limit=')) $limit = max(1, min(200, (int)substr($arg, strlen('--limit='))));
}

telegraph_ensure_store();
$lockFp = fopen($lockPath, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
  fwrite(STDERR, "telegraph_relay_daemon: another instance is running\n");
  exit(1);
}

$running = true;
if (function_exists('pcntl_async_signals')) {
  pcntl_async_signals(true);
  pcntl_signal(SIGTERM, static function() use (&$running): void { $running = false; });
  pcntl_signal(SIGINT, static function() use (&$running): void { $running = false; });
}

fwrite(STDOUT, "telegraph_relay_daemon started\n");

$run = static function() use ($limit): int {
  $settings = telegraph_load_settings_store();
  if (!(bool)($settings['relay_enabled'] ?? true)) {
    fwrite(STDOUT, "telegraph_relay_daemon: relay disabled in settings\n");
    return 0;
  }
  $channels = telegraph_collect_enabled_relay_channels();
  if (empty($channels)) {
    fwrite(STDOUT, "telegraph_relay_daemon: no enabled relay channels\n");
    return 0;
  }

  $store = telegraph_load_messages_store();
  $res = telegraph_process_relay_queue($store, $channels, $limit, null);
  telegraph_save_messages_store($res['store']);
  $processed = is_array($res['processed'] ?? null) ? $res['processed'] : [];
  fwrite(STDOUT, "telegraph_relay_daemon: processed=" . count($processed) . " channels=" . count($channels) . "\n");
  return 0;
};

if ($once) {
  $code = $run();
  flock($lockFp, LOCK_UN);
  fclose($lockFp);
  exit($code);
}

while ($running) {
  $run();
  usleep($intervalMs * 1000);
}

fwrite(STDOUT, "telegraph_relay_daemon stopped\n");
flock($lockFp, LOCK_UN);
fclose($lockFp);
exit(0);
