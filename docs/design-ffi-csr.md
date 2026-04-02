# 改善案: FFI CSR (Compressed Sparse Row) for Graph Substrate

## 背景

`inspector:memory:report` の Phase 3 (graph-based passes) は、SQLite から
全 edge を PHP 配列に読み込んで DFS/SCC/blame allocation を実行する。

PHP 配列は 1 エントリあたり ~100-370 bytes のオーバーヘッドがあり、
大きいターゲットで graph substrate のメモリが爆発する。

### 計測データ (このセッションで実測済み)

| データセット | edges | PHP 配列メモリ | FFI CSR 推定 |
|---|---|---|---|
| Eloquent | 1M | ~300 MB | ~8 MB |
| CommonMark | 2.5M | ~1.2 GB | ~20 MB |
| Monolog | 4.5M | ~2.1 GB | ~36 MB |
| PHP-Parser | 6.1M | ~2.2 GB | ~48 MB |
| reli-on-reli (Monolog 解析中) | ~50M (推定) | 15+ GB (OOM) | ~400 MB |

### reli-on-reli で問題が実証済み

reli が Monolog を解析中の自分自身を dump (5.7 GB)。
この dump を `inspector:memory:analyze` で解析しようとすると 15 GB+ で OOM。
FFI CSR なら 400 MB 程度で解析可能になり、reli のドッグフーディングが実現する。

## CSR (Compressed Sparse Row) 形式

### データ構造

グラフの隣接リスト表現をフラットな配列で持つ:

```
offsets[node_count + 1]:  各ノードの子リストの開始位置 (int32)
edges[edge_count]:        子ノード ID のフラット配列 (int32)
```

例: ノード 0 が子 [3, 5, 7]、ノード 1 が子 [2]、ノード 2 が子なし の場合:
```
offsets = [0, 3, 4, 4]  // ノード 0 は edges[0..3), ノード 1 は edges[3..4), ノード 2 は edges[4..4)
edges   = [3, 5, 7, 2]  // ノード 0 の子が 3,5,7、ノード 1 の子が 2
```

ノード N の子リスト = `edges[offsets[N] .. offsets[N+1])`

### メモリコスト

```
offsets: (node_count + 1) × 4 bytes (int32)
edges:   edge_count × 4 bytes (int32)
```

6M edges, 1.5M nodes の場合:
- offsets: 1.5M × 4 = 6 MB
- edges: 6M × 4 = 24 MB
- 合計: **30 MB** (vs PHP 配列 2.2 GB — **73 倍削減**)

### 追加データ (int64)

```
node_sizes[node_count]:     各ノードの shallow size (int64, 8 bytes)
subtree_sizes[node_count]:  DFS 結果 (int64, 8 bytes)
node_to_scc[node_count]:    SCC メンバーシップ (int32, 4 bytes)
```

6M edges, 1.5M nodes の場合の追加:
- node_sizes: 1.5M × 8 = 12 MB
- subtree_sizes: 1.5M × 8 = 12 MB
- node_to_scc: 1.5M × 4 = 6 MB

**合計: ~60 MB** (vs PHP 配列 2.2 GB+)

## FFI での実装

### 型の選択

| データ | 型 | 理由 |
|---|---|---|
| node_id (offsets, edges) | int32 | 21 億ノードで十分 |
| node_sizes, subtree_sizes | int64 | バイト数は 4GB 超えうる |
| node_to_scc | int32 | SCC ID |

### 実測パフォーマンス (このセッションで計測済み)

```php
$arr = FFI::new("int32_t[6000000]");
// 確保: 23 MB
// Fill: 0.1s
// Sequential read: 0.12s
// Random 1M reads: 0.16s
```

PHP 配列と同等のアクセス速度で、メモリ 1/100。

### CSR 構築手順

```php
// Step 1: SQLite から edge を読む (fetchAll)
$rows = $db->query("SELECT parent_node_id, child_node_id FROM context_edges WHERE is_tree = 1")
    ->fetchAll(PDO::FETCH_NUM);

// Step 2: 各ノードの degree を数える
$degree = FFI::new("int32_t[{$node_count}]");
foreach ($rows as [$parent, $child]) {
    $degree[$parent]++;
}

// Step 3: offsets を prefix sum で構築
$offsets = FFI::new("int32_t[" . ($node_count + 1) . "]");
$offsets[0] = 0;
for ($i = 0; $i < $node_count; $i++) {
    $offsets[$i + 1] = $offsets[$i] + $degree[$i];
}

// Step 4: edges を埋める
$edges = FFI::new("int32_t[{$edge_count}]");
$pos = FFI::new("int32_t[{$node_count}]");  // 書き込み位置
for ($i = 0; $i < $node_count; $i++) { $pos[$i] = $offsets[$i]; }
foreach ($rows as [$parent, $child]) {
    $edges[$pos[$parent]++] = $child;
}
unset($rows, $degree, $pos);  // 中間データ解放
```

