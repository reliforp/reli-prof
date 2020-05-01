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

use PhpProfiler\Lib\Binary\BinaryReader;
use PhpProfiler\Lib\Binary\CDataByteReader;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderInterface;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;

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
    private ProcessSymbolReaderInterface $symbol_reader;
    private ThreadPointerRetrieverInterface $thread_pointer_retriever;
    private MemoryReaderInterface $memory_reader;
    private BinaryReader $binary_reader;

    /**
     * LibThreadDbTlsFinder constructor.
     *
     * @param ProcessSymbolReaderInterface $symbol_reader
     * @param ThreadPointerRetrieverInterface $thread_pointer_retriever
     * @param MemoryReaderInterface $memory_reader
     */
    public function __construct(
        ProcessSymbolReaderInterface $symbol_reader,
        ThreadPointerRetrieverInterface $thread_pointer_retriever,
        MemoryReaderInterface $memory_reader
    ) {
        $this->symbol_reader = $symbol_reader;
        $this->thread_pointer_retriever = $thread_pointer_retriever;
        $this->memory_reader = $memory_reader;
        $this->binary_reader = new BinaryReader();
    }

    /**
     * @param int $pid
     * @param int $module_index
     * @return int
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws TlsFinderException
     */
    public function findTlsBlock(int $pid, int $module_index): int
    {
        $thread_pointer = $this->thread_pointer_retriever->getThreadPointer($pid);

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
     * @throws TlsFinderException
     */
    private function getLibThreadDbDescriptor(string $symbol_name): array
    {
        $buffer = $this->symbol_reader->read($symbol_name);
        if (is_null($buffer)) {
            throw new TlsFinderException('cannot find ' . $symbol_name);
        }
        $desc = new CDataByteReader($buffer);

        $desc_size = $this->binary_reader->read32($desc, 0) >> 3;
        $desc_num = $this->binary_reader->read32($desc, 4);
        $desc_offset = $this->binary_reader->read32($desc, 8);

        return [$desc_size, $desc_num, $desc_offset];
    }
}
