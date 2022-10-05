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

namespace Reli\Inspector\Settings\TargetPhpSettings;

use Reli\Lib\PhpInternals\ZendTypeReader;

/**
 * @psalm-type VersionDecided=value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>
 * @template TVersion of VersionDecided|'auto'
 */
final class TargetPhpSettings
{
    public const PHP_REGEX_DEFAULT = '.*/(php(74|7.4|80|8.0|81|8.1)?|php-fpm|libphp[78]?.*\.so)$';
    public const LIBPTHREAD_REGEX_DEFAULT = '.*/libpthread.*\.so';
    public const TARGET_PHP_VERSION_DEFAULT = 'auto';

    /** @param TVersion $php_version */
    public function __construct(
        public string $php_regex = self::PHP_REGEX_DEFAULT,
        public string $libpthread_regex = self::LIBPTHREAD_REGEX_DEFAULT,
        public string $php_version = self::TARGET_PHP_VERSION_DEFAULT,
        public ?string $php_path = null,
        public ?string $libpthread_path = null
    ) {
    }

    /** @psalm-assert-if-true self<'v70'|'v71'|'v72'|'v73'|'v74'|'v80'|'v81'> $this */
    public function isDecided(): bool
    {
        return $this->php_version !== 'auto';
    }
}
