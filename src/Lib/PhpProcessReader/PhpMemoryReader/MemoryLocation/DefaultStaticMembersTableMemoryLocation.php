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
use Reli\Lib\Process\MemoryLocation;

class DefaultStaticMembersTableMemoryLocation extends MemoryLocation
{
    public static function fromZendClassEntry(ZendClassEntry $zend_class_entry): self
    {
        assert($zend_class_entry->default_static_members_table !== null);
        return new self(
            $zend_class_entry->default_static_members_table->address,
            $zend_class_entry->default_static_members_table->size,
        );
    }
}
