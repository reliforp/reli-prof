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

namespace Reli\Inspector\Output\MemoryOutput\BinaryFormat;

/**
 * Two-mode string dictionary for the .rmem binary format.
 *
 * Writer mode: accumulate strings via intern(), each getting a unique uint32 id.
 * Reader mode: load from a serialized section via loadFromBuffer().
 *
 * Serialized layout: [uint32 count] then for each string [uint32 len][bytes...]
 */
final class StringDict
{
    /** @var array<string, int> string → id */
    private array $forward = [];

    /** @var list<string> id → string */
    private array $reverse = [];

    /**
     * Intern a string and return its id. Returns existing id if already interned.
     * Returns Format::NULL_STRING_ID for null.
     */
    public function intern(?string $s): int
    {
        if ($s === null) {
            return Format::NULL_STRING_ID;
        }
        if (isset($this->forward[$s])) {
            return $this->forward[$s];
        }
        $id = count($this->reverse);
        $this->forward[$s] = $id;
        $this->reverse[] = $s;
        return $id;
    }

    /**
     * Look up a string by id. Returns null for NULL_STRING_ID.
     */
    public function lookup(int $id): ?string
    {
        if ($id === Format::NULL_STRING_ID) {
            return null;
        }
        return $this->reverse[$id] ?? null;
    }

    public function count(): int
    {
        return count($this->reverse);
    }

    /**
     * Serialize the dictionary to a binary string.
     * Format: [uint32 count] then for each string [uint32 len][bytes...]
     */
    public function serialize(): string
    {
        $buf = pack('V', count($this->reverse));
        foreach ($this->reverse as $s) {
            $len = strlen($s);
            $buf .= pack('V', $len) . $s;
        }
        return $buf;
    }

    /**
     * Load a dictionary from a serialized binary buffer.
     */
    public static function deserialize(string $data): self
    {
        $dict = new self();
        $offset = 0;
        $count_row = unpack('V', $data, $offset);
        assert(is_array($count_row));
        $count = (int)$count_row[1];
        $offset += 4;
        for ($i = 0; $i < $count; $i++) {
            $len_row = unpack('V', $data, $offset);
            assert(is_array($len_row));
            $len = (int)$len_row[1];
            $offset += 4;
            $s = substr($data, $offset, $len);
            $offset += $len;
            $dict->forward[$s] = $i;
            $dict->reverse[] = $s;
        }
        return $dict;
    }
}
