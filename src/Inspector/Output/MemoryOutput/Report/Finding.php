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

namespace Reli\Inspector\Output\MemoryOutput\Report;

final class Finding
{
    /**
     * @param array<string, mixed> $facts
     * @param list<string> $next_checks
     * @param list<int> $evidence_node_ids
     * @param list<string> $representative_paths
     */
    public function __construct(
        public readonly string $kind,
        public readonly FindingSeverity $severity,
        public readonly FindingConfidence $confidence,
        public readonly string $summary,
        public readonly array $facts = [],
        public readonly string $hypothesis = '',
        public readonly array $next_checks = [],
        public readonly int $impact_bytes = 0,
        public readonly array $evidence_node_ids = [],
        public readonly array $representative_paths = [],
        public readonly string $replay_query = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [
            'kind' => $this->kind,
            'severity' => $this->severity->value,
            'confidence' => $this->confidence->value,
            'summary' => $this->summary,
        ];

        if ($this->facts !== []) {
            $result['facts'] = $this->facts;
        }
        if ($this->hypothesis !== '') {
            $result['hypothesis'] = $this->hypothesis;
        }
        if ($this->next_checks !== []) {
            $result['next_checks'] = $this->next_checks;
        }
        if ($this->impact_bytes > 0) {
            $result['impact_bytes'] = $this->impact_bytes;
        }
        if ($this->evidence_node_ids !== []) {
            $result['evidence_node_ids'] = $this->evidence_node_ids;
        }
        if ($this->representative_paths !== []) {
            $result['representative_paths'] = $this->representative_paths;
        }
        if ($this->replay_query !== '') {
            $result['replay_query'] = $this->replay_query;
        }

        return $result;
    }
}
