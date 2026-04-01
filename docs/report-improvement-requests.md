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

### 3d. パスを PHP 構文風に表示 [重要度: 高]

現在:
```
call_frames -> <main>:28 -> local_variables -> messages -> array_elements -> 0 -> value -> object_properties -> structure -> object_properties -> raw
```

理想:
```
<main>:28::$messages[0]->structure->raw
```

**変換ルール** (context node type に基づく):

| パス断片 | type | 変換 |
|---|---|---|
| `call_frames -> <main>:28 -> local_variables` | CallFrames/CallFrame/VariableTable | `<main>:28::` |
| `-> messages` (VariableTable の子) | link_name | `$messages` |
| `-> array_elements -> 0 -> value` | ArrayElements/Element/value | `[0]` |
| `-> object_properties -> structure` | ObjectProperties の子 | `->structure` |
| `-> object_properties -> raw` | ObjectProperties の子 | `->raw` |

省略対象 (構造的な中間ノード):
- `call_frames`, `local_variables`, `object_properties`, `array_elements`, `value`
- これらは PHP 構文上意味を持たない reli の内部表現

データは全て context_nodes.type から取得可能。
bottleneck_path, large_string, large_array, choke_point の全パス表示に適用。

## 追加確認済み（report branch 4cc7f8c で対応完了）

- ~~2a-2. PropertyScaling: retained ベース~~ ✅ ($attributes 56B → 599B retained)
- ~~2a-3. PropertyScaling: scalar 除外~~ ✅ ("12 scalar properties, included in object size")
- ~~3d. PHP 構文パス (bottleneck_path)~~ ✅ (`<main>:28::$messages[0]->structure->parts`)

## 残り

### 3d-2. large_string/large_array の 3-hop パスにも PHP 構文変換 [重要度: 中]

bottleneck_path は graph pass 経由で `$messages[0]->structure->parts` になるが、
large_string は SQL 3-hop なので `structure -> object_properties -> raw` のまま。

`--full-analysis` 時は large_string/large_array もフルパス + PHP 構文変換すべき。
あるいは 3-hop 結果に対しても `object_properties` → `->` の簡易変換を適用。

### 2a-4. PropertyScalingPass: 完全修飾名 [重要度: 中]

現在:
```
PER-INSTANCE:
  $attributes: 10,000 copies x 599B
  $casts: 10,000 copies x 376B
SHARED: $hidden, $guarded, $fillable, ...
```

expensive_property が `Structure::$raw` のように完全修飾名を出しているのに、
PropertyScalingPass は `$attributes` とクラス名なし。整合性がない。

理想:
```
PER-INSTANCE:
  User::$attributes: 10,000 copies x 599B (retained)
  User::$casts: 10,000 copies x 376B (retained)
SHARED: User::$hidden, User::$guarded, User::$fillable, ...
```

Pass 内で dominant_class 名を既に持っているので、プレフィックスに付けるだけ。

### 全般: クラス名は FQCN で統一 [重要度: 中]

全ての finding でクラス名を出す場所を FQCN (完全修飾名) に統一。

対象:
- dominant_class: `User` → `App\Models\User`
- companion_pair: `FormBuilder` → `Symfony\Component\Form\FormBuilder`
- companion_cluster: `Message` → `Webklex\PHPIMAP\Message`
- structural_duplicate: `Attribute` → `Webklex\PHPIMAP\Attribute`
- property_scaling: `User::$attributes` → `App\Models\User::$attributes`
- cycle_cluster: `Attachment:3, Message:1` → FQCN版

`class_objects_summary` には FQCN が入っているのでデータは既にある。
短縮表記にするかはユーザーの好みだが、まずは FQCN で出して、
将来的に `--short-class-names` オプションで短縮を選べるようにするのが無難。

### choke_point にパス表示 [重要度: 高]

現在:
```
[HIGH] choke_point: ZendArrayTableMemoryLocation (3208B shallow) holds 153.73 MB via 200 children
```

どこにあるか分からない。bottleneck_path と同じ PHP 構文パスを付ける:
```
[HIGH] choke_point: <main>:28::$messages (3208B shallow) holds 153.73 MB via 200 children
```

graph substrate から root まで遡るパスは bottleneck_path と同じコードで出せる。
PHP 構文変換も同じ。`--full-analysis` 時のみで OK。

