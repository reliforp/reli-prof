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

use PhpProfiler\Lib\ByteStream\CDataByteReader;
use PhpProfiler\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderInterface;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderInterface;

/**
 * This class uses some debugging symbols from libpthread.so,
 * so if the target process doesn't load libpthread, it won't work.
 */
final class LibThreadDbTlsFinder implements TlsFinderInterface
{
    public function __construct(
        private ProcessSymbolReaderInterface $symbol_reader,
        private ThreadPointerRetrieverInterface $thread_pointer_retriever,
        private MemoryReaderInterface $memory_reader,
        private IntegerByteSequenceReader $integer_reader,
    ) {
    }

    /**
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
        $dtv_pointer = $this->integer_reader->read64(new CDataByteReader($dtv_pointer_cdata), 0)->toInt();

        $dtv_slot = $thread_db_dtv_dtv_size * $module_index;
        $tls_address_pointer = $dtv_pointer + $dtv_slot + $thread_db_dtv_t_pointer_val_offset;

        $tls_address_cdata = $this->memory_reader->read($pid, $tls_address_pointer, 8);
        return $this->integer_reader->read64(new CDataByteReader($tls_address_cdata), 0)->toInt();
    }

    /**
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

        $desc_size = $this->integer_reader->read32($desc, 0) >> 3;
        $desc_num = $this->integer_reader->read32($desc, 4);
        $desc_offset = $this->integer_reader->read32($desc, 8);

        return [$desc_size, $desc_num, $desc_offset];
    }
}
