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

namespace Reli\Inspector\Watch;

/**
 * Identifies a variable to read from a target process.
 *
 * Expression format: scope::name
 * Examples:
 *   global::$cache
 *   local::App\Service::process()$retries
 *   static::App\Cache::$entries
 *   func_static::App\retry()$attempt
 */
final class VariableSpec
{
    public function __construct(
        public readonly string $scope,
        public readonly string $var_name,
        public readonly string $lookup_key,
    ) {
    }

    /**
     * Parse "scope::name" format.
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $expression): self
    {
        $parts = explode('::', $expression, 2);
        if (count($parts) !== 2 || $parts[1] === '') {
            throw new \InvalidArgumentException(
                "Invalid variable expression: '{$expression}'."
                    . ' Expected: scope::name'
                    . ' (e.g., global::$var,'
                    . ' local::func()$var,'
                    . ' static::Class::$prop,'
                    . ' func_static::func()$var)'
            );
        }
        $scope = $parts[0];
        $name = $parts[1];

        self::validateScope($scope, $name, $expression);

        return new self(
            scope: $scope,
            var_name: $name,
            lookup_key: $scope . '::' . $name,
        );
    }

    private static function validateScope(
        string $scope,
        string $name,
        string $expression,
    ): void {
        if (
            $scope === 'local'
            || $scope === 'func_static'
        ) {
            if (!str_contains($name, ')$')) {
                throw new \InvalidArgumentException(
                    "Invalid variable expression:"
                        . " '{$expression}'."
                        . " {$scope}:: requires"
                        . ' function()$variable syntax'
                        . " (e.g., {$scope}"
                        . '::myFunc()$var)',
                );
            }
        }
        if ($scope === 'global') {
            if (!str_starts_with($name, '$')) {
                throw new \InvalidArgumentException(
                    'Invalid variable expression:'
                        . " '{$expression}'."
                        . ' global:: requires $ prefix'
                        . ' (e.g., global::$var)',
                );
            }
        }
        $valid_scopes = ['global', 'local', 'static', 'func_static'];
        if (!in_array($scope, $valid_scopes, true)) {
            throw new \InvalidArgumentException(
                "Invalid variable expression:"
                    . " '{$expression}'."
                    . " Unknown scope '{$scope}'."
                    . ' Valid scopes: '
                    . implode(', ', $valid_scopes),
            );
        }
    }
}