### cycle_cluster の可読性 [重要度: 高]

現在:
```
[MEDIUM] cycle_cluster: 200 identical cycles: Attachment:3, AttachmentCollection:1, Message:1 (15 nodes each, 170.31 KB total)
[LOW] cycle_cluster: 1 identical cycle: Closure:113, Route:4, ... (869 nodes each, 74.23 KB total)
```

問題:
- `Attachment:3` の `:3` がクラス名の一部に見える
- `15 nodes each` / `869 nodes each` は internal nodes 含みで混乱する
  (「1252 objects + 5245 internal nodes」とか言われても閉口する)
- どの参照が循環を形成しているか分からない
- Laravel の巨大 cycle (Closure:113, Route:4, ..., Application:1) は
  クラス列挙が長すぎて読めない

理想:
```
[MEDIUM] cycle_cluster: 200 identical cycles
  Per cycle: 1× Message + 3× Attachment + 1× AttachmentCollection
  Circular path: Message->attachments[*]->oMessage → Message
  Total: 170.31 KB
```

大きい cycle (Laravel DI コンテナ等) は:
```
[LOW] cycle_cluster: 1 cycle (54 classes, 74.23 KB)
  Main classes: 113× Closure, 4× Route, 1× Application, ...
  Circular path: Application->... → Application
```

改善ポイント:
1. `クラス名:個数` → `個数× クラス名` (誤読防止)
2. internal nodes の数は表示しない (ユーザーに意味がない)
3. 循環を形成している参照パス (back-reference の link_name) を表示
4. クラスが多い cycle は top 3-5 に省略して class 数だけ出す
5. 巨大 SCC (数十クラス、各 1 インスタンス) は DI コンテナの構造コストとして
   提示する。バグではなくコスト情報:
   ```
   [INFO] di_container_cycle: 54 classes forming 1 cycle (74.23 KB)
     This is the structural cost of the DI container.
     Reducing container instances (e.g., in workers/tests) reduces this proportionally.
     Top classes: 113× Closure, 4× Route, 1× Application, ...
   ```
   アクショナブルでないことが多いが、Worker で毎リクエスト Application を
   再生成するケースや、テスト setUp ごとの再生成では意味のある情報になる。

### shared_singleton の表示改善 [重要度: 中]

現在:
```
[shared_singleton] withCount: 10,000 refs -> 1 target [singleton, normal]
[shared_singleton] relations: 10,000 refs -> 1 target [singleton, normal]
```

問題:
- クラス名がない（どのクラスの `$withCount`?）
- 「10,000 refs → 1 target」は non-tree edge 分析の生データで意味が伝わりにくい
- 「[singleton, normal]」の意味が不明
- PropertyScalingPass の SHARED リストと情報が重複する

対応案:
1. PropertyScalingPass が出るケースでは shared_singleton を抑制
   （SHARED リストで既にカバーされているため）
2. PropertyScalingPass が出ないケースでは、より読みやすい形で出す:
   ```
   [INFO] shared_property: User::$withCount — shared by 10,000 instances (normal CoW)
   ```
3. あるいは Additional Info セクション自体を見直し、
   「正常な共有パターン」として 1 行サマリーにまとめる:
   ```
   Normal sharing: 12 properties shared across 10,000 User instances via CoW
   ```

### "CoW" の表記を修正 [重要度: 中]

PropertyScalingPass の SHARED が "CoW sharing" と表記しているが、
reli が見ているのは「同じアドレスを参照している」事実のみ。

実際の共有理由は複数あり得る:
1. CoW (Copy-on-Write) — 空配列リテラルの共有
2. 同じインスタンスの代入 — シングルトン注入
3. Interned string — PHP の文字列自動共有
4. クラス定義のデフォルト値

location type と参照経由かどうかで区別できる:
- **配列/文字列** が shared (ZendReference 経由でない) → CoW
- **配列/文字列** が shared (ZendReference 経由) → PHP 参照 (`&`) で共有
- **オブジェクト** が shared → 同じインスタンスへの参照

context tree で `PhpReferenceContext` が間に挟まっているかどうかで判定可能。

