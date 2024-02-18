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

namespace Reli\Lib\Elf\Structure\Elf64;

use Reli\Lib\Integer\UInt64;

final class Elf64ProgramHeaderTable
{
    /** @var Elf64ProgramHeaderEntry[] */
    private array $entries;

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

    /** @return Elf64ProgramHeaderEntry[] */
    public function findNote(): array
    {
        $result = [];
        foreach ($this->entries as $entry) {
            if ($entry->isNote()) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    public function findBaseAddress(): UInt64
    {
        $base_address = new UInt64(0xffff_ffff, 0xffff_ffff);
        foreach ($this->findLoad() as $pt_load) {
            if ($pt_load->p_vaddr->hi < $base_address->hi) {
                $base_address = $pt_load->p_vaddr;
            } elseif ($pt_load->p_vaddr->hi === $base_address->hi) {
                if ($pt_load->p_vaddr->lo < $base_address->lo) {
                    $base_address = $pt_load->p_vaddr;
                }
            }
        }
        if ($base_address->hi === 0xffff_ffff and $base_address->lo === 0xffff_ffff) {
            $base_address = new UInt64(0, 0);
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
