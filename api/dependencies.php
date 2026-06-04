<?php

/**
 * PHP-DI container definitions.
 *
 * Autowiring (enabled in index.php) resolves every concrete class — repositories,
 * services, controllers — directly from its constructor type-hints. The only
 * thing that can't be autowired is PDO, since it comes from the connection
 * singleton rather than being constructed, so that's all we declare here.
 */

use Tarot\Database\Connection;

use function DI\factory;

return [
    // The shared PDO handle backs the entire Data layer.
    PDO::class => factory([Connection::class, 'getInstance']),
];
