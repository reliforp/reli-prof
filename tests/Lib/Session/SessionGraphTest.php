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

namespace Reli\Lib\Session;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Reli\Lib\Session\Attribute\SessionProtocol;

final class SessionGraphTest extends TestCase
{
    private function protocol(): SessionProtocol
    {
        return new SessionProtocol(
            name: 'Test',
            workerNamespace: 'Test\\Worker',
            controllerNamespace: 'Test\\Controller',
        );
    }

    private function graph(string $initial, array $transitions): SessionGraph
    {
        return new SessionGraph($this->protocol(), $initial, $transitions);
    }

    #[Test]
    public function valid_graph_has_no_errors(): void
    {
        // ?A . !B (simple ping-pong loop)
        $graph = $this->graph('S0', [
            'S0' => [
                new SessionTransition(Direction::Recv, \stdClass::class, 'S1'),
            ],
            'S1' => [
                new SessionTransition(Direction::Send, \stdClass::class, 'S0'),
            ],
        ]);

        $this->assertSame([], $graph->verify());
    }

    #[Test]
    public function detects_unreachable_state(): void
    {
        $graph = $this->graph('S0', [
            'S0' => [
                new SessionTransition(Direction::Recv, \stdClass::class, 'S0'),
            ],
            'Orphan' => [
                new SessionTransition(Direction::Send, \stdClass::class, 'Orphan'),
            ],
        ]);

        $errors = $graph->verify();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Orphan', $errors[0]);
        $this->assertStringContainsString('unreachable', $errors[0]);
    }

    #[Test]
    public function detects_deadlock_state(): void
    {
        $graph = $this->graph('S0', [
            'S0' => [
                new SessionTransition(Direction::Recv, \stdClass::class, 'Dead'),
            ],
            'Dead' => [],
        ]);

        $errors = $graph->verify();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Dead', $errors[0]);
        $this->assertStringContainsString('deadlock', $errors[0]);
    }

    #[Test]
    public function detects_mixed_directions(): void
    {
        $graph = $this->graph('S0', [
            'S0' => [
                new SessionTransition(Direction::Send, \stdClass::class, 'S0'),
                new SessionTransition(Direction::Recv, \ArrayObject::class, 'S0'),
            ],
        ]);

        $errors = $graph->verify();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('S0', $errors[0]);
        $this->assertStringContainsString('mixes Send and Recv', $errors[0]);
    }

    #[Test]
    public function detects_unknown_next_state(): void
    {
        $graph = $this->graph('S0', [
            'S0' => [
                new SessionTransition(Direction::Send, \stdClass::class, 'NoSuchState'),
            ],
        ]);

        $errors = $graph->verify();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('NoSuchState', $errors[0]);
        $this->assertStringContainsString('unknown state', $errors[0]);
    }

    #[Test]
    public function detects_duplicate_message_type(): void
    {
        $graph = $this->graph('S0', [
            'S0' => [
                new SessionTransition(Direction::Send, \stdClass::class, 'S0'),
                new SessionTransition(Direction::Send, \stdClass::class, 'S0'),
            ],
        ]);

        $errors = $graph->verify();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('duplicate message type', $errors[0]);
        $this->assertStringContainsString('stdClass', $errors[0]);
    }

    #[Test]
    public function multiple_send_transitions_with_distinct_messages_is_valid(): void
    {
        // ⊕{ !A.S0, !B.S0 }
        $graph = $this->graph('S0', [
            'S0' => [
                new SessionTransition(Direction::Send, \stdClass::class, 'S0'),
                new SessionTransition(Direction::Send, \ArrayObject::class, 'S0'),
            ],
        ]);

        $this->assertSame([], $graph->verify());
    }

    #[Test]
    public function detects_multiple_errors_at_once(): void
    {
        $graph = $this->graph('S0', [
            'S0' => [
                new SessionTransition(Direction::Send, \stdClass::class, 'Missing'),
                new SessionTransition(Direction::Recv, \ArrayObject::class, 'S0'),
            ],
            'Orphan' => [],
        ]);

        $errors = $graph->verify();
        // unreachable(Orphan), deadlock(Orphan), mixed directions(S0), unknown state(Missing)
        $this->assertGreaterThanOrEqual(3, count($errors));
    }

    #[Test]
    public function fromEnum_parses_real_protocol(): void
    {
        $graph = SessionGraph::fromEnum(
            \Reli\Inspector\Daemon\Reader\Protocol\PhpReaderSession::class,
        );

        $this->assertSame('AwaitingSettings', $graph->initialState);
        $this->assertArrayHasKey('AwaitingSettings', $graph->transitions);
        $this->assertArrayHasKey('AwaitingAttach', $graph->transitions);
        $this->assertArrayHasKey('Tracing', $graph->transitions);
        $this->assertSame([], $graph->verify());
    }

    #[Test]
    public function getDirection_returns_direction_for_state(): void
    {
        $graph = $this->graph('S0', [
            'S0' => [
                new SessionTransition(Direction::Recv, \stdClass::class, 'S1'),
            ],
            'S1' => [
                new SessionTransition(Direction::Send, \stdClass::class, 'S0'),
            ],
        ]);

        $this->assertSame(Direction::Recv, $graph->getDirection('S0'));
        $this->assertSame(Direction::Send, $graph->getDirection('S1'));
    }

    #[Test]
    public function getDirection_throws_for_empty_state(): void
    {
        $graph = $this->graph('S0', [
            'S0' => [],
        ]);

        $this->expectException(\LogicException::class);
        $graph->getDirection('S0');
    }

    #[Test]
    public function detects_nonexistent_message_class(): void
    {
        $graph = $this->graph('S0', [
            'S0' => [
                new SessionTransition(Direction::Send, 'App\\NoSuch\\FakeMessage', 'S0'),
            ],
        ]);

        $errors = $graph->verify();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('FakeMessage', $errors[0]);
        $this->assertStringContainsString('does not exist', $errors[0]);
    }

    #[Test]
    public function accepts_existing_message_class(): void
    {
        $graph = $this->graph('S0', [
            'S0' => [
                new SessionTransition(Direction::Send, \stdClass::class, 'S0'),
            ],
        ]);

        $errors = $graph->verify();
        $this->assertSame([], $errors);
    }
}
