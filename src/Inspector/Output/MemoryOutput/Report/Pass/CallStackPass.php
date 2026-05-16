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

namespace Reli\Inspector\Output\MemoryOutput\Report\Pass;

use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\FindingConfidence;
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\NodeLabeler;

/**
 * Surfaces the call stack(s) in Overview.
 *
 * Most snapshots have a single `call_frames` root — the program's
 * stack at the moment reli read EG(current_execute_data). When the
 * snapshot was captured via the sidecar on `memory_limit` and the
 * `--memory-limit-error-*` traceback hack succeeded, a second
 * `memory_limit_call_frames` root is also present, carrying the real
 * pre-fatal stack (recovered by linearly scanning the VM stack for
 * the op_array referenced by the user's file:line). In that case
 * the at-capture stack is just the shutdown handler that woke reli,
 * so we surface the recovered stack as the primary one and tuck the
 * at-capture one underneath.
 */
final class CallStackPass implements PassInterface
{
    /**
     * @param array<int, string>|null $frame_labels Pre-loaded frame labels (binary path)
     * @param array<int, string>|null $canonical_names Pre-loaded class/
     *     method/function canonical names (binary path)
     */
    public function __construct(
        private \PDO $db,
        private int $run_id,
        private GraphSubstrate $substrate,
        private ?array $frame_labels = null,
        private ?array $canonical_names = null,
    ) {
    }

    /**
     * @return list<Finding>
     */
    #[\Override]
    public function analyze(): array
    {
        $oom_root = null;
        $capture_root = null;
        foreach ($this->substrate->getRoots() as $root_id) {
            if ($this->substrate->getNodeType($root_id) !== 'CallFramesContext') {
                continue;
            }
            $name = $this->substrate->getTreeLinkName($root_id);
            if ($name === 'memory_limit_call_frames') {
                $oom_root = $root_id;
            } elseif ($name === 'call_frames') {
                $capture_root = $root_id;
            }
        }

        $labeler = new NodeLabeler(
            $this->db,
            $this->run_id,
            $this->frame_labels,
            $this->canonical_names,
        );

        $findings = [];
        if ($oom_root !== null) {
            $finding = $this->buildFinding(
                $oom_root,
                $labeler,
                'Call Stack at memory_limit:',
            );
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }
        if ($capture_root !== null) {
            // When the OOM-time stack is available, the at-capture
            // stack is just the shutdown handler that signalled the
            // sidecar. Keep it for diagnostic completeness but mark
            // the header so the formatter can render it as a sub-block.
            $header = $oom_root !== null
                ? 'Captured inside shutdown handler:'
                : 'Call Stack at capture:';
            $finding = $this->buildFinding(
                $capture_root,
                $labeler,
                $header,
            );
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function buildFinding(
        int $root_id,
        NodeLabeler $labeler,
        string $header,
    ): ?Finding {
        $framesByNo = [];
        foreach ($this->substrate->getChildren($root_id) as $child_id) {
            $link_name = $this->substrate->getTreeLinkName($child_id) ?? '?';
            // Call Stack is the one rendering site that benefits from
            // the line-number suffix ("paused at line N of fn"); every
            // other consumer of NodeLabeler treats `:lineno` as noise
            // and gets the path form ("fn()") by default.
            $label = $labeler->resolvePathLabel($link_name, $child_id, include_call_site: true);
            // resolvePathLabel returns the link_name unchanged when
            // the child has no function_name attribute. That can't
            // happen for a real CallFrameContext but we handle it
            // gracefully so a malformed dump doesn't show "0", "1",
            // ... as the call stack.
            if ($label === $link_name) {
                $label = '?';
            }
            $framesByNo[(int)$link_name] = $label;
        }
        if ($framesByNo === []) {
            return null;
        }
        ksort($framesByNo);
        $frames = array_values($framesByNo);

        $lines = [];
        foreach ($frames as $i => $frame) {
            $lines[] = "#{$i} {$frame}";
        }

        return new Finding(
            kind: 'call_stack',
            severity: FindingSeverity::Info,
            confidence: FindingConfidence::High,
            summary: 'Call stack: ' . implode(' -> ', $frames),
            facts: [
                'frames' => $frames,
                'depth' => count($frames),
                'header' => $header,
            ],
            hypothesis: implode("\n", $lines),
        );
    }
}
