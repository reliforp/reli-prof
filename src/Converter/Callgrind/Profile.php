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

namespace Reli\Converter\Callgrind;

use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;

final class Profile
{
    /** @var array<string, FunctionEntry> */
    public array $functions = [];

    public function addTrace(ParsedCallTrace $trace): void
    {
        $calleeEntry = null;
        foreach ($trace->call_frames as $frame) {
            $entry = $this->getFunctionEntry($frame->function_name, $frame->file_name);
            $entry->addSample($frame->lineno, $calleeEntry);
            $calleeEntry = $entry;
        }
    }

    private function getFunctionEntry(string $function_name, string $file_name): FunctionEntry
    {
        $this->functions[$function_name] ??= new FunctionEntry($function_name, $file_name);
        return $this->functions[$function_name];
    }
}
