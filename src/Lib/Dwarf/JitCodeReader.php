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

namespace Reli\Lib\Dwarf;

use Reli\Lib\ByteStream\CDataByteReader;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\SymbolResolver\Elf64ReverseSymbolResolver;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

/**
 * Reads JIT-compiled code info via the GDB JIT interface.
 *
 * When PHP is started with opcache.jit_debug=0x100, the JIT registers
 * in-memory ELF objects via __jit_debug_descriptor. Each ELF contains
 * .eh_frame (DWARF unwind info) and .symtab (symbols) for JIT code.
 *
 * struct jit_code_entry {        // linked list node
 *     jit_code_entry *next;      //  0: 8 bytes (x64)
 *     jit_code_entry *prev;      //  8: 8 bytes
 *     const char *symfile_addr;  // 16: pointer to in-memory ELF
 *     uint64_t symfile_size;     // 24: size of ELF
 * };                             // total: 32 bytes
 *
 * struct jit_descriptor {        // global descriptor
 *     uint32_t version;          //  0: 4 bytes (must be 1)
 *     uint32_t action_flag;      //  4: 4 bytes
 *     jit_code_entry *relevant;  //  8: 8 bytes
 *     jit_code_entry *first;     // 16: 8 bytes (head of list)
 * };                             // total: 24 bytes
 */
final class JitCodeReader
{
    private const JIT_CODE_ENTRY_SIZE = 32;
    private const JIT_CODE_ENTRY_NEXT_OFFSET = 0;
    private const JIT_CODE_ENTRY_SYMFILE_ADDR_OFFSET = 16;
    private const JIT_CODE_ENTRY_SYMFILE_SIZE_OFFSET = 24;

    private const JIT_DESCRIPTOR_FIRST_ENTRY_OFFSET = 16;

    private const MAX_ENTRIES = 10000;

    private LittleEndianReader $integerReader;
    private Elf64Parser $elfParser;
    private EhFrameParser $ehFrameParser;

    /** @var array<Fde>|null cached FDEs from all JIT ELF objects */
    private ?array $cachedFdes = null;

    /** @var Elf64ReverseSymbolResolver|null cached symbol resolver */
    private ?Elf64ReverseSymbolResolver $cachedSymbolResolver = null;

    private bool $loaded = false;

    public function __construct(
        private MemoryReaderInterface $memoryReader,
    ) {
        $this->integerReader = new LittleEndianReader();
        $this->elfParser = new Elf64Parser($this->integerReader);
        $this->ehFrameParser = new EhFrameParser($this->integerReader);
    }

    /**
     * Load all JIT ELF objects from the target process.
     *
     * @param int $descriptorAddress Address of __jit_debug_descriptor in target process
     */
    public function load(int $pid, int $descriptorAddress): void
    {
        $this->cachedFdes = null;
        $this->cachedSymbolResolver = null;
        $this->loaded = true;

        $all_fdes = [];
        $all_symbols = [];

        try {
            // Read jit_descriptor.first_entry pointer
            $first_entry_ptr = $this->readPointer(
                $pid,
                $descriptorAddress + self::JIT_DESCRIPTOR_FIRST_ENTRY_OFFSET,
            );

            if ($first_entry_ptr === 0) {
                return;
            }

            // Walk the linked list of jit_code_entry
            $entry_ptr = $first_entry_ptr;
            $count = 0;

            while ($entry_ptr !== 0 && $count < self::MAX_ENTRIES) {
                $count++;

                // Read symfile_addr and symfile_size
                $symfile_addr = $this->readPointer(
                    $pid,
                    $entry_ptr + self::JIT_CODE_ENTRY_SYMFILE_ADDR_OFFSET,
                );
                $symfile_size = $this->readPointer(
                    $pid,
                    $entry_ptr + self::JIT_CODE_ENTRY_SYMFILE_SIZE_OFFSET,
                );

                if ($symfile_addr !== 0 && $symfile_size > 0 && $symfile_size < 1024 * 1024) {
                    $this->processElfObject($pid, $symfile_addr, $symfile_size, $all_fdes);
                }

                // Follow next pointer
                $next_ptr = $this->readPointer(
                    $pid,
                    $entry_ptr + self::JIT_CODE_ENTRY_NEXT_OFFSET,
                );

                if ($next_ptr === $entry_ptr) {
                    break; // prevent infinite loop
                }
                $entry_ptr = $next_ptr;
            }
        } catch (\Throwable) {
            // Best effort - partial results are still useful
        }

        if ($all_fdes !== []) {
            usort($all_fdes, fn(Fde $a, Fde $b) => $a->initialLocation <=> $b->initialLocation);
            $this->cachedFdes = $all_fdes;
        }
    }

    /**
     * Find FDE for a JIT code address.
     */
    public function findFdeForAddress(int $absoluteAddress): ?Fde
    {
        if ($this->cachedFdes === null) {
            return null;
        }

        $lo = 0;
        $hi = count($this->cachedFdes) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $fde = $this->cachedFdes[$mid];
            if ($absoluteAddress < $fde->initialLocation) {
                $hi = $mid - 1;
            } elseif ($absoluteAddress >= $fde->initialLocation + $fde->addressRange) {
                $lo = $mid + 1;
            } else {
                return $fde;
            }
        }

        return null;
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    public function hasFdes(): bool
    {
        return $this->cachedFdes !== null;
    }

    /**
     * @param array<Fde> $allFdes collected FDEs (mutated)
     */
    private function processElfObject(
        int $pid,
        int $elfAddr,
        int $elfSize,
        array &$allFdes,
    ): void {
        try {
            // Read the entire in-memory ELF into local memory
            $elf_data = $this->memoryReader->read($pid, $elfAddr, $elfSize);
            $byte_reader = new CDataByteReader($elf_data);

            // Verify ELF magic
            if ($byte_reader[0] !== 0x7f || $byte_reader[1] !== 0x45
                || $byte_reader[2] !== 0x4c || $byte_reader[3] !== 0x46) {
                return;
            }

            $elf_header = $this->elfParser->parseElfHeader($byte_reader);
            if ($elf_header->e_shnum === 0) {
                return;
            }

            $section_headers = $this->elfParser->parseSectionHeader($byte_reader, $elf_header);

            // Extract .eh_frame
            $eh_frame_section = $section_headers->findSectionByName('.eh_frame');
            if ($eh_frame_section !== null) {
                // For in-memory JIT ELFs, sh_addr contains the actual code address
                // The .text section's sh_addr points to the JIT code in process memory
                $text_section = $section_headers->findSectionByName('.text');
                $code_base = $text_section !== null ? $text_section->sh_addr->toInt() : 0;

                $fdes = $this->ehFrameParser->parse(
                    $byte_reader,
                    $eh_frame_section->sh_offset->toInt(),
                    $eh_frame_section->sh_size->toInt(),
                    $code_base,
                );

                foreach ($fdes as $fde) {
                    $allFdes[] = $fde;
                }
            }
        } catch (\Throwable) {
            // Skip malformed ELF objects
        }
    }

    private function readPointer(int $pid, int $address): int
    {
        $buf = $this->memoryReader->read($pid, $address, 8);
        return $this->integerReader->read64(new CDataByteReader($buf), 0)->toInt();
    }
}
