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

namespace Reli\Lib\Dwarf;

use Reli\BaseTestCase;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\System\Architecture;

class EhFrameParserTest extends BaseTestCase
{
    public function testParsePhpBinaryEhFrame(): void
    {
        $php_path = PHP_BINARY;
        if (!is_file($php_path)) {
            $this->markTestSkipped('PHP binary not accessible');
        }

        $binary = file_get_contents($php_path);
        if ($binary === false) {
            $this->markTestSkipped('Cannot read PHP binary');
        }

        $integer_reader = new LittleEndianReader();
        $elf_parser = new Elf64Parser($integer_reader);
        $eh_frame_parser = new EhFrameParser($integer_reader);
        $byte_reader = new StringByteReader($binary);

        $elf_header = $elf_parser->parseElfHeader($byte_reader);
        if ($elf_header->e_shnum === 0) {
            $this->markTestSkipped('No section headers in PHP binary');
        }

        $section_headers = $elf_parser->parseSectionHeader($byte_reader, $elf_header);
        $eh_frame = $section_headers->findSectionByName('.eh_frame');
        if ($eh_frame === null) {
            $this->markTestSkipped('No .eh_frame in PHP binary');
        }

        $fdes = $eh_frame_parser->parse(
            $byte_reader,
            $eh_frame->sh_offset->toInt(),
            $eh_frame->sh_size->toInt(),
            $eh_frame->sh_addr->toInt(),
        );

        // PHP binary should have many FDEs
        $this->assertGreaterThan(100, count($fdes));

        // Each FDE should have valid fields
        foreach (array_slice($fdes, 0, 10) as $fde) {
            $this->assertGreaterThan(0, $fde->addressRange);
            $this->assertNotNull($fde->cie);
            $this->assertGreaterThan(0, $fde->cie->codeAlignmentFactor);
            $this->assertNotSame(0, $fde->cie->dataAlignmentFactor);
        }
    }

    public function testFdesHaveValidCieReferences(): void
    {
        $php_path = PHP_BINARY;
        $binary = file_get_contents($php_path);
        if ($binary === false) {
            $this->markTestSkipped('Cannot read PHP binary');
        }

        $integer_reader = new LittleEndianReader();
        $elf_parser = new Elf64Parser($integer_reader);
        $byte_reader = new StringByteReader($binary);

        $elf_header = $elf_parser->parseElfHeader($byte_reader);
        $section_headers = $elf_parser->parseSectionHeader($byte_reader, $elf_header);
        $eh_frame = $section_headers->findSectionByName('.eh_frame');
        if ($eh_frame === null) {
            $this->markTestSkipped('No .eh_frame');
        }

        $eh_frame_parser = new EhFrameParser($integer_reader);
        $fdes = $eh_frame_parser->parse(
            $byte_reader,
            $eh_frame->sh_offset->toInt(),
            $eh_frame->sh_size->toInt(),
            $eh_frame->sh_addr->toInt(),
        );

        // All FDEs should have valid CIE references
        $is_aarch64 = Architecture::detect() === Architecture::AARCH64;
        foreach (array_slice($fdes, 0, 50) as $fde) {
            $this->assertNotNull($fde->cie);
            if ($is_aarch64) {
                // AArch64 standard: code_alignment=4, data_alignment=-8, return_address=30 (LR)
                $this->assertSame(4, $fde->cie->codeAlignmentFactor);
                $this->assertSame(-8, $fde->cie->dataAlignmentFactor);
                $this->assertSame(30, $fde->cie->returnAddressRegister);
            } else {
                // x86_64 standard: code_alignment=1, data_alignment=-8, return_address=16 (RIP)
                $this->assertSame(1, $fde->cie->codeAlignmentFactor);
                $this->assertSame(-8, $fde->cie->dataAlignmentFactor);
                $this->assertSame(16, $fde->cie->returnAddressRegister);
            }
        }
    }

