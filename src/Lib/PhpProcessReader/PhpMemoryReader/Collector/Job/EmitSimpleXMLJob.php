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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\Job;

use Reli\Lib\PhpInternals\Types\C\RawString;
use Reli\Lib\PhpInternals\Types\Zend\PhpSxeObject;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendObject;
use Reli\Lib\PhpInternals\Types\Zend\ZendString;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\SimpleXmlInternalMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\SimpleXmlInternalContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Tier 1 SimpleXML support: attribute the emalloc'd ancillary blocks
 * (`php_libxml_node_ptr`, `php_libxml_ref_obj`) to their owning
 * SimpleXMLElement / SimpleXMLIterator, and follow the `properties`
 * HashTable so its contents are walked normally.
 *
 * The outer `php_sxe_object` allocation itself is already covered by the
 * size correction in {@see ZendObjectMemoryLocation::fromZendObject()}.
 *
 * The libxml2 node tree (xmlDoc/xmlNode/xmlAttr) lives in libc malloc,
 * not zend_mm, so it is intentionally outside the scope of this job.
 */
final class EmitSimpleXMLJob implements CollectorJob
{
    private PhpSxeObject $sxe;

    public function __construct(
        ZendObject $object,
        private ?int $object_node_id,
        CollectorContext $ctx,
    ) {
        $this->sxe = $ctx->dereferencer->deref(
            PhpSxeObject::getPointerFromZendObjectPointer(
                $object->getPointer(),
                $ctx->zend_type_reader,
            ),
        );
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $node_ptr_size = $ctx->zend_type_reader->sizeOf('php_libxml_node_ptr');
        $ref_obj_size = $ctx->zend_type_reader->sizeOf('php_libxml_ref_obj');

        $node_address = $this->sxe->node_address;
        if ($node_address !== 0 && !$ctx->memory_locations->has($node_address)) {
            $location = new SimpleXmlInternalMemoryLocation(
                $node_address,
                $node_ptr_size,
                'SimpleXMLElement::node (php_libxml_node_ptr)',
            );
            $ctx->memory_locations->add($location);
            $ctx->emitNode(
                new SimpleXmlInternalContext($location),
                $this->object_node_id,
                'simplexml_node',
            );
        } elseif ($node_address !== 0) {
            $ctx->checkVisitedAndEmitReference(
                $node_address,
                $this->object_node_id,
                'simplexml_node',
            );
        }

        $document_address = $this->sxe->document_address;
        if ($document_address !== 0 && !$ctx->memory_locations->has($document_address)) {
            $location = new SimpleXmlInternalMemoryLocation(
                $document_address,
                $ref_obj_size,
                'SimpleXMLElement::document (php_libxml_ref_obj)',
            );
            $ctx->memory_locations->add($location);
            $ctx->emitNode(
                new SimpleXmlInternalContext($location),
                $this->object_node_id,
                'simplexml_document',
            );
        } elseif ($document_address !== 0) {
            $ctx->checkVisitedAndEmitReference(
                $document_address,
                $this->object_node_id,
                'simplexml_document',
            );
        }

        // `sxe->properties` is populated on demand by var_dump / (array)
        // cast / json_encode / get_object_vars. When present it can hold
        // the whole serialised view of the node, so walking it picks up
        // the heavy content — child SimpleXMLElements, attribute strings,
        // and the HashTable itself.
        if ($this->sxe->properties !== null) {
            $queue->push(new EmitArrayJob(
                new Pointer(
                    ZendArray::class,
                    $this->sxe->properties->address,
                    $ctx->zend_type_reader->sizeOf('zend_array'),
                ),
                $this->object_node_id,
                'simplexml_properties_cache',
            ));
        }

        // iter.name / iter.nsprefix hold the element name / namespace prefix
        // used when the SimpleXMLElement represents a named child iterator.
        // Their storage differs by version:
        //   PHP 7.0-8.3: xmlChar* (estrdup'd null-terminated C string).
        //   PHP 8.4+:    zend_string* — pick up via the normal string walker.
        $this->trackIterString(
            $this->sxe->iter_name_address,
            'simplexml_iter_name',
            $ctx,
            $queue,
        );
        $this->trackIterString(
            $this->sxe->iter_nsprefix_address,
            'simplexml_iter_nsprefix',
            $ctx,
            $queue,
        );
    }

    private function trackIterString(
        int $address,
        string $link_name,
        CollectorContext $ctx,
        JobQueue $queue,
    ): void {
        if ($address === 0) {
            return;
        }

        if (!$ctx->zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V84)) {
            // Already a zend_string; let the standard walker handle
            // dedup + size accounting.
            $queue->push(new EmitStringJob(
                new Pointer(
                    ZendString::class,
                    $address,
                    $ctx->zend_type_reader->sizeOf('zend_string'),
                ),
                $this->object_node_id,
                $link_name,
            ));
            return;
        }

        if ($ctx->memory_locations->has($address)) {
            $ctx->checkVisitedAndEmitReference(
                $address,
                $this->object_node_id,
                $link_name,
            );
            return;
        }

        // estrdup'd C string: read bounded, then register strlen+1
        // as its allocation size. 256 is a safe upper bound for
        // XML element / namespace-prefix names in practice.
        try {
            /** @var RawString $raw */
            $raw = $ctx->dereferencer->deref(
                new Pointer(RawString::class, $address, 256),
            );
            $length = strlen($raw->value) + 1;
        } catch (\Throwable) {
            return;
        }

        $location = new SimpleXmlInternalMemoryLocation(
            $address,
            $length,
            'SimpleXMLElement::iter (estrdup)',
        );
        $ctx->memory_locations->add($location);
        $ctx->emitNode(
            new SimpleXmlInternalContext($location),
            $this->object_node_id,
            $link_name,
        );
    }
}
