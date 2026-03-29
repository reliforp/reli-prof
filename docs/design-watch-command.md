# Design: `inspector:watch` Command

## Overview

PHP プロセスを継続的に監視し、条件ベースのトリガが発火したときに自動的に
プロファイリングアクション（トレース取得、メモリダンプ、ログ記録等）を実行する
`inspector:watch` コマンドを追加する。

既存の `inspector:top`（リアルタイム表示）や `inspector:daemon`（複数プロセストレース）
とは異なり、**「条件を満たしたときだけアクションを起こす」** という受動的な監視に特化する。

### Motivation

- メモリリークの兆候を早期検出し、閾値到達時に自動でスナップショットを残す
- 特定の重い関数が呼ばれた瞬間を捕捉する
- 例外発生時のコールスタック状態を記録する
- プロダクション環境での低オーバーヘッド監視（条件非該当時はヒープ統計の読み取りのみ）

## CLI Interface

```bash
# 基本: 単一プロセス、メモリ閾値で trace を取得
reli inspector:watch -p <PID> \
  --memory-limit=256M \
  --action=trace

# daemon モード: 複数プロセス同時監視
reli inspector:watch --target-regex="php-fpm" \
  --memory-limit=512M \
  --memory-growth-rate=10M/min \
  --action=memory-dump \
  --action-output-dir=/var/log/reli-watch/

# 関数検出 + 例外検出
reli inspector:watch -p <PID> \
  --watch-function="App\\HeavyService::process" \
  --on-exception \
  --action=trace \
  --action=log

# 複数トリガ + カスタムコマンド実行
reli inspector:watch --target-regex="artisan" \
  --memory-limit=256M \
  --trace-depth-limit=200 \
  --action=exec --action-exec-command="curl -s -X POST https://hooks.example.com/alert" \
  --cooldown=30

# グローバル変数の配列サイズ監視
reli inspector:watch -p <PID> \
  --watch-global-array-size='cache:10000' \
  --action=memory-dump

# 変数値の監視 (グローバル変数)
reli inspector:watch -p <PID> \
  --watch-var='global::retry_count:gt:5' \
  --action=trace --action=log

# クラス静的プロパティの監視
reli inspector:watch -p <PID> \
  --watch-var='static::App\Cache::$size:gt:100000' \
  --action=memory-dump

# ローカル変数の監視
reli inspector:watch -p <PID> \
  --watch-var='local::items:count_gt:10000' \
  --watch-function="App\\process" \
  --action=trace

# 関数静的変数の監視
reli inspector:watch -p <PID> \
  --watch-var='func_static::App\retry::$attempt:gt:10' \
  --action=log
```

## Trigger System

### Tier 1: 軽量トリガ (ヒープ統計のみ、process_vm_readv 1-2回)

| トリガ | オプション | 説明 |
|--------|-----------|------|
| Memory Limit | `--memory-limit=<size>` | `ZendMmHeap::$size` が閾値を超過 |
| Memory Growth Rate | `--memory-growth-rate=<size>/<period>` | 直近 N サンプルの増加率が閾値を超過 |
| Memory Peak Watch | `--memory-peak-watch` | `ZendMmHeap::$peak` が更新されるたび |

**実装の要点:**
`ZendMmHeap` の `size`, `real_size`, `peak` フィールドは `process_vm_readv` 1回で読める。
既存の `MemoryLocationsCollector` はフルスキャン（数百ms〜数秒）だが、このパスは
`ZendMmChunk::heap_slot` → `ZendMmHeap` の数フィールドを読むだけで完了する（< 1ms）。

### Tier 2: トレース依存トリガ (コールスタック読み取り、process_vm_readv 数十回)

| トリガ | オプション | 説明 |
|--------|-----------|------|
| Function Detection | `--watch-function=<name>` | 指定関数がコールスタックに出現 |
| Trace Depth Limit | `--trace-depth-limit=<N>` | コールスタックが N 段を超過 |

**実装の要点:**
既存の `CallTraceReader::readCallTrace()` をそのまま利用。
Tier 2 トリガが1つでも有効な場合、ポーリングごとにトレースを読む。
`--stop-process` (`-S`) との併用で一貫性を確保可能。

### Tier 3: 高度なトリガ (PHP内部構造の深い読み取り)

