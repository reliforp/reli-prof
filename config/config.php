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

return [
    'reader' => [
        'collect_data' => [
            'class' => true,
            'function' => true,
            'file' => true,
            'line' => true,
            'opcode' => false,
        ],
    ],
    'output' => [
        'format' => [
            'template' => 'phpspy'
            // 'template' => 'json_lines'
        ],
    ],
];
