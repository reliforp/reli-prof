# レポート機能改善依頼（テスト検証に基づく）

## 修正済み（#559, #560 で対応完了）

- ~~1a. choke_point が bottleneck_path と重複~~ → パス上ノード抑制 ✅
- ~~1b. shared_fanin の structural noise~~ → name/key/value/数字フィルタ ✅
- ~~1c. expensive_property LOW が VM internal で冗長~~ → class-qualified のみ ✅
- ~~1d. object_handlers が dedup に出る~~ → 固定除外 ✅

## 残っている要望

### 2a. PropertyScalingPass [重要度: 高]

dominant_class に対して「どのプロパティがインスタンスと共にスケールするか」を検出。

Eloquent の例:
```
[MEDIUM] property_scaling: User (10,000 instances)
  PER-INSTANCE (scales linearly — cost grows with instance count):
    attributes:  10,000 copies × 56B = 546.88 KB
    casts:       10,000 copies × 56B = 546.88 KB
  SHARED (constant cost — CoW sharing across all instances):
    relations, changes, fillable, guarded, hidden, ... (15 props, 1 copy each)
```

**実装方法**: dominant_class の全インスタンスに対して、各プロパティの
`count(DISTINCT child_node_id)` を集計。tree + non-tree の両方を含める。

```sql
SELECT
    e_prop.link_name,
    sum(CASE WHEN e_prop.is_tree = 1 THEN 1 ELSE 0 END) as tree_refs,
    sum(CASE WHEN e_prop.is_tree = 0 THEN 1 ELSE 0 END) as nontree_refs,
    count(DISTINCT e_prop.child_node_id) as distinct_targets,
    CASE
        WHEN count(DISTINCT e_prop.child_node_id) = 1 THEN 'SHARED'
        WHEN count(DISTINCT e_prop.child_node_id) >= count(*) * 0.9 THEN 'PER-INSTANCE'
        ELSE 'PARTIALLY SHARED'
    END as scaling
FROM context_node_locations cnl_obj
JOIN context_edges e_to_props ON e_to_props.parent_node_id = cnl_obj.node_id
    AND e_to_props.link_name = 'object_properties'
JOIN context_edges e_prop ON e_prop.parent_node_id = e_to_props.child_node_id
LEFT JOIN context_node_locations cnl_val ON cnl_val.node_id = e_prop.child_node_id
WHERE cnl_obj.class_name = :dominant_class
GROUP BY e_prop.link_name
```

graph load 不要（SQL のみ）。Phase 2 に入れられる。

Eloquent SQLite で検証済み:
- `attributes`: PER-INSTANCE (10K copies, 546 KB) — ユーザーデータ
- `casts`: PER-INSTANCE (10K copies, 546 KB) — lazy resolution 候補
- `relations`: SHARED (1 copy, CoW) — 空配列が全インスタンスで共有
- `fillable`, `guarded`, `hidden`: SHARED (リテラル共有)

### 2b. OwnershipPatternPass [重要度: 中、別 PR 推奨]

「object A が必ず object B を子に持つ」検出。
CommonMark の DotAccessData (246K) が全 Node サブクラスの companion だが、
count ベースの companion 検出では捕まらない問題を解決。
graph substrate 必要、規模が大きいので別 PR。

### 3a. Overview にコールスタック表示 [重要度: 高]

スナップショット取得時点のコールスタックを Overview に出す。
「何をしていた瞬間のスナップショットか」が一目で分かる。

```
=== Overview ===
  Heap: 174.73 MB (100.0% analyzed)

  Call Stack at capture:
    #0 sleep()                     — (internal)
    #1 <main>                      — simulate_memory_leak.php:28
```

実装: SQL 1 本。graph load 不要。Phase 1 に入れられる。

```sql
SELECT e.link_name as frame_no, fn.value as function_name, ln.value as lineno
FROM context_edges e
JOIN context_nodes cn ON cn.node_id = e.child_node_id AND cn.type = 'CallFrameContext'
LEFT JOIN context_node_attributes fn ON fn.node_id = e.child_node_id AND fn.key = 'function_name'
LEFT JOIN context_node_attributes ln ON ln.node_id = e.child_node_id AND ln.key = 'lineno'
WHERE e.is_tree = 1 AND e.link_name GLOB '[0-9]*'
    AND e.parent_node_id IN (
        SELECT cn2.node_id FROM context_nodes cn2 WHERE cn2.type = 'CallFramesContext'
        AND cn2.node_id IN (SELECT child_node_id FROM context_edges WHERE parent_node_id IS NULL AND link_name = 'call_frames')
    )
ORDER BY cast(e.link_name as integer);
```