| トリガ | オプション | 説明 |
|--------|-----------|------|
| Exception Detection | `--on-exception` | `EG->exception` が非null（例外飛行中） |
| Global Array Size | `--watch-global-array-size=<name>:<limit>` | `EG->symbol_table` 内の配列の `nNumOfElements` が閾値超過 |
| Variable Value | `--watch-var=<scope>::<name>:<op>:<value>` | 指定スコープの変数値が条件を満たした時 |

**実装の要点:**

**Variable Value (`--watch-var`):**

4つのスコープの変数を監視可能。スコープは `<scope>::<name>` 形式で指定する。

```bash
# グローバル変数
--watch-var='global::counter:gt:1000'
--watch-var='global::status:eq:error'

# ローカル変数 (現在のコールフレームの変数)
--watch-var='local::items:count_gt:10000'
--watch-var='local::retries:gte:3'

# クラス静的プロパティ
--watch-var='static::App\Cache::$entries:count_gt:50000'
--watch-var='static::App\Config::$debug:eq:true'

# 関数静的変数
--watch-var='func_static::App\retry::$attempt:gt:10'
```

**各スコープの読み取りパス:**

| スコープ | 内部構造 | アクセスパス |
|----------|---------|-------------|
| `global` | `EG->symbol_table` | `ZendArray` からキーで lookup → `Zval` |
| `local` | `zend_execute_data` 直後の CV 領域 | `EG->current_execute_data` → `ZendOpArray->last_var` + 変数名テーブルで index 解決 → `execute_data + sizeof(zend_execute_data) + index * sizeof(zval)` |
| `static` | `ZendClassEntry->static_members_table` | `EG->class_table` からクラス名で lookup → `ZendClassEntry` → `static_members_table` + `PropertyInfo` で offset 解決 |
| `func_static` | `ZendOpArray->static_variables` | `EG->function_table` から関数名で lookup → `ZendOpArray->static_variables` (`ZendArray`) からキーで lookup |

**比較演算子:**

| 演算子 | 対象型 | 説明 |
|--------|--------|------|
| `eq`, `ne` | 全型 | 等値/非等値 |
| `gt`, `lt`, `gte`, `lte` | `IS_LONG`, `IS_DOUBLE` | 数値比較 |
| `contains` | `IS_STRING` | 部分文字列一致 |
| `count_gt`, `count_lt`, `count_eq` | `IS_ARRAY` | 配列要素数の比較 |
| `is_null` | 全型 | `IS_NULL` チェック |

**既存の型読み取り基盤:**
- `ZendArray`: ハッシュテーブル走査 (`iterateBucket()` 等)
- `Zval`: 型判定 (`type` フィールド) + 値読み取り
- `ZendClassEntry`: クラス名解決 + `static_members_table` アクセス
- `ZendOpArray`: `static_variables` (ZendArray), `last_var`, 変数名テーブル
- `ZendExecuteData`: `symbol_table`, `func`, CV 領域アクセス

**Exception Detection:**
`zend_executor_globals` の C 構造体には `zend_object *exception` フィールドがある
（`src/Lib/PhpInternals/Headers/v84.h:916`）。現在の `ZendExecutorGlobals.php` には
未公開のため、`exception` ポインタフィールドを追加する必要がある。
ポインタの非null チェックだけなので軽量（`process_vm_readv` 1回追加）。

```php
// ZendExecutorGlobals.php に追加
/** @var Pointer<ZendObject>|null */
public ?Pointer $exception;
```

**Global Array Size:**
`EG->symbol_table`（`ZendArray` 型）から変数名をキーに lookup し、
該当する zval が `IS_ARRAY` なら `ZendArray::$nNumOfElements` を読む。
`ZendArray` のハッシュテーブル走査が必要だが、グローバル変数名は
シンボルテーブルの先頭付近にあることが多く、実用的に高速。

## Action System

### Built-in Actions

| アクション | `--action=` 値 | 説明 |
|-----------|----------------|------|
| Trace Capture | `trace` | コールトレースを出力 |
| Memory Dump | `memory-dump` | フルメモリプロファイリング (JSON/SQLite3) (デフォルト) |
| Event Log | `log` | タイムスタンプ、PID、トリガ情報をログに記録 |
| Exec | `exec` | 外部コマンドを実行 |

`--action` は複数指定可能。未指定時は `memory-dump` がデフォルト。

