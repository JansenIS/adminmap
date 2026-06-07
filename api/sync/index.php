<?php declare(strict_types=1); require_once dirname(__DIR__) . '/lib/sync_api.php'; sync_endpoint_boot(); sync_error('entity_not_found','Sync endpoint not found',404);