```
SHARED:
  User::$relations (array, CoW)
  User::$fillable (array, CoW)
  User::$config (object, same instance)
  User::$sharedBuf (array, PHP reference)  ← & 経由
```

### PropertyScalingPass の SQL が遅い [重要度: 高]

Eloquent (1M edges) で PropertyScaling の SQL JOIN が **79 秒** かかる。
3 段 JOIN (cnl_obj → e_to_props → e_prop) が重い。
index 追加しても改善しない（JOIN 構造の問題）。

**対応案**: `--full-analysis` で graph substrate がある場合、SQL ではなく
PHP 側で計算する。graph load 済みなら各 object の children を辿るだけ。
TopStrings は 0.4 秒なので SQL のままで問題なし。

### reli 本体に追加すべき index [重要度: 中]

`PdoMemoryOutput::createTables()` に以下を追加すべき:
- `(run_id, location_type)` — TopStrings/TopArrays のフィルタ用
- `(run_id, link_name, parent_node_id, is_tree)` — link_name ベースのクエリ用

現在は辻斬りで手動追加した DB にのみ存在。reli が作る DB には入っていない。

### デフォルト full-analysis [重要度: 高]

full-analysis なしだと主要 finding (bottleneck_path, choke_point, SCC,
PropertyScaling retained, PHP 構文パス) が出ず、レポートの価値が半減。
Eloquent だと `dominant_class: User 98.2%` だけで終わる。

PropertyScaling の SQL 79秒問題を graph 側に移せば全事例 1 分以内。
デフォルトを full-analysis にして、巨大ターゲットのみ `--no-full-analysis`
で opt-out する方が自然。

### shared_fanin にクラス修飾パス [重要度: 中]

現在:
```
[MEDIUM] shared_fanin: oMessage: 600 refs -> 200 targets (3.0 each)
```

クラス名がないと「何の oMessage?」が分からない。
source/target class は SQL で取得可能（検証済み）:

理想:
```
[MEDIUM] shared_fanin: Attachment::$oMessage → Message (600 refs → 200 targets)
```

実装: ObjectPropertiesContext の親 ObjectContext から source class、
child_node_id の class_name から target class を取る。

### dedup_candidate にクラス修飾 [重要度: 中]

shared_fanin と同様、source/target class を付ける。

```
Before: dedup_candidate: part: 600 copies x 312B ALL SAME SIZE
After:  dedup_candidate: Attachment::$part (Part): 600 copies x 312B ALL SAME SIZE
```

SQL で取得可能（検証済み）。shared_fanin と同じ JOIN パターン。

### dedup_candidate に代表例を表示 [重要度: 低]

実際に重複かどうかは中身を見ないと分からない。代表例を数件出すと判断材料になる。

- 文字列なら `string_value` の先頭を数件表示
- 配列/オブジェクトならサイズと子ノード数を数件表示

```
dedup_candidate: Attachment::$part (Part): 600 copies x 312B ALL SAME SIZE
  Examples: Part{raw=210KB}, Part{raw=52KB}, Part{raw=52KB} — different content
```

ただし structural_duplicate pass が既に「同じ shape のオブジェクト群」を
検出しているので、dedup_candidate と structural_duplicate の整理が先。
両方出ると冗長な場合がある。

### large_array のサイズが配列テーブルのみ [重要度: 高]

現在:
```
[LOW] large_array: 0.15 MB array, 10,000 elements — users -> items
```

0.15 MB は配列のハッシュテーブル部分 (header 56B + table 160KB) のみ。
配列の中身 (10,000 User objects × ~1.6 KB = ~15 MB) は含まない。
ユーザーから見ると「$users が 0.15 MB？嘘でしょ」となる。

`--full-analysis` なら subtree_sizes から配列の retained size が取れるので、
中身含みの retained サイズで表示すべき:

テーブルサイズと retained の **両方** を出すべき:

```
[HIGH] large_array: 15.3 MB retained (table: 160 KB), 10,000 elements — <main>:54::$users->items
```

両方出す理由 — ケースが異なるため:
- テーブル大 + 中身大: `$users->items` (table 160KB, retained 15MB)
- テーブル大 + 中身スカスカ: 歯抜け配列（大量 unset 後、テーブル未縮小）
- テーブル小 + 中身巨大: 少数要素だが各要素が huge object

