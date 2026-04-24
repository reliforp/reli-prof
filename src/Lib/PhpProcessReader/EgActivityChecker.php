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

namespace Reli\Lib\PhpProcessReader;

use Reli\Lib\ByteStream\CDataByteReader;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

/**
 * Cheap liveness probe for a candidate PHP process.
 *
 * Reads EG(active) with a single-byte process_vm_readv, which does not
 * require ptrace and so does not SIGSTOP the target. A mod_php parent
 * httpd (where libphp.so is mapped but the request engine never ran)
 * leaves EG(active) at 0, so this check lets the searcher avoid picking
 * up such non-worker processes as valid PHP targets.
 *
 * @psalm-suppress ClassMustBeFinal
 */
class EgActivityChecker
{
    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private ZendTypeReaderCreator $zend_type_reader_creator,
    ) {
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    public function isActive(int $pid, int $eg_address, string $php_version): bool
    {
        try {
            $type_reader = $this->zend_type_reader_creator->create($php_version);
            [$offset, $_size] = $type_reader->getOffsetAndSizeOfMember(
                'zend_executor_globals',
                'active',
            );
            $cdata = $this->memory_reader->read($pid, $eg_address + $offset, 1);
            $reader = new CDataByteReader($cdata);
            return $reader[0] !== 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
