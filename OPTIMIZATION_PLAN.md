# memory:analyze 最適化計画

## 進捗更新 (2026-04-15)

元のプランに対する現在の進捗は次の通り。

- 完了
  - 優先度1: `DumpFileMemoryReader::read` の高速化
  - 優先度3: `FfiHashTable::findByHash` の Generator 除去
- 部分完了
  - 優先度2: `CachingDereferencer` はキャッシュサイズ拡大のみ採用。aggressive な eviction 改善は回帰のため撤回
  - 優先度5: 短い文字列のインライン化は未実施だが、短い文字列向け `hotExactCache` は導入済み
- 未着手
  - 優先度4: `MemoryLocations` の index invalidation 抑制
- 計画外で実施
  - binary path の `RegionBoundaries` 配線修正と `backfill` 依存の縮小
  - binary summary 計算前の `flush()` 漏れ修正
  - `dedup_candidate` の別 pass 化
  - `dynamic_properties_overhead` の structural cost 化
  - FFI subview の root owner 伝播
  - collect 中の context pool drain によるメモリリーク修正

## プロファイル概要

- トレースファイル: `analyze6.rbt`
- 185,791 サンプル, サンプリング周期 ~10ms, 壁時計 ~1877秒 (~31分)
- プロファイル対象: `memory:analyze` コマンド（メモリダンプ解析）
- 処理の 87% は `MemoryLocationsCollector::collectAll` (ジョブキュー駆動メインループ)

## トレース実行方法

```bash
php reli rbt:analyze --top 30 < analyze6.rbt
php reli rbt:analyze --top 0 --callers 'CachingDereferencer::deref' < analyze6.rbt
php reli rbt:analyze --top 0 --callees 'MemoryLocationsCollector::collectAll' --no-line < analyze6.rbt
```

---

## 優先度1: DumpFileMemoryReader::read — O(n) リージョン線形探索

**状況**: 完了

- `read()` の高速化は実装済み
- 現在は dump 互換性のため、
  - 非重複リージョン: 二分探索 fast path
  - 重複リージョンあり: 旧来の線形探索 fallback
- 関連コミット
  - `0e50ecaf` `Optimize dump reads and string interning`
  - `d537d3d6` `Fix dump region lookup for overlapping ranges`
  - `c86c67e0` `Fix dump reader compatibility for overlapping regions`

- **ファイル**: `src/Inspector/MemoryDump/DumpFileMemoryReader.php`
- **コスト**: ~6.4% self, 15.3% inclusive
- **問題**: `read()` の line 76 で `$this->region_index` を毎回 `foreach` で線形探索
- **呼び出し元**: `RemoteProcessDereferencer::deref` → `CachingDereferencer::deref` 経由

### 修正内容

コンストラクタで `region_index` をアドレス昇順ソートし、`read()` をバイナリサーチに置換。

```php
// コンストラクタに追加
usort($this->region_index, fn($a, $b) => $a['address'] <=> $b['address']);

// read() の foreach を以下に置換:
$lo = 0;
$hi = count($this->region_index) - 1;
while ($lo <= $hi) {
    $mid = ($lo + $hi) >> 1;
    $region = $this->region_index[$mid];
    $region_start = $region['address'];
    $region_end = $region_start + $region['size'];
    if ($remote_address >= $region_end) {
        $lo = $mid + 1;
    } elseif ($remote_address + $size <= $region_start) {
        $hi = $mid - 1;
    } else {
        // ヒット — 既存の fseek/fread/FFI::memcpy ロジックをそのまま使う
        $offset_in_region = $remote_address - $region_start;
        $file_offset = $region['file_offset'] + $offset_in_region;
        // ... (line 83-101 の既存コード)
        break;
    }
}
// ループ抜けたら fallback (line 106〜) へ
```

**注意**: `$remote_address + $size <= $region_end` のバウンダリチェックを忘れずに。

---

## 優先度2: CachingDereferencer — キャッシュサイズ拡大 + evict 改善

**状況**: 部分完了

- `max_entries` は `4096 -> 65536` に拡大済み
- `evictQuarter()` の全クリア化は実データで dangling-subview 系の回帰を起こしたため撤回
- 現在は「サイズ拡大のみ採用、eviction は従来の quarter-evict」
- 関連コミット
  - `08456cd2` `Increase dereference cache size conservatively`
  - `44c71131` `Restore conservative dereference cache eviction`

- **ファイル**: `src/Lib/Process/Pointer/CachingDereferencer.php`
- **コスト**: 7.8% self (deref 4.1% + evictQuarter 3.9%), 18.2% inclusive
- **最大呼び出し元**: `ZendArray::getBucketIterator` (13.2%)

### 修正内容

1. **キャッシュサイズを 4096 → 65536 以上に変更** (line 43)
   - オフライン処理なのでメモリは潤沢に使える
   - evict 頻度が劇的に下がる

