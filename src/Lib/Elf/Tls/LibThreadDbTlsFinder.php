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
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\BinaryFingerprint;
use Reli\Lib\Elf\Process\ProcessSymbolReaderInterface;
use Reli\Lib\Elf\Process\ProcessSymbolReaderException;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

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
        private ?BinaryAnalysisCache $binary_analysis_cache = null,
        private ?BinaryFingerprint $libpthread_fingerprint = null,
    ) {
    }

    /**
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws TlsFinderException
     */
    #[\Override]
    public function findTlsBlock(int $pid, ?int $link_map_address): int
    {
        $thread_pointer = $this->thread_pointer_retriever->getThreadPointer($pid);

        $descriptors = $this->resolveDescriptors();

        $dtv_pointer_address = $thread_pointer + $descriptors['pthread_dtvp_offset'];
        $dtv_pointer_cdata = $this->memory_reader->read($pid, $dtv_pointer_address, 8);
        $dtv_pointer = $this->integer_reader->read64(new CDataByteReader($dtv_pointer_cdata), 0)->toInt();

        $module_id = 1;
        if (!is_null($link_map_address)) {
            $module_id_address = $descriptors['link_map_l_tls_modid_offset'] + $link_map_address;
            $module_id = $this->integer_reader->read32(
                new CDataByteReader(
                    $this->memory_reader->read(
                        $pid,
                        $module_id_address,
                        4
                    )
                ),
                0
            );
        }

        $dtv_slot = $descriptors['dtv_dtv_size'] * $module_id;
        $tls_address_pointer = $dtv_pointer + $dtv_slot + $descriptors['dtv_t_pointer_val_offset'];

        $tls_address_cdata = $this->memory_reader->read($pid, $tls_address_pointer, 8);
        return $this->integer_reader->read64(new CDataByteReader($tls_address_cdata), 0)->toInt();
    }

    /**
     * @return array{
     *     pthread_dtvp_offset: int,
     *     dtv_dtv_size: int,
     *     dtv_t_pointer_val_offset: int,
     *     link_map_l_tls_modid_offset: int,
     * }
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws TlsFinderException
     */
    private function resolveDescriptors(): array
    {
        if ($this->binary_analysis_cache !== null && $this->libpthread_fingerprint !== null) {
            $cached = $this->binary_analysis_cache->get($this->libpthread_fingerprint, 'libpthread_descriptors');
            if (
                $cached !== null
                && isset(
                    $cached['pthread_dtvp_offset'],
                    $cached['dtv_dtv_size'],
                    $cached['dtv_t_pointer_val_offset'],
                    $cached['link_map_l_tls_modid_offset'],
                )
                && is_int($cached['pthread_dtvp_offset'])
                && is_int($cached['dtv_dtv_size'])
                && is_int($cached['dtv_t_pointer_val_offset'])
                && is_int($cached['link_map_l_tls_modid_offset'])
            ) {
                /** @var array{pthread_dtvp_offset: int, dtv_dtv_size: int, dtv_t_pointer_val_offset: int, link_map_l_tls_modid_offset: int} */
                return $cached;
            }
        }

        [,,$pthread_dtvp_offset] = $this->getLibThreadDbDescriptor('_thread_db_pthread_dtvp');
        [$dtv_dtv_size,,] = $this->getLibThreadDbDescriptor('_thread_db_dtv_dtv');
        [,,$dtv_t_pointer_val_offset] = $this->getLibThreadDbDescriptor('_thread_db_dtv_t_pointer_val');
        [,,$link_map_l_tls_modid_offset] = $this->getLibThreadDbDescriptor('_thread_db_link_map_l_tls_modid');

        $result = [
            'pthread_dtvp_offset' => $pthread_dtvp_offset,
            'dtv_dtv_size' => $dtv_dtv_size,
            'dtv_t_pointer_val_offset' => $dtv_t_pointer_val_offset,
            'link_map_l_tls_modid_offset' => $link_map_l_tls_modid_offset,
        ];

        if ($this->binary_analysis_cache !== null && $this->libpthread_fingerprint !== null) {
            $this->binary_analysis_cache->set($this->libpthread_fingerprint, 'libpthread_descriptors', $result);
        }

        return $result;
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
