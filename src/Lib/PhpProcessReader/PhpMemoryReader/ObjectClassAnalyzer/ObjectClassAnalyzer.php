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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ObjectClassAnalyzer;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\Process\MemoryLocation;

final class ObjectClassAnalyzer
{
    public function analyze(MemoryLocations $memory_locations): ObjectClassAnalyzerResult
    {
        $per_class_usage = [];
        foreach ($memory_locations->memory_locations as $memory_location) {
            if ($memory_location instanceof ZendObjectMemoryLocation) {
                $class_name = $memory_location->class_name;
                assert(is_a($class_name, MemoryLocation::class, true));
                if (!isset($per_class_usage[$class_name])) {
                    $per_class_usage[$class_name] = [
                        'count' => 0,
                        'memory_usage' => 0,
                    ];
                }
                $per_class_usage[$class_name]['count']++;
                $per_class_usage[$class_name]['memory_usage'] += $memory_location->size;
            }
        }
        return new ObjectClassAnalyzerResult($per_class_usage);
    }
}
