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

namespace Reli\Inspector\Watch\Trigger;

use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\VariableValue;
use Reli\Inspector\Watch\WatchContext;

/**
 * Trigger that fires when a variable value meets a condition.
 *
 * Expression format: scope::name:op:value
 * Examples:
 *   global::cache:count_gt:10000
 *   local::retries:gte:3
 *   static::App\Cache::$size:gt:100000
 *   func_static::App\retry()$attempt:gt:10
 *   global::status:eq:error
 */
final class VariableValueTrigger implements TriggerInterface
{
    public readonly string $scope;
    public readonly string $var_name;
    public readonly string $operator;
    public readonly string $operand;
    public readonly string $lookup_key;

    public function __construct(
        private string $expression,
    ) {
        $parsed = self::parseExpression($expression);
        $this->scope = $parsed['scope'];
        $this->var_name = $parsed['name'];
        $this->operator = $parsed['op'];
        $this->operand = $parsed['value'];
        $this->lookup_key = $this->scope . '::' . $this->var_name;

        $this->validateScope();
    }

    private function validateScope(): void
    {
        if (
            $this->scope === 'local'
            || $this->scope === 'func_static'
        ) {
            if (!str_contains($this->var_name, ')$')) {
                throw new \InvalidArgumentException(
                    "Invalid watch-var expression:"
                        . " '{$this->expression}'."
                        . " {$this->scope}:: requires"
                        . ' function()$variable syntax'
                        . " (e.g., {$this->scope}"
                        . '::myFunc()$var:op:value)',
                );
            }
        }
        if ($this->scope === 'global') {
            if (!str_starts_with($this->var_name, '$')) {
                throw new \InvalidArgumentException(
                    'Invalid watch-var expression:'
                        . " '{$this->expression}'."
                        . ' global:: requires $ prefix'
                        . ' (e.g., global::$var:op:value)',
                );
            }
        }
    }

    #[\Override]
    public function name(): string
    {
        return 'watch-var';
    }

    #[\Override]
    public function requiresCallTrace(): bool
    {
        return false;
    }

    #[\Override]
    public function requiresDeepInspection(): bool
    {
        return true;
    }

    #[\Override]
    public function evaluate(WatchContext $context): ?TriggerEvent
    {
        $var = $context->variable_values[$this->lookup_key] ?? null;
        if ($var === null) {
            return null;
        }

        if ($this->matches($var)) {
            return new TriggerEvent(
                trigger_name: $this->name(),
                description: sprintf(
                    '%s %s %s (actual: %s)',
                    $this->lookup_key,
                    $this->operator,
                    $this->operand,
                    $this->describeValue($var),
                ),
                timestamp: $context->timestamp,
                value: is_numeric($var->scalar_value)
                    ? (float)$var->scalar_value
                    : (float)($var->array_count ?? 0),
            );
        }

        return null;
    }

    private function matches(VariableValue $var): bool
    {
        return match ($this->operator) {
            'eq' => $this->matchEq($var),
            'ne' => !$this->matchEq($var),
            'gt' => $this->numericCompare($var) > 0,
            'lt' => $this->numericCompare($var) < 0,
            'gte' => $this->numericCompare($var) >= 0,
            'lte' => $this->numericCompare($var) <= 0,
            'contains' => is_string($var->scalar_value)
                && str_contains($var->scalar_value, $this->operand),
            'count_gt' => ($var->array_count ?? 0) > (int)$this->operand,
            'count_lt' => ($var->array_count ?? 0) < (int)$this->operand,
            'count_eq' => ($var->array_count ?? 0) === (int)$this->operand,
            'is_null' => $var->type === VariableValue::TYPE_NULL,
            default => false,
        };
    }

    private function matchEq(VariableValue $var): bool
    {
        if ($var->type === VariableValue::TYPE_BOOL) {
            return (string)$var->scalar_value === $this->operand
                || ($var->scalar_value === true && $this->operand === 'true')
                || ($var->scalar_value === false && $this->operand === 'false');
        }
        if ($var->type === VariableValue::TYPE_NULL) {
            return $this->operand === 'null';
        }
        return (string)$var->scalar_value === $this->operand;
    }

    private function numericCompare(VariableValue $var): int
    {
        $actual = match ($var->type) {
            VariableValue::TYPE_LONG,
            VariableValue::TYPE_DOUBLE => (float)$var->scalar_value,
            VariableValue::TYPE_ARRAY => (float)($var->array_count ?? 0),
            default => null,
        };
        if ($actual === null) {
            return 0;
        }
        $threshold = (float)$this->operand;
        return $actual <=> $threshold;
    }

    private function describeValue(VariableValue $var): string
    {
        return match ($var->type) {
            VariableValue::TYPE_ARRAY => sprintf(
                'array(%d)',
                $var->array_count ?? 0,
            ),
            VariableValue::TYPE_NULL => 'null',
            VariableValue::TYPE_BOOL => $var->scalar_value === true ? 'true' : 'false',
            default => (string)($var->scalar_value ?? ''),
        };
    }

    /**
     * @return array{scope: string, name: string, op: string, value: string}
     */
    public static function parseExpression(string $expr): array
    {
        // Format: scope::name:op:value
        // The scope part uses :: as delimiter
        // After scope::name, we have :op:value
        $parts = explode('::', $expr, 2);
        if (count($parts) !== 2 || $parts[1] === '') {
            throw new \InvalidArgumentException(
                "Invalid watch-var expression: {$expr}."
                    . ' Expected: scope::name:op:value'
            );
        }
        $scope = $parts[0];
        $rest = $parts[1];

        // For static properties: App\Cache::$size:op:value
        // The name may contain :: for class::$prop
        // We need to find :op:value at the end
        // Operators are known: eq,ne,gt,lt,gte,lte,contains,
        //   count_gt,count_lt,count_eq,is_null
        $ops = [
            'count_gt', 'count_lt', 'count_eq',
            'contains', 'is_null',
            'gte', 'lte',
            'gt', 'lt', 'eq', 'ne',
        ];

        foreach ($ops as $op) {
            $needle = ':' . $op . ':';
            $pos = strrpos($rest, $needle);
            if ($pos !== false) {
                $name = substr($rest, 0, $pos);
                $value = substr($rest, $pos + strlen($needle));
                return [
                    'scope' => $scope,
                    'name' => $name,
                    'op' => $op,
                    'value' => $value,
                ];
            }
            // is_null has no value part
            if ($op === 'is_null') {
                $needle_no_val = ':' . $op;
                if (str_ends_with($rest, $needle_no_val)) {
                    $name = substr(
                        $rest,
                        0,
                        strlen($rest) - strlen($needle_no_val),
                    );
                    return [
                        'scope' => $scope,
                        'name' => $name,
                        'op' => 'is_null',
                        'value' => '',
                    ];
                }
            }
        }

        throw new \InvalidArgumentException(
            "Invalid watch-var expression: {$expr}."
                . ' No recognized operator found.'
                . ' Valid operators: eq, ne, gt, lt, gte, lte,'
                . ' contains, count_gt, count_lt, count_eq, is_null'
        );
    }
}
