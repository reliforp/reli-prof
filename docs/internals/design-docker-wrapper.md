# Design: Docker wrapper for reli

## Motivation

README examples use `./reli <command> ...` — a bare-install invocation. In
practice many users will run reli via Docker (PHP 8.5 + FFI + PCNTL pre-built,
no host toolchain required). Under Docker the required invocation differs
substantially (flags for capabilities / namespaces / mounts, entrypoint
pinning, output path translation), so the documented examples are not
copy-pasteable for the Docker path.

Goal: every documented `./reli <command> ...` example should work as
`reli <command> ...` under a thin wrapper, **unchanged** (except `sudo`, which
is unnecessary inside the container).

Non-goal: making Docker-on-macOS / Windows host profile the *host* PHP.
reli targets Linux processes only; the wrapper is useful to Docker Desktop
users only for targeting processes that are themselves inside Linux
containers.

## Distribution

The wrapper is a **shell function**, emitted by a new reli CLI subcommand
that prints a shell snippet to stdout. Install with one command:

```bash
eval "$(docker run --rm --pull=always reliforp/reli-prof docker:print-wrapper)"
```

This defines `reli` as a shell function in the current session. To persist,
users save the emitted snippet to a file (e.g.
`~/.local/share/reli/wrapper.sh`) once and source that file from
`~/.bashrc` / `~/.zshrc`. **Don't** paste the `eval "$(docker run ...)"`
line directly into rc files — combined with `--pull=always` (below) it
would Docker-pull on every shell startup, which is slow and breaks shell
startup outright if the Docker daemon is down. The save-and-source form
is the recommended persistence shape across all the user-facing docs
(getting-started.md, docker-wrapper.md).

`--pull=always` is a deliberate part of the bootstrap recipe rather than
optional polish. A previously cached `reliforp/reli-prof:latest` produces
two distinct failure modes depending on its age:

1. **Pre-0.12 cache** — the image lacks `docker:print-wrapper` itself
   (the command was added in 0.12.0). `docker run` with the default
   `--pull=missing` reuses the stale image and the bootstrap fails with
   `There are no commands defined in the "docker" namespace.`, which
   reads like a reli bug rather than a Docker cache miss. This is the
   failure mode that motivated adding the flag in the first place.
2. **Stale-but-recent cache** — the image has `docker:print-wrapper`
   but predates the most recent release (e.g. you pulled `:latest`
   while 0.12.0 was current and 0.13.0 has since shipped). The
   bootstrap "succeeds" silently, but `defaultImage()` bakes the older
   image's tag into the emitted wrapper, leaving the user pinned to a
   previous reli release with no error to flag the drift. This is the
   quieter failure mode; the user only notices when a feature they
   expect from the new release is missing or behaves unexpectedly.

Forcing a pull at install time sidesteps both. The flag matters only
at install / upgrade time — the emitted wrapper bakes a concrete image
tag and never re-pulls per invocation — but it matters at *every*
install or upgrade, not just the first.

### Why a CLI subcommand (not a standalone script / README snippet)

- **Version-pinned by construction**: the subcommand bakes its own image
  tag (`reliforp/reli-prof:0.12.1`) into the emitted function. A user who
  pulls the 0.12 image gets a wrapper that always invokes the 0.12 image —
  no accidental version skew with later pulls of `:latest`.
- **Single source of truth**: README points at one command; bug fixes to
  the wrapper ship with the image.
- **Self-describing**: `docker run --rm reliforp/reli-prof docker:print-wrapper --help`
  documents options (`--name=`, `--image=`).

### Why a shell function (not a standalone PHP-based wrapper)

PHP was considered as the wrapper host language (it would allow reusing
Symfony Console metadata to drive argv analysis for auto-mounting,
port-opening, etc.). Rejected because **requiring host PHP contradicts the
entire reason users choose the Docker path**. A pure shell function works
identically on any POSIX shell, including macOS zsh and WSL bash, with no
host toolchain.

