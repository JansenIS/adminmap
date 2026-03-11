<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

telegraph_require_feature();
$state = api_load_state();
$actor = telegraph_actor_from_request($state);
$store = telegraph_load_messages_store();
telegraph_response(['ok' => true, 'counts' => telegraph_unread_counts($store['messages'], $actor), 'actor' => $actor]);
