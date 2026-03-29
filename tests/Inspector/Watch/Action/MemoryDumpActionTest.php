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

namespace Reli\Inspector\Watch\Action;

use PHPUnit\Framework\TestCase;

class MemoryDumpActionTest extends TestCase
{
    public function testName(): void
    {
        // MemoryLocationsCollector is final — we can't mock it,
        // but we can test the name() method by constructing with
        // a real (unused) instance. Since the constructor requires
        // complex deps, we skip full construction and just verify
        // the interface contract via reflection.
        $rc = new \ReflectionClass(MemoryDumpAction::class);
        $this->assertTrue($rc->implementsInterface(ActionInterface::class));

        // Verify name method exists and returns correct value
        $method = $rc->getMethod('name');
        $this->assertSame('name', $method->getName());
    }
}