### DFS on CSR

```php
// Post-order DFS (iterative)
$subtree_sizes = FFI::new("int64_t[{$node_count}]");
$stack = [];  // PHP 配列だが深さ分のみ (小さい)
foreach ($roots as $root) { $stack[] = [$root, false]; }

while ($stack) {
    [$node, $processed] = array_pop($stack);
    if ($processed) {
        $size = $node_sizes[$node];
        $start = $offsets[$node];
        $end = $offsets[$node + 1];
        for ($i = $start; $i < $end; $i++) {
            $size += $subtree_sizes[$edges[$i]];
        }
        $subtree_sizes[$node] = $size;
    } else {
        $stack[] = [$node, true];
        $start = $offsets[$node];
        $end = $offsets[$node + 1];
        for ($i = $start; $i < $end; $i++) {
            $child = $edges[$i];
            if ($subtree_sizes[$child] === 0) {
                $stack[] = [$child, false];
            }
        }
    }
}
```

### Tarjan SCC on CSR

同様に `$offsets` + `$edges` でイテレート。PHP 配列の `$children[$node]` を
`$edges[$offsets[$node] .. $offsets[$node+1])` に置き換えるだけ。
アルゴリズム自体は変わらない。

## node_id のマッピング

### 問題

SQLite の `context_edges` の `parent_node_id` / `child_node_id` は
連番とは限らない（歯抜けの可能性）。CSR の配列インデックスは 0-based 連番。

### 解決

SQLite から読み込み時に node_id → CSR index のマッピングを作る:

```php
// node_id → index (PHP 配列、ノード数分のみ)
$node_to_index = [];
$index_to_node = [];
$idx = 0;
foreach ($all_node_ids as $node_id) {
    $node_to_index[$node_id] = $idx;
    $index_to_node[$idx] = $node_id;
    $idx++;
}
```

このマッピング配列は PHP 配列だが、ノード数分のみ (edge 数ではない)。
1.5M ノードで ~100 MB — edge の 6M に比べれば小さい。

さらにこれも FFI int32 ペア配列にすれば ~12 MB。

## GraphSubstrate との統合

### 現在のインターフェース

```php
class GraphSubstrate {
    public array $children;        // parent_id => [child_id, ...]
    public array $all_parents;     // child_id => [parent_id, ...]
    public array $node_sizes;      // node_id => size
    public array $subtree_sizes;   // node_id => subtree_size
    public array $roots;           // [root_id, ...]
    public array $scc_profiles;    // [...]
    public array $node_to_scc;     // node_id => scc_id
}
```

### FFI CSR 版

```php
class GraphSubstrate {
    // CSR for tree children
    private \FFI\CData $tree_offsets;    // int32_t[]
    private \FFI\CData $tree_edges;      // int32_t[]

    // CSR for all children (tree + non-tree, for SCC)
    private \FFI\CData $all_offsets;     // int32_t[]
    private \FFI\CData $all_edges;       // int32_t[]

    // Per-node data
    private \FFI\CData $node_sizes;      // int64_t[]
    private \FFI\CData $subtree_sizes;   // int64_t[]
    private \FFI\CData $node_to_scc;     // int32_t[]

    // Mapping
    private array $node_to_index;        // PHP node_id => CSR index
    private array $index_to_node;        // CSR index => PHP node_id

    // Metadata (keep as PHP)
    public array $roots;
    public array $scc_profiles;
    public array $node_classes;          // node_id => class_name

    // Access methods
    public function getChildren(int $node_id): iterable { ... }
    public function getSubtreeSize(int $node_id): int { ... }
    public function getNodeSize(int $node_id): int { ... }
}
```

### 切り替え戦略

edge count で自動切り替え:

```php
public static function loadFromDb(PDO $db, int $run_id): self {
    $edge_count = $db->query("SELECT count(*) FROM context_edges WHERE ...")->fetchColumn();

    if ($edge_count > 2_000_000) {
        return self::loadWithFfiCsr($db, $run_id);
    } else {
        return self::loadWithPhpArrays($db, $run_id);
    }
}
```

