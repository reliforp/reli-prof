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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext;

use PHPUnit\Framework\TestCase;

/**
 * Pins the `#count` attribute on {@see LargeRunSlackContext} to the
 * constructor-supplied value. Slack locations are attached via
 * `addLocation()` rather than `add()`, so the trait-backed
 * `$referencing_contexts` slot stays empty and a
 * `count($this->referencing_contexts)` here would always read zero
 * (same shape as {@see VmStackContextTest}).
 */
class LargeRunSlackContextTest extends TestCase
{
    public function testDefaultCountIsZero(): void
    {
        $context = new LargeRunSlackContext();
        $attrs = $this->materialize($context->getContexts());
        $this->assertSame(0, $attrs['#count'] ?? null);
    }

    public function testCountReflectsConstructorArg(): void
    {
        $context = new LargeRunSlackContext(run_count: 108);
        $attrs = $this->materialize($context->getContexts());
        $this->assertSame(108, $attrs['#count'] ?? null);
    }

    public function testCountIsIndependentOfAddedReferences(): void
    {
        // Production code does NOT add child contexts here — it
        // attaches the slack as MemoryLocations via addLocation().
        // Even if a caller wires children through the trait's add()
        // API, the attribute should still come from the constructor
        // argument.
        $context = new LargeRunSlackContext(run_count: 3);
        $stub = $this->createStub(ReferenceContext::class);
        $context->add('0', $stub);
        $context->add('1', $stub);

        $attrs = $this->materialize($context->getContexts());
        $this->assertSame(3, $attrs['#count']);
    }

    /** @return array<string, mixed> */
    private function materialize(iterable $iterable): array
    {
        $out = [];
        foreach ($iterable as $k => $v) {
            $out[$k] = $v;
        }
        return $out;
    }
}
