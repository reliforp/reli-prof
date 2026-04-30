# Docker wrapper reference

The Docker image ships with `docker:print-wrapper`, a command that emits a
shell function you `eval` into your shell. The function makes `reli <cmd>
...` work exactly like a native install (same examples, same flags),
routed through `docker run` transparently.

## Install

```bash
# Full profile (default) — attaches to live processes.
eval "$(docker run --rm --pull=always reliforp/reli-prof docker:print-wrapper)"

# Minimal profile — viewers / converters only, lower privilege.
eval "$(docker run --rm --pull=always reliforp/reli-prof docker:print-wrapper --profile=minimal)"
```

`--pull=always` forces Docker to refresh `reliforp/reli-prof:latest`
before invoking `docker:print-wrapper`. Without it, a previously
cached `:latest` from an older release can be missing this command
(or other newer features) and the bootstrap fails with
`There are no commands defined in the "docker" namespace.` It only
matters at install time — once the wrapper is installed, the
per-invocation `reli` calls use the specific tag baked into the
wrapper rather than `:latest`.

For persistence across shells, save the emitted wrapper to a file once
and `source` it from your rc — pasting the `eval "$(docker run ...)"`
line into `~/.bashrc` / `~/.zshrc` directly re-runs `docker run` on
every shell start and breaks shell startup if the Docker daemon is
down:

```bash
mkdir -p ~/.local/share/reli
docker run --rm --pull=always reliforp/reli-prof docker:print-wrapper > ~/.local/share/reli/wrapper.sh
echo 'source ~/.local/share/reli/wrapper.sh' >> ~/.bashrc   # or ~/.zshrc
```

The image tag is baked into the emitted wrapper (matching the image
the `docker run` pulled), so you don't get accidental `:latest` drift —
re-run the snippet above to upgrade. `--pull=always` makes that re-run
actually fetch the newest `:latest` rather than reusing the cached
image from the previous install.

## Profiles

| Flag / setting                       | Full | Minimal | Notes                                                    |
|--------------------------------------|:----:|:-------:|----------------------------------------------------------|
| `--cap-add=SYS_PTRACE`               | ✓    |         | Required for attaching to live processes.                |
| `--security-opt=apparmor=unconfined` | ✓    |         | Needed for ptrace on AppArmor-enabled distros.           |
| `--pid=host`                         | ✓    |         | Makes host PIDs visible in the container.                |
| `--network=host`                     | ✓    |         | Required for `rmem:live` HTTP/SSE and similar.           |
| `--user $UID:$GID`                   | ✓    | ✓       | Generated files are host-user-owned.                     |
| `--read-only` rootfs + tmpfs         |      | ✓       | Container filesystem is immutable under Minimal.         |
| cwd bind mount (`$PWD:$PWD`)         | ✓    | ✓       | Relative and cwd-anchored paths Just Work.               |

Full is the default because most canonical reli invocations (tracing,
memory dump, peek-var, sidecar, watch) need host PID / ptrace access.
Minimal omits all four privilege flags — use it when you only need to
`rbt:explore` / `rmem:explore` / `rmem:viz` / `converter:*` an existing
capture, or on a shared / multi-tenant host where granting
`CAP_SYS_PTRACE` to a container is inappropriate.

### Commands safe under Minimal

`docker:print-wrapper --profile=minimal` prints the current allowlist at
the top of the emitted wrapper. At the time of writing:

- `cache:clear`
- `converter:*` (callgrind, flamegraph, folded, phpspy, pprof, rbt, speedscope)
- `docker:print-wrapper`
- `inspector:memory:analyze`, `inspector:memory:compare`,
  `inspector:memory:dump:inspect`, `inspector:memory:normalize-dump`,
  `inspector:memory:report`, `inspector:optimize-memory-db`
- `rbt:analyze`, `rbt:explore`, `rbt:recover`
- `rmem:*` (explore, live, mcp, query, serve, viz)

Running a Full-profile command under the Minimal wrapper will fail when
reli tries to use a capability it does not have (usually `EPERM` on a
ptrace call or an `address already in use` on a host-port bind). If a
command fails for an unexpected reason, reinstall with `--profile=full`
and retry.

## Options

