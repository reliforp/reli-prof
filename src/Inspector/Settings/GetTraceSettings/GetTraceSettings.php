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

namespace Reli\Inspector\Settings\GetTraceSettings;

use Reli\Inspector\Watch\VariableSpec;

final class GetTraceSettings
{
    public const BULK_STACK_COPY_DEFAULT_MAX_SIZE = 65536;

    /**
     * @param int|null $bulk_stack_copy_max_size null = disabled, int = max bytes to prefetch
     * @param list<VariableSpec> $var_specs list of variables to peek per sample (empty = disabled)
     * @param int $var_every read variables only every N-th sample (1 = every sample)
     * @param string|null $var_on_function skip variable reads when this FQN is not on the stack
     */
    public function __construct(
        public int $depth,
        public bool $with_native_trace = false,
        public bool $native_trace_anytime = false,
        public ?int $bulk_stack_copy_max_size = null,
        public array $var_specs = [],
        public int $var_every = 1,
        public ?string $var_on_function = null,
    ) {
    }

    /**
     * True if any var-peek spec targets a scope that requires the CG address
     * (class static property or function static variable).
     */
    public function needsCompilerGlobals(): bool
    {
        foreach ($this->var_specs as $spec) {
            if ($spec->scope === 'static' || $spec->scope === 'func_static') {
                return true;
            }
        }
        return false;
    }
}
