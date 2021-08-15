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

namespace PhpProfiler\Lib\Integer;

use PHPUnit\Framework\TestCase;

class Leb128DecoderTest extends TestCase
{
    /**
     * @dataProvider unsignedProvider
     */
    public function testUnsigned(int $expected, string $representation)
    {
        $this->assertSame($expected, (new Leb128Decoder())->unsigned($representation));
    }

    public function unsignedProvider(): array
    {
        return [
            'case 2' => [2, pack('c*', 2)],
            'case 127' => [127, pack('c*', 127)],
            'case 128' => [128, pack('c*', 0 + 0x80, 1)],
            'case 129' => [129, pack('c*', 1 + 0x80, 1)],
            'case 12857' => [12857, pack('c*', 57 + 0x80, 100)],
            'case 344865' => [344865, pack('c*', 0x21 + 0x80, 6 + 0x80, 0x15)],
            'case 624485' => [624485, pack('c*', 0x65 + 0x80, 0x0e + 0x80, 0x26)],
        ];
    }

    /**
     * @dataProvider signedProvider
     */
    public function testSigned(int $expected, string $representation)
    {
        $this->assertSame($expected, (new Leb128Decoder())->signed($representation));
    }

    public function signedProvider(): array
    {
        return [
            'case 2' => [2, pack('c*', 2)],
            'case -2' => [-2, pack('c*', 0x7e)],
            'case 127' => [127, pack('c*', 127 + 0x80, 0)],
            'case -127' => [-127, pack('c*', 1 + 0x80, 0x7f)],
            'case 128' => [128, pack('c*', 0 + 0x80, 1)],
            'case -128' => [-128, pack('c*', 0 + 0x80, 0x7f)],
            'case 129' => [129, pack('c*', 1 + 0x80, 1)],
            'case -129' => [-129, pack('c*', 0x7f + 0x80, 0x7e)],
            'case -123456' => [-123456, pack('c*', 0x40 + 0x80, 0x3b + 0x80, 0x78)],
            'case -344865' => [-344865, pack('c*', 0x5f + 0x80, 0x79 + 0x80, 0x6a)],
        ];
    }
}
