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

namespace Reli\Inspector\Watch;

use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\Process\ProcessSpecifier;

/**
 * Reads PHP variable values from a target process.
 *
 * Extracted from the concrete `VariableReader` so that collaborators
 * (watch triggers, trace-var peek, etc.) can depend on a narrow,
 * mock-friendly surface.
 */
interface VariableReaderInterface
{
    /**
     * @param list<VariableSpec> $specs
     * @param TargetPhpSettings<'v70'|'v71'|'v72'|'v73'|'v74'|'v80'|'v81'|'v82'|'v83'|'v84'|'v85'> $target_php_settings
     * @return array<string, VariableValue> keyed by `VariableSpec::$lookup_key`;
     *     specs that could not be resolved are omitted rather than being
     *     returned as a null or placeholder value.
     */
    public function readVariables(
        array $specs,
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
        int $cg_address = 0,
    ): array;
}