2. **evictQuarter() の改善** (line 77-92)
   - 現状: 全タイプバケット (~30個) を走査して `array_slice()` で 25% を削除
   - 案A (簡単): evict 時に `$this->cache = []; $this->count = 0;` で全クリア。LRU の精度より evict コスト削減を優先
   - 案B (中程度): タイプ別にサイズカウンタを持ち、最大バケットのみ evict

```php
// 案A: 最も簡単
private function evictQuarter(): void
{
    $this->cache = [];
    $this->count = 0;
}

// 案B: タイプ別サイズ追跡
/** @var array<class-string, int> */
private array $type_counts = [];

public function deref(Pointer $pointer): mixed
{
    // ...
    $this->type_counts[$type] = ($this->type_counts[$type] ?? 0) + 1;
    // ...
}

private function evictQuarter(): void
{
    // 最大バケットだけ evict
    $max_type = null;
    $max_count = 0;
    foreach ($this->type_counts as $t => $c) {
        if ($c > $max_count) { $max_type = $t; $max_count = $c; }
    }
    if ($max_type !== null) {
        $drop = (int)($max_count / 2);
        $this->cache[$max_type] = array_slice($this->cache[$max_type], $drop, preserve_keys: true);
        $this->type_counts[$max_type] -= $drop;
        $this->count -= $drop;
    }
}
```

---

## 優先度3: FfiHashTable::findByHash — Generator オーバーヘッド除去

**状況**: 完了

- `findByHash()` は Generator ではなく小さい配列を返す実装に変更済み
- あわせて large capture 向けに string dict offset の 64-bit 化も実施
- 関連コミット
  - `0e50ecaf` `Optimize dump reads and string interning`
  - `aa7ba179` `Fix large string dict offsets in binary analyze`

- **ファイル**: `src/Inspector/Output/MemoryOutput/BinaryFormat/FfiHashTable.php`
- **コスト**: ~5% self
- **呼び出し元**: `DiskBackedStringDict::intern` (3.8%), `findCandidates` (1.0%)

### 修正内容

`findByHash()` が Generator を返しているが、大半のケースで候補 0〜1 件。
Generator フレーム生成のオーバーヘッドが不要。

```php
// 現状 (Generator)
public function findByHash(int $hash): \Generator { ... yield ... }

// 案: 配列を返す
/** @return list<array{int, int, int}> */
public function findByHash(int $hash): array
{
    $h = self::sanitizeHash($hash);
    $slot = $h & $this->mask;
    $results = [];
    while (true) {
        $stored = (int)$this->hashes[$slot];
        if ($stored === 0) {
            return $results;
        }
        if ($stored === $h) {
            $results[] = [(int)$this->ids[$slot], (int)$this->offsets[$slot], (int)$this->lengths[$slot]];
        }
        $slot = ($slot + 1) & $this->mask;
    }
}
```

**より根本的**: 4 並列 FFI 配列を 1 パック構造体配列に統合するとキャッシュライン効率が上がる。
ただし実装コストが高いので Generator 除去だけでも ~1-2% 改善が見込める。

---

## 優先度4: MemoryLocations — インデックス無効化の抑制

**状況**: 未着手

- いまも `add()` / `addAlias()` のたびに `sorted_index = null`
- `getContainingMemoryLocation()` の backward scan も無制限のまま
- 元プランの中では唯一ほぼそのまま残っている

- **ファイル**: `src/Lib/PhpProcessReader/PhpMemoryReader/MemoryLocation/MemoryLocations.php`
- **コスト**: ~3.8% self (getContainingMemoryLocation 1.4%, add 1.3%, has 1.1%)

### 修正内容

`add()` のたびに `$this->sorted_index = null` (line 45) でインデックス無効化。
add と getContainingMemoryLocation が交互に呼ばれると毎回 usort が走る。

- **確認**: 実際に add/query が交互に起きているか。もし collect フェーズと query フェーズが分離しているなら問題は小さい
- **改善案**: sorted_index を無効化せず、新規エントリを別バッファに追加。query 時にマージ or 別バッファも探索
- **後方走査 (line 136)**: max_region_size でバウンドを設ける

```php
// 後方走査にバウンドを追加
for ($i = $result; $i >= 0; $i--) {
    // address + max_possible_size < target なら、これ以降は含みえない
    if ($index[$i]->address + $this->max_region_size < $target) {
        break;
    }
    if ($index[$i]->contains($memory_location)) {
        return $index[$i];
    }
}
```

---

## 優先度5: DiskBackedStringDict::intern — 短い文字列のインライン化

**状況**: 部分完了

- 当初案の「短い文字列の hash table 内インライン化」は未実施
- 代わりに短い文字列向けの bounded `hotExactCache` を導入済み
- 効果は限定的だが、構造変更なしで `intern()` のホットケースを軽くしている
- 関連コミット
  - `0e50ecaf` `Optimize dump reads and string interning`

