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
 * Shows the call stack at the time of snapshot capture.
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
        $callFramesNodeId = null;
        foreach ($this->substrate->iterateNodeSizes() as $node_id => $_) {
            if ($this->substrate->getNodeType($node_id) === 'CallFramesContext') {
                $callFramesNodeId = $node_id;
                break;
            }
        }
        if ($callFramesNodeId === null) {
            return [];
        }

        $labeler = new NodeLabeler(
            $this->db,
            $this->run_id,
            $this->frame_labels,
            $this->canonical_names,
        );
        $framesByNo = [];
        foreach ($this->substrate->getChildren($callFramesNodeId) as $child_id) {
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
            return [];
        }
        ksort($framesByNo);
        return $this->buildFindings(array_values($framesByNo));
    }

    /**
     * @param  list<string> $frames frame labels in call order
     * @return list<Finding>
     */
    private function buildFindings(array $frames): array
    {
        $lines = [];
        foreach ($frames as $i => $frame) {
            $lines[] = "#{$i} {$frame}";
        }

        return [
            new Finding(
                kind: 'call_stack',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::High,
                summary: 'Call stack: ' . implode(' -> ', $frames),
                facts: [
                    'frames' => $frames,
                    'depth' => count($frames),
                ],
                hypothesis: implode("\n", $lines),
            ),
        ];
    }
}
