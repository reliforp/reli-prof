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
 * Pins the `#count` attribute on {@see VmStackContext} to the
 * constructor-supplied value. The arena children are emitted via
 * `$ctx->emitNode($arena_context, $vm_stack_parent, ...)` rather than
 * `$vm_stack_context->add(...)`, so the trait-backed
 * `$referencing_contexts` slot stays empty and a
 * `count($this->referencing_contexts)` here would always read zero.
 */
class VmStackContextTest extends TestCase
{
    public function testDefaultCountIsZero(): void
    {
        $context = new VmStackContext();
        $this->assertSame(
            ['#count' => 0],
            iterator_to_array((function (iterable $iterable): \Generator {
                yield from $iterable;
            })($context->getContexts())),
        );
    }

    public function testCountReflectsConstructorArg(): void
    {
        $context = new VmStackContext(arena_count: 192);
        $attrs = (function (iterable $i): array {
            $out = [];
            foreach ($i as $k => $v) {
                $out[$k] = $v;
            }
            return $out;
        })($context->getContexts());

        $this->assertSame(192, $attrs['#count'] ?? null);
    }

    public function testCountIsIndependentOfAddedReferences(): void
    {
        // Even if a caller wires children through the trait's add()
        // API (production code does NOT — it uses emitNode against the
        // sink directly), the attribute should still come from the
        // constructor argument, since that is the authoritative count
        // collectAll() recorded.
        $context = new VmStackContext(arena_count: 3);
        $stub = $this->createStub(ReferenceContext::class);
        $context->add('0', $stub);
        $context->add('1', $stub);

        $attrs = iterator_to_array((function (iterable $i): \Generator {
            yield from $i;
        })($context->getContexts()));
        $this->assertSame(3, $attrs['#count']);
    }
}