### Trace Capture (`--action=trace`)

既存の `CallTraceReader` + `TraceOutputFactory` を利用。
Tier 2 トリガが有効な場合はトリガ評価時に取得済みのトレースを再利用。

### Memory Dump (`--action=memory-dump`)

既存の `MemoryLocationsCollector` + `MemoryOutputFactory` でフルスナップショットを取得。
`--action-output-dir` 配下に `watch-<PID>-<timestamp>.<format>` で出力。
フォーマットは `--memory-output-format={json,sqlite3}` で指定。

**注意:** フルメモリスキャンは重い (数百ms〜数秒)。アクション実行中はプロセスを
`-S` で停止することを推奨。

### Event Log (`--action=log`)

```
[2026-03-29T12:34:56+09:00] PID=1234 trigger=memory-limit value=268435456 threshold=256M
[2026-03-29T12:35:30+09:00] PID=1234 trigger=watch-function function="App\HeavyService::process" depth=45
[2026-03-29T12:36:01+09:00] PID=5678 trigger=on-exception
```

`--log-file=<path>` で出力先指定。未指定時は stderr。

### Exec (`--action=exec`)

`--action-exec-command=<command>` で指定したコマンドを実行。
環境変数でコンテキストを渡す:

| 環境変数 | 説明 |
|----------|------|
| `RELI_WATCH_PID` | 対象プロセスの PID |
| `RELI_WATCH_TRIGGER` | 発火したトリガ名 |
| `RELI_WATCH_MEMORY_USAGE` | 現在のメモリ使用量 (bytes) |
| `RELI_WATCH_MEMORY_PEAK` | メモリピーク (bytes) |
| `RELI_WATCH_TIMESTAMP` | ISO 8601 タイムスタンプ |
| `RELI_WATCH_FUNCTION` | (watch-function時) 検出された関数名 |
| `RELI_WATCH_DUMP_PATH` | (memory-dump時) 出力されたダンプファイルのパス |

**セキュリティ:** コマンド文字列はユーザーが明示的に指定するものであり、
reli-prof 自体が実行するのでシェルインジェクションのリスクは限定的だが、
環境変数経由でのみ動的値を渡し、コマンド文字列自体にはプレースホルダーを使わない。

## Common Options

### 監視間隔

| オプション | デフォルト | 説明 |
|---|---|---|
| `--poll-interval=<ms>` | `1000` | ポーリング間隔 (ミリ秒)。最小値 100ms。 |

`--poll-interval` はポーリングごとのスリープ時間。Tier 1 トリガのみの場合は
短い間隔 (100-500ms) でも対象プロセスへの影響は最小限 (`process_vm_readv` 1回/poll)。
Tier 2/3 トリガが有効な場合はトレース読み取りコストがあるため、
1000ms 以上を推奨。

### レートリミット・ディスク保護

連続トリガによるディスク爆発と対象プロセスの性能劣化を防ぐ多層制御:

| オプション | デフォルト | 説明 |
|---|---|---|
| `--cooldown=<seconds>` | `60` | 同一トリガの再発火までの最小待機時間 |
| `--max-triggers=<N>` | `0` (無制限) | 累計トリガ回数上限。到達したら監視終了 |
| `--max-triggers-per-hour=<N>` | `10` | 1時間あたりのトリガ回数上限。超過分は無視 |
| `--max-dump-size=<size>` | `1G` | ダンプファイルの累計サイズ上限。超過したら memory-dump アクションをスキップ |
| `--backoff-multiplier=<float>` | `2.0` | 連続トリガ時の cooldown 指数バックオフ倍率 |
| `--backoff-max=<seconds>` | `3600` | バックオフの上限秒数 |

**指数バックオフの動作:**

同一トリガが連続で発火する場合、cooldown を指数的に増加させてディスク・性能への
影響を抑制する。

```
1回目: 即座に発火
2回目: cooldown (60s) 待機
3回目: cooldown × backoff_multiplier (120s) 待機
4回目: cooldown × backoff_multiplier² (240s) 待機
  ...
上限: backoff_max (3600s = 1時間) で頭打ち
```

トリガ条件を満たさなくなった場合、バックオフカウンタはリセットされる。

**CooldownManager の拡張:**

