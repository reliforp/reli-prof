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

namespace PhpProfiler\Lib\PhpProcessReader;

use PhpProfiler\Lib\PhpInternals\Opcodes\OpcodeV70;
use PhpProfiler\Lib\PhpInternals\Types\Zend\Opline;
use PHPUnit\Framework\TestCase;

class CallFrameTest extends TestCase
{
    public function testGetFullyQualifiedFunctionName()
    {
        $call_frame = new CallFrame(
            '',
            'function_name',
            'file_name',
            null
        );
        $this->assertSame(
            'function_name',
            $call_frame->getFullyQualifiedFunctionName()
        );

        $call_frame = new CallFrame(
            'class_name',
            'function_name',
            'file_name',
            null
        );
        $this->assertSame(
            'class_name::function_name',
            $call_frame->getFullyQualifiedFunctionName()
        );
    }

    public function testGetLineno()
    {
        $call_frame = new CallFrame(
            'class_name',
            'function_name',
            'file_name',
            null
        );
        $this->assertSame(
            -1,
            $call_frame->getLineno()
        );

        $call_frame = new CallFrame(
            'class_name',
            'function_name',
            'file_name',
            new Opline(
                0,
                0,
                0,
                0,
                123,
                new OpcodeV70(0),
                0,
                0,
                0
            )
        );
        $this->assertSame(
            123,
            $call_frame->getLineno()
        );
    }

    public function testGetOpcodeName()
    {
        $call_frame = new CallFrame(
            'class_name',
            'function_name',
            'file_name',
            null
        );
        $this->assertSame(
            '',
            $call_frame->getOpcodeName()
        );

        $call_frame = new CallFrame(
            'class_name',
            'function_name',
            'file_name',
            new Opline(
                0,
                0,
                0,
                0,
                123,
                new OpcodeV70(OpcodeV70::ZEND_NOP),
                0,
                0,
                0
            )
        );
        $this->assertSame(
            'ZEND_NOP',
            $call_frame->getOpcodeName()
        );
    }
}
