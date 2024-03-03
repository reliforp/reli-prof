<?php

/**
 * This file is part of the reliforp/reli-prof package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DI {

    use Invoker\InvokerInterface;
    use Psr\Container\ContainerInterface;

    class Container implements ContainerInterface, FactoryInterface, InvokerInterface
    {
        /**
         * @template T
         * @template TId of class-string<T>
         * @param TId|string $id
         * @return ($id is class-string ? T : mixed)
         */
        public function get(string $id)
        {
            // TODO: Implement get() method.
        }

        public function has(string $id): bool
        {
        }

        /**
         * @template T
         * @template TId of class-string<T>
         * @param TId|string $name
         * @return ($name is class-string ? T : mixed)
         */
        public function make(string $name, array $parameters = []): mixed
        {
        }

        public function call($callable, array $parameters = [])
        {
        }
    }
}
