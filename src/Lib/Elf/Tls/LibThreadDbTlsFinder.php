<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\Elf\Tls;

use PhpProfiler\Lib\Binary\BinaryReader;
use PhpProfiler\Lib\Binary\CDataByteReader;
use PhpProfiler\Lib\Process\MemoryReaderException;
use PhpProfiler\Lib\Process\MemoryReaderInterface;
use PhpProfiler\Lib\Process\RegisterReader;
use PhpProfiler\Lib\Process\RegisterReaderException;
use PhpProfiler\ProcessReader\ProcessModuleSymbolReader;
use PhpProfiler\ProcessReader\ProcessSymbolReaderException;

/**
 * Class LibThreadDbTlsFinder
 *
 * This class uses some debugging symbols from libpthread.so,
 * so if the target process doesn't load libpthread, it won't work.
 *
 * @package PhpProfiler\Lib\Elf\Tls
 */
final class LibThreadDbTlsFinder implements TlsFinderInterface
{
    private RegisterReader $register_reader;
    private ProcessModuleSymbolReader $symbol_reader;
    private MemoryReaderInterface $memory_reader;
    private BinaryReader $binary_reader;

    /**
     * LibThreadDbTlsFinder constructor.
     *
     * @param ProcessModuleSymbolReader $symbol_reader
     * @param RegisterReader $register_reader
     * @param MemoryReaderInterface $memory_reader
     */
    public function __construct(
        ProcessModuleSymbolReader $symbol_reader,
        RegisterReader $register_reader,
        MemoryReaderInterface $memory_reader
    ) {
        $this->register_reader = $register_reader;
        $this->symbol_reader = $symbol_reader;
        $this->memory_reader = $memory_reader;
        $this->binary_reader = new BinaryReader();
    }

    /**
     * @param int $pid
     * @param int $module_index
     * @return int
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws RegisterReaderException
     */
    public function findTlsBlock(int $pid, int $module_index): int
    {
        $thread_pointer = $this->register_reader->attachAndReadOne($pid, RegisterReader::FS_BASE);

        [,,$thread_db_pthread_dtvp_offset] = $this->getLibThreadDbDescriptor('_thread_db_pthread_dtvp');
        [$thread_db_dtv_dtv_size,,] = $this->getLibThreadDbDescriptor('_thread_db_dtv_dtv');
        [,,$thread_db_dtv_t_pointer_val_offset] = $this->getLibThreadDbDescriptor('_thread_db_dtv_t_pointer_val');
//        [,,] = $this->getLibThreadDbDescriptor($pid, '_thread_db_link_map_l_tls_modid');

        $dtv_pointer_address = $thread_pointer + $thread_db_pthread_dtvp_offset;
        $dtv_pointer_cdata = $this->memory_reader->read($pid, $dtv_pointer_address, 8);
        $dtv_pointer = $this->binary_reader->read64(new CDataByteReader($dtv_pointer_cdata), 0)->toInt();

        $dtv_slot = $thread_db_dtv_dtv_size * $module_index;
        $tls_address_pointer = $dtv_pointer + $dtv_slot + $thread_db_dtv_t_pointer_val_offset;

        $tls_address_cdata = $this->memory_reader->read($pid, $tls_address_pointer, 8);
        return $this->binary_reader->read64(new CDataByteReader($tls_address_cdata), 0)->toInt();
    }

    /**
     * @param string $symbol_name
     * @return int[]
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     */
    private function getLibThreadDbDescriptor(string $symbol_name): array
    {
        $desc = new CDataByteReader(
            $this->symbol_reader->read($symbol_name)
        );

        $desc_size = $this->binary_reader->read32($desc, 0) >> 3;
        $desc_num = $this->binary_reader->read32($desc, 4);
        $desc_offset = $this->binary_reader->read32($desc, 8);

        return [$desc_size, $desc_num, $desc_offset];
    }
}