```
docker:print-wrapper [--profile=PROFILE] [--name=NAME] [--image=IMAGE] [--shell=SHELL]

--profile=PROFILE  full (default) or minimal.
--name=NAME        Shell function name. Default: reli for full, reli-view
                   for minimal. Use this to run both profiles side-by-side:
                     eval "$(docker run ... --profile=full --name=reli)"
                     eval "$(docker run ... --profile=minimal --name=reliv)"
--image=IMAGE      Image reference to bake in. Defaults to
                   reliforp/reli-prof:<current-version>; dev / unknown
                   versions fall back to :latest.
--shell=SHELL      Target shell dialect (bash|zsh|posix). Currently
                   informational; all emitted output uses bash-compatible
                   syntax (`local`, `[[ ]]`).
```

## Environment knobs

### `RELI_DOCKER_EXTRA_ARGS`

Passed verbatim to `docker run`. The main use cases:

```bash
# Make a directory outside cwd visible inside the container.
RELI_DOCKER_EXTRA_ARGS='-v /var/log:/var/log' reli inspector:trace ...

# Share the host's reli config read-only.
# Use double quotes so $HOME expands when the env var is assigned: the
# wrapper word-splits ${RELI_DOCKER_EXTRA_ARGS} unquoted but does not
# re-expand it, so a single-quoted '$HOME' would reach docker as the
# literal string '$HOME' and the bind mount would fail.
RELI_DOCKER_EXTRA_ARGS="-v $HOME/.config/reli:$HOME/.config/reli:ro" reli ...

# Extra capability you know you need (e.g. SYS_ADMIN for some edge case).
RELI_DOCKER_EXTRA_ARGS='--cap-add=SYS_ADMIN' reli ...
```

### Scratch directory

The wrapper chooses a scratch dir from `$XDG_RUNTIME_DIR/reli-docker`
(on systemd hosts) or `$HOME/.cache/reli-docker`, and exports
`HOME` / `XDG_STATE_HOME` / `XDG_CACHE_HOME` / `XDG_RUNTIME_DIR` to
sub-paths inside it. This keeps daemon output, binary-analysis cache,
sidecar sockets, and other state on the host user's filesystem.

The wrapper strictly validates the scratch dir (owner, mode 0700,
non-symlink) before each run. If the check fails, the wrapper aborts
with a reason-specific hint — typically `chmod 0700 '<path>'` is all
that's needed.

## Limitations

| Scenario                                                   | Guidance                                              |
|------------------------------------------------------------|-------------------------------------------------------|
| Absolute path outside cwd (`-o /var/log/...`)              | `RELI_DOCKER_EXTRA_ARGS='-v /var/log:/var/log'`.      |
| `inspector:trace -- python3 script.py` (non-PHP spawn)     | Not supported by default image; build a custom image. |
| `inspector:trace -- php7.3 ...` (specific PHP target)      | Not supported by default image; build a custom image. |
| `phpspy:*` commands                                        | Not bundled; build a custom image.                    |
| `ptrace_scope=2/3` on the host                             | Temporarily raise `yama.ptrace_scope`, or use a native install. |
| Multi-tenant host                                          | Prefer `--profile=minimal`; avoid installing the Full wrapper if multiple tenants share the host. |

## Security

The Full profile grants the container a substantial subset of host
privilege:

- `CAP_SYS_PTRACE` lets the container read `/proc/<pid>/mem` and ptrace
  any host process, not just PHP ones.
- `--pid=host` exposes every host process (argv, environment, cwd) in
  the container's `/proc`.
- `--network=host` lets the container bind directly on host interfaces.
- `--security-opt=apparmor=unconfined` removes AppArmor constraints.

Inside the container, reli runs as the host user (`--user $(id -u):$(id -g)`).
Linux zeroes the effective capability set at exec for non-zero uids, so
`--cap-add=SYS_PTRACE` alone would still leave ptrace returning `EPERM`.
The wrapper works around that by setting `--entrypoint /opt/reli/php-ptrace/php`,
selecting a shadow copy of the PHP binary that carries `cap_sys_ptrace=eip`
as a file capability — on exec the kernel re-promotes `CAP_SYS_PTRACE` into
the effective set. The default ENTRYPOINT (`/usr/local/bin/php`) is
intentionally left clean, so plain `docker run reliforp/reli-prof <cmd>`
for offline-only commands (`rbt:analyze`, `inspector:memory:report`,
`converter:*`, …) works without `--cap-add`. The shadow copy's basename is
`php` (the variant tag is in the directory name), so reli's default
`--php-regex` matches `/proc/<pid>/maps` for processes started from it —
relevant when one wrapper-launched reli attaches to another.

Trust the wrapper accordingly: the Full profile is fine for
single-user laptops and dedicated CI runners, but think twice on
shared hosts. The Minimal profile drops all four privilege flags
above; running it on any machine you'd trust to run an ordinary
unprivileged container is equivalent.
