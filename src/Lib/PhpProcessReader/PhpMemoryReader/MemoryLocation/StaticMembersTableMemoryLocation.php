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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation;

use Reli\Lib\PhpInternals\Types\Zend\ZendClassEntry;
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\MemoryLocation;
use Reli\Lib\Process\Pointer\Dereferencer;

class StaticMembersTableMemoryLocation extends MemoryLocation
{
    public static function fromZendClassEntry(
        ZendClassEntry $zend_class_entry,
        ZendTypeReader $zend_type_reader,
        Dereferencer $dereferencer,
        int $map_ptr_base,
    ): self {
        assert($zend_class_entry->static_members_table !== null);
        $property_count = $zend_class_entry->default_static_members_count;
        $table_size = $property_count * $zend_type_reader->sizeOf(Zval::getCTypeName());
        $table_address = $zend_type_reader->resolveMapPtr(
            $map_ptr_base,
            $zend_class_entry->static_members_table->address,
            $dereferencer,
        );

        return new self(
            $table_address,
            $table_size,
        );
    }
}
