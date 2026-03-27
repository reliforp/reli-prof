# Trace Consistency and Sampling Bias Investigation

`--stop-process` (`-S`) オプションの有無がトレースの整合性とサンプリング分布に与える影響の調査。
reli on reli (自己プロファイリング) および phpspy との比較で得られた知見をまとめる。

## 背景

reli のサンプリングプロファイラには 2 つのモードがある:

- **`-S` なし**: `process_vm_readv` でターゲットのメモリを直接読む (ターゲットは動き続ける)
- **`-S` あり**: `PTRACE_ATTACH` (`SIGSTOP`) でターゲットを一時停止してからメモリを読む

## 発見 1: `-S` フラグの VALUE_OPTIONAL バグ (修正済み)

`-S` を値なしで渡すと Symfony Console の `VALUE_OPTIONAL` が `null` を返し、
コード上 `null` → `false` に変換されて **`-S` が無視されていた**。

```
-S       → false (バグ: 効かない)
-S=1     → true
--stop-process=1 → true
未指定    → false
```

`hasParameterOption()` でフラグの存在を検出する形に修正。
修正後、`-S` で truncated フレーム (internal 関数が 1 フレームだけ出る現象) が 0 になることを確認。

## 発見 2: PTRACE_ATTACH の SIGSTOP サンプリングバイアス

### 現象

reli on reli で inner reli を `-S` ありでプロファイルすると、
`/proc/pid/stat` から計測した実際の user:sys 比率とサンプル分布が大きく乖離する。

```
実測 (bash time / /proc/pid/stat):
  user: 81%,  sys: 19%

サンプル分布 (-S あり, PHP フレーム):
  process_vm_readv (FFI/syscall): 68%
  PHP userland:                   30%

サンプル分布 (-S あり, ネイティブフレーム):
  libc::process_vm_readv:          92%
  PHP userland:                     6%
```

### 原因

`PTRACE_ATTACH` は `SIGSTOP` をターゲットに送るが、カーネルは `SIGSTOP` を
**syscall 境界またはスケジューラの切り替え点** で配送する。
ターゲットが user mode で PHP バイトコードを実行中は `SIGSTOP` の配送が遅延し、
次の syscall (FFI 経由の `process_vm_readv` 等) に入った時点で停止する。

結果として **syscall 内で停止するサンプルが系統的に過大に出る**。

### phpspy での検証

phpspy の `-S` (pause-process) オプションでも同じ傾向を確認:

```
phpspy -S なし:  process_vm_readv = 12%,  PHP userland = 88%
phpspy -S あり:  process_vm_readv = 92%,  PHP userland =  8%
```

`-S` を入れた瞬間に reli on reli と同じ傾向になる。
`PTRACE_ATTACH` + `SIGSTOP` というメカニズム自体の制約。

## 発見 3: `-S` なしの reli on reli では逆方向のバイアス

### 現象

reli on reli で `-S` なしにすると、`process_vm_readv` が frame 0 に **一切出ない**。

```
reli -S なし:
  process_vm_readv:   0%
  PHP userland:      61%
  壊れ (<unknown>/<internal>/truncated): 23%
```

### 原因

inner reli が FFI 内 (C コード実行中) にいるとき、`current_execute_data` は
FFI フレームを指している。しかし outer reli が PHP で遅い読み取りをしている間に
inner は FFI から戻って `current_execute_data` が変わってしまう。
結果として FFI フレームを整合的に読めず `<internal>` や `<unknown>` として壊れるか、
FFI から戻った後の PHP フレームを読む。

**読み取り速度が遅いと、速い処理 (syscall) のサンプルが系統的に欠落する。**

## 発見 4: phpspy -S なしだけが両立

```
                    サンプル分布の正確さ    スタック整合性    壊れ率
phpspy -S なし              正確               高           ~0%
phpspy -S あり          syscall に偏る          高           ~0%
reli -S あり            syscall に偏る          高           ~2%
reli -S なし           userland に偏る          低          ~23%
```

phpspy -S なしが唯一「分布が正確 + 整合性も OK」を両立できている理由は、
C で高速にスタックを読み切れるため、ターゲットのスタックが変わる前にスナップショットが取れるから。