The downside — shell argv handling is more fragile than PHP — is mitigated
by using namespace-sharing flags (`--pid=host`, `--network=host`) that
remove the need for argv-level port/PID translation entirely.

## Threat model

The wrapper is shipped with different guarantees per environment. The
design makes this explicit so README can warn users operating outside
the intended envelope.

| Scenario              | wrapper default guards                             | wrapper default does NOT guard                  |
|-----------------------|----------------------------------------------------|-------------------------------------------------|
| Single-user laptop    | cwd isolation (mounted), file ownership (via `--user`), user-private scratch dir (strict-checked), sidecar socket under per-user 0700 dir | (nothing — the host user trusts themselves)     |
| CI runner (dedicated) | same as laptop, plus cache directories confined to host user scope | (same — assumes runner is ephemeral / trusted)  |
| Multi-tenant host     | (partial — scratch uses `$XDG_RUNTIME_DIR` which systemd sets 0700 per-user; `--user` preserves per-user file ownership) | **ptrace of other tenants' processes** (wrapper grants `CAP_SYS_PTRACE` + `--pid=host`), **port conflicts via `--network=host`**, **apparmor removal** |

The `Full` profile (default) is unsafe on multi-tenant hosts by design:
it gives the container the capability to ptrace any host process, which
means any same-uid or cross-uid compromise of the container grants the
ability to read arbitrary process memory on the host.

The `Minimal` profile (opt-in; see below) drops `CAP_SYS_PTRACE`,
`--pid=host`, `--network=host`, and `apparmor=unconfined` — giving up
the ability to profile other processes in exchange for an isolation
profile comparable to an ordinary container. Viewer-only commands
(`rbt:explore`, `rmem:explore`, etc.) run correctly under Minimal.

README **must** call out that users on shared hosts should prefer the
Minimal profile when they only need to inspect previously-captured
traces / dumps, and should assess whether Full is appropriate at all.

## Wrapper shape

### Full profile (default)

```bash
reli() {
  local tty=
  [ -t 0 ] && [ -t 1 ] && tty=-t

  # Scratch dir path is chosen from locations that are normally user-
  # private ($XDG_RUNTIME_DIR under systemd; $HOME/.cache otherwise).
  # We do not *rely* on that — the mkdir below only sets mode 0700 on
  # fresh creation (no-op if the dir exists), and the strict check
  # after rejects anything we don't already own at mode 0700.
  local scratch
  if [ -n "${XDG_RUNTIME_DIR:-}" ] && [ -d "$XDG_RUNTIME_DIR" ]; then
    scratch="${XDG_RUNTIME_DIR}/reli-docker"
  elif [ -n "${HOME:-}" ] && [ -d "$HOME" ]; then
    scratch="${HOME}/.cache/reli-docker"
  else
    echo "reli: neither XDG_RUNTIME_DIR nor HOME is set; refusing to start" >&2
    return 2
  fi
  mkdir -m 0700 -p "$scratch" || return $?
  # Strictly reject if owner / mode / symlink don't match — don't
  # silently coerce. The validator echoes a reason-specific hint so
  # the user sees the actual problem, not a generic "chmod and retry".
  local reason
  if ! reason=$(reli_assert_scratch_safe "$scratch"); then
    echo "reli: scratch dir $scratch failed safety check: $reason" >&2
    return 2
  fi

  docker run --rm -i ${tty} \
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
    reliforp/reli-prof:<TAG_PINNED_AT_GENERATION> "$@"
}

reli_assert_scratch_safe() {
  # Host-side check. Runs on whichever shell invokes `docker run`
  # (Linux bash, macOS zsh, WSL bash, ...), NOT inside the container.
  # `stat` flag syntax differs between GNU (-c) and BSD (-f) userland;
  # the form below tries both. On stdout, emits a reason string when
  # the check fails so the caller can surface the actual problem; on
  # success, emits nothing.
  local d="$1"
  [ -d "$d" ]    || { echo "not a directory"; return 1; }
  [ ! -L "$d" ]  || { echo "scratch entry is a symlink; remove it and retry"; return 1; }
  local owner mode
  owner=$(stat -c '%u' "$d" 2>/dev/null) \
    || owner=$(stat -f '%u' "$d" 2>/dev/null) \
    || { echo "could not stat"; return 1; }
  [ "$owner" = "$(id -u)" ] \
    || { echo "not owned by uid $(id -u) (owned by $owner); rename or remove with appropriate privilege"; return 1; }
  mode=$(stat -c '%a' "$d" 2>/dev/null) \
    || mode=$(stat -f '%Lp' "$d" 2>/dev/null) \
    || { echo "could not stat mode"; return 1; }
  [ "$mode" = "700" ] \
    || { echo "mode is $mode, want 700; fix with: chmod 0700 '$d'"; return 1; }
  return 0
}
```