一方の数字だけだと片方のケースを見逃す。
severity は retained ベースで判定。

### large_array の element_count は reli が辿った数 [重要度: 中]

v_arrays の `element_count` は `#count` = reli が辿った要素数であり、
PHP の `nNumOfElements` (論理的要素数) ではない。概ね一致するが厳密には異なる。

ZendArray には 3 つの数がある:
- `nNumOfElements`: 有効要素数 (PHP の count($arr))
- `nNumUsed`: 使用スロット数 (unset で歯抜けのスロット含む)  
- `nTableSize`: 確保済みバケット数 (2のべき乗)

歯抜け配列の検出には `nTableSize` vs `nNumOfElements` の比率が有用:
```
large_array: 0.15 MB, 10,000 elements (capacity: 16,384) — 61% utilization
```

reli の ZendArray に全フィールドがあるので、context_node_attributes に
`#nTableSize` と `#nNumOfElements` も出すか、large_array pass で直接使う。

### sparse_array 検出 (新 finding type) [重要度: 中]

`nTableSize` >> `nNumOfElements` の配列を検出。
large_array とは別の問題 — サイズではなく使用効率が悪い。

```
[MEDIUM] sparse_array: 256 KB table, 5/16,384 slots used (0.03%) — <main>:42::$cache
  Likely: large array after mass unset() without reallocation
```

ZendArray の `nTableSize` と `nNumOfElements` で判定:
- `nNumOfElements / nTableSize < 0.25` かつ `nTableSize >= 1024` → sparse
- テーブルサイズ × スロットサイズが無駄なメモリ量

これは `array_values()` で再パッキングするか、新しい配列に
移し替えることで解消できるアクショナブルな finding。

### choke_point の severity がすべて HIGH [重要度: 中]

現在は subtree > 1 MB の choke_point が全部 `severity: High`。

Eloquent で classMap (1.51 MB) や DI instances (1.01 MB) が HIGH になるが、
これらはフレームワークの固定コストで HIGH は大げさ。

**対応案**: subtree サイズに応じて段階的に:
- subtree > ヒープの 30% → HIGH
- subtree > ヒープの 10% → MEDIUM
- subtree > 1 MB → LOW

あるいはヒープの割合ではなく、絶対値:
- > 50 MB → HIGH
- > 10 MB → MEDIUM
- > 1 MB → LOW

### dedup_candidate の array key/value にオーナーパス [重要度: 中]

現在:
```
[LOW] dedup_candidate: key: 20,099 copies x 41B SAME SIZE — 99% identical, "email_verified_at"
```

「何の配列のキー？」が分からない。`$attributes` のキーなのか `$casts` のキーなのか。
`ArrayElementContext` 経由の dedup_candidate にはオーナー配列のパスが欲しい：

```
[LOW] dedup_candidate: User::$attributes[key]: 20,099 copies x 41B — 99% identical, "email_verified_at"
```

現在のクラス修飾は `ObjectPropertiesContext` 起点のみ有効で、
`ArrayElementContext` の key/value は未修飾。

### ZendArrayMemoryLocation (56B) の dedup/expensive が無意味 [重要度: 高]

配列ヘッダ (ZendArrayMemoryLocation) は常に 56B。

dedup_candidate:
```
User::$appends: 10,000 copies x 56B SAME SIZE
User::$changes: 10,000 copies x 56B SAME SIZE
```
全部 56B なので「ALL SAME SIZE」は当然。空配列もデータ入りも 56B。無意味。

expensive_property:
```
User::$attributes: 10,000 occurrences x 56B = 0.53 MB
```
56B は配列ヘッダだけで中身のコストが見えない。

**対応案**:
1. dedup_candidate: ZendArrayMemoryLocation (56B) をスキップするか、
   テーブルサイズ (ZendArrayTableMemoryLocation) で比較する
2. expensive_property: retained ベースで配列の中身含みのコストを出す
   (PropertyScalingPass は既に retained で出しているので、こちらも合わせる)
3. あるいは配列ヘッダ + テーブル + 中身を合算した「配列全体サイズ」で比較

### companion_pair / companion_cluster に容量情報 [重要度: 中]

現在:
```
[MEDIUM] companion_pair: FormBuilder (3,611) always paired with Closure (3,619)
```