- **ファイル**: `src/Inspector/Output/MemoryOutput/BinaryFormat/DiskBackedStringDict.php`
- **コスト**: ~2.2% self
- **問題**: 短い文字列でも毎回ディスク読み

### 修正内容 (効果は小)

短い文字列 (< 64 bytes) は hash テーブルにインラインで持つ。
ただし FfiHashTable の構造変更が必要になるため、キャッシュヒット率向上のほうが現実的。

---

## 根本的改善 (10x を目指す場合)

**状況**: いずれも未着手

個別最適化や binary/report 周辺の修正はかなり進んだが、この章にある
アーキテクチャ変更はまだ入っていない。

上記の個別最適化では合計 ~10-15% 程度の改善 (31分 → 26-28分)。
劇的改善には以下のアーキテクチャ変更が必要:

1. **mmap ベース**: ダンプファイルを `mmap` して FFI 経由で直接ポインタアクセス
   - fseek/fread/FFI::new/FFI::memcpy を完全除去
   - `DumpFileMemoryReader` を `MmapMemoryReader` に置換
   - PHP の `FFI::cast($type, $mmap_ptr + $offset)` でゼロコピー読み出し

2. **型別 slab アロケータ**: FFI バッファの alloc/free コストを削減
   - **背景**: CachingDereferencer のキャッシュクリア時に 65K 個の FFI
     バッファ dtor が走り、GC バーストが発生。個別の `FFI::new()` も
     呼び出し自体が高コスト
   - **案**: PHP 内部構造体はサイズ固定なので、型ごとに
     `unsigned char[ELEMENT_SIZE * SLAB_COUNT]` を事前確保し、
     `FFI::cast($type, $slab[$index])` で切り出す
     - `zval` = 16 bytes, `Bucket` = 32 bytes, `zend_array` = 56 bytes,
       `zend_object` = 56 bytes → 型ごとの固定サイズ slab
     - 空きスロット管理: index bump + フリーリスト (slab 内は同一サイズ)
     - キャッシュクリア時は PHP ラッパーだけ dtor し、slab は再利用
       (index をリセットするだけ)。FFI バッファの free が slab 数個に集約
     - `FFI::new()` 呼び出し回数も slab 確保時のみに激減
   - **可変長構造体** (`zend_string`, `zend_object` の properties table):
     固定ヘッダ部分だけ slab に置く or よく使うサイズクラス別に slab を分ける
   - **実装場所**: `DumpFileMemoryReader::read` or
     `RemoteProcessDereferencer::deref` のレベルで slab アロケータを噛ませ、
     現在の `FFIHelper::new("unsigned char[$size]")` を置換
   - **効果見込み**: FFI alloc/free コスト削減 + キャッシュクリアの
     GC バースト解消。analyze7 では evictQuarter の dtor だけで
     数%を占めていた

3. **バッチジョブ処理**: 同じ型のジョブをまとめて処理し deref キャッシュヒット率向上

4. **C 拡張**: inner loop (deref + cast + field access) を C で書く

---

## self-time Top 10 まとめ

| # | frame | self% | 対策 |
|---|-------|-------|------|
| 1 | fread | 5.7% | mmap で除去 / バイナリサーチで呼び出し回数削減 |
| 2 | fwrite | 5.3% | 出力バッファリング |
| 3 | fseek | 4.8% | mmap で除去 |
| 4 | DumpFileMemoryReader::read | 4.4% | バイナリサーチ (優先度1) |
| 5 | CachingDereferencer::deref | 4.1% | キャッシュ拡大 (優先度2) |
| 6 | CachingDereferencer::evictQuarter | 3.7% | キャッシュ拡大 + evict 改善 (優先度2) |
| 7 | FfiHashTable::findByHash | 3.3% | Generator 除去 (優先度3) |
| 8 | Zval::__get | 1.7% | FFI アクセス最適化 (難) |
| 9 | DiskBackedStringDict::intern | 1.5% | キャッシュ改善 (優先度5) |
| 10 | ArrayContextPool::getContextForLocation | 1.4% | プール最適化 |

---

## 元プラン外で進んだもの

元の `analyze6.rbt` ベースのプランには直接書いていなかったが、その後の
binary/report 実装や実データ検証の中で次の修正が入っている。

- `af336993`
  - binary sink に `RegionBoundaries` を collect 中から渡すように修正
- `811ef975`
  - binary summary 計算前の `flush()` 漏れを修正
- `00c7912e`
  - `dedup_candidate` を `NonTreeEdgePass` から分離
- `36340b3e`
  - `dynamic_properties_overhead` を shallow size ではなく structural cost ベースに改善
- `91a51710`, `27135b63`, `9b550ac0`
  - FFI subview の root owner 伝播を導入
- `7c884bea`
  - collect 中に emit 済み context を pool から drain して巨大メモリリークを修正