Key properties:

- `--pid=host` + `CAP_SYS_PTRACE` cover attach (`-p <pid>`), `/proc/<pid>/*`
  reads, and daemon regex matching against host process list.
  Non-root `--user` makes `CAP_SYS_PTRACE` effective only when the `php`
  binary in the image has file capabilities set (see core change A).
- `--network=host` covers `rmem:live`, any HTTP/SSE, and host-visible TCP
  listeners — eliminates per-command `-p host:container` reasoning.
- `-v "$PWD":"$PWD" -w "$PWD"` makes every cwd-relative or cwd-anchored
  absolute path Just Work (`-o trace.rbt`, `-o ./sub/trace.rbt`,
  `-o $PWD/trace.rbt` all resolve identically inside and outside).
- Scratch dir under `$XDG_RUNTIME_DIR` (systemd: `/run/user/$UID`, 0700,
  strongly per-user by construction) or `$HOME/.cache` (typically
  user-private on single-user laptops and conventional home-directory
  setups, but less categorical — shared NFS homes or unusual permission
  regimes weaken the guarantee).
  `reli_assert_scratch_safe` inspects the scratch dir entry itself and
  rejects (loud abort, not silent coerce) if it is not a directory,
  if it is a symlink, if it is not owned by the current uid, or if it
  is not exactly mode 0700. The check is local to the entry: symlinks
  on path components *above* the scratch dir (e.g., a symlinked
  `$HOME` or `$XDG_RUNTIME_DIR`) are not traversed / validated — those
  rely on the underlying filesystem's per-user permission model being
  sane (which is the normal case for systemd-managed runtime dirs and
  for conventional home directories, but not an absolute guarantee).
  Each failure emits a reason-specific hint (e.g. `chmod 0700 '$d'`
  only when the mode is the actual issue; an ownership mismatch
  instead suggests rename / remove) rather than a one-size-fits-all
  message.
- `--user "$UID:$GID"` makes generated files owned by the host user
  (no `sudo rm` required after profiling).
- `HOME="${scratch}"` aligns in-container config discovery
  (`~/.config/reli/config.php`) with the wrapper-provided scratch, so
  the limitation table's "share host config" example points to a
  consistent location.
- `RELI_DOCKER_EXTRA_ARGS` is the documented escape hatch for extra
  mounts (paths outside cwd), extra capabilities, custom `/etc/hosts`, etc.

Dropped from earlier drafts:

- `--ipc=host` — unnecessary. reli's IPC (sidecar, rmem-serve) is Unix
  sockets on filesystem paths, not System V IPC. Dropping reduces
  privilege surface.
- `-v /tmp:/tmp` — sidecar socket default is being moved to
  `$XDG_RUNTIME_DIR/reli/sidecar.sock` (see core change B), so a broad
  `/tmp` mount is no longer needed.
