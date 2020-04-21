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

use PhpProfiler\Lib\Process\MemoryReader;
use PhpProfiler\Lib\Process\MemoryReaderException;
use PhpProfiler\Lib\Process\RegisterReader;
use PhpProfiler\Lib\Process\RegisterReaderException;
use PhpProfiler\ProcessReader\ProcessModuleSymbolReader;
use PhpProfiler\ProcessReader\ProcessSymbolReaderException;

/**
 * Class TlsFinder
 * @package PhpProfiler\Lib\Elf\Tls
 */
final class TlsFinder
{
    private RegisterReader $register_reader;
    private ProcessModuleSymbolReader $symbol_reader;
    private MemoryReader $memory_reader;

    /**
     * TlsFinder constructor.
     *
     * @param ProcessModuleSymbolReader $symbol_reader
     * @param RegisterReader $register_reader
     * @param MemoryReader $memory_reader
     */
    public function __construct(
        ProcessModuleSymbolReader $symbol_reader,
        RegisterReader $register_reader,
        MemoryReader $memory_reader
    ) {
        $this->register_reader = $register_reader;
        $this->symbol_reader = $symbol_reader;
        $this->memory_reader = $memory_reader;
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
        $dtv_pointer = $this->memory_reader->readAsInt64($pid, $dtv_pointer_address);

        $dtv_slot = $thread_db_dtv_dtv_size * $module_index;
        $tls_address_pointer = $dtv_pointer + $dtv_slot + $thread_db_dtv_t_pointer_val_offset;

        return $this->memory_reader->readAsInt64($pid, $tls_address_pointer);
    }

    /**
     * @param string $symbol_name
     * @return int[]
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     */
    private function getLibThreadDbDescriptor(string $symbol_name): array
    {
        $desc = $this->symbol_reader->read($symbol_name);
        $desc_size =
            $desc[0]
                + ($desc[1] << 8)
                + ($desc[2] << 16)
                + ($desc[3] << 21); // ($desc[3] << 24) / 8
        $desc_num =
            $desc[4]
            + ($desc[5] << 8)
            + ($desc[6] << 16)
            + ($desc[7] << 24);
        $desc_offset =
            $desc[8]
            + ($desc[9] << 8)
            + ($desc[10] << 16)
            + ($desc[11] << 24);
        return [$desc_size, $desc_num, $desc_offset];
    }
}
