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

namespace Reli\Inspector\Settings\OutputSettings;

/**
 * @immutable
 */
final class OutputSettings
{
    /**
     * @param string $rbt_timestamps 'none' or 'delta'
     */
    public function __construct(
        public string $output_format,
        public ?string $output_path = null,
        public string $rbt_timestamps = 'none',
    ) {
    }

    public function hasRbtTimestamps(): bool
    {
        return $this->rbt_timestamps === 'delta';
    }

    public function isBinaryTrace(): bool
    {
        return $this->output_format === 'rbt';
    }

    public function isBinaryTraceBundled(): bool
    {
        return $this->output_format === 'rbt-bundled';
    }

    public function isTemplate(): bool
    {
        return !$this->isBinaryTrace() && !$this->isBinaryTraceBundled();
    }

    public function getTemplateName(): string
    {
        // "template:phpspy" → "phpspy", plain "phpspy" → "phpspy"
        if (str_starts_with($this->output_format, 'template:')) {
            return substr($this->output_format, strlen('template:'));
        }
        return $this->output_format;
    }
}