```php
final class CooldownManager
{
    /** @var array<string, CooldownState> トリガ名 → 状態 */
    private array $states = [];

    public function canFire(string $trigger_name, float $now): bool;
    public function recordFire(string $trigger_name, float $now): void;
    public function recordClear(string $trigger_name): void;  // 条件解除時のリセット
    public function getHourlyCount(): int;
}

final class CooldownState
{
    public int $fire_count = 0;
    public int $consecutive_fires = 0;      // 連続発火回数 (バックオフ計算用)
    public float $last_fire_time = 0.0;
    public float $current_cooldown;          // 現在の実効 cooldown
    /** @var \SplQueue<float> */
    public \SplQueue $hourly_timestamps;     // 直近1時間のタイムスタンプ
}
```

**ディスク使用量トラッキング:**

`MemoryDumpAction` 実行時にファイルサイズを記録し、累計が `--max-dump-size` を
超過したら以降の dump をスキップして警告を出力する。trace/log アクションは
サイズが小さいため制限対象外。

```php
final class DiskUsageTracker
{
    private int $total_bytes = 0;

    public function recordFile(string $path): void
    {
        $this->total_bytes += filesize($path);
    }

    public function canWrite(int $limit_bytes): bool
    {
        return $this->total_bytes < $limit_bytes;
    }
}
```

### ステータス表示

**単一プロセスモード:**

ターミナルにインラインでステータスラインを上書き表示 (`\r` + ANSI)。
`inspector:top` と同じスタイルで、画面を埋め尽くさずに状態を把握可能:

```
[watching] PID=1234 | mem=45.2M/256M | polls=1523 | triggers=3/10 | disk=127M/1G | cooldown=OK
```

トリガ発火・スキップ時のみ改行付きで記録:

```
[TRIGGERED] PID=1234 | trigger=memory-limit | mem=261.3M>256M | action=memory-dump → watch-1234-20260329T123456.json
[SKIPPED]   PID=1234 | trigger=memory-limit | reason=hourly limit (10/10)
```

**daemon モード:**

複数プロセスを監視するため、ポーリングごとのステータスライン表示は行わない。
代わりに `--status-interval=<seconds>` (デフォルト: 60) で定期的にサマリを出力:

```
[status] 2026-03-29T12:35:00+09:00 | watching=12 procs | triggers=5 total | disk=423M/1G
  PID=1234 (php-fpm) mem=198.7M  triggers=2  last=12:34:01  cooldown=backoff(240s)
  PID=2345 (php-fpm) mem=45.2M   triggers=0  last=never     cooldown=OK
  PID=3456 (artisan) mem=312.1M  triggers=3  last=12:34:55  cooldown=60s
  ... (12 procs, showing top 5 by memory)
```

daemon のステータスは **イベント駆動 + 定期サマリ** のハイブリッド:
- トリガ発火/スキップ時: 即座にイベント行を出力 (`[TRIGGERED]`, `[SKIPPED]`)
- プロセス発見/消失時: 即座に通知 (`[+process]`, `[-process]`)
- 定期サマリ: `--status-interval` ごとに全プロセスの概要を出力
- `--quiet`: イベント行もサマリも抑制 (ログファイルのみに出力)

**ログファイル出力 (`--log-file`):**

`--log-file` が指定されている場合、全イベント (ステータス含む) を構造化ログとして
ファイルに書き出す。ターミナルへの表示とは独立して動作。

```
--status-log-level=<level>   # ステータスサマリのログレベル (debug/info/none)
                              # デフォルト: daemon=info, single=debug
```

| オプション | デフォルト | 説明 |
|---|---|---|
| `--status-interval=<seconds>` | `60` | daemon モードのサマリ出力間隔 |
| `--status-log-level=<level>` | `info` (daemon) / `debug` (single) | ステータスのログレベル |

### その他

| オプション | デフォルト | 説明 |
|---|---|---|
| `--action-output-dir=<path>` | `.` | dump/log のファイル出力先ディレクトリ |
| `--stop-process` / `-S` | `false` | アクション実行時にプロセスを ptrace で停止 |
| `--quiet` | `false` | トリガ発火時のターミナル出力を抑制 |

## Architecture

### Class Diagram

