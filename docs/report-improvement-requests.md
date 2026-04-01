# レポート機能改善依頼（テスト検証に基づく）

## 1. ノイズ削減

### 1a. choke_point が bottleneck_path と重複 [重要度: 高]

php-imap で choke_point が 6 個連続で出るが、全て bottleneck_path の各段。
同じことを 2 回言っている。

```
[HIGH] bottleneck_path: call_frames -> 1 -> local_variables -> messages -> ... (154.75 MB)
[HIGH] choke_point: CallFramesContext (0B) holds 154.75 MB     ← 上と同じ
[HIGH] choke_point: CallFrameContext (0B) holds 154.75 MB      ← 上と同じ
[HIGH] choke_point: CallFrameVariableTableContext (0B) holds 154.72 MB  ← 上と同じ
```

**対応案**: bottleneck_path 上のノードは choke_point から除外。
あるいは choke_point は「bottleneck_path に **ない** 場所で大きい subtree を持つノード」だけ出す。

### 1b. shared_fanin の structural noise [重要度: 高]

```
shared_fanin: name: 12,677 refs -> 5,048 targets (2.5 each)
shared_fanin: key: 12,043 refs -> 2,991 targets (4.0 each)
shared_fanin: 0: 747 refs -> 155 targets (4.8 each)
shared_fanin: 1: 410 refs -> 135 targets (3.0 each)
shared_fanin: 2: 226 refs -> 90 targets (2.5 each)
```

`name`, `key`, 数字インデックスは PHP VM の正常な interned string 共有。
意味のあるのは `oMessage`, `mask`, `filename` 程度。

**対応案**: link_name フィルタ。以下を除外:
- `name`, `key`, `value` (interned string 共有)
- 数字インデックス (`0`, `1`, `2`, ...)
- `object_handlers` (全オブジェクト共有)

または `ObjectPropertiesContext` 起点のものだけ出す。

### 1c. expensive_property LOW の冗長 [重要度: 中]

```
[LOW] expensive_property: op_array: 1,736 occurrences x 536B = 0.89 MB
[LOW] expensive_property: name: 9,038 occurrences x 40B = 0.34 MB
[LOW] expensive_property: methods: 650 occurrences x 447B = 0.28 MB
...（0.1-0.9 MB のものが 7-8 個）
```

`op_array`, `methods`, `doc_comment` は PHP VM 内部構造で、ユーザーコードの問題ではない。

**対応案**:
- class-qualified なもの (`Structure::$raw`) だけ MEDIUM 以上で出す
- クラス修飾なし（VM internal）は省略するか、別セクション（Additional Info 等）に移す
- あるいは 1MB 以下の LOW は抑制

### 1d. object_handlers が dedup_candidate に出る [重要度: 中]

```
[LOW] dedup_candidate: object_handlers: 9,045 copies x 200B ALL SAME SIZE = 1766.60 KB
```

PHP VM の仕様で全オブジェクトが同じ handler テーブルを共有する。
常に TOP に来て毎回ノイズ。

**対応案**: `object_handlers` を dedup_candidate から固定除外。

## 2. 新規 Pass

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
WHERE cnl_obj.class_name = :dominant_class
GROUP BY e_prop.link_name
```

graph load 不要（SQL のみ）。Phase 2 に入れられる。

既に Eloquent SQLite で検証済み:
- `attributes`: PER-INSTANCE (10K copies, 546 KB)
- `relations`: SHARED (1 copy, CoW)
- `casts`: PER-INSTANCE (10K copies, 546 KB) — lazy resolution 候補

### 2b. OwnershipPatternPass [重要度: 中、別 PR 推奨]

「object A が必ず object B を子に持つ」検出。
CommonMark の DotAccessData (246K) が全 Node サブクラスの companion だが、
count ベースの companion 検出では捕まらない問題を解決。

これは graph substrate が必要で、規模が大きいので別 PR が妥当。

## 3. 細かい改善

### 3a. cycle と shared_fanin の重複注釈 [重要度: 低]

php-imap で `cycle_cluster: 201 cycles (oMessage)` と
`shared_fanin: oMessage 603→201` が両方出る。

**対応案**: cycle_cluster で検出された link_name の shared_fanin には
「(part of detected cycle)」と注釈するか抑制。

### 3b. companion_pair と companion_cluster の統合 [重要度: 低]

Symfony Forms で FormBuilder↔Closure (3,611) が companion_pair で、
OptionsResolver 群 (1,806) が companion_cluster で別々に出る。

統合は難しい（count が違うので別グループ）。現状で問題ないが、
将来的に「Form 関連のオブジェクトが全体の X% を占める」的な
上位サマリーがあるとユーザーに親切。