- `/tmp/reli-$UID` scratch — replaced with `$XDG_RUNTIME_DIR` /
  `$HOME/.cache` based paths that are inherently protected by host
  filesystem user boundaries rather than by our own `mkdir -m 700`
  (which did not protect against pre-existing dirs).

### Minimal profile (`--profile=minimal`)

Installed separately as a distinct shell function (default name
`reli-view`). Intended for running viewer commands on files already
captured elsewhere, on untrusted hosts, or inside CI.

```bash
reli-view() {
  local tty=
  [ -t 0 ] && [ -t 1 ] && tty=-t
  docker run --rm -i ${tty} \
    --user "$(id -u):$(id -g)" \
    --read-only \
    --tmpfs /tmp:rw,nosuid,nodev,size=64m \
    -v "$PWD":"$PWD" -w "$PWD" \
    ${RELI_DOCKER_EXTRA_ARGS:-} \
    reliforp/reli-prof:<TAG> "$@"
}
```

Dropped vs Full: `CAP_SYS_PTRACE`, `--pid=host`, `--network=host`,
`apparmor=unconfined`, XDG mounts (tmpfs covers ephemeral needs).

Commands safe under Minimal are those annotated
`DockerProfile::Minimal` in the command classification (see core
change D). Running a Full-profile command under Minimal will fail at
reli's first ptrace / network attempt — by design, not via an
explicit wrapper-level guard.

Because the failure path is generic (`EPERM` from ptrace, address
bind failure, etc.) and the user may not immediately connect it to
the profile choice, two mitigations ship together:

1. `docker:print-wrapper --profile=minimal` prepends a header comment
   to the emitted function listing the Minimal-safe commands
   (generated via reflection over `ReliCommand` subclasses), so the
   installed wrapper self-documents its scope.
2. README explicitly says: "if a command fails unexpectedly under
   `reli-view`, reinstall the wrapper with `--profile=full` and try
   again." — the first-run troubleshooting path should be one
   command, not a spelunking expedition.

A wrapper-level runtime guard that pattern-matches `$1` and refuses
incompatible commands up-front was considered and declined (see Out
of scope): it would add a second enforcement layer that must be kept
in sync with `DockerProfile`, and reli's own error is ultimately
more accurate about *why* a command fails.

## Privilege and trust surface

| Flag / setting                       | Full profile | Minimal profile | Effect                                                        |
|--------------------------------------|:---:|:---:|----------------------------------------------------------------------|
| `--cap-add=SYS_PTRACE`               | ✓   |     | Can ptrace / read `/proc/<pid>/mem` of host processes.               |
| `--security-opt=apparmor=unconfined` | ✓   |     | Removes AppArmor confinement on the container.                       |
| `--pid=host`                         | ✓   |     | Container `/proc` shows all host processes (argv, env).              |
| `--network=host`                     | ✓   |     | Container binds directly on host interfaces.                         |
| `--user $UID:$GID`                   | ✓   | ✓   | Non-root in container; generated files host-user-owned.              |
| `--read-only` + tmpfs                |     | ✓   | Container rootfs immutable; only `/tmp` and bind mounts writable.    |
| cwd bind mount                       | ✓   | ✓   | Inputs / outputs accessible at same path inside container.           |

`CAP_SYS_PTRACE` under non-root `--user` requires file capabilities on
the `php` binary inside the image. See core change A.

## Changes required in reli core

### A. Dockerfile: file capabilities on the PHP binary

**Problem**: Linux clears the effective capability set when a process is
non-root, so `--cap-add=SYS_PTRACE` + `--user 1000:1000` leaves
`CAP_SYS_PTRACE` in the bounding set but *not* effective. reli's memory
reads (`process_vm_readv` / pread on `/proc/<pid>/mem`) then fail with
`EPERM`.

