<?php

/**
 * This file is part of the reliforp/reli-prof package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\LocationTypeAnalyzer;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\Process\MemoryLocation;

final class LocationTypeAnalyzer
{
    public function analyze(
        MemoryLocations $memory_locations,
    ): LocationTypeAnalyzerResult {
        $per_type_analysis = [];
        foreach ($memory_locations->memory_locations as $memory_location) {
            $type = str_replace(
                'Reli\\Lib\\PhpProcessReader\\PhpMemoryReader\\MemoryLocation\\',
                '',
                $memory_location::class,
            );
            if (!isset($per_type_analysis[$type])) {
                $per_type_analysis[$type] = [
                    'count' => 0,
                    'memory_usage' => 0,
                ];
            }
            $per_type_analysis[$type]['count']++;
            $per_type_analysis[$type]['memory_usage'] += $memory_location->size;
        }

        uasort(
            $per_type_analysis,
            fn (array $a, array $b) => $b['memory_usage'] <=> $a['memory_usage']
        );

        return new LocationTypeAnalyzerResult(
            $per_type_analysis,
        );
    }
}