## スループットとコスト比較

### スタック深さとスループット (sleep=0, -S なし)

```
                    reli                    phpspy
              readv/loop  loops/s     readv/loop  loops/s     倍率
Shallow(3)          9       735            ~3    10,240       x14
Deep(22)           37       225            ~4     6,947       x31
Laravel(75)       174        53            ~5     1,892       x36
```

readv の総呼び出し回数はほぼ同じ (~27,000-29,000/3s)。
スループット差の本質は readv 間の PHP オーバーヘッド (FFI 呼び出し、Pointer 生成、型解決等)。

### 1 トレースあたりの CPU 時間 (Laravel 75 frames)

```
              user/trace    sys/trace    total/trace    user:sys
phpspy         0.19ms        0.30ms        0.50ms       0.65:1 (sys 支配)
reli           0.81ms        0.15ms        0.96ms       5.3:1  (user 支配)
```

CPU/trace の差は約 2 倍。スループット差 (36x) に比べて小さい。

### readv のサイズ別コスト

```
      8 bytes: 0.7 μs/call
     64 bytes: 0.7 μs/call
   4096 bytes: 0.8 μs/call
  65536 bytes: 0.7 μs/call
 262144 bytes: 0.8 μs/call
```

`process_vm_readv` はサイズにほぼ依存しない。

## 改善案: VM スタック一括コピー

### 現状の整合性ウィンドウ

```
reli (current):  最初の readv → 最後の readv = ~3,480 μs
phpspy:          最初の readv → 最後の readv = ~500 μs
1回の bulk readv: ~0.7 μs
```

### 2-pass scatter-gather 方式

1. **Pass 1**: VM スタックを 1 回の `process_vm_readv` で一括コピー (~64KB, ~0.7μs)
   - ローカルで `execute_data` チェーンをパース
   - `func`, `opline` 等のヒープ上ポインタを収集
2. **Pass 2**: 収集したポインタを scatter-gather iovec で一括読み取り (~0.7μs)
   - `IOV_MAX` = 1024、75 フレームで ~300-450 iovec なので収まる

合計 2 syscall, 整合性ウィンドウ ~1.4μs + ローカル処理時間。

### 実装の見通し

`MemoryReaderInterface` がきれいに抽象化されているため、
**バッファ付きデコレータを 1 クラス追加** するだけで実現可能な可能性がある。

```php
class BufferedMemoryReader implements MemoryReaderInterface {
    public function prefetch(int $pid, int $address, int $size): void {
        // VM スタックを一括コピー
    }

    public function read(int $pid, int $address, int $size): CData {
        if (/* address がバッファ範囲内 */) {
            return /* ローカルバッファから返す */;
        }
        return $this->inner->read($pid, $address, $size);
    }
}
```

既存の `ZendExecuteData`, `FieldReader`, `Pointer`, `LazyDereferencer` 等は変更不要。
アドレスベースで透過的にバッファから返すため、上位コードはリモートメモリかローカルバッファかを意識しない。

### 課題

- VM スタックの位置 (`EG(vm_stack)`) とサイズの取得が必要
- VM スタック上の `execute_data` はカバーできるが、ヒープ上の `zend_function`,
  `zend_string` (関数名/ファイル名/クラス名) は個別読み取りが残る
  - ただし Pass 2 の scatter-gather でこれらも一括化できる見込み
- `zend_function`, `zend_string` はリクエスト中に GC されないため、
  Pass 1 → Pass 2 間 (~μs + ローカル処理) の不整合リスクは実質的に無視可能

### 期待される効果

- **整合性**: 3,480μs → ~1.4μs のウィンドウ縮小で `-S` なしでも壊れサンプルが激減する見込み
- **スループット**: syscall 回数が 174 → 2 に減少。ただしボトルネックは PHP 側の処理なので
  劇的な改善にはならない可能性。PHP 側の Pointer/FieldReader 処理の軽量化が別途必要
- **サンプリングバイアス**: `-S` 不要になれば SIGSTOP バイアスを回避でき、
  ネイティブトレースの分布も正確になる
