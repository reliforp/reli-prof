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

use Reli\Lib\ByteStream\CDataByteReader;
use Reli\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

/**
 * TLS block finder for musl libc.
 *
 * musl's pthread struct layout (x86_64, TLS Variant II):
 *   struct pthread {
 *       struct pthread *self;   // offset 0, 8 bytes
 *       uintptr_t *dtv;         // offset 8, 8 bytes
 *       ...
 *   };
 *
 * FS_BASE points directly to struct pthread (= pthread_self()).
 * DTV layout: dtv[0] = entry count, dtv[module_id] = TLS block address.
 * No dtv_t wrapper — entries are bare uintptr_t pointers, 8 bytes each.
 *
 * Unlike glibc, musl has no libthread_db.so and no _thread_db_* symbols.
 */
final class MuslTlsFinder implements TlsFinderInterface
{
    /** Offset of dtv pointer within musl's struct pthread (x86_64) */
    private const PTHREAD_DTV_OFFSET = 8;

    /** Size of one DTV entry (bare uintptr_t on 64-bit) */
    private const DTV_ENTRY_SIZE = 8;

    public function __construct(
        private ThreadPointerRetrieverInterface $thread_pointer_retriever,
        private MemoryReaderInterface $memory_reader,
        private IntegerByteSequenceReader $integer_reader,
    ) {
    }

    /**
     * @throws TlsFinderException
     */
    #[\Override]
    public function findTlsBlock(int $pid, ?int $link_map_address): int
    {
        $thread_pointer = $this->thread_pointer_retriever->getThreadPointer($pid);

        // Read DTV pointer from pthread struct
        $dtv_pointer_address = $thread_pointer + self::PTHREAD_DTV_OFFSET;
        $dtv_pointer_cdata = $this->memory_reader->read($pid, $dtv_pointer_address, 8);
        $dtv_pointer = $this->integer_reader->read64(
            new CDataByteReader($dtv_pointer_cdata),
            0,
        )->toInt();

        if ($dtv_pointer === 0) {
            throw new TlsFinderException('musl DTV pointer is null');
        }

        // Module ID 1 = the main executable's TLS block (default for PHP)
        $module_id = 1;

        // musl DTV: dtv[module_id] is a bare uintptr_t (no wrapper struct)
        $tls_address_pointer = $dtv_pointer + self::DTV_ENTRY_SIZE * $module_id;

        $tls_address_cdata = $this->memory_reader->read($pid, $tls_address_pointer, 8);
        $tls_block_address = $this->integer_reader->read64(
            new CDataByteReader($tls_address_cdata),
            0,
        )->toInt();

        if ($tls_block_address === 0) {
            throw new TlsFinderException('musl TLS block address is null');
        }

        return $tls_block_address;
    }
}
