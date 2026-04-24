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

use Reli\Lib\Process\MemoryLocation;

/**
 * An emalloc'd internal allocation held by a SimpleXMLElement/SimpleXMLIterator
 * instance — the php_libxml_node_ptr wrapper around an xmlNode, or the
 * php_libxml_ref_obj wrapper around an xmlDoc. The libxml2 tree itself lives
 * in libc malloc and is not visible from zend_mm.
 */
final class SimpleXmlInternalMemoryLocation extends MemoryLocation
{
    public function __construct(
        int $address,
        int $size,
        public readonly string $type_name,
    ) {
        parent::__construct($address, $size);
    }
}
