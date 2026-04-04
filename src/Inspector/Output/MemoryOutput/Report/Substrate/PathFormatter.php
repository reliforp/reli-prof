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

namespace Reli\Inspector\Output\MemoryOutput\Report\Substrate;

/**
 * Converts raw context paths to PHP-style syntax.
 *
 * Raw:  call_frames -> <main>:28 -> local_variables -> messages
 *       -> array_elements -> 0 -> value -> object_properties -> structure -> raw
 * PHP:  <main>:28::$messages[0]->structure->raw
 *
 * Structural intermediaries (call_frames, local_variables, object_properties,
 * array_elements, value) are collapsed. Variable names get $, array indices get [].
 */
final class PathFormatter
{
    /** Context types that are structural and should be skipped */
    private const STRUCTURAL = [
        'call_frames',
        'local_variables',
        'symbol_table',
        'object_properties',
        'array_elements',
        'value',
        'object_handlers',
        'dynamic_properties',
        'included_files',
        'interned_strings',
        'function_table',
        'class_table',
        'global_variables',
        'global_constants',
        'global_callbacks',
        'modules',
    ];

    /**
     * Format a raw path (from link_names) into PHP-style syntax.
     *
     * @param list<string> $parts resolved link_names (frame names already resolved)
     * @param list<string> $node_types context_nodes.type for each child node
     */
    public static function toPhpSyntax(
        array $parts,
        array $node_types = [],
    ): string {
        if ($parts === []) {
            return '(root)';
        }

        $segments = [];
        $prev_type = '';
        $in_variable_table = false;
        $in_global_variables = false;
        $in_object_props = false;
        $in_array_elements = false;

        for ($i = 0; $i < count($parts); $i++) {
            $part = $parts[$i];
            $type = $node_types[$i] ?? '';

            // Skip structural intermediaries
            if (in_array($part, self::STRUCTURAL, true)) {
                if ($part === 'local_variables' || $part === 'symbol_table') {
                    $in_variable_table = true;
                } elseif ($part === 'global_variables') {
                    $in_global_variables = true;
                } elseif ($part === 'object_properties') {
                    $in_object_props = true;
                } elseif ($part === 'array_elements') {
                    $in_array_elements = true;
                }
                $prev_type = $type;
                continue;
            }

            // Format based on context
            if ($in_global_variables && $in_array_elements && $part !== 'value') {
                // global_variables → array_elements → varname: treat as $varname
                $segments[] = '$' . $part;
                $in_global_variables = false;
                $in_array_elements = false;
                $prev_type = $type;
                continue;
            }
            if ($in_array_elements && $part !== 'value') {
                // Array index — append [key] to previous segment
                if ($segments !== []) {
                    $last = count($segments) - 1;
                    $segments[$last] .= "[{$part}]";
                } else {
                    $segments[] = "[{$part}]";
                }
                $in_array_elements = false;
                $prev_type = $type;
                continue;
            }

            if ($in_global_variables) {
                // Global variable — add $ prefix
                $segments[] = '$' . $part;
                $in_global_variables = false;
            } elseif ($in_variable_table) {
                // Local variable — add $ prefix
                $segments[] = '$' . $part;
                $in_variable_table = false;
            } elseif ($in_object_props) {
                // Object property — add -> prefix
                if ($segments !== []) {
                    $segments[] = '->' . $part;
                } else {
                    $segments[] = $part;
                }
                $in_object_props = false;
            } elseif (
                $type === 'CallFrameContext'
                || str_contains($part, ':')
                || $part === '<main>'
                || str_contains($part, '()')
            ) {
                // Call frame — use :: separator for the next segment
                $segments[] = $part . '::';
            } elseif (
                $type === 'ObjectContext'
                || $prev_type === 'ObjectContext'
            ) {
                // Object node — show class name
                if ($segments !== []) {
                    $segments[] = '->' . $part;
                } else {
                    $segments[] = $part;
                }
            } else {
                $segments[] = $part;
            }

            $in_array_elements = false;
            $prev_type = $type;
        }

        if ($segments === []) {
            return implode(' -> ', $parts);
        }

        // Join segments, collapsing :: with the next segment
        $result = '';
        foreach ($segments as $seg) {
            if (str_ends_with($result, '::')) {
                $result .= $seg;
            } elseif (
                str_starts_with($seg, '->')
                || str_starts_with($seg, '[')
            ) {
                $result .= $seg;
            } elseif ($result !== '') {
                $result .= '->' . $seg;
            } else {
                $result = $seg;
            }
        }

        return $result;
    }
}
