<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\PhpInternals\Opcodes;

use PHPUnit\Framework\TestCase;

class OpcodeFactoryTest extends TestCase
{
    public function testCreate(): void
    {
        $factory = new OpcodeFactory();
        $this->assertSame(
            'ZEND_NOP',
            $factory->create('v70', OpcodeV70::ZEND_NOP)->getName()
        );
        $this->assertSame(
            'ZEND_NOP',
            $factory->create('v71', OpcodeV71::ZEND_NOP)->getName()
        );
        $this->assertSame(
            'ZEND_NOP',
            $factory->create('v72', OpcodeV72::ZEND_NOP)->getName()
        );
        $this->assertSame(
            'ZEND_NOP',
            $factory->create('v73', OpcodeV73::ZEND_NOP)->getName()
        );
        $this->assertSame(
            'ZEND_NOP',
            $factory->create('v74', OpcodeV74::ZEND_NOP)->getName()
        );
        $this->assertSame(
            'ZEND_NOP',
            $factory->create('v80', OpcodeV80::ZEND_NOP)->getName()
        );
        $this->assertSame(
            'ZEND_NOP',
            $factory->create('v81', OpcodeV81::ZEND_NOP)->getName()
        );
    }
}
