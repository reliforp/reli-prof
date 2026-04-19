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

namespace Reli\Inspector\MemoryDump\FastPath;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ZvalTypeTest extends TestCase
{
    // --- Constants match PHP's zend_types.h values ---

    #[Test]
    public function constantsHaveExpectedValues(): void
    {
        $this->assertSame(0, ZvalType::IS_UNDEF);
        $this->assertSame(1, ZvalType::IS_NULL);
        $this->assertSame(2, ZvalType::IS_FALSE);
        $this->assertSame(3, ZvalType::IS_TRUE);
        $this->assertSame(4, ZvalType::IS_LONG);
        $this->assertSame(5, ZvalType::IS_DOUBLE);
        $this->assertSame(6, ZvalType::IS_STRING);
        $this->assertSame(7, ZvalType::IS_ARRAY);
        $this->assertSame(8, ZvalType::IS_OBJECT);
        $this->assertSame(9, ZvalType::IS_RESOURCE);
        $this->assertSame(10, ZvalType::IS_REFERENCE);
        $this->assertSame(12, ZvalType::IS_INDIRECT);
    }

    // --- isHeapType ---

    /** @return list<array{int, bool}> */
    public static function heapTypeProvider(): array
    {
        return [
            // Non-heap types (< IS_STRING or IS_INDIRECT)
            [ZvalType::IS_UNDEF, false],
            [ZvalType::IS_NULL, false],
            [ZvalType::IS_FALSE, false],
            [ZvalType::IS_TRUE, false],
            [ZvalType::IS_LONG, false],
            [ZvalType::IS_DOUBLE, false],
            [ZvalType::IS_INDIRECT, false],

            // Heap types (>= IS_STRING and not IS_INDIRECT)
            [ZvalType::IS_STRING, true],
            [ZvalType::IS_ARRAY, true],
            [ZvalType::IS_OBJECT, true],
            [ZvalType::IS_RESOURCE, true],
            [ZvalType::IS_REFERENCE, true],

            // Type 11 (between REFERENCE and INDIRECT) should be heap
            [11, true],

            // Types beyond INDIRECT should also be heap
            [13, true],
            [15, true],
        ];
    }

    #[Test]
    #[DataProvider('heapTypeProvider')]
    public function isHeapTypeClassifiesCorrectly(int $type, bool $expected): void
    {
        $this->assertSame($expected, ZvalType::isHeapType($type));
    }

    // --- toName ---

    /** @return list<array{int, string}> */
    public static function typeNameProvider(): array
    {
        return [
            [ZvalType::IS_UNDEF, 'IS_UNDEF'],
            [ZvalType::IS_NULL, 'IS_NULL'],
            [ZvalType::IS_FALSE, 'IS_FALSE'],
            [ZvalType::IS_TRUE, 'IS_TRUE'],
            [ZvalType::IS_LONG, 'IS_LONG'],
            [ZvalType::IS_DOUBLE, 'IS_DOUBLE'],
            [ZvalType::IS_STRING, 'IS_STRING'],
            [ZvalType::IS_ARRAY, 'IS_ARRAY'],
            [ZvalType::IS_OBJECT, 'IS_OBJECT'],
            [ZvalType::IS_RESOURCE, 'IS_RESOURCE'],
            [ZvalType::IS_REFERENCE, 'IS_REFERENCE'],
            [ZvalType::IS_INDIRECT, 'IS_INDIRECT'],
        ];
    }

    #[Test]
    #[DataProvider('typeNameProvider')]
    public function toNameReturnsExpectedLabel(int $type, string $expected): void
    {
        $this->assertSame($expected, ZvalType::toName($type));
    }

    #[Test]
    public function toNameReturnsUnknownForUndefinedType(): void
    {
        $this->assertSame('UNKNOWN(99)', ZvalType::toName(99));
    }

    #[Test]
    public function toNameReturnsUnknownForGapType11(): void
    {
        // Type 11 is not defined in PHP's zend_types.h
        $this->assertSame('UNKNOWN(11)', ZvalType::toName(11));
    }

    #[Test]
    public function toNameReturnsUnknownForNegativeType(): void
    {
        $this->assertSame('UNKNOWN(-1)', ZvalType::toName(-1));
    }
}
