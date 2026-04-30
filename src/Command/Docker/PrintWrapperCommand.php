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

namespace Reli\Command\Docker;

use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Reli\ReliProfiler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PrintWrapperCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('docker:print-wrapper')
            ->setDescription(
                'emit a shell function that runs reli via docker '
                . '(eval the output to install)'
            )
            ->addOption(
                'profile',
                null,
                InputOption::VALUE_REQUIRED,
                'wrapper profile (full|minimal)',
                'full',
            )
            ->addOption(
                'name',
                null,
                InputOption::VALUE_REQUIRED,
                'shell function name (default: reli for full, reli-view for minimal)',
            )
            ->addOption(
                'image',
                null,
                InputOption::VALUE_REQUIRED,
                'image reference to bake into the wrapper',
            )
            ->addOption(
                'shell',
                null,
                InputOption::VALUE_REQUIRED,
                'target shell dialect (bash|zsh|posix) — currently informational',
                'bash',
            )
            ->addOption(
                'memory-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'set PHP memory_limit for analysis (e.g. 2G, 512M)',
            )
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $memory_limit */
        $memory_limit = $input->getOption('memory-limit');
        if (is_string($memory_limit) && $memory_limit !== '') {
            ini_set('memory_limit', $memory_limit);
        }
        $profile = DockerProfile::tryFrom((string)$input->getOption('profile'));
        if ($profile === null) {
            $output->writeln(
                "<error>unknown --profile: "
                . (string)$input->getOption('profile')
                . " (expected: full, minimal)</error>"
            );
            return 2;
        }

        $name = (string)($input->getOption('name') ?? match ($profile) {
            DockerProfile::Full => 'reli',
            DockerProfile::Minimal => 'reli-view',
        });

        $image = (string)($input->getOption('image') ?? $this->defaultImage());

        $output->write($this->renderWrapper($profile, $name, $image));
        return 0;
    }

    private function defaultImage(): string
    {
        $version = ReliProfiler::getVersion();
        // Prefer a concrete semver-looking tag; fall back to 'latest' for
        // any dev / branch / unknown variant since those don't correspond
        // to a published image.
        $isDev = $version === 'unknown'
            || str_contains(strtolower($version), 'dev');
        $tag = $isDev ? 'latest' : $version;
        return "reliforp/reli-prof:{$tag}";
    }

    private function renderWrapper(DockerProfile $profile, string $name, string $image): string
    {
        return match ($profile) {
            DockerProfile::Full => $this->renderFull($name, $image),
            DockerProfile::Minimal => $this->renderMinimal($name, $image),
        };
    }

    private function renderFull(string $name, string $image): string
    {
        $template = <<<'SHELL'
# reli docker wrapper (full profile)
# Runs reli in a container with the capabilities needed to attach to host
# PHP processes (ptrace, pid=host, network=host). Files written by the
# container to bind-mounted paths are owned by the host user via --user.
#
# --entrypoint selects the setcap'd PHP binary baked into the image
# (see Dockerfile). When --user is non-root the kernel zeroes the effective
# capability set at exec, so --cap-add=SYS_PTRACE alone would still leave
# ptrace returning EPERM; the file capability on /opt/reli/php-ptrace/php
# raises CAP_SYS_PTRACE back into the effective set on each invocation.
# The basename is `php` (not `php-ptrace`) so reli's default --php-regex
# still matches /proc/<pid>/maps lines for processes started from this
# binary — important when one wrapper-launched reli attaches to another.
# Overriding the entrypoint also drops the image's `php /app/reli`
# ENTRYPOINT, so we re-supply the absolute script path explicitly below.
# Absolute path matters because `-w "$PWD"` puts the container CWD at the
# host's working dir, and PHP CLI does not search PATH for script args.
{{NAME}}() {
    local tty=
    [ -t 0 ] && [ -t 1 ] && tty=-t

    # Scratch dir path is chosen from locations that are normally
    # user-private ($XDG_RUNTIME_DIR under systemd; $HOME/.cache
    # otherwise). The strict check below rejects anything we don't
    # already own at mode 0700, rather than relying on the parent's
    # assumed permissions.
    local scratch
    if [ -n "${XDG_RUNTIME_DIR:-}" ] && [ -d "$XDG_RUNTIME_DIR" ]; then
        scratch="${XDG_RUNTIME_DIR}/reli-docker"
    elif [ -n "${HOME:-}" ] && [ -d "$HOME" ]; then
        scratch="${HOME}/.cache/reli-docker"
    else
        echo "{{NAME}}: neither XDG_RUNTIME_DIR nor HOME is set; refusing to start" >&2
        return 2
    fi
    mkdir -m 0700 -p "$scratch" || return $?
    # Pre-create the XDG_* sub-paths the docker run below points at.
    # `inspector:sidecar` (and any other consumer that resolves a default
    # path under $XDG_RUNTIME_DIR) checks `is_dir(parent)` before binding;
    # without this `runtime/` mkdir the default sidecar socket path fails
    # immediately with "XDG_RUNTIME_DIR is not set or not a directory".
    # `state/` and `cache/` are auto-created on demand by other commands,
    # but we make them up front for symmetry so the XDG_* triple inside
    # the container always points at real directories.
    mkdir -m 0700 -p "$scratch/runtime" "$scratch/state" "$scratch/cache" \
        || return $?
    local reason
    if ! reason=$(reli_assert_scratch_safe "$scratch"); then
        echo "{{NAME}}: scratch dir $scratch failed safety check: $reason" >&2
        return 2
    fi

    docker run --rm -i ${tty} \
        --entrypoint /opt/reli/php-ptrace/php \
        --cap-add=SYS_PTRACE \
        --security-opt=apparmor=unconfined \
        --pid=host \
        --network=host \
        --user "$(id -u):$(id -g)" \
        -v "$PWD":"$PWD" -w "$PWD" \
        -v "${scratch}":"${scratch}" \
        -e HOME="${scratch}" \
        -e XDG_STATE_HOME="${scratch}/state" \
        -e XDG_CACHE_HOME="${scratch}/cache" \
        -e XDG_RUNTIME_DIR="${scratch}/runtime" \
        ${RELI_DOCKER_EXTRA_ARGS:-} \
        {{IMAGE}} /app/reli "$@"
}

reli_assert_scratch_safe() {
    # Host-side check. `stat` flag syntax differs between GNU (-c) and
    # BSD (-f) userland; we try both so the function works on Linux
    # bash, macOS zsh, and WSL bash alike.
    local d="$1"
    [ -d "$d" ]    || { echo "not a directory"; return 1; }
    [ ! -L "$d" ]  || { echo "scratch entry is a symlink; remove it and retry"; return 1; }
    local owner mode
    owner=$(stat -c '%u' "$d" 2>/dev/null) \
        || owner=$(stat -f '%u' "$d" 2>/dev/null) \
        || { echo "could not stat"; return 1; }
    [ "$owner" = "$(id -u)" ] \
        || { echo "not owned by uid $(id -u) (got $owner); chown or remove and retry"; return 1; }
    mode=$(stat -c '%a' "$d" 2>/dev/null) \
        || mode=$(stat -f '%Lp' "$d" 2>/dev/null) \
        || { echo "could not stat mode"; return 1; }
    [ "$mode" = "700" ] \
        || { echo "mode is $mode, want 700; fix with: chmod 0700 '$d'"; return 1; }
    return 0
}

SHELL;
        return strtr($template, [
            '{{NAME}}' => $name,
            '{{IMAGE}}' => $image,
        ]);
    }

    private function renderMinimal(string $name, string $image): string
    {
        $allowlist = $this->buildMinimalAllowlist();

        $template = <<<'SHELL'
# reli docker wrapper (minimal profile)
# No ptrace, no host pid / network namespace sharing, no apparmor changes.
# Suitable for viewer / converter commands that operate on local files.
# If a command fails unexpectedly, reinstall with --profile=full.
{{ALLOWLIST}}
{{NAME}}() {
    local tty=
    [ -t 0 ] && [ -t 1 ] && tty=-t
    docker run --rm -i ${tty} \
        --user "$(id -u):$(id -g)" \
        --read-only \
        --tmpfs /tmp:rw,nosuid,nodev,size=64m \
        -v "$PWD":"$PWD" -w "$PWD" \
        ${RELI_DOCKER_EXTRA_ARGS:-} \
        {{IMAGE}} "$@"
}

SHELL;
        return strtr($template, [
            '{{NAME}}' => $name,
            '{{IMAGE}}' => $image,
            '{{ALLOWLIST}}' => $allowlist,
        ]);
    }

    private function buildMinimalAllowlist(): string
    {
        $application = $this->getApplication();
        if ($application === null) {
            return '# (command classification unavailable — Application not set)';
        }

        $names = [];
        foreach ($application->all() as $registeredName => $cmd) {
            $registeredName = (string)$registeredName;
            if (
                $cmd instanceof ReliCommand
                && $cmd::getDockerProfile() === DockerProfile::Minimal
                && !str_starts_with($registeredName, '_')
            ) {
                $names[$registeredName] = true;
            }
        }
        $names = array_keys($names);
        sort($names);

        if ($names === []) {
            return '# (no commands are currently classified as Minimal-safe;'
                . ' reli commands have not yet adopted the DockerProfile enum)';
        }

        return "# Commands classified as Minimal-safe:\n#   "
            . implode("\n#   ", $names);
    }
}
