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
eval "$(docker run --rm reliforp/reli-prof docker:print-wrapper)"
```

This defines `reli` as a shell function in the current session. To persist,
users append the command to their `~/.bashrc` / `~/.zshrc`.

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

## Wrapper shape (draft)

```bash
reli() {
  local tty=
  [ -t 0 ] && [ -t 1 ] && tty=-t

  # Per-user scratch dirs (mode 700) to avoid cross-user leaks on shared hosts.
  local uid; uid=$(id -u)
  local scratch="/tmp/reli-${uid}"
  mkdir -m 700 -p "${scratch}/state" "${scratch}/cache" "${scratch}/runtime" 2>/dev/null || true

  docker run --rm -i ${tty} \
    --cap-add=SYS_PTRACE \
    --security-opt=apparmor=unconfined \
    --pid=host \
    --network=host \
    --user "${uid}:$(id -g)" \
    -v "$PWD":"$PWD" -w "$PWD" \
    -v "${scratch}":"${scratch}" \
    -e XDG_STATE_HOME="${scratch}/state" \
    -e XDG_CACHE_HOME="${scratch}/cache" \
    -e XDG_RUNTIME_DIR="${scratch}/runtime" \
    -e HOME="${scratch}" \
    ${RELI_DOCKER_EXTRA_ARGS:-} \
    reliforp/reli-prof:<TAG_PINNED_AT_GENERATION> "$@"
}
```

Key properties:

- `--pid=host` + `CAP_SYS_PTRACE` cover attach (`-p <pid>`), `/proc/<pid>/*`
  reads, and daemon regex matching against host process list.
- `--network=host` covers `rmem:live`, any HTTP/SSE, and host-visible TCP
  listeners — eliminates per-command `-p host:container` reasoning.
- `-v "$PWD":"$PWD" -w "$PWD"` makes every cwd-relative or cwd-anchored
  absolute path Just Work (`-o trace.rbt`, `-o ./sub/trace.rbt`,
  `-o $PWD/trace.rbt` all resolve identically inside and outside).
- Per-user scratch in `/tmp/reli-$UID` (mode 700) hosts XDG-derived
  defaults that would otherwise fall under `$HOME` inside the container
  and vanish on exit.
- `--user "$UID:$GID"` makes generated files owned by the host user
  (no `sudo rm` required after profiling).
- `RELI_DOCKER_EXTRA_ARGS` is the documented escape hatch for extra
  mounts (paths outside cwd), extra capabilities, custom `/etc/hosts`, etc.

Dropped from earlier drafts:

- `--ipc=host` — unnecessary. reli's IPC (sidecar, rmem-serve) is Unix
  sockets on filesystem paths, not System V IPC. Dropping reduces
  privilege surface.
- `-v /tmp:/tmp` — replaced by the narrower per-user scratch mount. The
  sidecar socket default will be moved (see below) so that a broad
  `/tmp` mount is not needed.

## Privilege and trust surface

The wrapper grants the container a substantial subset of host privilege.
README **must** call this out explicitly so that users on shared or
multi-tenant hosts understand the bargain:

| Flag                              | Effect                                                      |
|-----------------------------------|-------------------------------------------------------------|
| `--cap-add=SYS_PTRACE`            | Can ptrace / read `/proc/<pid>/mem` of any host process.    |
| `--security-opt=apparmor=unconfined` | Removes AppArmor confinement on the container.           |
| `--pid=host`                      | Container `/proc` shows all host processes (argv, env).     |
| `--network=host`                  | Container binds directly on host interfaces.                |

Recommendation for shared hosts: document `RELI_DOCKER_EXTRA_ARGS` usage
to narrow the profile, or discourage the wrapper entirely on
multi-tenant machines.

Viewer-only commands (`rbt:explore`, `rmem:explore`, `rmem:viz`) do not
need ptrace, pid=host, or network=host — they only read local files.
A least-privilege variant could be emitted per-command (wrapper dispatches
on `$1`), but this was **deferred**: it violates the "same look"
invariant slightly (behavioural asymmetry across commands) and adds a
maintenance surface (every new command requires a classification entry).
Revisit if users request it.

## Changes required in reli core

### A. `inspector:sidecar` socket default

**Current**: `SidecarSettings::DEFAULT_SOCKET_PATH = '/var/run/reli-sidecar.sock'`.

**Problem**: `/var/run` is root-owned and not writable by the non-root
container user. Even for native installs, the current default forces
`sudo` usage for sidecar startup.

**Change**: default to `${XDG_RUNTIME_DIR}/reli/sidecar.sock`, resolved at
runtime. On most systemd distros `$XDG_RUNTIME_DIR` is `/run/user/$UID`
(mode 0700, per-user). Fallback when unset: `/tmp/reli-$UID/sidecar.sock`
where `/tmp/reli-$UID` is created with mode 0700 by reli itself before
binding.

Why not just `/tmp/reli-sidecar.sock`: `/tmp` is world-writable. A
concurrent attacker on the same host could pre-create the socket path,
intercepting the target app's sidecar IPC (which has no authentication
on the wire). The per-user 0700 parent directory prevents this.

Secondary benefit: native installs lose the `sudo` requirement for
sidecar.

### B. `inspector:daemon` output default

**Current**: resolves to `$XDG_STATE_HOME/reli/daemon-traces/{session}/`
(typically `$HOME/.local/state/reli/...`).

**Decision**: **keep the default unchanged**. Move the problem entirely
into the wrapper by setting `XDG_STATE_HOME=/tmp/reli-$UID/state` in the
container's environment. Reasoning:

- Profile data is sensitive (variable values via `--trace-var`, heap
  contents via memory dumps — routinely contains credentials / PII).
- Defaulting to cwd normalises writing such data into directories that
  may be world-readable, git-tracked, or cloud-synced.
- XDG state home (mode 0700, per-user) is the privacy-conserving default.

### C. XDG handling in wrapper

Wrapper pre-creates `/tmp/reli-$UID/{state,cache,runtime}` with mode 0700
and exports the three XDG env vars pointing at them. `HOME` is set to
`/tmp/reli-$UID` so that any `$HOME`-derived config path (e.g.
`~/.config/reli/config.php` discovery) has a stable, per-user, mounted
location.

Users who want to share state with host can override via
`RELI_DOCKER_EXTRA_ARGS` — e.g.,
`-v "$HOME/.local/state/reli":"$HOME/.local/state/reli" -e HOME="$HOME"`.

## Integrity hazards: known and handled

Inventoried via a pass over every Symfony Console command definition.

### Handled by wrapper

| Hazard                                             | Handling                                |
|----------------------------------------------------|-----------------------------------------|
| `-o trace.rbt` / `-o ./foo/bar.rbt`                | cwd mounted at same path.               |
| Absolute output paths inside cwd                   | cwd mounted at same path.               |
| `-p <pid>` attach                                  | `--pid=host` + `CAP_SYS_PTRACE`.        |
| `/proc/<pid>/{exe,maps,mem}` reads                 | `--pid=host` + `CAP_SYS_PTRACE`.        |
| HTTP/SSE listeners (`rmem:live`)                   | `--network=host`.                       |
| Unix sockets under `$XDG_RUNTIME_DIR`              | scratch mount + XDG override.           |
| TUI (`rbt:explore`, `rmem:explore`)                | auto `-t`.                              |
| File ownership                                     | `--user "$UID:$GID"`.                   |
| Daemon output default                              | XDG_STATE_HOME → scratch.               |

### Handled by core changes

| Hazard                        | Change                                          |
|-------------------------------|-------------------------------------------------|
| sidecar socket at `/var/run`  | default → `$XDG_RUNTIME_DIR/reli/sidecar.sock`  |

### Documented limitations (not auto-handled)

| Scenario                                                   | Guidance                                              |
|------------------------------------------------------------|-------------------------------------------------------|
| `-o /var/log/...` (absolute path outside cwd)              | Use `RELI_DOCKER_EXTRA_ARGS='-v /var/log:/var/log'`.  |
| `inspector:trace -- python3 script.py` (non-PHP spawn)     | Not supported via default image; use custom image.    |
| `inspector:trace -- php7.3 script.php` (specific PHP ver)  | Not supported via default image; use custom image.    |
| `phpspy:*` commands                                        | Not supported via default image (phpspy not bundled). |
| Host `~/.config/reli/` visibility                          | `RELI_DOCKER_EXTRA_ARGS='-v $HOME/.config/reli:/.config/reli:ro'`. |
| ptrace_scope=2/3 on host                                   | `--user 0:0` fallback via `RELI_DOCKER_AS_ROOT=1`.    |
| Multi-tenant host                                          | README warning; discourage default wrapper.           |

### Open verification items

- `CAP_SYS_PTRACE` under `--user non-root`: confirmed by spec
  (CAP_SYS_PTRACE bypasses ptrace_scope=1 and the uid check in
  `/proc/*/mem`), but should be verified on a distro sample
  (Ubuntu 24.04, Alpine, Amazon Linux 2023) before release.
- `/dev/tty` open inside container (used by `Terminal`): confirmed
  to work with `-it`, but needs verification for `reli ... | less`
  style pipelines (wrapper's TTY auto-detect sees stdout=pipe and
  skips `-t`, which is correct but may surprise users of TUI commands).
- Read access on image files (composer vendor, resources) under
  non-root `--user`: verify image build does not leave root-only
  modes on these trees.

## CLI shape

New command: `docker:print-wrapper`.

```
docker:print-wrapper [--name=NAME] [--image=IMAGE] [--shell=SHELL]