**Verified on Ubuntu 24.04 (kernel 6.17, ptrace_scope=1)**: running the
reli image as `--user $(id -u):$(id -g) --cap-add=SYS_PTRACE --pid=host`
reproducibly failed with `failed to read memory ... errno=1` in
`MemoryReader.php:94`. Rebuilding the image with
`setcap cap_sys_ptrace=eip /usr/local/bin/php` and re-running produced
correct sampling output — no other changes needed. FFI, process
introspection, and Symfony console behaviour were unaffected.

**Change**: add to the image Dockerfile:

```dockerfile
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcap2-bin \
    && setcap cap_sys_ptrace=eip /usr/local/bin/php \
    && rm -rf /var/lib/apt/lists/*
```

Side effects to be aware of (none of which affect reli in testing):

- `PR_DUMPABLE` becomes 0 on setcap'd binaries; core dumps of the
  container's `php` process are suppressed.
- `LD_PRELOAD` / `LD_LIBRARY_PATH` are filtered by `ld.so` for binaries
  with file capabilities. reli's image does not rely on either.
- The capability is image-scoped. Users who rebuild / re-layer the image
  in a way that loses `xattr`s (some registry tooling, some filesystems)
  need to re-apply `setcap` post-build.

### B. `inspector:sidecar` socket default

**Current**: `SidecarSettings::DEFAULT_SOCKET_PATH = '/var/run/reli-sidecar.sock'`.

**Problem**: `/var/run` is root-owned and not writable by the non-root
container user. Even for native installs, the current default forces
`sudo` usage for sidecar startup.

**Change**: default to `${XDG_RUNTIME_DIR}/reli/sidecar.sock`, resolved
at runtime. On most systemd distros `$XDG_RUNTIME_DIR` is
`/run/user/$UID` (mode 0700, per-user). When `XDG_RUNTIME_DIR` is
unset, **fail closed** with an instructive error (`RELI_SIDECAR_SOCKET=<path>`
override, or point at a user-owned 0700 parent dir). No `/tmp`
fallback: `/tmp` is world-writable, and a concurrent attacker could
pre-create the socket path, intercepting the target app's sidecar IPC
(which has no authentication on the wire). Requiring an explicit
user-scoped path preserves the privilege-boundary property.

Before binding, reli stats the parent dir and refuses if it is not
a real directory, not owned by the current uid, not mode 0700, or is
a symlink.

Secondary benefit: native installs lose the `sudo` requirement for
sidecar (no more `/var/run` write).

### C. `inspector:daemon` output default

**Current**: resolves to `$XDG_STATE_HOME/reli/daemon-traces/{session}/`
(typically `$HOME/.local/state/reli/...`).

**Decision**: **keep the default unchanged**. Under the wrapper, `HOME`
and `XDG_STATE_HOME` are already steered to the wrapper-managed scratch
dir (see wrapper shape), which is itself owner/mode-validated before
use. No core change needed. Reasoning:

- Profile data is sensitive (variable values via `--trace-var`, heap
  contents via memory dumps — routinely contains credentials / PII).
- Defaulting to cwd would normalise writing such data into directories
  that may be world-readable, git-tracked, or cloud-synced.
- XDG state home (mode 0700, per-user) is the privacy-conserving default.

### D. `ReliCommand` base class + `getDockerProfile()`

**Purpose**: classify each CLI command as "needs full host privilege"
vs "viewer-only, safe under Minimal". Used by `docker:print-wrapper` to
document which commands are safe under which profile, and (indirectly)
used by users to choose the right profile to install.

**Shape**:

```php
enum DockerProfile {
    case Full;     // needs ptrace / pid=host / network=host
    case Minimal;  // operates on local files only
}

abstract class ReliCommand extends \Symfony\Component\Console\Command\Command
{
    abstract public static function getDockerProfile(): DockerProfile;
}
```

