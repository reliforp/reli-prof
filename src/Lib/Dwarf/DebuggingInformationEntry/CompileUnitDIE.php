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

namespace PhpProfiler\Lib\Dwarf\DebuggingInformationEntry;

use PhpProfiler\Lib\Dwarf\Attribute;
use PhpProfiler\Lib\Dwarf\Attribute\IdentifierCaseCode;
use PhpProfiler\Lib\Dwarf\CompilationUnit\CompilationUnit;
use PhpProfiler\Lib\Dwarf\Language;
use PhpProfiler\Lib\Dwarf\LineNumberInformation\LineNumberInformation;
use PhpProfiler\Lib\Dwarf\MacroInformation\MacroInformation;
use PhpProfiler\Lib\Dwarf\Tag;

final class CompileUnitDIE implements DebuggingInformationEntry
{
    use DebuggingInformationEntryTrait;

    /**
     * @param Attribute[] $attributes
     * @param DebuggingInformationEntry[] $children
     */
    public function __construct(
        public int $abbreviation_code,
        public Tag $tag,
        public array $attributes,
        public array $children
    ) {
    }

    public function getRanges(): AddressRanges
    {
        // - use DW_AT_low_pc and DW_AT_high_pc
        // - use DW_AT_ranges
        // - use DW_AT_low_pc and DW_AT_ranges
    }

    public function isInRanges(int $address): bool
    {
        $this->getRanges()->isInRanges($address);
    }

    public function getName(): string
    {
        // use DW_AT_name
    }

    public function getLanguage(): Language
    {
        // use DW_AT_language
    }

    public function getLineNumberInformation(): LineNumberInformation
    {
        // use DW_AT_stmt_list
    }

    public function getMacroInformation(): MacroInformation
    {
        // use DW_AT_macros
    }

    public function getCompilationDirectory(): string
    {
        // use DW_AT_comp_dir
    }

    public function getProducer(): string
    {
        // use DW_AT_producer
    }

    public function getIdentifierCase(): IdentifierCaseCode
    {
        // use DW_AT_identifier_case
    }

    public function getBaseTypes(): CompilationUnit
    {
        // use DW_AT_base_types
    }

    public function isUseUtf8(): bool
    {
        // use DW_AT_use_UTF8
    }

    public function isMainSubprogram(): bool
    {
        // use DW_AT_main_subprogram
    }

    public function getEntryPC(): int
    {
        // use DW_AT_entry_pc
    }

    public function getStrOffsetsBase(): int
    {
        // use DW_AT_str_offsets_base
    }

    public function getAddressBase(): int
    {
        // use DW_AT_addr_base
    }

    public function getRNGListsBase(): int
    {
        // use DW_AT_rnglists_base
    }

    public function getLocationListsBase(): int
    {
        // use DW_AT_loclists_base
    }
}