メモリコストがないので「どれだけ重要か」が分からない。
`class_objects_summary.memory_usage` から取得可能:

```
[MEDIUM] companion_pair: FormBuilder (3,611, 1.74 MB) always paired with Closure (3,619, 1.19 MB)
  Combined: 2.93 MB
```

companion_cluster も:
```
[MEDIUM] companion_cluster: 6 classes × ~1,806 instances (total: 3.84 MB):
  OptionsResolver (578 KB), Form (521 KB), EventDispatcher (156 KB), ...
```

### Findings を severity + impact 順にソート [重要度: 中]

現在は Pass 実行順で並ぶ。容量が大きい問題が埋もれることがある。

**対応案**: severity 優先、同一 severity 内で impact_bytes 降順:
```
[HIGH]   bottleneck_path: ...$messages (153.76 MB)
[HIGH]   choke_point: ...$messages (153.73 MB)
[HIGH]   dominant_type: ZendString 96.9% (150.65 MB)
[MEDIUM] expensive_property: Structure::$raw (40.21 MB)
[MEDIUM] large_array: $messages (153.74 MB retained)
[MEDIUM] cycle_cluster: 200 cycles (170 KB)
[MEDIUM] companion_cluster: 4 classes (no direct size)
[LOW]    ...
```

impact_bytes がない finding (companion_pair, cycle_cluster 等) は
severity 内の末尾に配置。

### shared_fanin の位置づけ見直し [重要度: 中]

shared_fanin は「1 つのプロパティが複数ターゲットを指し、各ターゲットが
複数箇所から参照される」パターンを報告するが、ユーザーにとって：

- 循環参照なら → cycle_cluster が既に報告している（重複）
- 正常な共有なら → shared_singleton で報告（重複）
- 中間的なら → 「だからどうすべき？」が不明（アクションなし）

**対応案**:
1. cycle_cluster で検出された link_name は shared_fanin から除外
2. 残りは severity を LOW に下げるか Additional Info に移す
3. あるいは shared_fanin を「cycle の証拠」「共有の証拠」に分類して
   他の finding の補足情報として統合する

↑ の補足: shared_fanin は循環参照の証拠ではなく「多対一の参照」の事実。
info に格下げして Additional Info セクションに移すのが妥当。
retained size や blame allocation の補足データとしての意味はある。

### companion_pair は companion_cluster に統合可能 [重要度: 低]

companion_pair は companion_cluster の size=2 の特殊ケース。
companion_cluster があれば pair を別 finding type にする意味がない。

Symfony Forms:
- companion_pair: FormBuilder (3,611) ↔ Closure (3,619)
- companion_cluster: 6 classes × ~1,806

cluster に統合して、size=2 のときは pair 風の表示にすれば十分:
```
[MEDIUM] companion_cluster: FormBuilder (3,611) ↔ Closure (3,619) — 2.93 MB
[MEDIUM] companion_cluster: 6 classes × ~1,806 instances (3.84 MB): ...
```

### reli 自身の memory_limit を CLI オプションで指定 [重要度: 中]

現在は `php -d memory_limit=2G reli ...` と書く必要がある。
Docker 経由だと entrypoint の都合で `-d` を渡しにくい。

```
# 理想
reli inspector:memory:report foo.sqlite3 --memory-limit=2G
```

実装: `ini_set('memory_limit', $value)` をコマンド実行の冒頭で呼ぶだけ。
全コマンド共通のオプションとして追加するか、report/analyze 系のみに追加。

### Overview にキャプチャ日時 [重要度: 低]

`runs` テーブルにタイムスタンプが既にある。Overview に表示:

```
=== Overview ===
  Captured: 2026-03-28T17:58:56Z
  Heap: 38.47 MB (97.7% analyzed), ...
```

dump → analyze の流れや watch の自動 dump で「いつのスナップショットか」が分かる。

### cycle_cluster に循環パス（back-reference）表示 [重要度: 高]

現在はクラス構成だけ。どの参照が循環を作っているか分からない。

SCC 内の non-tree edge を調べれば back-reference が分かる（検証済み）：

```
[MEDIUM] cycle_cluster: 200 identical cycles
  Per cycle: 3× Attachment + 1× AttachmentCollection + 1× Message
  Back-reference: Attachment::$oMessage → Message (creates cycle)
```

