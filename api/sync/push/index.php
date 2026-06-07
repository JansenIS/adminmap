<?php declare(strict_types=1); require_once dirname(__DIR__, 2) . '/lib/sync_api.php'; sync_endpoint_boot(); sync_require_method(['POST']); sync_json(sync_push(sync_read_body()));