200 万 edges 以下なら PHP 配列で十分 (< 1 GB)。
200 万超なら FFI CSR に自動切り替え。

## 期待される効果 (理論値)

| シナリオ | 現在 | FFI CSR 全面適用後 |
|---|---|---|
| Monolog (4.5M edges) | 2.1 GB, 44s | ~60 MB, ~30s |
| PHP-Parser (6.1M edges) | 2.2 GB, 30s | ~70 MB, ~20s |
| reli-on-reli (~50M edges) | 15+ GB OOM | ~400 MB, 数分 |
| PHPStan 4GB target (~80M edges 推定) | ~30 GB OOM | ~700 MB, 数分 |

reli のドッグフーディングと、大規模ターゲットの解析が可能になる。

## Phase 1 実測結果 (adjacency list のみ CSR 化)

`$children` (tree edges) と `$all_children` (all edges) を FFI CSR 化。
`--ffi-csr` / `--no-ffi-csr` で切り替え。

| データセット | edges | PHP 配列 RSS | FFI CSR RSS | 削減 | 時間 (PHP→CSR) |
|---|---|---|---|---|---|
| Eloquent | 1M | 904 MB | 661 MB | -27% | — |
| Monolog | 4.5M | 3,798 MB | 2,425 MB | -36% | 1m44s → 1m03s |
| PHP-Parser | 6.1M | 6,056 MB (OOM) | 4,736 MB | — | OOM → 成功 |

PHP-Parser は PHP 配列で 6GB OOM だったのが FFI CSR で解決。

削減が理論値 (73x) ほどではない理由:
**adjacency list 以外の PHP 配列がまだ大量に残っている。**

## Phase 2: さらに FFI 化すべきデータ

### 即座に FFI 化可能 (int 配列)

| データ | 現在 | FFI 化後 | 型 |
|---|---|---|---|
| `$node_sizes` | PHP 連想配列 (node_id → size) | `FFI::new("int64_t[N]")` | int64 |
| `$subtree_sizes` | PHP 連想配列 (node_id → size) | `FFI::new("int64_t[N]")` | int64 |
| `$node_to_scc` | PHP 連想配列 (node_id → scc_id) | `FFI::new("int32_t[N]")` | int32 |

Monolog (3M nodes) での推定削減:
- `$node_sizes`: PHP 配列 ~300 MB → FFI int64 ~24 MB
- `$subtree_sizes`: PHP 配列 ~300 MB → FFI int64 ~24 MB
- `$node_to_scc`: PHP 配列 ~200 MB → FFI int32 ~12 MB
- **追加削減: ~760 MB**

### CSR 化可能 (隣接リスト)

| データ | 現在 | 用途 |
|---|---|---|
| `$all_parents` | PHP 配列 (child → [parent, ...]) | SCC 計算、blame allocation |

`$all_parents` は reverse adjacency で、forward の `$all_children` と同じ CSR 形式にできる。

### FFI 化が難しいもの

| データ | 理由 |
|---|---|
| `$node_classes` (node_id → class_name) | 文字列。FFI に直接入れられない |
| `$scc_profiles` | 構造体の配列。PHP 配列が自然 |
| `$roots` | 小さい (数個)。変える意味なし |
| node_id マッピング (node_id ↔ CSR index) | PHP 連想配列が必要。ただし node_id が連番なら不要 |

`$node_classes` は辞書テーブル (class_name → int ID) + FFI int 配列で間接化できるが、
コードの複雑さが増す。class_name は report pass でしか使わないので、
必要なときに SQLite から引く方が現実的。

### node_id マッピングの最適化

SQLite の node_id が連番であれば CSR index = node_id となりマッピング不要。
`ContextAnalyzer` が連番の node_id を振っているなら、PHP 連想配列のマッピングを
丸ごと省ける。歯抜けがある場合は compact mapping が必要。

## Phase 2 実装後の推定効果

| データセット | Phase 1 RSS | Phase 2 推定 RSS | 削減 |
|---|---|---|---|
| Monolog (4.5M edges, 3M nodes) | 2,425 MB | ~1,600 MB | -34% |
| PHP-Parser (6.1M edges) | 4,736 MB | ~3,200 MB | -32% |
| reli-on-reli (~50M edges) | (未測定) | ~5-6 GB | (解析可能に？) |
