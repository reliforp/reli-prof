<?php

namespace Reli\Lib\PhpInternals\Types\Zend;

use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

class ZendCompilerGlobals implements Dereferencable
{
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendArena>|null
     */
    private ?Pointer $arena;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendArena>|null
     */
    private ?Pointer $ast_arena;

    public function __construct(
        public CastedCData $casted_cdata,
    ) {
        unset($this->arena);
        unset($this->ast_arena);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'arena' => $this->arena = $this->casted_cdata->casted->arena !== null
                ? Pointer::fromCData(
                    ZendArena::class,
                    $this->casted_cdata->casted->arena,
                )
                : null
            ,
            'ast_arena' => $this->ast_arena = $this->casted_cdata->casted->ast_arena !== null
                ? Pointer::fromCData(
                    ZendArena::class,
                    $this->casted_cdata->casted->ast_arena,
                )
                : null
            ,
        };
    }

    public function getSizeOfArena(Dereferencer $dereferencer): int
    {
        if ($this->arena === null) {
            return 0;
        }
        $size = 0;
        $arena = $dereferencer->deref($this->arena);
        foreach ($arena->iterateChain($dereferencer) as $arena) {
            $size += $arena->getSize();
        }
        return $size;
    }

    public function getSizeOfAstArena(Dereferencer $dereferencer): int
    {
        if ($this->ast_arena === null) {
            return 0;
        }
        $size = 0;
        $arena = $dereferencer->deref($this->ast_arena);
        foreach ($arena->iterateChain($dereferencer) as $arena) {
            $size += $arena->getSize();
        }
        return $size;
    }

    public static function getCTypeName(): string
    {
        return 'zend_compiler_globals';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        return new static($casted_cdata);
    }
}
