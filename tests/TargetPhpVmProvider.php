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

namespace Reli;

use Reli\Lib\PhpInternals\ZendTypeReader;

class TargetPhpVmProvider
{
    private const ALL_TARGETS = [
        ZendTypeReader::V70,
        ZendTypeReader::V71,
        ZendTypeReader::V72,
        ZendTypeReader::V73,
        ZendTypeReader::V74,
        ZendTypeReader::V80,
        ZendTypeReader::V81,
        ZendTypeReader::V82,
        ZendTypeReader::V83,
        ZendTypeReader::V84,
        // ZTS variants (PHP 7.2+)
        'v72_zts',
        'v73_zts',
        'v74_zts',
        'v80_zts',
        'v81_zts',
        'v82_zts',
        'v83_zts',
        'v84_zts',
    ];

    private static function isZts(string $target): bool
    {
        return str_ends_with($target, '_zts');
    }

    private static function phpVersionFromTarget(string $target): string
    {
        return self::isZts($target) ? substr($target, 0, -4) : $target;
    }

    private static function getFilteredVersions(array $versions): array
    {
        $targets = getenv('RELI_TEST_PHP_TARGETS');
        if ($targets === false || $targets === '') {
            return $versions;
        }
        $allowed = array_map('trim', explode(',', $targets));
        return array_values(array_filter(
            $versions,
            fn (string $v) => in_array($v, $allowed, true),
        ));
    }

    public static function from(string $php_version)
    {
        $targets = self::getFilteredVersions(self::ALL_TARGETS);
        $hasResults = false;
        foreach ($targets as $target) {
            $targetPhpVersion = self::phpVersionFromTarget($target);
            if ($php_version <= $targetPhpVersion) {
                $hasResults = true;
                yield $target => [$targetPhpVersion, self::dockerImageNameFromTarget($target)];
            }
        }
        if (!$hasResults) {
            yield 'skip' => ['skip', 'skip'];
        }
    }

    public static function allSupported()
    {
        $targets = self::getFilteredVersions(self::ALL_TARGETS);
        foreach ($targets as $target) {
            $phpVersion = self::phpVersionFromTarget($target);
            yield $target => [$phpVersion, self::dockerImageNameFromTarget($target)];
        }
    }

    public static function dockerImageNameFromTarget(string $target): string
    {
        $phpVersion = self::phpVersionFromTarget($target);
        $suffix = self::isZts($target) ? '-zts' : '-cli';
        return match ($phpVersion) {
            ZendTypeReader::V70 => 'php:7.0' . $suffix,
            ZendTypeReader::V71 => 'php:7.1' . $suffix,
            ZendTypeReader::V72 => 'php:7.2' . $suffix,
            ZendTypeReader::V73 => 'php:7.3' . $suffix,
            ZendTypeReader::V74 => 'php:7.4' . $suffix,
            ZendTypeReader::V80 => 'php:8.0' . $suffix,
            ZendTypeReader::V81 => 'php:8.1' . $suffix,
            ZendTypeReader::V82 => 'php:8.2' . $suffix,
            ZendTypeReader::V83 => 'php:8.3' . $suffix,
            ZendTypeReader::V84 => 'php:8.4' . $suffix,
            default => throw new \InvalidArgumentException("unsupported php version: $phpVersion"),
        };
    }

    public static function runScriptViaContainer(
        string $docker_image_name,
        string $script,
        array &$pipes,
    ) {
        $tmp_file = tempnam('/tmp/reli-test', 'reli-prof-test');
        $pid_writer = tempnam('/tmp/reli-test', 'reli-prof-test-pid-writer');
        $pid_file = tempnam('/tmp/reli-test', 'reli-prof-test-pid');

        chmod($tmp_file, 0777);
        chmod($pid_writer, 0777);
        chmod($pid_file, 0777);

        file_put_contents(
            $pid_writer,
            <<<CODE
            <?php
            file_put_contents('/target-pid', getmypid());
            fputs(STDOUT, "pid written\n");
            CODE
        );
        file_put_contents(
            $tmp_file,
            $script
        );

        $proc_handle = self::procOpenViaDocker(
            $docker_image_name,
            'php -dauto_prepend_file=/pid-writer /source',
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w']
            ],
            $pipes,
            [
                $tmp_file => '/source',
                $pid_writer => '/pid-writer',
                $pid_file => '/target-pid',
                '/tmp/reli-test' => '/tmp/reli-test',
            ],
        );
        $pid_written_message = fgets($pipes[1]);
        assert($pid_written_message === "pid written\n");
        $pid = (int)file_get_contents($pid_file);
        return [$proc_handle, $pid];
    }

    public static function runScriptViaFrankenPhpContainer(
        string $docker_image_name,
        string $script,
        array &$pipes,
    ) {
        $tmp_file = tempnam('/tmp/reli-test', 'reli-prof-test');
        $pid_file = tempnam('/tmp/reli-test', 'reli-prof-test-pid');

        chmod($tmp_file, 0777);
        chmod($pid_file, 0777);

        // Prepend PID writing logic directly into the script
        $pid_writer_code = <<<'CODE'
        file_put_contents('/target-pid', getmypid());
        fputs(STDOUT, "pid written\n");
        CODE;
        // Insert after <?php opening tag
        $modified_script = preg_replace(
            '/^<\?php\s*/s',
            "<?php\n" . $pid_writer_code . "\n",
            $script
        );
        file_put_contents($tmp_file, $modified_script);

        $proc_handle = self::procOpenViaDocker(
            $docker_image_name,
            'frankenphp php-cli /source',
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w']
            ],
            $pipes,
            [
                $tmp_file => '/source',
                $pid_file => '/target-pid',
                '/tmp/reli-test' => '/tmp/reli-test',
            ],
        );
        $pid_written_message = fgets($pipes[1]);
        assert($pid_written_message === "pid written\n");
        $pid = (int)file_get_contents($pid_file);
        return [$proc_handle, $pid];
    }

    public static function procOpenViaDocker(
        string $docker_image_name,
        string $command,
        array $descriptorspec,
        array &$pipes,
        array $mount_points = [],
    ) {
        $mount_options = array_map(
            fn ($source, $target) => "-v$source:$target:rw",
            array_keys($mount_points),
            array_values($mount_points)
        );
        $uid = posix_getuid();
        $gid = posix_getgid();

        $docker_command = [
            'docker',
            'run',
            '--rm',
            '-u',
            "$uid:$gid",
            '--pid',
            'host',
            '-i',
            '--entrypoint',
            'sh',
            ...$mount_options,
            $docker_image_name,
            '-c',
            $command,
        ];
        return proc_open(
            $docker_command,
            $descriptorspec,
            $pipes
        );
    }
}
