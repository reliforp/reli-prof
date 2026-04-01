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

final class RetainedSizeConfidencePass implements PassInterface
{
    public function __construct(
        private GraphSubstrate $substrate,
    ) {
    }

    /** @return list<Finding> */
    #[\Override]
    public function analyze(): array
    {
        $scc_count = count($this->substrate->scc_profiles);

        if ($scc_count === 0) {
            return [
                new Finding(
                    kind: 'retained_exact',
                    severity: FindingSeverity::Info,
                    confidence: FindingConfidence::High,
                    summary: 'No cycles — retained size is exact',
                    facts: ['scc_count' => 0],
                    hypothesis: 'Graph is a DAG; tree-based retained sizes are precise',
                ),
            ];
        }

        $total_scc_nodes = 0;
        foreach ($this->substrate->scc_profiles as $profile) {
            $total_scc_nodes += $profile['node_count'];
        }

        return [
            new Finding(
                kind: 'retained_approximate',
                severity: FindingSeverity::Info,
                confidence: FindingConfidence::Medium,
                summary: sprintf(
                    '%d cycle%s (%d nodes) — retained size is approximate; collapse to DAG for exact',
                    $scc_count,
                    $scc_count > 1 ? 's' : '',
                    $total_scc_nodes,
                ),
                facts: [
                    'scc_count' => $scc_count,
                    'total_scc_nodes' => $total_scc_nodes,
                ],
                hypothesis: 'Cycles make tree-based retained sizes approximate; SCC condensation provides exact values',
            ),
        ];
    }
}
