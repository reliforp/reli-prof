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
    private const NAMESPACE_PREFIX = 'Reli\\Lib\\PhpProcessReader\\PhpMemoryReader\\MemoryLocation\\';
    private const NAMESPACE_PREFIX_LEN = 62; // strlen of NAMESPACE_PREFIX

    /** @var array<class-string, string> */
    private array $type_name_cache = [];

    public function analyze(
        MemoryLocations $memory_locations,
    ): LocationTypeAnalyzerResult {
        $per_type_analysis = [];
        foreach ($memory_locations->memory_locations as $memory_location) {
            $class = $memory_location::class;
            $type = $this->type_name_cache[$class]
                ??= substr($class, self::NAMESPACE_PREFIX_LEN);
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