    public function testCieAugmentationParsed(): void
    {
        $php_path = PHP_BINARY;
        $binary = file_get_contents($php_path);
        if ($binary === false) {
            $this->markTestSkipped('Cannot read PHP binary');
        }

        $integer_reader = new LittleEndianReader();
        $elf_parser = new Elf64Parser($integer_reader);
        $byte_reader = new StringByteReader($binary);
        $elf_header = $elf_parser->parseElfHeader($byte_reader);
        $section_headers = $elf_parser->parseSectionHeader(
            $byte_reader,
            $elf_header,
        );
        $eh_frame = $section_headers->findSectionByName('.eh_frame');
        if ($eh_frame === null) {
            $this->markTestSkipped('No .eh_frame');
        }

        $eh_frame_parser = new EhFrameParser($integer_reader);
        $fdes = $eh_frame_parser->parse(
            $byte_reader,
            $eh_frame->sh_offset->toInt(),
            $eh_frame->sh_size->toInt(),
            $eh_frame->sh_addr->toInt(),
        );

        // Check CIE augmentation strings
        $augmentations = [];
        foreach ($fdes as $fde) {
            $augmentations[$fde->cie->augmentation] = true;
        }

        // Most x86_64 binaries use 'zR' augmentation
        $this->assertArrayHasKey('zR', $augmentations);
    }

    public function testFdeAddressRangesAreValid(): void
    {
        $php_path = PHP_BINARY;
        $binary = file_get_contents($php_path);
        if ($binary === false) {
            $this->markTestSkipped('Cannot read PHP binary');
        }

        $integer_reader = new LittleEndianReader();
        $elf_parser = new Elf64Parser($integer_reader);
        $byte_reader = new StringByteReader($binary);
        $elf_header = $elf_parser->parseElfHeader($byte_reader);
        $section_headers = $elf_parser->parseSectionHeader(
            $byte_reader,
            $elf_header,
        );
        $eh_frame = $section_headers->findSectionByName('.eh_frame');
        if ($eh_frame === null) {
            $this->markTestSkipped('No .eh_frame');
        }

        $eh_frame_parser = new EhFrameParser($integer_reader);
        $fdes = $eh_frame_parser->parse(
            $byte_reader,
            $eh_frame->sh_offset->toInt(),
            $eh_frame->sh_size->toInt(),
            $eh_frame->sh_addr->toInt(),
        );

        // FDEs should not overlap and should have reasonable ranges
        foreach ($fdes as $fde) {
            $this->assertGreaterThan(0, $fde->addressRange);
            // Reasonable range: < 10MB per function
            $this->assertLessThan(
                10 * 1024 * 1024,
                $fde->addressRange,
            );
            $this->assertNotEmpty($fde->cie->augmentation);
        }
    }

    public function testFdeContainsAddress(): void
    {
        $php_path = PHP_BINARY;
        $binary = file_get_contents($php_path);
        if ($binary === false) {
            $this->markTestSkipped('Cannot read PHP binary');
        }

        $integer_reader = new LittleEndianReader();
        $elf_parser = new Elf64Parser($integer_reader);
        $byte_reader = new StringByteReader($binary);
        $elf_header = $elf_parser->parseElfHeader($byte_reader);
        $section_headers = $elf_parser->parseSectionHeader(
            $byte_reader,
            $elf_header,
        );
        $eh_frame = $section_headers->findSectionByName('.eh_frame');
        if ($eh_frame === null) {
            $this->markTestSkipped('No .eh_frame');
        }

        $eh_frame_parser = new EhFrameParser($integer_reader);
        $fdes = $eh_frame_parser->parse(
            $byte_reader,
            $eh_frame->sh_offset->toInt(),
            $eh_frame->sh_size->toInt(),
            $eh_frame->sh_addr->toInt(),
        );

        $fde = $fdes[0];
        $this->assertTrue(
            $fde->containsAddress($fde->initialLocation),
        );
        $this->assertTrue(
            $fde->containsAddress(
                $fde->initialLocation + $fde->addressRange - 1
            ),
        );
        $this->assertFalse(
            $fde->containsAddress($fde->initialLocation - 1),
        );
        $this->assertFalse(
            $fde->containsAddress(
                $fde->initialLocation + $fde->addressRange
            ),
        );
    }
}
