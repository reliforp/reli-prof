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

use Reli\Lib\ByteStream\CDataByteReader;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

final class NativeStackUnwinder
{
    private const MAX_FRAMES = 128;

    private CfiInstructionDecoder $cfiDecoder;
    private DwarfExpression $dwarfExpression;
    private IntegerByteSequenceReader $integerReader;

    public function __construct(
        private ModuleEhFrameCache $ehFrameCache,
        private MemoryReaderInterface $memoryReader,
    ) {
        $this->cfiDecoder = new CfiInstructionDecoder();
        $this->dwarfExpression = new DwarfExpression();
        $this->integerReader = new LittleEndianReader();
    }

    /**
     * @param callable(int): ?array{string, int} $symbolResolver  address => [name, offset] or null
     */
    public function unwind(
        int $pid,
        RegisterState $registers,
        ProcessMemoryMap $memoryMap,
        callable $symbolResolver,
        int $maxFrames = self::MAX_FRAMES,
    ): NativeTrace {
        $frames = [];
        $rip = $registers->getRip();
        $rsp = $registers->getRsp();

        $current_registers = clone $registers;

        for ($i = 0; $i < $maxFrames; $i++) {
            if ($rip === 0) {
                break;
            }

            // Find module for this address
            $areas = $memoryMap->findByAddress($rip);
            if ($areas === []) {
                // Unknown address - try frame pointer unwinding as last resort
                $frame = $this->createFrameForAddress($rip, '??', $symbolResolver);
                $frames[] = $frame;
                break;
            }

            $area = $this->findExecutableArea($areas) ?? $areas[0];
            $module_path = $area->name;
            $module_base = hexdec($area->begin) - hexdec($area->file_offset);

            // Create frame for this IP
            $frames[] = $this->createFrameForAddress($rip, $module_path, $symbolResolver);

            // Try DWARF-based unwinding
            $relative_address = $rip - $module_base;
            $fde = $this->ehFrameCache->findFdeForAddress($module_path, $relative_address);

            if ($fde !== null) {
                $new_registers = $this->unwindWithFde($pid, $fde, $relative_address, $current_registers);
                if ($new_registers !== null) {
                    $new_rip = $new_registers->getRip();
                    $new_rsp = $new_registers->getRsp();

                    // Sanity check: RSP should increase (stack grows down)
                    if ($new_rsp <= $rsp && $new_rip === $rip) {
                        break;
                    }

                    $rip = $new_rip;
                    $rsp = $new_rsp;
                    $current_registers = $new_registers;
                    continue;
                }
            }

            // Fallback: frame pointer unwinding
            $new_registers = $this->framePointerUnwind($pid, $current_registers);
            if ($new_registers !== null) {
                $rip = $new_registers->getRip();
                $rsp = $new_registers->getRsp();
                $current_registers = $new_registers;
                continue;
            }

            break;
        }

        return new NativeTrace(...$frames);
    }

    /**
     * @param callable(int): ?array{string, int} $symbolResolver
     */
    private function createFrameForAddress(
        int $ip,
        string $modulePath,
        callable $symbolResolver,
    ): NativeFrame {
        $resolved = $symbolResolver($ip);
        $symbol_name = $resolved !== null ? $resolved[0] : null;
        $offset = $resolved !== null ? $resolved[1] : 0;

        // Use basename for display
        $module_name = $modulePath !== '' ? basename($modulePath) : '??';

        return new NativeFrame($ip, $symbol_name, $module_name, $offset);
    }

