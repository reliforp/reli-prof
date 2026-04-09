# reli Binary Trace Format (`.rbt`) Specification

**Version:** 1  
**Status:** Draft

## 概要

reli Binary Trace Format は、PHP プロファイリングトレースを効率的に保存するための append-only バイナリストリーム形式です。

### 設計目標

- phpspy テキスト形式と比べて **大幅に小さい容量**（sample あたり 2〜5 byte）
- **append-only** で追記可能
- プロセス途中停止時、末尾の不完全イベントを捨てれば残りを **復旧可能**
- pprof / speedscope / folded stacks / flamegraph への **変換が容易**

### 基本方針

- **frame** (関数名+ファイル名+行番号) は一度だけ定義し `frame_id` を振る
- **stack** (frame_id の配列) は一度だけ定義し `stack_id` を振る
- **sample** は `stack_id` 参照のみ（＋オプション timestamp delta）
- 同じ文字列を繰り返さないことで圧縮効果を得る

---

## ファイル構造

```
[Header: 16 bytes] [Event₁] [Event₂] ... [Eventₙ]
```

### Header (固定 16 bytes)

| Offset | Size | Field | Description |
|--------|------|-------|-------------|
| 0 | 4 | Magic | `"RELI"` (0x52 0x45 0x4C 0x49) |
| 4 | 1 | Version | `1` |
| 5 | 1 | Flags | bit 0: has_timestamps |
| 6 | 2 | Reserved | 0x0000 |
| 8 | 4 | Sampling Period | サンプリング周期 (µs), little-endian uint32 |
| 12 | 4 | Reserved | 0x00000000 |

### Flags

| Bit | Name | Description |
|-----|------|-------------|
| 0 | `has_timestamps` | 1 の場合 SAMPLE に timestamp delta を含む |
| 1-7 | Reserved | 0 |

---

## Varint エンコーディング

ID、length、depth、timestamp delta には **protobuf 互換 base-128 varint** を使用します。

- 各バイトの下位 7 bit がデータ
- MSB (bit 7) が 1 なら後続バイトあり、0 なら最終バイト
- リトルエンディアン（下位バイトから）
- 符号なし整数のみ

| 値の範囲 | バイト数 |
|----------|---------|
| 0 – 127 | 1 |
| 128 – 16,383 | 2 |
| 16,384 – 2,097,151 | 3 |

---

## イベント構造

全イベントは **length-delimited** です：

```
[event_type: 1 byte] [payload_length: varint] [payload: payload_length bytes]
```

未知の `event_type` を受信した場合は `payload_length` 分スキップして次のイベントへ進みます。

### Event Types

| Type | Value | Description |
|------|-------|-------------|
| `FRAME_DEF` | 0x01 | フレーム定義 |
| `STACK_DEF` | 0x02 | スタック定義 |
| `SAMPLE` | 0x03 | サンプルイベント |
| `CHECKPOINT` | 0x04 | チェックポイント |
| `SEGMENT_END` | 0x05 | セグメント終端 |

---

## イベント詳細

### FRAME_DEF (0x01)

新しいフレーム（関数名＋ファイル名＋行番号の組）を定義します。

```
Payload:
  [frame_id: varint]
  [function_name_length: varint] [function_name: UTF-8 bytes]
  [file_name_length: varint]     [file_name: UTF-8 bytes]
  [lineno: varint]
```

- `frame_id` は 0 から連番で振られる
- `function_name` は `Class::method` 形式（クラスメソッドの場合）
- 同じフレームが二度定義されることはない

### STACK_DEF (0x02)

フレーム ID の配列としてスタックを定義します。

```
Payload:
  [stack_id: varint]
  [depth: varint]
  [frame_id₀: varint] [frame_id₁: varint] ... [frame_id_{depth-1}: varint]
```

- `stack_id` は 0 から連番
- `frame_id₀` が最内側（リーフ関数）、`frame_id_{depth-1}` が最外側（エントリポイント）
- 参照される frame_id は事前に FRAME_DEF で定義済みでなければならない

### SAMPLE (0x03)

1 つのサンプリングイベントを記録します。

```
Payload:
  [stack_id: varint]
  if flags.has_timestamps:
    [timestamp_delta_us: varint]
```

- `stack_id` は事前に STACK_DEF で定義済みでなければならない
- `timestamp_delta_us` は前回サンプルからの経過時間（µs）

**容量**: stack_id が 127 以下で timestamp なしの場合、1 イベントあたり **3 bytes**。

### CHECKPOINT (0x04)

定期的にストリームの状態を記録し、復旧時の整合性チェックに使います。

```
Payload:
  [max_frame_id: varint]
  [max_stack_id: varint]
  [sample_count: varint]
```

### SEGMENT_END (0x05)

セグメントの正常終了を示します。

```
Payload: (empty, length = 0)
```

---

## 容量の見積もり

100 samples/sec の typical なワークロードを想定：

