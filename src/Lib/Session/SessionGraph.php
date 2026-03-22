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

use Reli\Lib\Session\Attribute\Initial;
use Reli\Lib\Session\Attribute\Recv;
use Reli\Lib\Session\Attribute\Send;
use Reli\Lib\Session\Attribute\SessionProtocol;
use ReflectionEnum;
use ReflectionEnumUnitCase;

final class SessionGraph
{
    /**
     * @param SessionProtocol $protocol
     * @param string $initialState
     * @param array<string, list<SessionTransition>> $transitions state name => transitions
     */
    public function __construct(
        public readonly SessionProtocol $protocol,
        public readonly string $initialState,
        public readonly array $transitions,
    ) {
    }

    /**
     * @param class-string<\UnitEnum> $enumClass
     */
    public static function fromEnum(string $enumClass): self
    {
        $ref = new ReflectionEnum($enumClass);

        $protocolAttrs = $ref->getAttributes(SessionProtocol::class);
        if ($protocolAttrs === []) {
            throw new \InvalidArgumentException("{$enumClass} is not annotated with #[SessionProtocol]");
        }
        $protocol = $protocolAttrs[0]->newInstance();

        $initialState = null;
        $transitions = [];

        foreach ($ref->getCases() as $case) {
            assert($case instanceof ReflectionEnumUnitCase);
            $stateName = $case->getName();
            $transitions[$stateName] = [];

            $initialAttrs = $case->getAttributes(Initial::class);
            if ($initialAttrs !== []) {
                if ($initialState !== null) {
                    throw new \LogicException("Multiple initial states: {$initialState} and {$stateName}");
                }
                $initialState = $stateName;
            }

            foreach ($case->getAttributes(Send::class) as $attr) {
                $send = $attr->newInstance();
                $transitions[$stateName][] = new SessionTransition(
                    direction: Direction::Send,
                    messageClass: $send->messageClass,
                    nextState: $send->next,
                );
            }

            foreach ($case->getAttributes(Recv::class) as $attr) {
                $recv = $attr->newInstance();
                $transitions[$stateName][] = new SessionTransition(
                    direction: Direction::Recv,
                    messageClass: $recv->messageClass,
                    nextState: $recv->next,
                );
            }
        }

        if ($initialState === null) {
            throw new \LogicException("No initial state found in {$enumClass}");
        }

        return new self($protocol, $initialState, $transitions);
    }

    /** @return list<string> */
    public function verify(): array
    {
        $errors = [];

        // 1. All states reachable from initial
        $reachable = $this->getReachableStates($this->initialState);
        foreach (array_keys($this->transitions) as $state) {
            if (!in_array($state, $reachable, true)) {
                $errors[] = "State '{$state}' is unreachable from initial state '{$this->initialState}'";
            }
        }

        // 2. No dead-end states (every state has at least one transition)
        foreach ($this->transitions as $state => $trans) {
            if ($trans === []) {
                $errors[] = "State '{$state}' has no transitions (deadlock)";
            }
        }

        // 3. Consistent direction per state
        foreach ($this->transitions as $state => $trans) {
            if (count($trans) <= 1) {
                continue;
            }
            $directions = array_unique(array_map(fn (SessionTransition $t) => $t->direction->name, $trans));
            if (count($directions) > 1) {
                $errors[] = "State '{$state}' mixes Send and Recv transitions";
            }
        }

        // 4. All next states exist
        foreach ($this->transitions as $state => $trans) {
            foreach ($trans as $t) {
                if (!isset($this->transitions[$t->nextState])) {
                    $errors[] = "State '{$state}': transition to unknown state '{$t->nextState}'";
                }
            }
        }

        // 5. Deterministic: no duplicate message types per state
        foreach ($this->transitions as $state => $trans) {
            $seen = [];
            foreach ($trans as $t) {
                if (in_array($t->messageClass, $seen, true)) {
                    $errors[] = "State '{$state}': duplicate message type '{$t->messageClass}'";
                }
                $seen[] = $t->messageClass;
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function getReachableStates(string $from): array
    {
        $visited = [];
        $stack = [$from];
        while ($stack !== []) {
            $current = array_pop($stack);
            if (in_array($current, $visited, true)) {
                continue;
            }
            $visited[] = $current;
            foreach ($this->transitions[$current] ?? [] as $t) {
                $stack[] = $t->nextState;
            }
        }
        return $visited;
    }

    public function getDirection(string $state): Direction
    {
        $trans = $this->transitions[$state] ?? [];
        if ($trans === []) {
            throw new \LogicException("State '{$state}' has no transitions");
        }
        return $trans[0]->direction;
    }
}