```
src/
├── Command/Inspector/
│   └── WatchCommand.php                          # Symfony Console command
│
├── Inspector/
│   ├── Settings/WatchSettings/
│   │   ├── WatchSettings.php                     # Immutable settings data class
│   │   └── WatchSettingsFromConsoleInput.php      # CLI → Settings conversion
│   │
│   └── Watch/
│       ├── WatchContext.php                       # Per-poll collected data
│       ├── TriggerEvent.php                       # Trigger fire event DTO
│       ├── HeapStats.php                          # Lightweight heap statistics DTO
│       ├── HeapStatsReader.php                    # ZendMmHeap lightweight reader
│       ├── CooldownManager.php                    # Per-trigger cooldown + backoff tracking
│       ├── DiskUsageTracker.php                   # Cumulative dump size limiter
│       ├── WatchLoop.php                          # Single-process watch loop
│       ├── DaemonWatchCoordinator.php             # Multi-process daemon orchestrator
│       │
│       ├── Trigger/
│       │   ├── TriggerInterface.php
│       │   ├── MemoryLimitTrigger.php
│       │   ├── MemoryGrowthRateTrigger.php
│       │   ├── MemoryPeakTrigger.php
│       │   ├── FunctionDetectionTrigger.php
│       │   ├── TraceDepthTrigger.php
│       │   ├── ExceptionDetectionTrigger.php
│       │   ├── GlobalArraySizeTrigger.php
│       │   └── VariableValueTrigger.php
│       │
│       └── Action/
│           ├── ActionInterface.php
│           ├── TraceAction.php
│           ├── MemoryDumpAction.php
│           ├── LogAction.php
│           └── ExecAction.php
```

### Key Interfaces

```php
interface TriggerInterface
{
    /** トリガの名前 (CLI表示・ログ用) */
    public function name(): string;

    /** トリガが Tier 2 (トレース読み取り) を必要とするか */
    public function requiresCallTrace(): bool;

    /** トリガが Tier 3 (EG深読み) を必要とするか */
    public function requiresDeepInspection(): bool;

    /** 評価: 条件を満たせば TriggerEvent を返す、そうでなければ null */
    public function evaluate(WatchContext $context): ?TriggerEvent;
}

interface ActionInterface
{
    /** アクション名 */
    public function name(): string;

    /** トリガ発火時に実行 */
    public function execute(
        TriggerEvent $event,
        ProcessSpecifier $process,
        WatchContext $context,
    ): void;
}
```

### WatchContext

```php
final class WatchContext
{
    public function __construct(
        public readonly int $pid,
        public readonly HeapStats $heap_stats,
        public readonly ?CallTrace $call_trace,       // Tier 2 トリガ有効時のみ
        public readonly ?bool $has_exception,          // Tier 3: on-exception 有効時のみ
        public readonly float $timestamp,
        public readonly ?WatchContext $previous,       // 前回コンテキスト (growth rate 用)
    ) {}
}
```

### HeapStats / HeapStatsReader

```php
final class HeapStats
{
    public function __construct(
        public readonly int $size,           // memory_get_usage(false) 相当
        public readonly int $real_size,      // memory_get_usage(true) 相当
        public readonly int $peak,           // memory_get_peak_usage(false) 相当
        public readonly int $limit,          // memory_limit の値
    ) {}
}
```

`HeapStatsReader` は `MemoryLocationsCollector::collectAll()` (L131-L220) の最初のパス
—— main_chunk 取得 → `ZendMmChunk::heap_slot` → `ZendMmHeap` フィールド読み取り ——
を軽量版として切り出す。`PhpZendMemoryManagerChunkFinder` と `Dereferencer` を利用。

### Adaptive Polling (Tier-based Optimization)

有効なトリガの最大 Tier に応じて、ポーリングごとの読み取り量を最適化する:

```
Tier 1 のみ有効 → HeapStatsReader だけ実行 (< 1ms)
Tier 2 が有効   → HeapStats + CallTraceReader (数ms)
Tier 3 が有効   → HeapStats + CallTrace + EG deep fields (数ms〜数十ms)
```

Tier 1 のみの場合、ターゲットプロセスへのパフォーマンス影響はほぼゼロ。

### Single-Process Mode Flow