Every existing concrete command class is retargeted to extend
`ReliCommand` instead of Symfony `Command` directly. The abstract method
means **any new command must classify itself at the source level** — a
concrete subclass without `getDockerProfile()` produces a class-load
fatal error, triggered at reli startup the moment the DI container
registers the command. CI (or even a developer's first `./reli --version`
after adding the class) catches the omission immediately; no separate
lint rule is needed.

`docker:print-wrapper` reads the classification via reflection. The
emitted wrapper for `--profile=minimal` can include the allowlist of
Minimal-compatible command names in a comment block for user reference.

**Default stance on misclassification**: there is no runtime default —
the abstract method forces an explicit choice. If in doubt, annotate
`Full` (errs toward over-privilege, but the user can escape by picking
the wrong profile, which surfaces a clear error rather than a silent
security downgrade).

## Integrity hazards: known and handled

Inventoried via a pass over every Symfony Console command definition.

### Handled by wrapper

| Hazard                                             | Handling                                          |
|----------------------------------------------------|---------------------------------------------------|
| `-o trace.rbt` / `-o ./foo/bar.rbt`                | cwd mounted at same path.                         |
| Absolute output paths inside cwd                   | cwd mounted at same path.                         |
| `-p <pid>` attach                                  | `--pid=host` + `CAP_SYS_PTRACE` (via setcap).     |
| `/proc/<pid>/{exe,maps,mem}` reads                 | `--pid=host` + `CAP_SYS_PTRACE` (via setcap).     |
| HTTP/SSE listeners (`rmem:live`)                   | `--network=host`.                                 |
| Unix sockets under `$XDG_RUNTIME_DIR`              | scratch mount + XDG override.                     |
| TUI (`rbt:explore`, `rmem:explore`)                | auto `-t`.                                        |
| File ownership                                     | `--user "$UID:$GID"`.                             |
| Daemon output default                              | `HOME` / `XDG_STATE_HOME` → scratch.              |
| Scratch-dir pre-creation / symlink attacks         | parent path chosen from normally user-private locations (XDG runtime / `$HOME/.cache`); strict owner + mode + "entry is not a symlink" check on every invocation rejects anything that doesn't match. Upstream path components (e.g. a symlinked `$HOME`) are not traversed — relies on the underlying FS's per-user permission model for those. |

### Handled by core changes

| Hazard                                           | Change                                          |
|--------------------------------------------------|-------------------------------------------------|
| sidecar socket at `/var/run`                     | default → `$XDG_RUNTIME_DIR/reli/sidecar.sock`. |
| `CAP_SYS_PTRACE` ineffective under `--user`      | `setcap cap_sys_ptrace=eip` on `php` in image.  |
| Missing profile classification on new commands   | abstract `getDockerProfile()` on `ReliCommand`. |

### Documented limitations (not auto-handled)

| Scenario                                                   | Guidance                                              |
|------------------------------------------------------------|-------------------------------------------------------|
| `-o /var/log/...` (absolute path outside cwd)              | Use `RELI_DOCKER_EXTRA_ARGS='-v /var/log:/var/log'`.  |
| `inspector:trace -- python3 script.py` (non-PHP spawn)     | Not supported via default image; use custom image.    |
| `inspector:trace -- php7.3 script.php` (specific PHP ver)  | Not supported via default image; use custom image.    |
| `phpspy:*` commands                                        | Not supported via default image (phpspy not bundled). |
| Host `~/.config/reli/` visibility                          | `RELI_DOCKER_EXTRA_ARGS='-v $HOME/.config/reli:"$scratch/.config/reli":ro'` (target path must align with the in-container `HOME=$scratch`). |
| ptrace_scope=2/3 on host                                   | Raise scope temporarily on host, or use native install. |
| Multi-tenant host                                          | README warning; prefer `--profile=minimal` or avoid Docker wrapper entirely. |

### Release blockers

These must be resolved (or an explicit workaround documented) before
the wrapper is recommended to end users via README.

