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

use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\Elf\DebugFileLocator;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\BinaryFingerprint;
use Reli\Lib\File\FileReaderInterface;
use Reli\Lib\File\NativeFileReader;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;

final class ModuleEhFrameCache
{
    /** @var array<string, array<Fde>> module path => sorted FDE array */
    private array $cache = [];

    /** @var array<string, bool> modules that have been attempted but have no .eh_frame */
    private array $noEhFrame = [];

    private EhFrameParser $ehFrameParser;
    private Elf64Parser $elfParser;
    private DebugFileLocator $debugFileLocator;
    private FileReaderInterface $fileReader;

    public function __construct(
        ?DebugFileLocator $debugFileLocator = null,
        private string $processRoot = '',
        ?FileReaderInterface $fileReader = null,
        private ?BinaryAnalysisCache $binaryAnalysisCache = null,
        private ?ProcessMemoryMap $memoryMap = null,
    ) {
        $integer_reader = new LittleEndianReader();
        $this->ehFrameParser = new EhFrameParser($integer_reader);
        $this->elfParser = new Elf64Parser($integer_reader);
        $this->debugFileLocator = $debugFileLocator ?? new DebugFileLocator();
        $this->fileReader = $fileReader ?? new NativeFileReader();
    }

    /**
     * @return array<Fde>|null
     */
    public function getFdesForModule(string $modulePath): ?array
    {
        if (isset($this->noEhFrame[$modulePath])) {
            return null;
        }

        if (isset($this->cache[$modulePath])) {
            return $this->cache[$modulePath];
        }

        return $this->loadModule($modulePath);
    }

    public function findFdeForAddress(string $modulePath, int $relativeAddress): ?Fde
    {
        $fdes = $this->getFdesForModule($modulePath);
        if ($fdes === null || $fdes === []) {
            return null;
        }

        // Binary search for the FDE containing the address
        $lo = 0;
        $hi = count($fdes) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $fde = $fdes[$mid];
            if ($relativeAddress < $fde->initialLocation) {
                $hi = $mid - 1;
            } elseif ($relativeAddress >= $fde->initialLocation + $fde->addressRange) {
                $lo = $mid + 1;
            } else {
                return $fde;
            }
        }