```
WatchCommand::execute()
  │
  ├── TargetProcessResolver::resolve()           // PID 取得
  ├── PhpVersionDetector::decidePhpVersion()     // PHP バージョン判定
  ├── PhpGlobalsFinder::findExecutorGlobals()    // EG アドレス取得
  ├── WatchSettings から Trigger[] / Action[] を構築
  │
  └── LoopBuilder で監視ループ構築
       ├── ExitLoopOnSpecificExceptionMiddleware
       ├── RetryOnExceptionMiddleware
       ├── KeyboardCancelMiddleware ('q')
       ├── NanoSleepMiddleware (poll_interval)
       └── CallableMiddleware:
            │
            ├── HeapStatsReader::read()              // 常時
            ├── CallTraceReader::readCallTrace()     // Tier 2+ が有効時
            ├── EG->exception チェック               // Tier 3 が有効時
            │
            ├── WatchContext 構築
            │
            ├── foreach (triggers as trigger):
            │     event = trigger->evaluate(context)
            │     if event && cooldown passed:
            │       foreach (actions as action):
            │         action->execute(event, process, context)
            │
            └── return true  // ループ継続
```

### Daemon Mode

既存の `inspector:daemon` パターンを拡張し、`DaemonWatchCoordinator` が
複数プロセスの監視を並行管理する。

```
WatchCommand::execute() [daemon mode]
  │
  ├── PhpSearcherContextCreator で検索ワーカー起動
  │     └── target-regex にマッチするプロセスを継続的に発見
  │
  ├── DaemonWatchCoordinator
  │     ├── 発見プロセスごとに WatchLoop を Amphp ワーカーに割り当て
  │     ├── プロセス消失時にワーカー解放
  │     └── トリガイベントをメインスレッドに送信
  │
  └── メインスレッド
        ├── ワーカーからのトリガイベント受信
        ├── アクション実行 (ファイル出力の排他制御)
        └── 'q' キーでキャンセル
```

**Amphp ワーカープロトコルの拡張:**

既存の Reader ワーカーは `TraceMessage` / `DetachWorkerMessage` を送信するが、
Watch 用ワーカーは `WatchTriggerMessage` を送信する新しいプロトコルが必要。

```php
final class WatchTriggerMessage
{
    public function __construct(
        public readonly int $pid,
        public readonly TriggerEvent $event,
        public readonly HeapStats $heap_stats,
        public readonly ?CallTrace $call_trace,
    ) {}
}
```

既存の `PhpReaderContextCreator` / `PhpReaderEntryPoint` を参考に、
`PhpWatcherContextCreator` / `PhpWatcherEntryPoint` を新規作成する。
ワーカー内で WatchLoop を実行し、トリガ発火時にメッセージを送信する。

## Reused Existing Classes

| クラス | 用途 |
|--------|------|
| `LoopBuilder` / `TraceLoopProvider` | ミドルウェア付き監視ループ構築 |
| `CallTraceReader` | コールトレース読み取り (Tier 2) |
| `MemoryLocationsCollector` | memory-dump アクション用フルスキャン |
| `MemoryOutputFactory` | memory-dump の出力フォーマット |
| `TraceOutputFactory` | trace アクションの出力 |
| `PhpGlobalsFinder` | EG/SG/CG アドレス解決 |
| `PhpVersionDetector` | PHP バージョン判定 |
| `ProcessSearcher` | daemon モードのプロセス発見 |
| `PhpSearcherContextCreator` | daemon モードの検索ワーカー |
| `WorkerPool` | daemon モードのワーカー管理 (参考) |
| `DispatchTable` | daemon モードのプロセス割り当て (参考) |
| `MemoryReaderInterface` | `process_vm_readv` によるメモリ読み取り |
| `ProcessStopper` | ptrace attach/detach |
| `TargetProcessResolver` | PID / コマンド実行による対象解決 |
| `ZendMmHeap` | ヒープメタデータ型 |
| `ZendMmChunk` | チャンクからの heap_slot アクセス |
| `PhpZendMemoryManagerChunkFinder` | main_chunk アドレス取得 |
| `DaemonSettingsFromConsoleInput` | `--target-regex`, `--threads` の設定 |
| `EchoBackCanceller` | ターミナルエコーバック制御 |

## Required Modifications to Existing Code

### 1. ZendExecutorGlobals に `exception` フィールドを追加