Options:
  --name=NAME   Shell function name (default: reli)
  --image=IMAGE Image reference (default: reliforp/reli-prof:<current-version>)
  --shell=SHELL Target shell syntax: bash|zsh|posix (default: posix)
```

Output: shell code on stdout, no trailing prose. Safe to `eval`.

Non-zero exit on unknown shell or malformed option.

## Implementation scope

1. `src/Command/Docker/PrintWrapperCommand.php` — new Symfony Console
   Command. Emits the function body as a heredoc with the current image
   tag substituted in. Image tag source: composer.json or a dedicated
   `VERSION` constant — whichever matches existing conventions.
2. DI container / command registration — wherever existing commands
   register (to confirm: `config/` directory).
3. Core change: `SidecarSettings::DEFAULT_SOCKET_PATH` →
   `$XDG_RUNTIME_DIR/reli/sidecar.sock` resolution. Creates parent dir
   with 0700 if absent. Falls back to `/tmp/reli-$UID/sidecar.sock`
   when `$XDG_RUNTIME_DIR` is unset (same directory-creation protocol).
4. `tests/Command/Docker/PrintWrapperCommandTest.php` — snapshot of
   emitted output for `--shell=posix` and `--shell=bash`.
5. `tests/...SidecarSettingsTest.php` — XDG resolution and fallback.
6. `README.md` — replace "From Docker" install snippet with the
   `eval "$(...)"` one-liner. Drop `sudo` from examples that use it
   only for ptrace permission. Add a warning section on privilege
   surface and multi-tenant hosts.
7. `docs/docker-wrapper.md` — full reference: env vars
   (`RELI_DOCKER_EXTRA_ARGS`, `RELI_DOCKER_AS_ROOT`), limitation table
   (reproduced from above), security notes.

## Out of scope (future work)

- Per-command least-privilege profiles (drop ptrace for viewers).
- Bundling phpspy in the default image.
- Multi-PHP-version spawn (`-- php7.3 ...`) via alternate image tags.
- PHP-based wrapper for Composer/Git installs (the host-PHP path uses
  `./reli` natively; no wrapper needed).
- Automatic argv path scanning to auto-`-v` mount out-of-cwd paths.
