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

use Reli\BaseTestCase;

class PathFormatterTest extends BaseTestCase
{
    public function testNumericArrayKeysRenderBare(): void
    {
        // `$rows[0]` — numeric keys stay unquoted because PHP arrays
        // auto-cast numeric-string keys to int slots.
        $parts = ['global_variables', 'array_elements', 'rows', 'array_elements', '0'];
        $types = ['', '', '', '', ''];

        $this->assertSame(
            '$rows[0]',
            PathFormatter::toPhpSyntax($parts, $types),
        );
    }

    public function testNegativeNumericArrayKeysRenderBare(): void
    {
        // PHP allows negative int keys (`$arr[-1] = "x"` works).
        $parts = ['global_variables', 'array_elements', 'cache', 'array_elements', '-1'];
        $types = array_fill(0, 5, '');

        $this->assertSame('$cache[-1]', PathFormatter::toPhpSyntax($parts, $types));
    }

    public function testStringArrayKeysAreSingleQuoted(): void
    {
        // T2.6: bareword inside `[ ]` reads as a constant reference
        // in PHP source. Path output should match what a developer
        // would actually type — `$decoded['data']`, not `$decoded[data]`.
        $parts = ['global_variables', 'array_elements', 'decoded', 'array_elements', 'data'];
        $types = array_fill(0, 5, '');

        $this->assertSame(
            "\$decoded['data']",
            PathFormatter::toPhpSyntax($parts, $types),
        );
    }

    public function testMixedNumericAndStringKeysQuoteOnlyTheStringOnes(): void
    {
        // `$rows[0]['metadata']['ref']` — the 0 stays bare, the two
        // string keys get quoted.
        $parts = [
            'global_variables', 'array_elements', 'rows',
            'array_elements', '0',
            'array_elements', 'metadata',
            'array_elements', 'ref',
        ];
        $types = array_fill(0, 9, '');

        $this->assertSame(
            "\$rows[0]['metadata']['ref']",
            PathFormatter::toPhpSyntax($parts, $types),
        );
    }

    public function testKeysWithDotsOrSpecialCharsGetQuoted(): void
    {
        // Keys like `request.completed` look enough like constants to
        // confuse a reader if rendered bare.
        $parts = [
            'global_variables', 'array_elements', 'listeners',
            'array_elements', 'request.completed',
        ];
        $types = array_fill(0, 5, '');

        $this->assertSame(
            "\$listeners['request.completed']",
            PathFormatter::toPhpSyntax($parts, $types),
        );
    }

    public function testKeyWithEmbeddedSingleQuoteIsEscaped(): void
    {
        // Single-quoted PHP literals escape `'` as `\'`. Rare in real
        // captures but the formatter shouldn't produce malformed
        // pseudo-PHP.
        $parts = [
            'global_variables', 'array_elements', 'msgs',
            'array_elements', "it's",
        ];
        $types = array_fill(0, 5, '');

        $this->assertSame(
            "\$msgs['it\\'s']",
            PathFormatter::toPhpSyntax($parts, $types),
        );
    }

    public function testKeyWithEmbeddedBackslashIsEscaped(): void
    {
        // `\` is the only other character a single-quoted PHP literal
        // needs to escape. Order of escape (`\` before `'`) matters:
        // a naive `'` → `\'` then `\` → `\\` would double-escape the
        // newly-added `\`.
        $parts = [
            'global_variables', 'array_elements', 'paths',
            'array_elements', 'a\\b',
        ];
        $types = array_fill(0, 5, '');

        $this->assertSame(
            "\$paths['a\\\\b']",
            PathFormatter::toPhpSyntax($parts, $types),
        );
    }

    public function testNumericLookingStringKeyIsTreatedAsNumeric(): void
    {
        // Documented edge case from the T2.6 spec: keys like '01' or
        // '0e123' are stored as PHP strings at runtime (PHP doesn't
        // canonicalise these), but the formatter's simple `^-?\d+$`
        // check treats them as numeric. Common-case heuristic;
        // strings of all-digits in real captures are almost always
        // semantically integer.
        $parts = [
            'global_variables', 'array_elements', 'arr',
            'array_elements', '01',
        ];
        $types = array_fill(0, 5, '');

        $this->assertSame('$arr[01]', PathFormatter::toPhpSyntax($parts, $types));
    }
}