```php
// src/Lib/PhpInternals/Types/Zend/ZendExecutorGlobals.php

/** @var Pointer<ZendObject>|null */
public ?Pointer $exception;

// getFieldLazy() に追加:
'exception' => $this->exception = $this->field_reader->readPointerField(
    $this->pointer,
    'exception',
    ZendObject::class,
),
```

### 2. DI Container への登録

```php
// config/di.php に WatchCommand 関連のバインディングを追加
// ほとんどは autowire で解決可能
```

## Implementation Plan

### Phase 1: Core (単一プロセスモード)

1. `HeapStats` / `HeapStatsReader` — 軽量ヒープ統計リーダー
2. `TriggerInterface` + Tier 1 トリガ (`MemoryLimitTrigger`, `MemoryGrowthRateTrigger`, `MemoryPeakTrigger`)
3. `ActionInterface` + `TraceAction`, `LogAction`
4. `WatchContext`, `TriggerEvent`, `CooldownManager`
5. `WatchLoop` — 単一プロセス監視ループ
6. `WatchSettings` / `WatchSettingsFromConsoleInput`
7. `WatchCommand` — Symfony Console コマンド

### Phase 2: Advanced Triggers

8. `ZendExecutorGlobals` に `exception` フィールド追加
9. Tier 2 トリガ (`FunctionDetectionTrigger`, `TraceDepthTrigger`)
10. Tier 3 トリガ (`ExceptionDetectionTrigger`, `GlobalArraySizeTrigger`)
11. `MemoryDumpAction`, `ExecAction`

### Phase 3: Daemon Mode

12. `WatchTriggerMessage` — ワーカー通信プロトコル
13. `PhpWatcherEntryPoint` / `PhpWatcherContextCreator` — Watch 用ワーカー
14. `DaemonWatchCoordinator` — マルチプロセスオーケストレーター
15. `WatchCommand` に daemon モードパスを追加

## Testing Strategy

### Unit Tests

- 各 Trigger の `evaluate()` ロジック (mock WatchContext で閾値前後をテスト)
- `CooldownManager` のタイミング制御
- `MemoryGrowthRateTrigger` の rate 計算
- `HeapStats` のサイズパース (`256M` → bytes)

### Integration Tests

- `HeapStatsReader` が実プロセスからヒープ統計を読めるか
- `WatchLoop` がトリガ発火 → アクション実行のパイプラインを正しく動かすか

### Manual Tests

```bash
# メモリリークする PHP スクリプト
php -r 'while(true){$a[]=str_repeat("x",1024);usleep(10000);}'

# 監視
reli inspector:watch -p <PID> --memory-limit=10M --action=trace --action=log
```

### CI

- `composer test` — 既存テスト回帰なし
- `composer phpstan` — 静的解析パス

## Container / Orchestrator Deployment

`process_vm_readv` と ptrace は **同一 PID namespace** のプロセスにしかアクセスできない。
コンテナ環境では PID namespace の共有設定が必須となる。

### Kubernetes

**推奨: サイドカーコンテナ**

```yaml
apiVersion: v1
kind: Pod
metadata:
  name: php-app
spec:
  shareProcessNamespace: true    # 必須: PID namespace を共有
  containers:
  - name: app
    image: php-app:latest
  - name: reli-watch
    image: reli-prof:latest
    command:
    - reli
    - inspector:watch
    - --target-regex=php-fpm
    - --memory-limit=512M
    - --action=memory-dump
    - --action=log
    - --log-file=/var/log/reli/watch.log
    - --action-output-dir=/var/log/reli/dumps/
    - --max-dump-size=2G
    - --quiet
    securityContext:
      capabilities:
        add: ["SYS_PTRACE"]      # 必須: process_vm_readv / ptrace
    volumeMounts:
    - name: reli-logs
      mountPath: /var/log/reli
  volumes:
  - name: reli-logs
    emptyDir:
      sizeLimit: 3Gi             # ディスク保護の二重化
```

**ポイント:**
- `shareProcessNamespace: true` で Pod 内の全コンテナが同じ PID namespace
- `SYS_PTRACE` capability のみ追加 (privileged は不要)
- `--quiet` + `--log-file` で stdout ノイズ防止
- emptyDir の `sizeLimit` と `--max-dump-size` でディスク保護を二重化
- dump ファイルは emptyDir に書いて、別途 FluentBit 等で転送 or
  `--action=exec` で S3 アップロード

**k8s Ephemeral Container (一時的な調査用):**