- **Non-root `--user` with image files readable**: `docker run --user
  "$(id -u):$(id -g)" --entrypoint php reliforp/reli-prof:<new-tag> -r
  'echo readable'` must succeed after image rebuild. Verify composer
  `vendor/` tree, reli sources, and any generated caches are not
  root-only-readable.
- **`/dev/tty` under piped stdout**: `reli rbt:explore trace.rbt | cat`
  should either work or fail with a comprehensible error (not a silent
  hang). The wrapper's TTY auto-detect sees stdout=pipe and skips `-t`,
  which is correct in principle but needs a smoke test against TUI
  commands.
- **Distro spot-check** (at minimum one systemd + `ptrace_scope=1`
  non-Ubuntu host — e.g. Fedora or Amazon Linux 2023): end-to-end run
  of the wrapper attaching to a PHP process. The `setcap` file-cap
  mechanism is kernel-level and portable, but AppArmor /
  SELinux / seccomp interactions vary.
- **Docker Desktop on macOS**: wrapper should at least produce a
  comprehensible error on commands that need `--pid=host`, rather than
  a confusing no-match. (Full attach may or may not work under Docker
  Desktop's VM — document the result.)

Verified items (no longer blocking):

- Non-root `--user` + `CAP_SYS_PTRACE` via file capabilities on `php`:
  verified on Ubuntu 24.04 (kernel 6.17.0-22-generic, Docker 29.1.3,
  `ptrace_scope=1`). Control case `--user 0:0 --cap-add=SYS_PTRACE`
  also works. See core change A for mechanism.

## Related issues discovered (out of wrapper scope)

- **Large ELF OOM in `NativeFileReader::readAll`**. When the target
  process's PHP binary has debug symbols (common with phpenv / asdf
  managed PHP versions — e.g. a 65 MB `php` with DWARF sections), reli
  aborts during ELF symbol resolution with PHP's 128 MB `memory_limit`
  exhausted (trace: `Elf64SymbolResolverCreator.php:39`
  → `NativeFileReader::readAll('/proc/<pid>/root/...')`). Independent of
  Docker. Filed separately; the Docker wrapper should raise
  `memory_limit` via a container-side `php.ini` tweak as a temporary
  mitigation, and the core fix is streaming / mmap-based ELF parsing.

## CLI shape

New command: `docker:print-wrapper`.

```
docker:print-wrapper [--profile=PROFILE] [--name=NAME] [--image=IMAGE] [--shell=SHELL]

Options:
  --profile=PROFILE  full (default) or minimal
  --name=NAME        Shell function name
                     (default: reli for full, reli-view for minimal)
  --image=IMAGE      Image reference
                     (default: reliforp/reli-prof:<current-version>)
  --shell=SHELL      Target shell syntax: bash|zsh|posix (default: posix)
```

Output: shell code on stdout, no trailing prose. Safe to `eval`.

`--shell` governs syntactic variant (`local`, `[[ ]]`, POSIX-portable
fallbacks) of the emitted shell, not host-OS assumptions. The wrapper
function executes on the user's host shell — which may be Linux
bash / zsh, macOS zsh (Docker Desktop), WSL bash, etc. — not inside
the container. Userland-level differences (notably `stat` flag
syntax between GNU and BSD) are handled by emitting a portable probe
that tries both. An explicit `--host-os=linux|macos|wsl` option is
**out of scope for v1** but reserved as a future extension if the
portable probe proves unreliable in practice.

When `--profile=minimal`, the emitted output includes a leading comment
block listing the commands classified as `DockerProfile::Minimal` (for
user reference), gathered via reflection over the `ReliCommand`
subclasses.

Non-zero exit on unknown shell, unknown profile, or malformed option.

## Implementation scope

1. **`src/Command/ReliCommand.php` (new abstract base)** — defines
   `abstract public static function getDockerProfile(): DockerProfile`.
   Plus `src/Command/DockerProfile.php` enum (`Full`, `Minimal`).
