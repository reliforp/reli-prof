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

namespace Reli\Lib\Process\MemoryReader;

use FFI;
use Reli\Lib\FFI\FFIHelper;

/**
 * @internal test helper
 */
class MockMemoryReader implements MemoryReaderInterface
{
    /** @var array<string, string> */
    public array $data = [];
    public int $readCount = 0;

    public function preload(int $address, string $bytes): void
    {
        $this->data[$address . ':' . strlen($bytes)] = $bytes;
    }

    #[\Override]
    public function read(int $pid, int $remote_address, int $size): FFI\CData
    {
        $this->readCount++;
        $key = $remote_address . ':' . $size;
        $bytes = $this->data[$key] ?? str_repeat("\0", $size);
        $buf = FFIHelper::new("char[$size]");
        assert($buf !== null);
        FFI::memcpy($buf, $bytes, $size);
        /** @var \FFI\CArray<int> */
        return $buf;
    }
}
