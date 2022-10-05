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

use FFI\CData;
use Mockery;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\Elf\Process\ProcessSymbolReaderInterface;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use PHPUnit\Framework\TestCase;

class LibThreadDbTlsFinderTest extends TestCase
{
    public function testFindTls()
    {
        $symbol_reader = Mockery::mock(ProcessSymbolReaderInterface::class);
        $_thread_db_pthread_dtvp = \FFI::new('unsigned char[12]');
        $_thread_db_dtv_dtv = \FFI::new('unsigned char[12]');
        $_thread_db_dtv_t_pointer_val = \FFI::new('unsigned char[12]');
        \FFI::memset($_thread_db_pthread_dtvp, 0, 12);
        \FFI::memset($_thread_db_dtv_dtv, 0, 12);
        \FFI::memset($_thread_db_dtv_t_pointer_val, 0, 12);
        $_thread_db_pthread_dtvp[8] = 8;
        $_thread_db_dtv_dtv[0] = 64;
        $symbol_reader->expects()->read('_thread_db_pthread_dtvp')->andReturns($_thread_db_pthread_dtvp);
        $symbol_reader->expects()->read('_thread_db_dtv_dtv')->andReturns($_thread_db_dtv_dtv);
        $symbol_reader->expects()->read('_thread_db_dtv_t_pointer_val')->andReturns($_thread_db_dtv_t_pointer_val);

        $thread_pointer_retriever = Mockery::mock(ThreadPointerRetrieverInterface::class);
        $thread_pointer_retriever->expects()->getThreadPointer(1)->andReturns(0x10000);

        $dtv_address = \FFI::new('unsigned char[8]');
        \FFI::memset($dtv_address, 0, 8);
        $dtv_address[2] = 2;
        $tls_block_address = \FFI::new('unsigned char[8]');
        \FFI::memset($tls_block_address, 0, 8);
        $tls_block_address[2] = 3;

        $memory_reader = Mockery::mock(MemoryReaderInterface::class);
        $memory_reader->expects()->read(1, 0x10008, 8)->andReturns($dtv_address);
        $memory_reader->expects()->read(1, 0x20008, 8)->andReturns($tls_block_address);

        $finder = new LibThreadDbTlsFinder(
            $symbol_reader,
            $thread_pointer_retriever,
            $memory_reader,
            new LittleEndianReader()
        );

        $this->assertSame(
            0x30000,
            $finder->findTlsBlock(1, 1)
        );
    }

    /**
     * @dataProvider casesDebugSymbolsFoundOrNot
     * @throws TlsFinderException
     */
    public function testThrowTlsFinderExceptionIfDebugSymbolsNotFound(
        ?CData $_thread_db_pthread_dtvp,
        ?CData $_thread_db_dtv_dtv,
        ?CData $_thread_db_dtv_t_pointer_val
    ) {
        $this->expectException(TlsFinderException::class);

        $symbol_reader = Mockery::mock(ProcessSymbolReaderInterface::class);
        $symbol_reader->expects()->read('_thread_db_pthread_dtvp')->andReturns($_thread_db_pthread_dtvp);
        $symbol_reader->expects()->read('_thread_db_dtv_dtv')->andReturns($_thread_db_dtv_dtv);
        $symbol_reader->expects()->read('_thread_db_dtv_t_pointer_val')->andReturns($_thread_db_dtv_t_pointer_val);

        $thread_pointer_retriever = Mockery::mock(ThreadPointerRetrieverInterface::class);
        $thread_pointer_retriever->expects()->getThreadPointer(1)->andReturns(0x10000);

        $memory_reader = Mockery::mock(MemoryReaderInterface::class);

        $finder = new LibThreadDbTlsFinder(
            $symbol_reader,
            $thread_pointer_retriever,
            $memory_reader,
            new LittleEndianReader()
        );
        $finder->findTlsBlock(1, 1);
    }

    /**
     * @return array<string, array<?CData>>
     */
    public function casesDebugSymbolsFoundOrNot(): array
    {
        $_thread_db_pthread_dtvp = \FFI::new('unsigned char[12]');
        $_thread_db_dtv_dtv = \FFI::new('unsigned char[12]');
        $_thread_db_dtv_t_pointer_val = \FFI::new('unsigned char[12]');
        \FFI::memset($_thread_db_pthread_dtvp, 0, 12);
        \FFI::memset($_thread_db_dtv_dtv, 0, 12);
        \FFI::memset($_thread_db_dtv_t_pointer_val, 0, 12);
        $_thread_db_pthread_dtvp[8] = 8;
        $_thread_db_dtv_dtv[0] = 64;

        return [
            'pthread_dtvp not found' => [null, $_thread_db_dtv_dtv, $_thread_db_dtv_t_pointer_val],
            'dtv_dtv not found' => [$_thread_db_pthread_dtvp, null, $_thread_db_dtv_t_pointer_val],
            'dtv_t_pointer_val not found' => [$_thread_db_pthread_dtvp, $_thread_db_dtv_dtv, null],
        ];
    }
}