    private function unwindWithFde(
        int $pid,
        Fde $fde,
        int $relativeAddress,
        RegisterState $registers,
    ): ?RegisterState {
        try {
            // Build initial row from CIE
            $initial_row = $this->cfiDecoder->buildInitialRow($fde->cie);

            // Execute FDE instructions to build unwind table
            $rows = $this->cfiDecoder->execute(
                $fde->instructions,
                $fde->cie,
                $fde->initialLocation,
                $initial_row,
            );

            // Find the row for our address
            $row = $this->findRowForAddress($rows, $relativeAddress);
            if ($row === null) {
                return null;
            }

            // Compute CFA
            $cfa = $this->computeCfa($row->cfaRule, $registers, $pid);
            if ($cfa === null) {
                return null;
            }

            // Create new register state
            $new_registers = new RegisterState();
            $new_registers->set(RegisterState::RSP, $cfa);

            // Restore return address register
            $ra_register = $fde->cie->returnAddressRegister;
            $ra_rule = $row->getRegisterRule($ra_register);
            $ra = $this->applyRegisterRule($ra_rule, $cfa, $registers, $pid);
            if ($ra === null) {
                return null;
            }
            $new_registers->set(RegisterState::RIP, $ra);

            // Restore callee-saved registers
            foreach ([RegisterState::RBP, RegisterState::RBX, RegisterState::R12,
                       RegisterState::R13, RegisterState::R14, RegisterState::R15] as $reg) {
                $rule = $row->getRegisterRule($reg);
                $value = $this->applyRegisterRule($rule, $cfa, $registers, $pid);
                if ($value !== null) {
                    $new_registers->set($reg, $value);
                } else {
                    $new_registers->set($reg, $registers->get($reg));
                }
            }

            return $new_registers;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<UnwindTableRow> $rows
     */
    private function findRowForAddress(array $rows, int $address): ?UnwindTableRow
    {
        $result = null;
        foreach ($rows as $row) {
            if ($row->location > $address) {
                break;
            }
            $result = $row;
        }
        return $result;
    }

    private function computeCfa(CfaRule $rule, RegisterState $registers, int $pid): ?int
    {
        return match ($rule->type) {
            CfaRuleType::RegisterOffset => $registers->get($rule->register) + $rule->offset,
            CfaRuleType::Expression => $this->evaluateExpression(
                $rule->expressionBytes,
                $registers,
                $pid,
            ),
        };
    }

    private function applyRegisterRule(
        RegisterRule $rule,
        int $cfa,
        RegisterState $registers,
        int $pid,
    ): ?int {
        return match ($rule->type) {
            RegisterRuleType::Undefined => null,
            RegisterRuleType::SameValue => $registers->get($rule->value),
            RegisterRuleType::Offset => $this->readMemoryInt64($pid, $cfa + $rule->value),
            RegisterRuleType::ValOffset => $cfa + $rule->value,
            RegisterRuleType::Register => $registers->get($rule->value),
            RegisterRuleType::Expression => $this->readMemoryInt64(
                $pid,
                $this->evaluateCfaExpression($rule->expressionBytes, $cfa, $registers, $pid),
            ),
            RegisterRuleType::ValExpression => $this->evaluateCfaExpression(
                $rule->expressionBytes,
                $cfa,
                $registers,
                $pid,
            ),
        };
    }

    private function evaluateExpression(string $bytes, RegisterState $registers, int $pid): ?int
    {
        try {
            return $this->dwarfExpression->evaluate(
                $bytes,
                $registers->toArray(),
                $this->memoryReader,
                $pid,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function evaluateCfaExpression(
        string $bytes,
        int $cfa,
        RegisterState $registers,
        int $pid,
    ): int {
        // For register rules with expressions, CFA is pushed onto stack first
        // We prepend a synthetic DW_OP_constu + CFA value
        // Actually, the DWARF spec says the CFA is pushed before expression evaluation
        // So we evaluate with CFA as initial stack value
        $regs_with_cfa = $registers->toArray();

        // Create a modified expression that pushes CFA first
        // Use DW_OP_constu (0x10) + ULEB128(cfa) + original expression
        $prefix = chr(0x10); // DW_OP_constu
        $encoded_cfa = '';
        $val = $cfa;
        do {
            $byte = $val & 0x7f;
            $val >>= 7;
            if ($val !== 0) {
                $byte |= 0x80;
            }
            $encoded_cfa .= chr($byte);
        } while ($val !== 0);

        return $this->dwarfExpression->evaluate(
            $prefix . $encoded_cfa . $bytes,
            $regs_with_cfa,
            $this->memoryReader,
            $pid,
        );
    }

    private function readMemoryInt64(int $pid, int $address): ?int
    {
        try {
            $buf = $this->memoryReader->read($pid, $address, 8);
            return $this->integerReader->read64(new CDataByteReader($buf), 0)->toInt();
        } catch (\Throwable) {
            return null;
        }
    }

    private function framePointerUnwind(int $pid, RegisterState $registers): ?RegisterState
    {
        $rbp = $registers->getRbp();
        if ($rbp === 0) {
            return null;
        }

        try {
            // Standard frame pointer chain: [rbp] = saved rbp, [rbp+8] = return address
            $saved_rbp = $this->readMemoryInt64($pid, $rbp);
            $return_address = $this->readMemoryInt64($pid, $rbp + 8);

            if ($saved_rbp === null || $return_address === null || $return_address === 0) {
                return null;
            }

            // Sanity: saved RBP should be higher than current (stack grows down)
            if ($saved_rbp !== 0 && $saved_rbp <= $rbp) {
                return null;
            }

            $new_registers = new RegisterState();
            $new_registers->set(RegisterState::RIP, $return_address);
            $new_registers->set(RegisterState::RSP, $rbp + 16);
            $new_registers->set(RegisterState::RBP, $saved_rbp);
            return $new_registers;
        } catch (\Throwable) {
            return null;
        }
    }

    private function findExecutableArea(array $areas): ?ProcessMemoryArea
    {
        foreach ($areas as $area) {
            if ($area->attribute->execute) {
                return $area;
            }
        }
        return null;
    }
}
