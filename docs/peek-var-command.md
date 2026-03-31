# inspector:peek-var — One-Shot PHP Variable Inspection

`inspector:peek-var` reads PHP variable values from a running process.
Unlike `inspector:watch --watch-var` (condition-based trigger), this command
simply reads and displays the current value — no triggers, no actions,
no polling loop overhead.

## Quick Start

```bash
# Read a global variable
reli inspector:peek-var -p <pid> --var='global::$cache'

# Read multiple variables at once
reli inspector:peek-var -p <pid> \
  --var='global::$counter' \
  --var='global::$config[database][host]'

# Repeat every 500ms (Ctrl+C to stop)
reli inspector:peek-var -p <pid> --var='global::$cache' --repeat=500

# JSON output for scripting
reli inspector:peek-var -p <pid> --var='global::$counter' --format=json
```

## Requirements

Same as other `inspector:*` commands:

- PHP 8.1+ (for execution), targets PHP 7.0+
- FFI extension
- `CAP_SYS_PTRACE` capability (usually root)

## Variable Specification

Variable expressions use the format `scope::identifier`.

### Scopes

| Scope | Syntax | What it reads |
|-------|--------|---------------|
| Global | `global::$var` | `$GLOBALS['var']` |
| Local | `local::func()$var` | Local variable in a specific function frame |
| Static property | `static::Class::$prop` | Class static property |
| Function static | `func_static::func()$var` | Function's `static $var` |

For `local::` and `func_static::`, the function name is **required**.
Use `<main>` for the top-level script scope.

### Path Expressions

Nested array keys and object properties are supported:

```bash
# Array key access
--var='global::$config[database][host]'

# Object property access
--var='global::$app->cache->size'

# Mixed
--var='global::$container->services[cache]->pool[active]'
```

### Examples

```bash
# Global variable
--var='global::$counter'

# Local variable in script scope
--var='local::<main>()$result'

# Local variable in a specific function
--var='local::App\Controller::index()$response'

# Class static property
--var='static::App\Cache::$entries'

# Function static variable
--var='func_static::App\retry()$attempts'
```

## Output Formats

### Text (default)

```
$ reli inspector:peek-var -p 1234 --var='global::$counter' --var='global::$name' --var='global::$items'
global::$counter = (int) 42
global::$name = (string) "hello_world"
global::$items = (array) count=10
```

Types displayed: `(int)`, `(float)`, `(string)`, `(bool)`, `(array) count=N`, `null`, `(unknown)`.

Variables not found in the target process show `<not found>`.

### JSON (`--format=json`)

```json
{"global::$counter":{"type":"long","value":42,"array_count":null},"global::$name":{"type":"string","value":"hello_world","array_count":null},"global::$items":{"type":"array","value":null,"array_count":10}}
```

## Repeat Mode

With `--repeat=<ms>`, the command polls the target process at the given
interval and prints values each cycle. Useful for watching a value change
over time.

```bash
# Poll every 200ms
reli inspector:peek-var -p <pid> --var='global::$queue' --repeat=200
```

If a read fails (e.g., target is between requests), the error is printed
and the next cycle continues.

## All Options

| Option | Default | Description |
|--------|---------|-------------|
| `-p, --pid` | — | Target process PID |
| `cmd` | — | Command to execute as target (alternative to --pid) |
| `--var` | — | Variable to read (repeatable) |
| `--repeat` | — | Repeat interval in ms (omit for one-shot) |
| `--format` | `text` | Output format: `text` or `json` |
| `--php-version` | `auto` | Target PHP version |
| `--php-regex` | — | Regex to find the php binary in the target |
| `--no-cache` | off | Disable binary analysis cache |

## Relationship with inspector:watch

| | `inspector:peek-var` | `inspector:watch --watch-var` |
|---|---|---|
| **Purpose** | Read current value | Trigger actions on condition |
| **Output** | Variable values | Trigger events + actions |
| **Overhead** | Minimal (one read) | Poll loop + trigger/action system |
| **Use case** | Quick inspection, scripting | Production monitoring |

`peek-var` is useful for:

- Quick checks: "what's in `$cache` right now?"
- Exploring variables before writing `--watch-var` trigger conditions
- Scripting with `--format=json`
- Debugging: see a variable's value without modifying the target code
