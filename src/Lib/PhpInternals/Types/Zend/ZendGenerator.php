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

namespace Reli\Lib\PhpInternals\Types\Zend;

use FFI;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\PointedTypeResolver;
use Reli\Lib\Process\Pointer\PointedTypeResolverAware;

/**
 * @psalm-consistent-constructor
 * @psalm-suppress ClassMustBeFinal
 */
class ZendGenerator implements PointedTypeResolverAware
{
    use InlineCDataCreatorTrait;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendObject $std;

    /** @var Pointer<ZendExecuteData>|null */
    public ?Pointer $execute_data;

    /** @var Pointer<ZendExecuteData>|null */
    public ?Pointer $frozen_call_stack;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public Zval $value;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public Zval $key;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public Zval $retval;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_generator> $casted_cdata
     * @param Pointer<ZendGenerator> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->std);
        unset($this->execute_data);
        unset($this->frozen_call_stack);
        unset($this->value);
        unset($this->key);
        unset($this->retval);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'std' => $this->std = new ZendObject(
                $this->casted_cdata->createSubView($this->casted_cdata->casted->std),
                new Pointer(
                    ZendObject::class,
                    $this->pointer->address,
                    FFI::typeof($this->casted_cdata->casted->std)->getSize(),
                ),
            ),
            'execute_data' => $this->execute_data =
                $this->casted_cdata->casted->execute_data !== null
                ? Pointer::fromCData(
                    ZendExecuteData::class,
                    $this->casted_cdata->casted->execute_data,
                )
                : null
            ,
            'frozen_call_stack' => $this->frozen_call_stack =
                $this->casted_cdata->casted->frozen_call_stack !== null
                ? Pointer::fromCData(
                    ZendExecuteData::class,
                    $this->casted_cdata->casted->frozen_call_stack,
                )
                : null
            ,
            'value' => $this->value = $this->createInlineDereferencable('value', Zval::class),
            'key' => $this->key = $this->createInlineDereferencable('key', Zval::class),
            'retval' => $this->retval = $this->createInlineDereferencable('retval', Zval::class),
        };
    }

    /**
     * Raw zend_generator* pointers stored inside the `node` field. PHP uses
     * these to wire `yield from` chains together: `parent` points back up the
     * chain, `child`/`leaf` point down to the currently-executing sub-generator,
     * and the union at offsets 16/24 holds either `leaves.first`/`leaves.last`
     * (both Generator*) or `children.ht` (HashTable*) depending on whether
     * this node has multiple children. These references are NOT zval-shaped
     * — the engine bumps refcount on the pointed-to generator directly — so
     * an IS_OBJECT-zval-only scan misses them and any sub-generator only
     * reached via this chain ends up looking orphan even when its parent
     * generator is a walker-reachable Coroutine.
     *
     * @return list<int> non-zero pointer values (filtered)
     */
    public function getNodeRawGeneratorPointers(ZendTypeReader $zend_type_reader): array
    {
        [$node_offset, $node_size] = $zend_type_reader->getOffsetAndSizeOfMember(
            'zend_generator',
            'node',
        );
        if ($node_size < 32) {
            return [];
        }
        // The node struct (32 bytes) holds four 8-byte slots:
        //   +0  parent       (zend_generator*)
        //   +8  leaf/child   (zend_generator*)  -- union
        //   +16 first/ht     (zend_generator* | HashTable*)
        //   +24 last         (zend_generator*) (only when leaves variant)
        // We can't tell from the on-disk struct which union arm is active,
        // so we treat all four slots as candidate pointers and let the
        // downstream emit job validate the target — non-generator addresses
        // dispatch to a regular EmitObjectJob which fails-soft on bad
        // pointers via the job runner's catch.
        $out = [];
        for ($slot = 0; $slot < 4; $slot++) {
            $ptr_raw = $this->casted_cdata->raw;
            $unpacked = unpack(
                'Pv',
                FFI::string(
                    $ptr_raw,
                    $node_offset + ($slot + 1) * 8,
                ),
                $node_offset + $slot * 8,
            );
            if ($unpacked === false) {
                continue;
            }
            // unpack('P', …) yields an int; cast silences Psalm's Mixed
            // without a per-element Cast::toInt dispatch in this small loop.
            $ptr = (int)$unpacked['v'];
            if ($ptr !== 0) {
                $out[] = $ptr;
            }
        }
        return $out;
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_generator';
    }

    #[\Override]
    public static function fromCastedCDataWithResolver(
        CastedCData $casted_cdata,
        Pointer $pointer,
        PointedTypeResolver $pointed_type_resolver,
    ): static {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_generator> $casted_cdata
         * @var Pointer<ZendGenerator> $pointer
         */
        $self = new static($casted_cdata, $pointer);
        $self->pointed_type_resolver = $pointed_type_resolver;
        return $self;
    }

    /** @return Pointer<ZendGenerator> */
    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    /**
     * @param Pointer<ZendObject> $pointer
     * @return Pointer<ZendGenerator>
     */
    public static function getPointerFromZendObjectPointer(
        Pointer $pointer,
        ZendTypeReader $zend_type_reader,
    ): Pointer {
        return new Pointer(
            ZendGenerator::class,
            $pointer->address,
            $zend_type_reader->sizeOf('zend_generator'),
        );
    }
}
