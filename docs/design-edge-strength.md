# 改善案: Edge Strength (参照の強弱の区別)

## 背景

reli の context tree は全ての参照を同じ edge として扱っている。
しかし PHP の参照には refcount を増やすもの（strong）と増やさないもの（weak/structural）
がある。区別できると retained size, SCC, blame allocation の精度が上がる。

## PHP の参照と refcount の関係

### refcounted な型
- `zend_string` (interned 以外)
- `zend_array`
- `zend_object`
- `zend_resource`
- `zend_reference` (PHP の `&`)

### refcounted でない型
- `int`, `float`, `bool`, `null` — zval にインライン
- interned string — IS_INTERNED フラグで refcount 操作スキップ
- immutable array — opcache が作る共有配列

### refcount を増やさない参照
- `objects_store` → object: ハンドルテーブル、所有権ではない
- `$this` (同一オブジェクトのメソッドチェーン内): VM 最適化
- `object_handlers`: クラス共有、個別オブジェクトと無関係
- WeakReference / WeakMap key: 明示的弱参照
- interned string への参照: IS_INTERNED で refcount 固定
- class_entry への参照: 内部構造

## 設計

### Edge Strength の分類

| Strength | 意味 | retained に寄与 |
|---|---|---|
| `strong` | refcount を増やす通常の参照 | ✅ |
| `weak` | refcount を増やさない参照 (objects_store, this 等) | ❌ |
| `structural` | PHP VM の内部構造 (object_handlers, class_entry) | ❌ |

### 実装方針: Context 側で区別

Context の `getLinks()` が各リンクの strength を返す:

```php
// 案
interface ReferenceContext {
    /** @return iterable<string, array{context: ReferenceContext, strength: EdgeStrength}> */
    public function getLinks(): iterable;
}

enum EdgeStrength: string {
    case Strong = 'strong';
    case Weak = 'weak';
    case Structural = 'structural';
}
```

ContextAnalyzer が getLinks() で strength を受け取り、Sink に渡す:

```php
interface ContextTreeSinkInterface {
    public function emitNode(
        int $node_id,
        ?int $parent_node_id,
        string $link_name,
        string $type,
        array $locations,
        array $attributes,
        string $edge_strength = 'strong',  // 追加
    ): void;
}
```

### Context ごとの strength 定義

| Context | link_name | strength | 理由 |
|---|---|---|---|
| ObjectContext | object_properties | strong | プロパティ参照 |
| ObjectContext | object_handlers | structural | クラス共有 |
| ObjectContext | closure | strong | Closure の中身 |
| ObjectContext | dynamic_properties | strong | 動的プロパティ |
| ObjectsStoreContext | (全子) | weak | refcount 増やさない |
| CallFrameContext | local_variables | strong | ローカル変数 |
| CallFrameContext | this | **条件付き** | 同一チェーンなら weak |
| ArrayHeaderContext | array_elements | strong | 配列がテーブルを所有 |
| ArrayElementContext | key | strong | 要素のキー |
| ArrayElementContext | value | strong | 要素の値 |
| GlobalVariablesContext | (全子) | strong | グローバル変数 |
| FiberContext | call_frames | strong | Fiber のスタック |
| GeneratorContext | call_frames | strong | Generator のスタック |
| StringContext | — | strong | 文字列値 |
| ClassDefinitionContext | class_entry | structural | クラス定義 |

### `this` の条件判定

```
call_frames → frame[0] (Foo::bar) → this → Foo#1  ← weak (同一オブジェクト)
call_frames → frame[1] (Foo::baz) → this → Foo#1  ← weak (同一オブジェクト)
call_frames → frame[2] (main)     → $obj → Foo#1  ← strong (外部からの呼び出し)
```

call stack 上の複数フレームの `this` が同じ node_id を指している場合、
最初の呼び出し元（一番深いフレーム）以外は weak。

MemoryLocationsCollector で callframe を辿る際に、前フレームの this と
同じオブジェクトかどうかを判定して strength を設定。

### DB スキーマ

```sql
ALTER TABLE context_edges ADD COLUMN strength TEXT NOT NULL DEFAULT 'strong';
-- 値: 'strong', 'weak', 'structural'

CREATE INDEX idx_edges_strength ON context_edges(run_id, strength);
```

### JSON 表現

```json
{
  "#node_id": 123,
  "#type": "ObjectContext",
  "object_properties": {
    "#node_id": 124,
    "#edge_strength": "strong",
    "..."
  },
  "object_handlers": {
    "#reference_node_id": 125,
    "#edge_strength": "structural"
  }
}
```

## 影響を受ける解析

### Retained size
strong edge のみで subtree sum を計算:
```sql
-- 今: 全 edge で計算
WHERE is_tree = 1
-- 改善後: strong edge のみ
WHERE is_tree = 1 AND strength = 'strong'
```

### SCC (循環参照検出)
strong edge のみで Tarjan を実行。weak/structural edge は循環を形成しない
（GC が無視する）。SCC の精度が上がる。

### Blame allocation
weak edge (objects_store) 経由の blame を除外。
Eloquent で objects_store に 8.2% の blame が付いていた問題が解消。

### レポートのノイズ
object_handlers の shared_fanin/dedup_candidate は strength = structural で
自動フィルタ。手動の link_name フィルタリストが不要に。

## 実装の段階

### Phase 1: 固定 strength (大半のケース)
Context クラスごとに link_name → strength のマッピングを定義。
ObjectContext なら `object_handlers → structural`、
ObjectsStoreContext なら全子 → weak。

ほとんどのケースはこれで対応可能。

### Phase 2: 条件付き strength (this のみ)
CallFrameContext の `this` を同一オブジェクトチェーン判定で weak/strong 切り替え。

### Phase 3: PDO 循環参照リスク検知
strong edge のみの SCC + その downstream に PDO がいるかチェック。
```
SCC (strong edges only) → ext_outgoing (strong) → PDO
→ [HIGH] resource_leak_risk: PDO downstream of circular reference
```

persistent connection の場合、GC がサイクル回収時に PDO デストラクタが
ロールバックを発行し、同じ persistent connection を使う別コンテキストの
トランザクションが巻き添えになる。GC タイミングは予測不能で、
一見ランダムなトランザクション消失として現れる再現困難なバグ。
