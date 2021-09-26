<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

use Psr\Log\LogLevel;

return [
    'log' => [
        'path' => [
            'default' => 'php-profiler.log',
        ],
        'level' => LogLevel::INFO,
    ],
    'paths' => [
        'templates' => __DIR__ . '/../resources/templates',
    ],
    'output' => [
        'template' => [
            'default' => 'phpspy',
            // 'default' => 'phpspy_with_opcode'
            // 'default' => 'compat'
            // 'default' => 'json_lines'
        ],
    ],
];
