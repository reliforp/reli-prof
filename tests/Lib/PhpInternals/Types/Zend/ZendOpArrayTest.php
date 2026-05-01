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

namespace Reli\Lib\PhpInternals\Types\Zend;

use FFI;
use Reli\BaseTestCase;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencer;

class ZendOpArrayTest extends BaseTestCase
{
    /** @var array<string, FFI> */
    private array $ffi_holder = [];

    private function buildOpArrayFromHeader(string $version): ZendOpArray
    {
        $ffi = FFI::load(
            __DIR__ . '/../../../../../src/Lib/PhpInternals/Headers/' . $version . '.h'
        );
        $this->assertNotNull($ffi);
        $this->ffi_holder[$version] = $ffi;
        $cdata = $ffi->new('zend_op_array');
        $this->assertNotNull($cdata);
        return new ZendOpArray(new CastedCData($cdata, $cdata));
    }

    public function testHasLiveRangeSupportIsFalseOnV70(): void
    {
        $op_array = $this->buildOpArrayFromHeader('v70');
        $this->assertFalse($op_array->hasLiveRangeSupport());
    }

    public function testHasLiveRangeSupportIsTrueOnV71(): void
    {
        $op_array = $this->buildOpArrayFromHeader('v71');
        $this->assertTrue($op_array->hasLiveRangeSupport());
    }

    public function testFindLiveTmpVarsReturnsNullOnV70(): void
    {
        $op_array = $this->buildOpArrayFromHeader('v70');
        $dereferencer = $this->createMock(Dereferencer::class);
        $dereferencer->expects($this->never())->method('deref');

        $this->assertNull($op_array->findLiveTmpVars(0, $dereferencer));
    }

    public function testFindLiveTmpVarsReturnsEmptyArrayOnV71WhenLiveRangeIsNull(): void
    {
        $op_array = $this->buildOpArrayFromHeader('v71');
        $dereferencer = $this->createMock(Dereferencer::class);
        $dereferencer->expects($this->never())->method('deref');

        $this->assertSame([], $op_array->findLiveTmpVars(0, $dereferencer));
    }
}
