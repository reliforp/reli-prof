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

    /**
     * Re-hydrate a Finding previously produced by {@see self::toArray}.
     * Used by ParallelPassRunner to reconstitute findings that were
     * serialised out to a temp file by a forked worker process.
     *
     * @param array<string, mixed> $data
     * @psalm-suppress MixedArgumentTypeCoercion, MixedArgument, MixedAssignment
     */
    public static function fromArray(array $data): self
    {
        return new self(
            kind: (string)($data['kind'] ?? ''),
            severity: FindingSeverity::from((string)($data['severity'] ?? 'info')),
            confidence: FindingConfidence::from((string)($data['confidence'] ?? 'low')),
            summary: (string)($data['summary'] ?? ''),
            facts: is_array($data['facts'] ?? null) ? $data['facts'] : [],
            hypothesis: (string)($data['hypothesis'] ?? ''),
            next_checks: is_array($data['next_checks'] ?? null) ? $data['next_checks'] : [],
            impact_bytes: (int)($data['impact_bytes'] ?? 0),
            evidence_node_ids: is_array($data['evidence_node_ids'] ?? null)
                ? $data['evidence_node_ids']
                : [],
            representative_paths: is_array($data['representative_paths'] ?? null)
                ? $data['representative_paths']
                : [],
            replay_query: (string)($data['replay_query'] ?? ''),
        );
    }
}