        return null;
    }

    /**
     * @return array<Fde>|null
     */
    private function loadModule(string $modulePath): ?array
    {
        // Try loading from disk cache
        $fingerprint = $this->getFingerprint($modulePath);
        if ($fingerprint !== null) {
            $fdes = $this->loadFromCache($fingerprint);
            if ($fdes !== null) {
                $this->cache[$modulePath] = $fdes;
                return $fdes;
            }
        }

        // Try loading .eh_frame from the binary itself
        $fdes = $this->tryLoadEhFrame($modulePath);
        if ($fdes === null) {
            // Fallback: try separate debug file
            $debugFile = $this->debugFileLocator->locate($modulePath);
            if ($debugFile !== null) {
                $fdes = $this->tryLoadEhFrame($debugFile);
            }
        }

        if ($fdes !== null) {
            $this->cache[$modulePath] = $fdes;
            if ($fingerprint !== null) {
                $this->saveToCache($fingerprint, $fdes);
            }
            return $fdes;
        }

        $this->noEhFrame[$modulePath] = true;
        return null;
    }

    /**
     * @return array<Fde>|null
     */
    private function tryLoadEhFrame(string $filePath): ?array
    {
        $resolved = $filePath;
        if ($this->processRoot !== '' && str_starts_with($filePath, '/')) {
            $resolved = $this->processRoot . $filePath;
        }

        try {
            $binary_data = $this->fileReader->readAll($resolved);
        } catch (\Throwable) {
            return null;
        }
        if (strlen($binary_data) < 4) {
            return null;
        }

        $byte_reader = new StringByteReader($binary_data);

        // Check ELF magic
        if (
            $byte_reader[0] !== 0x7f
            || $byte_reader[1] !== 0x45 // 'E'
            || $byte_reader[2] !== 0x4c // 'L'
            || $byte_reader[3] !== 0x46 // 'F'
        ) {
            return null;
        }

        try {
            $elf_header = $this->elfParser->parseElfHeader($byte_reader);

            if ($elf_header->e_shnum === 0 || $elf_header->e_shoff->toInt() === 0) {
                return null;
            }

            $section_headers = $this->elfParser->parseSectionHeader($byte_reader, $elf_header);
            $eh_frame_section = $section_headers->findSectionByName('.eh_frame');

            if ($eh_frame_section === null) {
                return null;
            }

            $fdes = $this->ehFrameParser->parse(
                $byte_reader,
                $eh_frame_section->sh_offset->toInt(),
                $eh_frame_section->sh_size->toInt(),
                $eh_frame_section->sh_addr->toInt(),
            );

            // Sort by initial location for binary search
            usort($fdes, fn(Fde $a, Fde $b) => $a->initialLocation <=> $b->initialLocation);

            return $fdes;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getFingerprint(string $modulePath): ?BinaryFingerprint
    {
        if ($this->memoryMap === null) {
            return null;
        }
        $areas = $this->memoryMap->findByNameRegex(preg_quote($modulePath, '/'));
        if ($areas === []) {
            return null;
        }
        $area = $areas[0];
        return new BinaryFingerprint(
            join('_', [$area->device_id, $area->inode_num, $area->name])
        );
    }

    /** @return array<Fde>|null */
    private function loadFromCache(BinaryFingerprint $fingerprint): ?array
    {
        if ($this->binaryAnalysisCache === null) {
            return null;
        }
        $cached = $this->binaryAnalysisCache->get($fingerprint, 'eh_frame_fdes');
        if ($cached === null || !isset($cached['fdes']) || !is_array($cached['fdes'])) {
            return null;
        }
        return $this->deserializeFdes($cached['fdes']);
    }

    /** @param array<Fde> $fdes */
    private function saveToCache(BinaryFingerprint $fingerprint, array $fdes): void
    {
        if ($this->binaryAnalysisCache === null) {
            return;
        }
        $this->binaryAnalysisCache->set($fingerprint, 'eh_frame_fdes', [
            'fdes' => $this->serializeFdes($fdes),
        ]);
    }

    /**
     * @param array<Fde> $fdes
     * @return array<array<string, mixed>>
     */
    private function serializeFdes(array $fdes): array
    {
        $cie_map = [];
        $result = [];
        foreach ($fdes as $fde) {
            $cie_id = spl_object_id($fde->cie);
            if (!isset($cie_map[$cie_id])) {
                $cie_map[$cie_id] = count($cie_map);
            }
            $result[] = [
                'il' => $fde->initialLocation,
                'ar' => $fde->addressRange,
                'ins' => base64_encode($fde->instructions),
                'ci' => $cie_map[$cie_id],
            ];
        }

        $cies = [];
        foreach ($cie_map as $obj_id => $index) {
            // Find any FDE referencing this CIE to get the actual object
            foreach ($fdes as $fde) {
                if (spl_object_id($fde->cie) === $obj_id) {
                    $cie = $fde->cie;
                    $cies[$index] = [
                        'caf' => $cie->codeAlignmentFactor,
                        'daf' => $cie->dataAlignmentFactor,
                        'rar' => $cie->returnAddressRegister,
                        'ii' => base64_encode($cie->initialInstructions),
                        'aug' => $cie->augmentation,
                        'fe' => $cie->fdeEncoding,
                        'le' => $cie->lsdaEncoding,
                        'pe' => $cie->personalityEncoding,
                        'pa' => $cie->personalityAddress,
                    ];
                    break;
                }
            }
        }

        return ['cies' => $cies, 'fdes' => $result];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<Fde>|null
     */
    private function deserializeFdes(array $data): ?array
    {
        if (!isset($data['cies'], $data['fdes']) || !is_array($data['cies']) || !is_array($data['fdes'])) {
            return null;
        }

        $cies = [];
        foreach ($data['cies'] as $index => $cie_data) {
            if (!is_array($cie_data)) {
                return null;
            }
            $cies[$index] = new Cie(
                codeAlignmentFactor: $cie_data['caf'],
                dataAlignmentFactor: $cie_data['daf'],
                returnAddressRegister: $cie_data['rar'],
                initialInstructions: base64_decode($cie_data['ii']),
                augmentation: $cie_data['aug'],
                fdeEncoding: $cie_data['fe'],
                lsdaEncoding: $cie_data['le'] ?? null,
                personalityEncoding: $cie_data['pe'] ?? null,
                personalityAddress: $cie_data['pa'] ?? null,
            );
        }

        $fdes = [];
        foreach ($data['fdes'] as $fde_data) {
            if (!is_array($fde_data) || !isset($cies[$fde_data['ci']])) {
                return null;
            }
            $fdes[] = new Fde(
                initialLocation: $fde_data['il'],
                addressRange: $fde_data['ar'],
                instructions: base64_decode($fde_data['ins']),
                cie: $cies[$fde_data['ci']],
            );
        }

        return $fdes;
    }
}