「Attachment::$oMessage を切れば 200 サイクル全部解消」とアクショナブルに。

SQL:
```sql
SELECT e.link_name, cnl_obj.class_name, cnl_target.class_name
FROM context_edges e
JOIN context_edges e_to_obj ON e_to_obj.child_node_id = e.parent_node_id
    AND e_to_obj.link_name = 'object_properties'
JOIN context_node_locations cnl_obj ON cnl_obj.node_id = e_to_obj.parent_node_id
LEFT JOIN context_node_locations cnl_target ON cnl_target.node_id = e.child_node_id
WHERE e.is_tree = 0 AND e.link_name = :back_ref_link_name
GROUP BY e.link_name, cnl_obj.class_name, cnl_target.class_name
```

graph substrate でも: SCC 内ノード間の non-tree edge を見つけるだけ。

↑ 補足: back-reference だけでなく、SCC への **entry point のパス** も必要。
「どこにある循環か」が分からないと、コードのどこを見ればいいか分からない。

```
[MEDIUM] cycle_cluster: 200 identical circular references
  Per cycle: 3× Attachment + 1× AttachmentCollection + 1× Message
  Back-reference: Attachment::$oMessage → Message
  Example: <main>:28::$messages[0]->attachments->items[0]
  (199 more with same pattern)
```

SCC の external incoming edge から root までのパスを PHP 構文で出す。
200 個全部ではなく代表 1 件 + 残数。

### cycle_cluster に retained size [重要度: 中]

現在の 170 KB は SCC 内オブジェクトの shallow size 合計のみ。
サイクルのせいで保持されている downstream メモリ（Message の raw body 等）
は含まれない。実際の影響は 170 KB より遥かに大きい可能性がある。

SCC を super-node に潰した condensed DAG 上で subtree sum を取れば
正確な retained size が出る。

実装:
1. SCC 内ノードの shallow sum → super-node の shallow (既にある)
2. SCC → 外部 edge の先の subtree → downstream retained
3. SCC retained = shallow + downstream

condensed DAG は SCC profiles + SCC 間 edge で構築。
post-order DFS で計算。SCC 0 個なら既存の subtree_sizes がそのまま使える。

### PDO を巻き込む循環参照の特別検知 [重要度: 中]

PDO の循環参照はメモリ問題より **リソースリーク** が深刻。
PDO はデストラクタでコネクションを閉じるが、循環参照で GC 待ちになると
コネクションが閉じられない。長寿命 worker で DB コネクション枯渇の原因。

SCC 内に PDO / PDOStatement が含まれる場合は severity を上げるか、
別の finding type (`resource_leak_risk`) として報告すべき:

```
[HIGH] resource_leak_risk: PDO connection in cycle
  SCC contains: PDO, Repository, Service (circular reference)
  Risk: DB connection not closed until GC cycle collection
  Impact: connection pool exhaustion in long-running workers
```

検出方法: PDO 自体は他オブジェクトを参照しないので SCC のメンバーにはならない。
PDO は SCC からの **外向き参照 (ext_outgoing) の先** にいる。
```
Service ↔ Repository (SCC) → Service::$pdo → PDO (downstream)
```
SCC が GC 回収 → Service 解放 → PDO refcount 減少 → PDO デストラクタ → ロールバック。

SCC の ext_outgoing edge の先のクラスに PDO / PDOStatement / Mysqli が
いるかチェック。SCC の class_composition ではなく downstream の class を見る。

PDO 以外にも: curl handle, file handle, socket など、
デストラクタで外部リソースを解放するクラスが循環に巻き込まれると同じ問題。

persistent connection (`PDO::ATTR_PERSISTENT`) の場合は特に危険:
- 循環参照で GC 待ちの PDO が、GC cycle collection でデストラクタ実行
- デストラクタがロールバックを発行
- 同じ persistent connection を使っている別のコンテキストのトランザクションが巻き添え
- GC タイミングは予測不能 → **一見ランダムにトランザクションが消える**
- 再現困難な heisenバグの原因になる

reli のスナップショットからは persistent かどうかの判定は難しい
(PDO の属性は C レベルの内部状態)。SCC に PDO がいることを検知したら
warning テキストで persistent のリスクに言及するのが現実的。
