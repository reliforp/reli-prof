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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector;

/**
 * The design's HIGH-confidence function-pointer detector (section C).
 *
 * Reads the leading 8-byte qword as a candidate function pointer and
 * uses the {@see ModuleResolverInterface} from {@see DetectorContext}
 * to confirm the address sits inside an executable region. When it does,
 * label the slot with the loaded module's basename — the validator's
 * issue #88 retest needs to know whether the leaked handler addresses
 * point into `libphp.so` (zend_op handlers) or `libuv.so.1` (libuv
 * callback table) to disambiguate the leak source.
 *
 * Confidence is HIGH when both axes match (executable region + named
 * module), MEDIUM for executable but unnamed mappings (anonymous JIT
 * pages), null otherwise. Without a module resolver in context the
 * detector can't do its job and bows out — same behaviour as a
 * detector that doesn't apply to this slot.
 *
 * Symbol-level resolution (`execute_ex+0xN` style) is the next step
 * past this commit; it needs an ELF symbol table walker plumbed
 * through, which is heavier than the module-level cut shipping here.
 *
 * Runs ahead of {@see OpArrayDetector} in the registry order so a
 * single-pointer "this looks like a vtable / handler" verdict can
 * dominate slots where the OpArrayDetector would also have matched —
 * a labelled module name is more useful to the user than a stride
 * count when both apply.
 */
final class FunctionPointerDetector implements ShapeDetector
{
    /**
     * Userspace pointer floor — 0x100000 covers ELF text segments on
     * Linux x86_64 (the typical PIE base, plus standard non-PIE
     * 0x400000). Below this is either NULL or kernel-page territory.
     */
    private const TEXT_MIN = 0x100000;

    /** Userspace ceiling — same as the rest of the detectors. */
    private const TEXT_MAX = 0x800000000000;

    #[\Override]
    public function name(): string
    {
        return 'FunctionPointerDetector';
    }

    #[\Override]
    public function detect(
        string $fingerprint,
        int $bin_size,
        ?DetectorContext $context = null,
    ): ?ShapeDetection {
        if ($context === null || $context->module_resolver === null) {
            return null;
        }
        if (strlen($fingerprint) < 8) {
            return null;
        }

        /** @var array{1: int} $u */
        $u = unpack('P', substr($fingerprint, 0, 8));
        $ptr = $u[1];

        if ($ptr < self::TEXT_MIN || $ptr >= self::TEXT_MAX) {
            return null;
        }

        $resolver = $context->module_resolver;
        if (!$resolver->isExecutable($ptr)) {
            // Pointer-shaped but not pointing into anything executable.
            // Could be a heap pointer that happens to look high; the
            // other detectors handle pointer-bearing zvals.
            return null;
        }

        $module = $resolver->moduleBasenameFor($ptr);
        if ($module === null) {
            return new ShapeDetection(
                label: sprintf('func_ptr (anon-exec @0x%x)', $ptr),
                confidence: ShapeDetection::CONFIDENCE_MEDIUM,
            );
        }

        // Symbol-level promotion: when a symbol resolver is in context
        // and lands the pointer inside a named function, render the
        // full "module:symbol+offset" so the validator can read
        // "is this an opcode handler in libphp.so or a libuv callback?"
        // off a single line in the compare output.
        $symbol_label = '';
        if ($context->symbol_resolver !== null) {
            $hit = $context->symbol_resolver->resolve($ptr);
            if ($hit !== null) {
                [$sym_name, $offset] = $hit;
                $symbol_label = $offset > 0
                    ? sprintf(':%s+0x%x', $sym_name, $offset)
                    : sprintf(':%s', $sym_name);
            }
        }

        return new ShapeDetection(
            label: sprintf('func_ptr (%s%s)', $module, $symbol_label),
            confidence: ShapeDetection::CONFIDENCE_HIGH,
        );
    }
}
