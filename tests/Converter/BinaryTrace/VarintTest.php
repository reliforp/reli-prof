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

namespace Reli\Converter\BinaryTrace;

use PHPUnit\Framework\Attributes\DataProvider;
use Reli\BaseTestCase;

final class VarintTest extends BaseTestCase
{
    #[DataProvider('varintDataProvider')]
    public function testEncodeAndDecode(int $value, string $expected_hex): void
    {
        $encoded = Varint::encode($value);
        $this->assertSame($expected_hex, bin2hex($encoded));

        [$decoded, $consumed] = Varint::decode($encoded);
        $this->assertSame($value, $decoded);
        $this->assertSame(strlen($encoded), $consumed);
    }

    /** @return iterable<string, array{int, string}> */
    public static function varintDataProvider(): iterable
    {
        yield '0' => [0, '00'];
        yield '1' => [1, '01'];
        yield '127' => [127, '7f'];
        yield '128' => [128, '8001'];
        yield '300' => [300, 'ac02'];
        yield '16384' => [16384, '808001'];
        yield '10000' => [10000, '904e'];
        yield 'PHP_INT_MAX' => [PHP_INT_MAX, 'ffffffffffffffff7f'];
        // Negative values use protobuf int64 wire format: 64-bit two's
        // complement, always 10 bytes. -1 is the sentinel reli writes for
        // internal-call linenos, so this is a regression case for the
        // encoder hanging in an infinite loop.
        yield '-1' => [-1, 'ffffffffffffffffff01'];
        yield '-2' => [-2, 'feffffffffffffffff01'];
        yield 'PHP_INT_MIN' => [PHP_INT_MIN, '80808080808080808001'];
    }

    public function testDecodeWithOffset(): void
    {
        $buffer = "\x00" . Varint::encode(42) . "\x00";
        [$value, $consumed] = Varint::decode($buffer, 1);
        $this->assertSame(42, $value);
        $this->assertSame(1, $consumed);
    }

    public function testDecodeFromStream(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, Varint::encode(300));
        rewind($stream);

        $value = Varint::decodeFromStream($stream);
        $this->assertSame(300, $value);
        fclose($stream);
    }

    public function testDecodeThrowsOnTruncatedBuffer(): void
    {
        $this->expectException(BinaryTraceException::class);
        Varint::decode("\x80"); // continuation bit set but no more bytes
    }

    public function testDecodeFromStreamThrowsOnEof(): void
    {
        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, "\x80"); // continuation bit set
        rewind($stream);
        fread($stream, 1); // consume it, now at EOF

        $this->expectException(BinaryTraceException::class);
        Varint::decodeFromStream($stream);
        fclose($stream);
    }
}
