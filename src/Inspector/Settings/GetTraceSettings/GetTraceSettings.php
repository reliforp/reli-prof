<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Inspector\Settings\GetTraceSettings;

use PhpProfiler\Inspector\Settings\InspectorSettingsException;
use Symfony\Component\Console\Input\InputInterface;

final class GetTraceSettings
{
    public int $depth;

    /**
     * GetTraceSettings constructor.
     * @param int $depth
     */
    public function __construct(int $depth)
    {
        $this->depth = $depth;
    }

    /**
     * @param InputInterface $input
     * @return self
     * @throws InspectorSettingsException
     */
    public static function fromConsoleInput(InputInterface $input): self
    {
        $depth = $input->getOption('depth');
        if (is_null($depth)) {
            $depth = PHP_INT_MAX;
        }
        $depth = filter_var($depth, FILTER_VALIDATE_INT);
        if ($depth === false) {
            throw GetTraceSettingsException::create(GetTraceSettingsException::DEPTH_IS_NOT_INTEGER);
        }
        return new self($depth);
    }
}
