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

namespace PhpProfiler\Lib\Elf\Structure\Elf64;

use PhpProfiler\Lib\UInt64;

/**
 * Class Elf64ProgramHeaderTable
 * @package PhpProfiler\Lib\Elf
 */
final class Elf64ProgramHeaderTable
{
    /** @var Elf64ProgramHeaderEntry[] */
    private array $entries;

    /**
     * Elf64ProgramHeaderTable constructor.
     * @param Elf64ProgramHeaderEntry ...$entries
     */
    public function __construct(Elf64ProgramHeaderEntry ...$entries)
    {
        $this->entries = $entries;
    }

    /**
     * @return Elf64ProgramHeaderEntry[]
     */
    public function findLoad(): array
    {
        $result = [];
        foreach ($this->entries as $entry) {
            if ($entry->isLoad()) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    /**
     * @return UInt64
     */
    public function findBaseAddress(): UInt64
    {
        $base_address = new UInt64(0, 0);
        foreach ($this->findLoad() as $pt_load) {
            if ($pt_load->p_vaddr->hi < $base_address->hi) {
                $base_address = $pt_load->p_vaddr;
            } elseif ($pt_load->p_vaddr->hi === $base_address->hi) {
                if ($pt_load->p_vaddr->lo < $base_address->lo) {
                    $base_address = $pt_load->p_vaddr;
                }
            }
        }
        return $base_address;
    }

    /**
     * @return Elf64ProgramHeaderEntry[]
     */
    public function findDynamic(): array
    {
        $result = [];
        foreach ($this->entries as $entry) {
            if ($entry->isDynamic()) {
                $result[] = $entry;
            }
        }
        return $result;
    }
}
