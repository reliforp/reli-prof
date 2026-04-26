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

namespace Reli\Inspector\Output\MemoryOutput\Report\Substrate;

use Reli\BaseTestCase;
use Reli\Inspector\Output\MemoryOutput\Report\Finding;
use Reli\Inspector\Output\MemoryOutput\Report\FindingConfidence;
use Reli\Inspector\Output\MemoryOutput\Report\FindingSeverity;

class FindingClustererTest extends BaseTestCase
{
    /**
     * @param array<string, mixed> $facts
     * @param list<int> $evidence_node_ids
     */
    private function finding(
        string $kind,
        array $facts = [],
        array $evidence_node_ids = [],
        FindingSeverity $severity = FindingSeverity::Medium,
    ): Finding {
        return new Finding(
            kind: $kind,
            severity: $severity,
            confidence: FindingConfidence::Medium,
            summary: $kind . ' summary',
            facts: $facts,
            evidence_node_ids: $evidence_node_ids,
        );
    }

    public function testFindingsSharingClassNameClusterTogether(): void
    {
        // empty_object / structural_duplicate / expensive_property all
        // publish `class_name`. Different kinds with the same class_name
        // mean the same target seen through different detectors — that's
        // exactly what S12 collapses.
        $a = $this->finding('expensive_property', ['class_name' => 'App\\Envelope']);
        $b = $this->finding('empty_object', ['class_name' => 'App\\Envelope']);
        $c = $this->finding('structural_duplicate', ['class_name' => 'App\\Envelope']);

        $clusters = FindingClusterer::cluster([$a, $b, $c]);

        $this->assertCount(1, $clusters);
        $this->assertSame($a, $clusters[0]['representative']);
        $this->assertSame([$b, $c], $clusters[0]['others']);
    }

    public function testOwnedClassAndTargetClassAliasIntoTheSameKey(): void
    {
        // ownership_pattern publishes `owned_class`, dedup_candidate
        // publishes `target_class`. Both name the class the finding is
        // *about*, so they should cluster with class_name-keyed findings
        // for the same class.
        $a = $this->finding('expensive_property', ['class_name' => 'App\\Envelope']);
        $b = $this->finding('ownership_pattern', ['owned_class' => 'App\\Envelope']);
        $c = $this->finding('dedup_candidate', ['target_class' => 'App\\Envelope']);

        $clusters = FindingClusterer::cluster([$a, $b, $c]);

        $this->assertCount(1, $clusters);
        $this->assertSame($a, $clusters[0]['representative']);
        $this->assertSame([$b, $c], $clusters[0]['others']);
    }

    public function testNodeIdFallbackKeyClustersWhenNoClassFactPresent(): void
    {
        // choke_point and bottleneck_path don't publish a class_name —
        // their target identity is the evidence node. Two findings that
        // both point at node 42 should still cluster.
        $a = $this->finding('choke_point', evidence_node_ids: [42]);
        $b = $this->finding('bottleneck_path', evidence_node_ids: [42, 17, 9]);

        $clusters = FindingClusterer::cluster([$a, $b]);

        $this->assertCount(1, $clusters);
        $this->assertSame($a, $clusters[0]['representative']);
        $this->assertSame([$b], $clusters[0]['others']);
    }

    public function testClassKeyTakesPrecedenceOverNodeKey(): void
    {
        // Two findings carrying the same evidence_node_ids[0] but
        // different class_name should NOT cluster — the class is the
        // more specific target.
        $a = $this->finding('expensive_property', ['class_name' => 'App\\Foo'], [42]);
        $b = $this->finding('expensive_property', ['class_name' => 'App\\Bar'], [42]);

        $clusters = FindingClusterer::cluster([$a, $b]);

        $this->assertCount(2, $clusters);
        $this->assertSame($a, $clusters[0]['representative']);
        $this->assertSame($b, $clusters[1]['representative']);
    }

    public function testFindingWithoutClassFactsOrNodeIdsRendersAsSingleton(): void
    {
        // companion_cluster publishes a `classes` array (not a single
        // class_name) and no evidence_node_ids — by design, it's about
        // a group, not a single target. Such findings have no useful
        // grouping key and stand alone.
        $a = $this->finding('companion_cluster', ['classes' => [['name' => 'X']]]);
        $b = $this->finding('expensive_property', ['class_name' => 'App\\Foo']);

        $clusters = FindingClusterer::cluster([$a, $b]);

        $this->assertCount(2, $clusters);
        $this->assertSame($a, $clusters[0]['representative']);
        $this->assertSame([], $clusters[0]['others']);
        $this->assertSame($b, $clusters[1]['representative']);
    }

    public function testRepresentativeIsTheFirstInputForEachKey(): void
    {
        // Caller is responsible for sorting by severity. The clusterer
        // takes the first finding it sees for each key as the rep —
        // a severity-sorted input therefore lands the highest-severity
        // entry as head naturally, without the clusterer needing to
        // duplicate severity logic.
        $high = $this->finding(
            'expensive_property',
            ['class_name' => 'App\\Envelope'],
            severity: FindingSeverity::High,
        );
        $medium = $this->finding(
            'empty_object',
            ['class_name' => 'App\\Envelope'],
            severity: FindingSeverity::Medium,
        );

        $clusters = FindingClusterer::cluster([$high, $medium]);

        $this->assertCount(1, $clusters);
        $this->assertSame($high, $clusters[0]['representative']);
    }

    public function testInsertionOrderIsPreservedAcrossSingletonsAndGroups(): void
    {
        // Class group A starts at position 0, an ungroupable singleton
        // at position 1, class group B at position 2, and a member of
        // group A at position 3. Output should still read [A, singleton, B]
        // — `array_values` over an associative array would put the
        // singletons after the groups, scrambling severity order.
        $a1 = $this->finding('expensive_property', ['class_name' => 'A']);
        $singleton = $this->finding('companion_cluster', ['classes' => []]);
        $b1 = $this->finding('empty_object', ['class_name' => 'B']);
        $a2 = $this->finding('structural_duplicate', ['class_name' => 'A']);

        $clusters = FindingClusterer::cluster([$a1, $singleton, $b1, $a2]);

        $this->assertCount(3, $clusters);
        $this->assertSame($a1, $clusters[0]['representative']);
        $this->assertSame([$a2], $clusters[0]['others']);
        $this->assertSame($singleton, $clusters[1]['representative']);
        $this->assertSame($b1, $clusters[2]['representative']);
    }

    public function testEmptyInputProducesEmptyOutput(): void
    {
        $this->assertSame([], FindingClusterer::cluster([]));
    }

    public function testSummariseOtherKindsCountsRepeatsAndPreservesOrder(): void
    {
        // The `Also detected as` line should read in encounter order
        // — chronological "and we saw", not re-sorted alphabetic. Repeats
        // collapse with `×N`.
        $others = [
            $this->finding('empty_object', ['class_name' => 'X']),
            $this->finding('structural_duplicate', ['class_name' => 'X']),
            $this->finding('empty_object', ['class_name' => 'X']),
            $this->finding('structural_duplicate', ['class_name' => 'X']),
            $this->finding('structural_duplicate', ['class_name' => 'X']),
            $this->finding('dedup_candidate', ['target_class' => 'X']),
        ];

        $this->assertSame(
            'empty_object×2, structural_duplicate×3, dedup_candidate',
            FindingClusterer::summariseOtherKinds($others),
        );
    }

    public function testSummariseOtherKindsHandlesEmptyList(): void
    {
        $this->assertSame('', FindingClusterer::summariseOtherKinds([]));
    }
}
