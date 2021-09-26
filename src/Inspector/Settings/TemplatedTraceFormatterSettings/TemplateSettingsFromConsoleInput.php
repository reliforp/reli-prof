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

namespace PhpProfiler\Inspector\Settings\TemplatedTraceFormatterSettings;

use Noodlehaus\Config;
use PhpCast\NullableCast;
use PhpProfiler\Inspector\Settings\InspectorSettingsException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function is_null;

final class TemplateSettingsFromConsoleInput
{
    public function __construct(
        private Config $config
    ) {
    }

    /** @codeCoverageIgnore */
    public function setOptions(Command $command): void
    {
        $command
            ->addOption(
                'template',
                't',
                InputOption::VALUE_OPTIONAL,
                'template name (phpspy|phpspy_with_opcode|json_lines) (default: phpspy)'
            )
        ;
    }

    /**
     * @throws InspectorSettingsException
     */
    public function createSettings(InputInterface $input): TemplateSettings
    {
        $template = NullableCast::toString($input->getOption('template'));
        if (is_null($template)) {
            $template = NullableCast::toString($this->config->get('output.template.default'));
            if (is_null($template)) {
                throw TemplateSettingsException::create(
                    TemplateSettingsException::TEMPLATE_NOT_SPECIFIED
                );
            }
        }

        return new TemplateSettings($template);
    }
}