```bash
kubectl debug -it php-app \
  --image=reli-prof:latest \
  --target=app \
  -- reli inspector:watch --target-regex=php --memory-limit=256M --action=trace
```

サイドカーを事前にデプロイせず、問題発生時にオンデマンドでアタッチ可能。
ただし `shareProcessNamespace` が Pod 作成時に有効でないと使えない。

**DaemonSet パターン (ノード全体の監視):**

```yaml
apiVersion: apps/v1
kind: DaemonSet
metadata:
  name: reli-watch
spec:
  template:
    spec:
      hostPID: true               # ホストの PID namespace を使用
      containers:
      - name: reli-watch
        image: reli-prof:latest
        command:
        - reli
        - inspector:watch
        - --target-regex=php-fpm
        - --memory-limit=1G
        - --action=log
        - --action=exec
        - --action-exec-command=<alert script>
        securityContext:
          capabilities:
            add: ["SYS_PTRACE"]
```

ノード上の全 PHP プロセスを一括監視。セキュリティ要件が許す環境向け。

### Amazon ECS

```json
{
  "family": "php-app",
  "pidMode": "task",
  "containerDefinitions": [
    {
      "name": "app",
      "image": "php-app:latest",
      "essential": true
    },
    {
      "name": "reli-watch",
      "image": "reli-prof:latest",
      "essential": false,
      "command": [
        "reli", "inspector:watch",
        "--target-regex=php",
        "--memory-limit=512M",
        "--action=memory-dump",
        "--action=log",
        "--log-file=/var/log/reli/watch.log",
        "--action-output-dir=/var/log/reli/dumps/",
        "--max-dump-size=2G",
        "--quiet"
      ],
      "linuxParameters": {
        "capabilities": {
          "add": ["SYS_PTRACE"]
        }
      }
    }
  ]
}
```

**ポイント:**
- `pidMode: "task"` で PID namespace 共有 (Fargate 1.4.0+, EC2 共に対応)
- `essential: false` でウォッチャーが落ちてもアプリは継続

### Dump ファイルの転送

コンテナ環境ではローカルディスクは揮発的。dump ファイルの永続化パターン:

| パターン | 実装 | 適用場面 |
|----------|------|----------|
| S3 直接アップロード | `--action=exec --action-exec-command='aws s3 cp $RELI_WATCH_DUMP_PATH s3://...'` | AWS 環境 |
| FluentBit サイドカー | dump ディレクトリを tail して転送 | ログ基盤が整っている場合 |
| Persistent Volume | PVC マウント | k8s で EBS/EFS 利用可能な場合 |
| `--action=log` のみ | dump は取らずイベントログだけ記録 | ディスクに余裕がない場合 |

**exec アクション + 環境変数で S3 転送:**

```bash
--action=exec \
--action-exec-command='aws s3 cp "$RELI_WATCH_DUMP_PATH" "s3://my-bucket/reli-dumps/$(hostname)/" && rm "$RELI_WATCH_DUMP_PATH"'
```

`RELI_WATCH_DUMP_PATH` 環境変数は `memory-dump` アクションが出力したファイルパスを
格納する。exec アクションは memory-dump の後に実行されるため、dump → upload → 削除
のパイプラインが構成可能。

### セキュリティ考慮事項

- `SYS_PTRACE` capability は他プロセスのメモリを読める強力な権限
- 本番環境では RBAC / Pod Security Standards で reli-watch サイドカーのデプロイを制限
- `--target-regex` を絞って意図しないプロセスへのアタッチを防止
- dump ファイルにはメモリ内容が含まれるため、転送・保存時の暗号化を推奨
- `--action=exec` のコマンドは Pod spec / Task Definition でハードコードし、
  環境変数経由での動的コマンド組み立ては避ける

## Future Considerations

- **Auto-analysis report** 連携: `--action=report` で feature branch の自動分析レポート出力
- **Prometheus / StatsD 連携**: メトリクス export アクション
- **Conditional action**: トリガごとに異なるアクションを設定 (`--on memory-limit do memory-dump`)
- **Watch profile**: YAML/JSON でトリガ・アクションの設定をファイルから読み込み
- **Web UI**: WebSocket でリアルタイム監視ダッシュボード
- **OCI image**: reli-prof のサイドカー用 Docker イメージ公式提供
