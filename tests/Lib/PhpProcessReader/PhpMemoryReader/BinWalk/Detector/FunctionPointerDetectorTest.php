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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector;

use Reli\BaseTestCase;

class FunctionPointerDetectorTest extends BaseTestCase
{
    public function testReturnsNullWithoutContext(): void
    {
        $detector = new FunctionPointerDetector();
        $fp = pack('P', 0x00007ffe1234c000) . str_repeat("\x00", 24);
        $this->assertNull($detector->detect($fp, 32));
    }

    public function testReturnsNullWithoutModuleResolver(): void
    {
        $detector = new FunctionPointerDetector();
        $fp = pack('P', 0x00007ffe1234c000) . str_repeat("\x00", 24);
        $ctx = new DetectorContext(module_resolver: null);
        $this->assertNull($detector->detect($fp, 32, $ctx));
    }

    public function testReturnsNullForNonExecutablePointer(): void
    {
        $detector = new FunctionPointerDetector();
        $fp = pack('P', 0x00007ffe1234c000) . str_repeat("\x00", 24);
        $ctx = new DetectorContext(
            module_resolver: $this->fakeResolver(executable: false, module: null),
        );
        $this->assertNull($detector->detect($fp, 32, $ctx));
    }

    public function testReturnsHighWhenInsideNamedExecutableModule(): void
    {
        $detector = new FunctionPointerDetector();
        $fp = pack('P', 0x00007ffe1234c000) . str_repeat("\x00", 24);
        $ctx = new DetectorContext(
            module_resolver: $this->fakeResolver(executable: true, module: 'libuv.so.1'),
        );
        $hit = $detector->detect($fp, 32, $ctx);
        $this->assertNotNull($hit);
        $this->assertSame(ShapeDetection::CONFIDENCE_HIGH, $hit->confidence);
        $this->assertStringContainsString('libuv.so.1', $hit->label);
    }

    public function testReturnsMediumForAnonExecutableMapping(): void
    {
        $detector = new FunctionPointerDetector();
        $fp = pack('P', 0x00007ffe1234c000) . str_repeat("\x00", 24);
        $ctx = new DetectorContext(
            module_resolver: $this->fakeResolver(executable: true, module: null),
        );
        $hit = $detector->detect($fp, 32, $ctx);
        $this->assertNotNull($hit);
        $this->assertSame(ShapeDetection::CONFIDENCE_MEDIUM, $hit->confidence);
        $this->assertStringContainsString('anon-exec', $hit->label);
    }

    public function testRejectsBelowTextMin(): void
    {
        $detector = new FunctionPointerDetector();
        // 0x42 isn't a userspace pointer at all — short-circuit before
        // even consulting the resolver.
        $fp = pack('P', 0x42) . str_repeat("\x00", 24);
        $ctx = new DetectorContext(
            module_resolver: $this->fakeResolver(executable: true, module: 'libuv.so.1'),
        );
        $this->assertNull($detector->detect($fp, 32, $ctx));
    }

    public function testRejectsAboveTextMax(): void
    {
        $detector = new FunctionPointerDetector();
        $fp = pack('P', 0x800000000000) . str_repeat("\x00", 24);
        $ctx = new DetectorContext(
            module_resolver: $this->fakeResolver(executable: true, module: 'libuv.so.1'),
        );
        $this->assertNull($detector->detect($fp, 32, $ctx));
    }

    public function testSymbolLevelPromotionWithOffset(): void
    {
        // The validator's "is this libphp or libuv?" question — when
        // both module + symbol resolver land hits, the label carries
        // module:symbol+offset so a single line of compare output
        // resolves the leak source.
        $detector = new FunctionPointerDetector();
        $fp = pack('P', 0x00007ffe1234c123) . str_repeat("\x00", 24);
        $ctx = new DetectorContext(
            module_resolver: $this->fakeResolver(executable: true, module: 'libphp.so'),
            symbol_resolver: $this->fakeSymbolResolver('execute_ex', 0x123),
        );
        $hit = $detector->detect($fp, 32, $ctx);
        $this->assertNotNull($hit);
        $this->assertSame('func_ptr (libphp.so:execute_ex+0x123)', $hit->label);
        $this->assertSame(ShapeDetection::CONFIDENCE_HIGH, $hit->confidence);
    }

    public function testSymbolLevelPromotionExactSymbolStart(): void
    {
        // Offset 0 is rendered without the +0xN suffix.
        $detector = new FunctionPointerDetector();
        $fp = pack('P', 0x00007ffe1234c000) . str_repeat("\x00", 24);
        $ctx = new DetectorContext(
            module_resolver: $this->fakeResolver(executable: true, module: 'libphp.so'),
            symbol_resolver: $this->fakeSymbolResolver('zend_objects_store_del_ref', 0),
        );
        $hit = $detector->detect($fp, 32, $ctx);
        $this->assertNotNull($hit);
        $this->assertSame('func_ptr (libphp.so:zend_objects_store_del_ref)', $hit->label);
    }

    public function testFallsBackToModuleLabelWhenSymbolMisses(): void
    {
        // When the symbol resolver returns null (anonymous JIT trampoline
        // landing inside a known module, or a binary with stripped
        // symbols), the label stays at module-level.
        $detector = new FunctionPointerDetector();
        $fp = pack('P', 0x00007ffe1234c000) . str_repeat("\x00", 24);
        $ctx = new DetectorContext(
            module_resolver: $this->fakeResolver(executable: true, module: 'libphp.so'),
            symbol_resolver: $this->fakeSymbolResolver(null, 0),
        );
        $hit = $detector->detect($fp, 32, $ctx);
        $this->assertNotNull($hit);
        $this->assertSame('func_ptr (libphp.so)', $hit->label);
    }

    private function fakeResolver(bool $executable, ?string $module): ModuleResolverInterface
    {
        return new class ($executable, $module) implements ModuleResolverInterface {
            public function __construct(
                private bool $executable,
                private ?string $module,
            ) {
            }

            public function moduleBasenameFor(int $address): ?string
            {
                return $this->module;
            }

            public function isExecutable(int $address): bool
            {
                return $this->executable;
            }
        };
    }

    private function fakeSymbolResolver(?string $name, int $offset): SymbolResolverInterface
    {
        return new class ($name, $offset) implements SymbolResolverInterface {
            public function __construct(
                private ?string $name,
                private int $offset,
            ) {
            }

            public function resolve(int $address): ?array
            {
                return $this->name === null ? null : [$this->name, $this->offset];
            }
        };
    }
}