| Component | Size | Notes |
|-----------|------|-------|
| Header | 16 bytes | 一度だけ |
| FRAME_DEF | ~40-80 bytes each | 関数名・ファイルパスに依存 |
| STACK_DEF | ~5-20 bytes each | スタック深度に依存 |
| SAMPLE (繰り返し) | **3 bytes** | stack_id < 128, timestamps なし |
| CHECKPOINT | ~5-10 bytes | 1000 サンプルごと |

典型例（100 ユニークフレーム、50 ユニークスタック）：

```
初期定義: ~100 × 60 + 50 × 15 = ~6,750 bytes
1 時間分のサンプル: 100 × 3600 × 3 = ~1,080,000 bytes ≈ 1.03 MB
合計: ~1.04 MB/hour
```

phpspy テキスト形式の同等データ: ~50-100 MB/hour → **約 50-100 倍の圧縮**

---

## 破損時の復旧手順

1. ヘッダ（16 bytes）を読む
2. イベントを先頭から順にパースする
3. `event_type` が不正、または `payload_length` 分のデータが読めない場合：
   - 直前の完全イベントまでの定義状態を保持する
   - そこから 1 バイトずつスキャンし、有効な `event_type` (0x01-0x05) を探す
   - 候補が見つかったら、続く `payload_length` varint と payload が妥当か検証
   - 有効なイベント列が再開した位置から読み取りを再開
4. CHECKPOINT イベントで `max_frame_id`, `max_stack_id`, `sample_count` を検証し、状態の整合性を確認

---

## 将来の拡張方針

以下は v1 では未実装だが、後方互換を保ちつつ拡張可能です：

- **新規 event_type の追加**: 未知のイベントは payload_length でスキップされるため安全
- **派生 STACK_DEF**: 既存の stack_id をベースに 1-2 フレームだけ変更した差分定義
- **THREAD_SAMPLE**: thread_id を含むサンプルイベント
- **METADATA**: 任意の key-value メタデータ
- **圧縮**: ストリーム全体またはセグメント単位の zstd/gzip 圧縮
- **Flags の拡張**: 予約ビットを利用

---

## 変換パイプライン

```
                                    ┌─→ speedscope JSON
                                    │
phpspy text ─→ binary-trace-encode ─┤─→ pprof protobuf (gzip)
                                    │
CallTrace ──→ BinaryTraceOutput    ─┤─→ folded stacks ─→ flamegraph.pl
                                    │
                                    └─→ phpspy text (decode)
```

### CLI コマンド

```bash
# phpspy テキスト → バイナリトレース
reli converter:binary-trace-encode < trace.txt > trace.rbt

# バイナリトレース → phpspy テキスト
reli converter:binary-trace-decode < trace.rbt

# バイナリトレース → speedscope JSON
reli converter:binary-trace-to-speedscope < trace.rbt > profile.json

# バイナリトレース → folded stacks (flamegraph 入力用)
reli converter:binary-trace-to-folded < trace.rbt | flamegraph.pl > graph.svg

# バイナリトレース → pprof protobuf
reli converter:binary-trace-to-pprof < trace.rbt > profile.pb.gz
```

### ライブキャプチャでの使用

```php
use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Reli\Inspector\Output\TraceOutput\BinaryTraceOutput;

$stream = fopen('trace.rbt', 'wb');
$writer = new BinaryTraceWriter($stream, sampling_period_us: 10000);
$output = new BinaryTraceOutput($writer, checkpoint_interval: 1000);

// TraceOutput インターフェースを実装しているため、
// 既存のプロファイリングループでそのまま使用可能
$output->output($call_trace);
```

---

## 実装ファイル一覧

| File | Description |
|------|-------------|
| `src/Converter/BinaryTrace/Varint.php` | Varint エンコード/デコード |
| `src/Converter/BinaryTrace/EventType.php` | イベント種別 enum |
| `src/Converter/BinaryTrace/BinaryTraceWriter.php` | エンコーダ（frame/stack 重複排除込み） |
| `src/Converter/BinaryTrace/BinaryTraceReader.php` | デコーダ（ParsedCallTrace を yield） |
| `src/Converter/BinaryTrace/BinaryTraceException.php` | 例外クラス |
| `src/Converter/BinaryTrace/FoldedStacksFormatter.php` | Folded stacks 形式変換 |
| `src/Converter/BinaryTrace/PprofEncoder.php` | pprof protobuf エンコーダ |
| `src/Inspector/Output/TraceOutput/BinaryTraceOutput.php` | ライブキャプチャ用 TraceOutput |
| `src/Command/Converter/BinaryTraceEncodeCommand.php` | phpspy → binary CLI |
| `src/Command/Converter/BinaryTraceDecodeCommand.php` | binary → phpspy CLI |
| `src/Command/Converter/BinaryTraceSpeedscopeCommand.php` | binary → speedscope CLI |
| `src/Command/Converter/BinaryTraceFoldedCommand.php` | binary → folded stacks CLI |
| `src/Command/Converter/BinaryTracePprofCommand.php` | binary → pprof CLI |
