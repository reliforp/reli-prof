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

namespace PhpProfiler\Lib\Elf\Tls;

use PhpProfiler\Lib\Process\RegisterReader\RegisterReaderException;
use PhpProfiler\Lib\Process\RegisterReader\X64RegisterReader;

/**
 * Class X64ThreadPointerFinder
 * @package PhpProfiler\Lib\Elf\Tls
 */
final class X64LinuxThreadPointerRetriever implements ThreadPointerRetrieverInterface
{
    private X64RegisterReader $register_reader;

    public static function createDefault(): self
    {
        return new self(
            new X64RegisterReader()
        );
    }

    /**
     * X64ThreadPointerFinder constructor.
     * @param X64RegisterReader $register_reader
     */
    public function __construct(X64RegisterReader $register_reader)
    {
        $this->register_reader = $register_reader;
    }

    /**
     * @param int $pid
     * @return int
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
