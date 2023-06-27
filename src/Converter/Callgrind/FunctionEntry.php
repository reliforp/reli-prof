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

final class FunctionEntry
{
    /** @var array<int, int> Line number to number of samples map. */
    public array $lineno_samples = [];

    /** @var array<string, CallEntry> Callee name and caller lineno to call entry map. */
    public array $calls = [];

    public function __construct(
        public string $function_name,
        public string $file_name,
    ) {
    }

    public function addSample(int $lineno, ?FunctionEntry $callee): void
    {
        if ($callee === null) {
            $this->lineno_samples[$lineno] ??= 0;
            ++$this->lineno_samples[$lineno];
        } else {
            $key = $callee->function_name . '@' . $lineno;
            $this->calls[$key] ??= new CallEntry($callee, $lineno);
            ++$this->calls[$key]->samples;
        }
    }
}