2. **Retarget every existing concrete command** from
   `\Symfony\Component\Console\Command\Command` to `ReliCommand`, and
   implement `getDockerProfile()` on each. Mostly mechanical; the
   abstract method makes missing entries fatal at startup.
3. **`src/Command/Docker/PrintWrapperCommand.php`** — new Symfony
   Console Command. Emits the function body as a heredoc with the
   current image tag and profile-appropriate flag set substituted in.
   Image tag source: composer.json version field or a dedicated
   `VERSION` constant — match existing project convention.
4. **DI container / command registration** — add
   `PrintWrapperCommand` wherever existing commands register
   (likely `config/console.php` or similar).
5. **Core change: sidecar socket default** —
   `SidecarSettings::DEFAULT_SOCKET_PATH` resolution:
   `$XDG_RUNTIME_DIR/reli/sidecar.sock` when `XDG_RUNTIME_DIR` is set,
   otherwise fail closed with an instructive error (no `/tmp` fallback
   — keep the privilege-boundary property). Parent dir created mode
   0700 with owner validation before bind.
6. **Dockerfile change** — install `libcap2-bin` and apply
   `setcap cap_sys_ptrace=eip /usr/local/bin/php`.
7. **Tests**:
   - `tests/Command/Docker/PrintWrapperCommandTest.php` — snapshot of
     emitted output for each `--profile` × `--shell` combination.
   - `tests/Command/DockerProfileCoverageTest.php` — iterates
     `Application::all()`, asserts each command is a `ReliCommand`
     subclass and `getDockerProfile()` returns a known enum value.
     (Redundant given the abstract-method enforcement, but catches the
     "accidentally extended Symfony Command directly" case.)
   - `tests/...SidecarSettingsTest.php` — XDG resolution and failure
     mode.
   - Integration smoke test (optional, CI may skip if Docker
     unavailable): `docker run --rm --user $(id -u) --cap-add=SYS_PTRACE
     reliforp/reli-prof:local --version` succeeds without `EPERM`.
8. **README.md** — replace "From Docker" snippet with the
   `eval "$(...)"` one-liner; also mention the `--profile=minimal`
   variant. Drop `sudo` from examples where it was only for ptrace
   permission. Add a privilege / trust surface warning section. Add
   a one-line troubleshooting note: "if a command fails unexpectedly
   under `reli-view` (Minimal), reinstall with `--profile=full` and
   retry" — so first-time users don't have to diagnose the
   profile-mismatch case themselves.
9. **`docs/docker-wrapper.md`** (new) — full reference: profile
   comparison table, `RELI_DOCKER_EXTRA_ARGS` recipes, limitation
   table (reproduced from this design), security notes, FAQ
   (distinguishing "which commands work under Minimal" from the
   profile runtime behaviour).

## Out of scope (future work)

- Fine-grained per-command capability profiles (beyond the binary
  Full / Minimal split). If `DockerProfile` grows additional cases
  (e.g. `NetworkOnly`, `LocalFsOnly`), the wrapper can emit
  correspondingly trimmed flag sets, but we intentionally start
  with two.
- Bundling phpspy in the default image.
- Multi-PHP-version spawn (`-- php7.3 ...`) via alternate image tags.
- PHP-based wrapper for Composer/Git installs (the host-PHP path uses
  `./reli` natively; no wrapper needed).
- Automatic argv path scanning to auto-`-v` mount out-of-cwd paths.
- Wrapper-level runtime guard that refuses to run Full-profile commands
  under the Minimal wrapper (the reli-side failure is considered
  sufficient; a second enforcement layer adds maintenance cost without
  raising the security floor).
- macOS-native / non-Linux host targeting. Docker Desktop's Linux VM
  can run the wrapper but only against Linux PHP processes inside
  containers; host-native PHP on macOS / Windows is not in reli's
  supported target set.
