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

namespace Reli\Inspector\Daemon\Searcher\Controller;

use Reli\Inspector\Daemon\AutoContextRecoveringInterface;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\TargetPhpSettingsMessage;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use Reli\Inspector\Daemon\Searcher\Protocol\PhpSearcherControllerProtocolInterface;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;

final class PhpSearcherController implements PhpSearcherControllerInterface
{
    private ?TargetPhpSettingsMessage $settings_already_sent = null;

    /** @param AutoContextRecoveringInterface<PhpSearcherControllerProtocol> $auto_context_recovering */
    public function __construct(
        readonly private AutoContextRecoveringInterface $auto_context_recovering
    ) {
        $this->auto_context_recovering->onRecover(
            function (): void {
                if ($this->settings_already_sent !== null) {
                    $this->auto_context_recovering
                        ->getContext()
                        ->getProtocol()
                        ->sendTargetRegex($this->settings_already_sent)
                    ;
                }
            }
        );
    }

    #[\Override]
    public function start(): void
    {
        $this->auto_context_recovering->getContext()->start();
    }

    /** @param non-empty-string $regex */
    #[\Override]
    public function sendTarget(
        string $regex,
        TargetPhpSettings $target_php_settings,
        int $pid,
        bool $no_cache = false,
    ): void {
        $message = new TargetPhpSettingsMessage(
            $regex,
            $target_php_settings,
            $pid,
            $no_cache,
        );
        $this->auto_context_recovering->withAutoRecover(
            function (PhpSearcherControllerProtocolInterface $protocol) use ($message) {
                $protocol->sendTargetRegex($message);
            },
            'failed to send target',
        );
        $this->settings_already_sent = $message;
    }

    #[\Override]
    public function receivePidList(): UpdateTargetProcessMessage
    {
        return $this->auto_context_recovering->withAutoRecover(
            function (PhpSearcherControllerProtocolInterface $protocol) {
                return $protocol->receiveUpdateTargetProcess();
            },
            'failed to receive pid list',
        );
    }
}
