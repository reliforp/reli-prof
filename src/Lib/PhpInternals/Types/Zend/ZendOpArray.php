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

use FFI\CData;
use FFI\PhpInternals\zend_op_array;
use Reli\Lib\PhpInternals\Types\C\PointerArray;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

class ZendOpArray
{
    /**
     * @var Pointer<ZendString>|null
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public ?Pointer $filename;

    /**
     * @var Pointer<ZendArray>|null
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public ?Pointer $static_variables;
    public int $last;
    public int $T;
    public int $num_args;
    public int $last_var;

    /** @var Pointer<PointerArray>|null */
    public ?Pointer $vars;

    /** @param zend_op_array $cdata */
    public function __construct(
        private CData $cdata,
    ) {
        unset($this->filename);
        unset($this->static_variables);
        unset($this->last);
        unset($this->T);
        unset($this->num_args);
        unset($this->last_var);
        unset($this->vars);
    }

    public function __get(string $field_name)
    {
        return match ($field_name) {
            'filename' => $this->filename = $this->cdata->filename !== null
                ? Pointer::fromCData(
                    ZendString::class,
                    $this->cdata->filename,
                )
                : null
            ,
            'static_variables' => $this->static_variables = $this->cdata->static_variables !== null
                ? Pointer::fromCData(
                    ZendArray::class,
                    $this->cdata->static_variables,
                )
                : null
            ,
            'last' => $this->cdata->last,
            'T' => $this->cdata->T,
            'num_args' => $this->cdata->num_args,
            'last_var' => $this->cdata->last_var,
            'vars' => $this->vars = $this->cdata->vars !== null
                ? PointerArray::createPointerFromCData(
                    \FFI::cast('long', $this->cdata->vars)->cdata,
                    $this->cdata->last_var,
                )
                : null
            ,
        };
    }

    public function getFileName(Dereferencer $dereferencer): ?string
    {
        if (is_null($this->filename)) {
            return null;
        }
        $filename = $dereferencer->deref($this->filename);
        return (string)$dereferencer->deref(
            $filename->getValuePointer($this->filename)
        );
    }

    /** @return iterable<int, string> */
    public function getVariableNames(Dereferencer $dereferencer, ZendTypeReader $zend_type_reader): iterable
    {
        if (is_null($this->vars)) {
            return [];
        }
        $vars = $dereferencer->deref($this->vars);
        $iterator = $vars->getIteratorOfPointersTo(
            ZendString::class,
            $zend_type_reader,
        );
        foreach ($iterator as $key => $name_pointer) {
            $zend_string = $dereferencer->deref($name_pointer);
            $string = (string)($dereferencer->deref(
                $zend_string->getValuePointer($name_pointer)
            ));
            yield $key => $string;
        }
    }
}
