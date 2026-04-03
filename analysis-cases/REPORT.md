# reli-prof PHP Memory Analysis Report

**Date:** 2026-04-03
**Tool:** reli-prof (reliforp/reli-prof)
**Environment:** PHP 8.4.19, Linux x86_64, NTS

## Summary

GitHub 上の実際の PHP メモリ問題 5 件を収集し、各問題の再現スクリプトを作成して
reli-prof の `inspector:memory -f report` で解析を行った。
ツールの有用性と発見された課題を以下に報告する。

---

## Case 1: FrankenPHP Worker Mode Memory Leak

**元Issue:** [php/frankenphp#1797](https://github.com/php/frankenphp/issues/1797)

**問題概要:** FrankenPHP の worker モードで Symfony の `dump()`/`dd()` を使うと
リクエスト間でメモリが蓄積し、"Allowed memory size of 268435456 bytes exhausted" が発生。

**再現:** `VarCloner` がリクエストごとにオブジェクトをディープクローンし、
`ProfilerStorage` に蓄積。`RequestContext` がリンクリストを形成。

### reli-prof 解析結果

```
memory_get_usage(): 553.88 MB | memory_get_usage(true): 555.86 MB

[HIGH] 380.63 MB — dominant_type: ZendString (99.7% of heap, 9,349 items)
[HIGH] 363.01 MB — bottleneck_path: VarCloner::cloneVar:23::$result[0]
[HIGH] 172.85 MB — choke_point: VarCloner::cloneVar:23::$result[1]
[HIGH] 1.20 KB  — dominant_class: RequestContext: 14 instances (91.7% of objects)
[LOW]  120.76 MB — dedup_candidate: 700 copies x 176.65 KB (100% identical)

Root Blame: call_frames 97.7%, global_variables 1.3%
```

### 評価

| 項目 | 評価 |
|------|------|
| 問題特定 | **優秀** - `VarCloner::cloneVar:23::$result` がボトルネックと即座に特定 |
| 原因推定 | **優秀** - 700個の同一コピー(dedup_candidate)を検出、クローン肥大化を示唆 |
| 対策示唆 | **良好** - "unbounded accumulation" と指摘し、ストリーミング化を推奨 |
| コールスタック | **優秀** - `str_repeat → VarCloner::cloneVar → <main>` を正確にキャプチャ |

---

## Case 2: PHPStan Error Duplication OOM

**元Issue:** [phpstan/phpstan#13813](https://github.com/phpstan/phpstan/issues/13813)

**問題概要:** PHPStan で特定の制御フロー下で未定義変数エラーが指数的に複製され、
メモリ枯渇。同一エラーが数十万回記録される。

**再現:** `TypeAnalyzer` が再帰的に分岐を解析し、各分岐で同じ `AnalysisError` を生成。
`ErrorCollector` に重複排除なしで蓄積。

### reli-prof 解析結果

```
memory_get_usage(): 38.43 MB | memory_get_usage(true): 40.00 MB

[HIGH] 1.95 MB — dominant_class: AnalysisError: 19,680 instances x 104 B (100% of objects)
```

### 評価

| 項目 | 評価 |
|------|------|
| 問題特定 | **良好** - AnalysisError 19,680インスタンスの蓄積を検出 |
| 原因推定 | **良好** - "Unbounded accumulation — likely a loop without limit" と正確に指摘 |
| 対策示唆 | **良好** - スケーリングチェック・蓄積コンテナの特定を推奨 |
| 詳細度 | **不足** - レポートが短い。配列や文字列の詳細が少ない |

**課題:** 38MB のメモリに対して Findings が 1 件のみ。Heap 解析率 0.8% で
大部分のメモリが解析対象外になっている（後述の課題参照）。

---

## Case 3: Zend MM Chunk Fragmentation

**元Issue:** [php/php-src#13599](https://github.com/php/php-src/issues/13599)

**問題概要:** ~1MB 文字列を大量に確保すると、ZendMM のチャンク(2MB)に
効率的に収まらず、`memory_get_usage()` が実メモリの50%しか報告しない。

**再現:** 1MB 文字列 30 個を確保し、各チャンクに小オブジェクトを混在させて
チャンク解放を阻止。

### reli-prof 解析結果

```
memory_get_usage(): 15.56 MB | memory_get_usage(true): 32.00 MB
(スクリプト自体の報告: Waste ratio 51.4%)

[HIGH] 15.35 MB — dominant_type: ZendString (91.2% of heap)
[HIGH] 15.05 MB — bottleneck_path: global_variables[strings][15]
[HIGH] x10     — choke_point: 各 strings[N] が 1 MB ずつ保持
[HIGH] 58.59 KB — dominant_class: stdClass: 1,500 instances (チャンクピン)
[MEDIUM] 15 MB — large_array: global_variables[strings] (15 elements)
[LOW] 58.55 KB — empty_object: stdClass 1,500 instances (no properties)
```

### 評価

| 項目 | 評価 |
|------|------|
| 問題特定 | **優秀** - 1MB 文字列 15 個が個別に特定され、各 choke_point が表示 |
| 断片化検出 | **不足** - reported vs real の差異(断片化)を直接検出する機能がない |
| ピン検出 | **良好** - stdClass 1,500 個の empty_object を検出、チャンクピンを示唆 |
| 対策示唆 | **部分的** - SplFixedArray を推奨するが、gc_mem_caches() の示唆なし |

**課題:** `memory_get_usage()` と `memory_get_usage(true)` の差異から断片化を
検出・報告する機能があると非常に有用。現状ではユーザーが自分で気付く必要がある。

---

## Case 4: Unbounded Allocation (number_format pattern)

**元Issue:** [php/php-src#17384](https://github.com/php/php-src/issues/17384)

**問題概要:** `number_format()` に巨大な `$decimals` パラメータを渡すと
検証なしにメモリを確保し尽くす。

**再現:** ユーザー入力由来の大きなフォーマットパラメータで
大量の文字列を配列に蓄積するパターン。

### reli-prof 解析結果

```
memory_get_usage(): 15.71 MB | memory_get_usage(true): 18.00 MB

[HIGH] 14.67 MB — dominant_type: ZendString (92.5% of heap)
[HIGH] 56 B     — dominant_class: ReportGenerator: 1 instance
[LOW]  48.85 KB — large_string: formattedData[0][amount]: "000000..."
[LOW]  48.85 KB — large_string: formattedData[0][rate]: "000000..."
...（同様の formattedData エントリが多数）
```

### 評価

| 項目 | 評価 |
|------|------|
| 問題特定 | **良好** - ZendString 支配を検出、formattedData 内の大文字列を列挙 |
| パターン検出 | **良好** - 配列内の繰り返しパターンを個別に表示 |
| 対策示唆 | **不足** - 入力バリデーション不足の指摘がない（ツールの範疇外か） |
| 実用性 | **良好** - 実際のメモリ消費箇所を正確に特定できる |

---

## Case 5: Eloquent-like ORM Hydration

**元Issue:** [firefly-iii/firefly-iii#9864](https://github.com/firefly-iii/firefly-iii/issues/9864)

**問題概要:** Firefly III で 4,000 件のトランザクションをリレーション付きで
ロードするとメモリ枯渇。v6.2.7 でのリグレッション。

**再現:** 4,000 件の `Transaction` オブジェクトに `Account`, `Category`, `Tag`
リレーションを eager-load。各 Transaction に Carbon 日付オブジェクト3個付き。

### reli-prof 解析結果

**注意:** `-f report` 直接出力ではレポートがほぼ空だったが、
`-f sqlite3 -o snapshot.db` → `inspector:memory:report snapshot.db` の
2段階実行で詳細なレポートが得られた。

```
memory_get_usage(): 45.81 MB | memory_get_usage(true): 48.00 MB
Heap: 320.00 KB (0.7% analyzed)

[HIGH] 85.57 MB — bottleneck_path: objects_store->10520->relations[tags][0]
[HIGH] 85.57 MB — choke_point: ObjectsStoreMemoryLocation (512 KB) holds 85.57 MB via 41,978 children
[HIGH] 30.91 MB — choke_point: global_variables[transactions] (62.51 KB) holds 30.91 MB via 4,000 children

[MEDIUM] structural_duplicate: Tag: 13,978 identical shapes x 120 B = 1.60 MB
[MEDIUM] structural_duplicate: Account: 8,000 identical shapes x 120 B = 937 KB
[MEDIUM] structural_duplicate: Carbon: 8,000 identical shapes x 104 B = 812 KB
[MEDIUM] structural_duplicate: Category: 4,000 identical shapes x 120 B = 468 KB
[MEDIUM] structural_duplicate: Transaction: 3,999 identical shapes x 120 B = 468 KB

[LOW] 24.14 MB — dedup_candidate: "translation_string" 602,668 copies x 42 B (100% identical)
[LOW] 4.87 MB  — dedup_candidate: Transaction::$dateFormat 145,911 copies x 35 B (100% identical)

Root Blame: objects_store 98.2%
shared_fanin: dateFormat -> 2 targets (72,956 refs each)
shared_fanin: relations -> 3 targets (43,301 refs each)
```

### 評価

| 項目 | 評価 |
|------|------|
| 問題特定 | **優秀** - objects_store が 98.2% を占有と即座に特定 |
| クラス分析 | **優秀** - Tag/Account/Carbon/Category の structural_duplicate を検出 |
| 対策示唆 | **優秀** - flyweight/sharing パターンを提案、dateFormat の共有不足を指摘 |
| dedup検出 | **優秀** - "translation_string" 60万コピーで24MB、dateFormat 14.5万コピーで4.8MB |
| shared_fanin | **優秀** - dateFormat が2ターゲットに72,956参照という異常な集中を検出 |

**課題:** `-f report` 直接出力と sqlite 経由で結果が大きく異なる（後述 Issue 6）。
sqlite 経由なら Firefly III の ORM 肥大化を正確に診断できる。

---

## Tool Issues & Limitations Found

### Issue 1: プロセス終了時のエラーメッセージが不親切

**現象:** 対象プロセスが解析前に終了すると、以下のようなスタックトレースが出力される：
```
PHP Fatal error: Uncaught TypeError: ...getDeviceId(): Return value must be of type string, null returned
in ProcessModuleMemoryMap.php:82
```

**期待:** "Target process (PID: XXXX) has exited" のような明確なエラーメッセージ。

**該当箇所:** `src/Lib/Process/MemoryMap/ProcessModuleMemoryMap.php:82`

### Issue 2: Heap 解析率が極端に低い (CRITICAL)

**現象:** `memory_get_usage()` が数十〜数百MB を報告しているにもかかわらず、
"Only X% of heap analyzed" が 0.0%〜2.0% と表示される。

```
Case 1: memory_get_usage() = 553.88 MB → Heap: 320 KB (0.1%)
Case 2: memory_get_usage() = 38.43 MB  → Heap: 320 KB (0.8%)
Case 3: memory_get_usage() = 15.56 MB  → Heap: 320 KB (2.0%)
Case 5: memory_get_usage() = 45.82 MB  → Heap: 320 KB (0.7%)
```

**分析:**
- `memory_get_usage()` で報告されるメモリは ZendMM 管理下なので、解析可能なはず
- `zend_mm_heap_usage` の値が実質 VM stack(256KB) + Compiler arena(64KB) = 320KB で
  ヒープ本体の解析結果が 0 になっている
- `RegionAnalyzer` がメモリロケーションをチャンク/huge 領域にマッチさせられていない可能性
- ただし Findings は大量の ZendString 等を検出しているため、
  **ロケーション収集は機能しているが、リージョン分類が失敗している**

**該当箇所:**
- `src/Lib/PhpProcessReader/PhpMemoryReader/RegionAnalyzer/RegionAnalyzer.php:87-126`
- `src/Command/Inspector/MemoryCommand.php:151-154`

**ユーザーの指摘:** 表示上 "Heap: 320 KB" と表示されるが、これが
「解析済み部分」なのか「未解析部分」なのか直感的にわかりにくい。

### Issue 3: `-f report` 直接出力と sqlite 経由で結果が大きく異なる

**現象:** Case 5 (48MB, 4万オブジェクト) で:
- `inspector:memory -f report` → Findings がほぼ空
- `inspector:memory -f sqlite3 -o x.db` → `inspector:memory:report x.db` → 詳細なレポート

**仮説:** `-f report` のストリーミングモードでは `MemoryLocations::createLightweight()`
が使用され、アドレスのみが保存されてサイズ情報が失われる。
sqlite 経由ではフルデータが保存されるため、解析が正常に機能する。

**該当箇所:** `src/Lib/PhpProcessReader/PhpMemoryReader/MemoryLocation/MemoryLocations.php:32-44`

**影響:** ユーザーが `-f report` を使うと大規模ケースで不完全な結果を得る。
ドキュメントで sqlite 経由の推奨を明記するか、ストリーミングモードの改善が必要。

### Issue 4: 断片化(fragmentation)の直接検出機能がない

**現象:** Case 3 のように `memory_get_usage()` と `memory_get_usage(true)` に
大きな差異がある場合、ZendMM チャンクの断片化が原因であることが多い。
現状ではこの差異を自動検出・報告する機能がない。

**提案:** Overview に断片化指標を追加：
```
Fragmentation: 51.4% (reported: 15.56 MB, real: 32.00 MB)
  → Consider gc_mem_caches() after freeing large allocations
```

### Issue 5: Heap 解析率の表示が誤解を招く

**現象:** "Heap: 320 KB (0.7% analyzed)" と表示されるが:
- 320 KB が「解析済み」なのか「ヒープ合計」なのか不明瞭
- 実態は VM stack(256KB) + Compiler arena(64KB) = 320KB で、ヒープ本体は 0
- Findings では数十〜数百MB のデータを検出しているのに矛盾して見える

**提案:** 表示を改善して内訳を明示：
```
Heap analysis: 85.57 MB found / 45.81 MB reported (memory_get_usage)
  Chunks: X MB, Huge: Y MB, VM stack: 256 KB, Compiler arena: 64 KB
  Coverage: XX% of reported heap accounted for
```

### Issue 6: dedup_candidate の改善余地

**良い点:** Case 1 で 700 コピーの同一文字列を検出し、120.76 MB の節約可能性を提示。

**改善案:** 重複の原因となっているコードパス（どの関数がコピーを生成しているか）を
特定できると、さらに有用。

---

## Overall Assessment

### Strengths (強み)

1. **プロセス外からの解析** - 対象プロセスを変更せずにメモリ状態を取得できる
2. **ボトルネック特定** - `bottleneck_path` で最大のメモリ消費パスを即座に特定
3. **choke_point 検出** - 小オブジェクトが大きなメモリツリーを保持するパターンを検出
4. **dedup_candidate** - 重複データの検出と節約可能量の提示
5. **dominant_class/type** - クラス・型別のメモリ使用量ランキング
6. **Root Blame Allocation** - メモリの所有者を根本から追跡
7. **コールスタック付きスナップショット** - キャプチャ時のコールスタックが有用

### Weaknesses (弱み)

1. **解析カバー率の問題** - 全ケースで Heap 解析率が極端に低い（最重要課題）
2. **大量オブジェクト時の解析不全** - Case 5 でレポートが空
3. **エラーハンドリング** - プロセス終了時のエラーが不親切
4. **断片化検出なし** - ZendMM チャンク断片化を直接指摘しない
5. **Heap 解析率の表示が誤解を招く** - 何が解析済みで何が未解析か不明瞭

### Usefulness Score by Case

| Case | Issue Type | Usefulness | Note |
|------|-----------|-----------|------|
| 1 | Worker leak | 9/10 | ボトルネック・重複を正確に特定 |
| 2 | Error duplication | 6/10 | クラス蓄積は検出、詳細不足 |
| 3 | Chunk fragmentation | 5/10 | 文字列は検出、断片化自体は未検出 |
| 4 | Unbounded alloc | 7/10 | 消費箇所を特定、パターン可視化 |
| 5 | ORM hydration | 8/10 (sqlite経由) / 2/10 (直接) | sqlite経由なら詳細に特定 |

**総合:** reli-prof は特に Worker モードのメモリリーク（蓄積型）の解析に非常に強い。
一方、大量オブジェクトの ORM ハイドレーションや ZendMM 内部の断片化問題には改善が必要。
Heap 解析率の問題が解決されれば、全ケースで大幅に有用性が向上すると思われる。
