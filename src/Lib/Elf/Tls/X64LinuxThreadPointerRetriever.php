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

namespace Reli\Lib\Elf\Tls;

use Reli\Lib\Libc\Errno\Errno;
use Reli\Lib\Libc\Sys\Ptrace\PtraceX64;
use Reli\Lib\Process\RegisterReader\RegisterReaderException;
use Reli\Lib\Process\RegisterReader\X64RegisterReader;

final class X64LinuxThreadPointerRetriever implements ThreadPointerRetrieverInterface
{
    public static function createDefault(): self
    {
        return new self(
            new X64RegisterReader(
                new PtraceX64(),
                new Errno(),
            )
        );
    }

    public function __construct(
        private X64RegisterReader $register_reader,
    ) {
    }

    /**
     * @throws TlsFinderException
     */
    public function getThreadPointer(int $pid): int
    {
        try {
            return $this->register_reader->attachAndReadOne($pid, X64RegisterReader::FS_BASE);
        } catch (RegisterReaderException $e) {
            throw new TlsFinderException('cannot find thread pointer', 0, $e);
        }
    }
}
