<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/puff
 * https://github.com/php-puff/puff/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

return [
    'addr' => '127.0.0.1:8791',
    'routes' => \dirname(__DIR__, 2) . '/app/websocket.php',
    'workers' => 1,
    'max_message_size' => 2 * 1024 * 1024,
    'max_handshake_size' => 16 * 1024,
    'max_headers' => 100,
    'max_pending_messages' => 128,
    'allowed_origins' => [],
    'protocols' => [],
];