### 3b. 全パス表示の名前解決 [重要度: 高]

パスを出す全ての finding で、フレーム番号→関数名、ノード→クラス名を解決する。

**対象 finding**: bottleneck_path, choke_point, large_string, large_array

**現在**:
```
bottleneck_path: call_frames -> 1 -> local_variables -> messages -> ...
large_string: 205.88 KB string — raw: --boundary_...
```

**理想**:
```
bottleneck_path: <main>:67 -> $messages[0] -> Structure -> raw -> ... (153 MB)
large_string: 205.88 KB — <main>:67 -> $messages[0] -> Structure::$raw: --boundary_...
```

**名前解決ルール**:
- `CallFrameContext` → `function_name:lineno` (attributes から)
- `ObjectContext` → `class_name` (context_node_locations から)
- `CallFrameVariableTableContext` の子 → `$変数名` (link_name にプレフィックス)
- `ArrayElementContext` → `[index]`
- `ObjectPropertiesContext` の子 → そのまま (プロパティ名)

**実装**:
- `--full-analysis` 時: graph substrate で root まで遡るフルパス
- graph なし: 3-hop JOIN でフォールバック
- 両方で名前解決を適用

`--full-analysis` のコストは Monolog (4.5M edges) でも 44 秒なので、
フルパスを出すために graph load する価値は十分にある。

現在:
```
call_frames -> 1 -> local_variables -> messages -> ...
```

フレーム番号 `1` では何の関数か分からない。CallFrameContext には
`function_name` と `lineno` が attributes にあるので、表示時に
解決すべき:

```
call_frames -> <main>:54 -> $messages -> ...
```

あるいは:
```
call_frames -> PdfParser::parseFile():42 -> $messages -> ...
```

bottleneck_path と choke_point の両方で、CallFrameContext の表示を
`function_name:lineno` に変換する。

### 3b. Eloquent の bottleneck_path が classMap を指す [重要度: 低]

Eloquent のレポートで bottleneck_path が:
```
class_table -> ComposerAutoloader -> classMap (18.86 MB)
```
を指すが、ユーザーの関心は call_frames 側の `$users` (16 MB) のほう。

class_table はフレームワークの固定コストなので、bottleneck_path を
「call_frames 起点」「class_table 起点」の 2 本出すか、
root branch ごとの drill-down を出すと親切。

## 追加確認済み（report branch 最新で対応完了）

- ~~3a. Overview にコールスタック表示~~ ✅
- ~~3b-1. bottleneck_path のフレーム名前解決~~ ✅
- ~~3b-2. large_string/large_array にオーナーパス~~ ✅
- ~~2a. PropertyScalingPass~~ ✅

## 新規要望

### 2a-2. PropertyScalingPass: retained ベースの per-property コスト [重要度: 高]

現在の per-property コストは shallow size のみ (例: `$attributes: 56B`)。
実際にはその先に配列テーブル + 文字列値がぶら下がっている。
`--full-analysis` で `$subtree_sizes` が使えるなら retained size で表示すべき:

```
Before: $attributes: 10,000 copies x 56B = 546.88 KB
After:  $attributes: 10,000 copies x ~500B (retained) = ~4.88 MB
```

### 2a-3. PropertyScalingPass: zval コストのみのプロパティを除外 [重要度: 中]

`$wasRecentlyCreated: 10,000 copies x 0B` のような bool/int/float/null は
追加の MemoryLocation を持たない（zval コストのみで、object の shallow size
に properties_table スロットとして既に含まれている）。

size = 0 の PER-INSTANCE プロパティは object サイズとの二重報告なので、
PER-INSTANCE リストから除外するか、別扱いにすべき:

```
PER-INSTANCE (additional allocations):
  $attributes: 10,000 copies x 56B = 546.88 KB
  $casts:      10,000 copies x 56B = 546.88 KB
(14 scalar properties also per-instance but included in object size)
```

### 3c. bottleneck_path を root branch ごとに [重要度: 低]

Eloquent のレポートで bottleneck_path が:
```
class_table -> ComposerAutoloader -> classMap (18.86 MB)
```
を指すが、ユーザーの関心は call_frames 側の `$users` (16 MB) のほう。

class_table はフレームワークの固定コストなので、bottleneck_path を
「call_frames 起点」「class_table 起点」の 2 本出すか、
root branch ごとの drill-down を出すと親切。
