# Hybrid phpspy mode

reli can use [phpspy](https://github.com/adsr/phpspy) as the fast
C-based tracing backend while reli itself handles EG address
resolution — including for ZTS targets, which phpspy alone cannot
resolve. The result is phpspy's sampling throughput with reli's
ZTS support.

> **Note:** The official `reliforp/reli-prof` Docker image does **not**
> bundle phpspy. If you installed reli via the Docker wrapper, the
> `phpspy:*` commands won't work out of the box — install phpspy
> natively (e.g. via `phpspy:install` on a host install of reli) or
> build a custom image that runs `phpspy:install` at build time. See
> [docker-wrapper.md § Limitations](../docker-wrapper.md#limitations).

## Commands

| Command | Purpose |
|---|---|
| `phpspy:install` | Build + install the phpspy binary to `~/.reli/bin/phpspy` by default |
| `phpspy:trace` | Attach to a single process, resolve EG / SG, then launch phpspy against the resolved address |
| `phpspy:daemon` | Discover processes by regex and launch phpspy per process |

## Quick start

```bash
# 1. Install phpspy (builds from source by default)
./reli phpspy:install

# 2. Trace a single process
sudo php ./reli phpspy:trace -p <pid>

# 3. Or trace a whole php-fpm pool
sudo php ./reli phpspy:daemon -P "^php-fpm"
```

`phpspy:trace` typically prints the resolved EG / SG addresses before
starting phpspy, so you can confirm the right target was selected:

```
resolving EG address...
EG address resolved: 0x564102620bc0
SG address resolved: 0x564102620600
starting phpspy for pid 12345...
0 usleep <internal>:-1
1 <main> Command line code:1
```

## ZTS targets

This is the primary reason the hybrid mode exists: phpspy alone
cannot resolve the Executor Globals address for ZTS PHP, but reli
can. Simply targeting a ZTS PID works transparently:

```bash
sudo php ./reli phpspy:trace -p <zts-pid>
```

If you only need the EG address (e.g. you want to invoke phpspy
yourself with a custom workflow), use `inspector:eg`:

```bash
sudo php ./reli inspector:eg -p <zts-pid>
# 0x555ae7825d80
```

## Passing extra phpspy flags

`--phpspy-args` is a passthrough that forwards arbitrary flags to
the phpspy binary:

```bash
sudo php ./reli phpspy:trace -p <pid> --phpspy-args="-c -1"
```

## All options

Run `./reli phpspy:install --help`, `./reli phpspy:trace --help`, or
`./reli phpspy:daemon --help` for the complete flag list — the CLI
help is the source of truth.

Common `phpspy:trace` / `phpspy:daemon` flags include
`-s/--sleep-ns`, `-b/--buffer-size`, `-H/--rate-hz`, `--phpspy-args`,
`--phpspy-path`, `-o/--output`.

## See also

- [adsr/phpspy](https://github.com/adsr/phpspy) — upstream project
- [docs/comparison.md](../comparison.md) — when to reach for reli vs. phpspy directly
