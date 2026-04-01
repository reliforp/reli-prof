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

### 3a. パス表示の改善 [重要度: 高]

**3a-1. フレーム番号 → 関数名**

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

bottleneck_path と choke_point の両方で適用。

**3a-2. large_string / large_array にオーナーパスを付ける**

現在:
```
[MEDIUM] large_string: 205.88 KB string — raw: --boundary_...
[LOW] large_array: 0.09 MB array, 2,010 elements — interned_strings
```

link_name 1 段だけでは「誰が持っているか」分からない。
3-hop ancestor を付けるとアクショナブルになる:

```
[MEDIUM] large_string: 205.88 KB — messages[0] -> structure -> raw: --boundary_...
[LOW] large_array: 0.09 MB, 2,010 elements — class_table -> interned_strings
```

実装: `--full-analysis` 時は graph substrate から root まで遡るフルパスを出す。
パスの途中で class_name や function_name を持つノードがあれば解決して表示。
graph なしの場合は 3-hop JOIN でフォールバック。

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
