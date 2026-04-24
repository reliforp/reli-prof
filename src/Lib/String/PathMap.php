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

namespace Reli\Lib\String;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Longest-prefix rewrite for recorded file paths.
 *
 * Given a set of "from=to" mappings, rewrites the longest matching
 * prefix. Used by rbt:explore and rmem:explore so paths recorded on a
 * remote host can be mapped to something the local IDE or a URL
 * template can open.
 *
 * @psalm-immutable
 */
final class PathMap
{
    /** @param array<string, string> $entries source-prefix => local-prefix */
    public function __construct(
        private readonly array $entries,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->entries;
    }

    public function map(string $path): string
    {
        if ($this->entries === []) {
            return $path;
        }
        $best_prefix = '';
        $best_replacement = '';
        foreach ($this->entries as $from => $to) {
            if (str_starts_with($path, $from) && strlen($from) > strlen($best_prefix)) {
                $best_prefix = $from;
                $best_replacement = $to;
            }
        }
        if ($best_prefix === '') {
            return $path;
        }
        return $best_replacement . substr($path, strlen($best_prefix));
    }

    /**
     * Parse CLI option values of the form "from=to" into a PathMap.
     *
     * @param list<string> $raw
     * @throws \InvalidArgumentException on malformed entries
     */
    public static function parseOption(array $raw): self
    {
        $entries = [];
        foreach ($raw as $entry) {
            $eq = strpos($entry, '=');
            if ($eq === false) {
                throw new \InvalidArgumentException(
                    "Invalid --path-map value \"{$entry}\": expected \"from=to\" format."
                );
            }
            $from = substr($entry, 0, $eq);
            $to = substr($entry, $eq + 1);
            if ($from === '') {
                throw new \InvalidArgumentException(
                    "Invalid --path-map value \"{$entry}\": \"from\" part must not be empty."
                );
            }
            $entries[$from] = $to;
        }
        return new self($entries);
    }
}
