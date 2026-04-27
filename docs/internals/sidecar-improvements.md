# sidecar 改善案メモ

> **Status:** Working scratchpad (Japanese, similar to `future-ideas.md` style).
> 後で英語化 / セクション分割して個別 PR に切り出す前提の作業メモ。

`reli inspector:sidecar` + `reliforp/reli-prof-sidecar-client` (Packagist 経由)
の end-to-end 検証中に出てきた改善ネタを忘れる前に列挙したもの。検証日 2026-04-27。
このメモ単体では実装はしていない。

---

## A. dump パイプラインのメモリ効率 (本丸)

### 現状

`MemoryDumper.php:591-611` で全 region を `process_vm_readv` で読みつつ
`$regions_data[]` に PHP 文字列として積み、その後 `MemoryDumpWriter::write()`
で disk に流す。target 停止は `SidecarDumpHandler::doDump()` の `try/finally`
で `memory_dumper->dump()` 全体を覆っている。

実測 (1 GB の target):
- sidecar baseline RSS: ~88 MB
- dump 中 peak RSS:    ~1109 MB (= target heap + baseline)
- target 停止時間:      read + write 合計 (~9s)

つまり「sidecar RSS は target heap 相当」「target 停止は read+write 全部」の
両方を払っている。どちらの軸でも改善余地あり。

### 提案

3 モードに整理可能 (現状は実質「悪いとこ取り」):

| モード | sidecar peak RSS | target 停止時間 |
|---|---|---|
| 現状: full buffer + late resume | target 相当 | read + write |
| **A: fast-resume** (buffer all → resume → write) | target 相当 | read のみ |
| **B: streaming** (region 毎 read→write→free) | 数 MB | read + write |

`MemoryDumpWriter::writeStreaming()` (line 74-128) は既に実装済み。
`SidecarDumpHandler` から呼ばれていないだけ。

### サブタスク

- [ ] **A1**: `MemoryDumper::dump` を fast-resume にデフォルト切替
      (`SidecarDumpHandler` の `try/finally` の `resume()` を read 完了直後に移動)
      → 無条件改善、API 変更なし
- [ ] **A2**: `--dump-mode={fast-resume,low-memory}` を `inspector:sidecar` に追加
- [ ] **A3**: protocol に `mode` field 追加 (additive、protocol_version 据え置き)
      → `SidecarRequest::$mode`
      → `SidecarClient::requestDump(..., mode: 'low-memory')`
      → 古い sidecar に投げても無視される、新しい sidecar が古い client から
         field 無しで受けたら server default (= --dump-mode) を使う
- [ ] **A4**: `MemoryLimitHandler::register(... dump_mode: 'low-memory')` を生やす
      → OOM 経路で sidecar 連鎖死を予防 (target heap == sidecar memory_limit な状況)

優先度: A1 > A2 > A3 > A4。A1 単独で先に PR 切るのが安全。

---

## B. sidecar の自己防衛 (落ちない sidecar)

### 現状

target heap > sidecar `--memory-limit` の状態で dump 要求すると、
`MemoryDumper.php:603` の `\FFI::string($data, $entry['size'])` で
`Allowed memory size exhausted` の PHP Fatal を喰い、**sidecar プロセス全体が死亡**。

- target は ptrace 経由なので kernel が auto-detach、フリーズはしない
- socket file は stale で残るが、次の sidecar 起動時に `SidecarServer.php:161` で unlink される
- partial dump も無し (writer に渡る前に死ぬ)
- client は `null` 戻り、後続リクエストも ECONNREFUSED (即 null)
- supervisor (systemd / k8s) 再起動を期待する設計

死亡した瞬間の dump は永久ロスト = OOM ハンドラ経路で「まさに欲しかった」
スナップショットを取り損ねる。supervisor 再起動はバックストップとしては有効だが、
それで救えない種類の損失がある。

### 提案

`SidecarDumpHandler::doDump()` の `process_stopper->stop()` 直前で
`heap_stats_reader->read()` 済みなので、dump に必要なメモリを事前計算して
収まらなければエラー応答で早期 return する。sidecar は生きたまま。

```php
$memory_limit = self::parseIniBytes(ini_get('memory_limit'));
if ($memory_limit > 0) {
    $needed = (int)($heap_stats->size * 1.15) + 16 * 1024 * 1024;
    $available = $memory_limit - memory_get_usage(true);
    if ($needed > $available) {
        return SidecarResponse::error(sprintf(
            'dump would need ~%d MB but only %d MB available '
            . '(sidecar memory_limit=%d MB). Increase --memory-limit '
            . 'or use --dump-mode=low-memory.',
            $needed >> 20, $available >> 20, $memory_limit >> 20,
        ));
    }
}
```

効果:
- sidecar 生存
- 同居している他 client への dump サービスは継続
- client は status=error + 具体的な message を受け取る (現状: null だけ)
- crash loop が起きない

### サブタスク

- [ ] **B1**: pre-flight memory check を `SidecarDumpHandler::doDump()` 冒頭に追加
      (~10 行)
- [ ] **B2**: docs に「supervisor 必須」の運用前提と systemd unit / k8s
      restartPolicy 例を載せる

A の streaming モードが入れば B1 の制約は構造的に消えるが、それまでの間も、
入った後も「自分の限界を知っているデーモン」としてあった方が動作が分かりやすい。

---

## C. timeout 関連

### 現状

- `SidecarClient::__construct(timeout_seconds: 30)` がデフォルト
- `stream_set_timeout` は **応答 JSON 受信まで** 効く = sidecar が dump 完了を
  終えるまで client は block
- 実測 dump スループット ~110 MB/s (process_vm_readv + disk write)
  → 30 秒 default は ~3 GB ヒープ相当を捌ける、合理的な値
- ただし `MemoryLimitHandler::register()` から timeout を渡す手段が無い
  (内部で `new SidecarClient($socket_path)` するだけ)

### 提案

- [ ] **C1**: `MemoryLimitHandler::register(... timeout_seconds: int = 30)` を追加
      (内部で `new SidecarClient($socket_path, $timeout_seconds)`)
- [ ] **C2**: docs に timeout 指針表を追加
      ```
      memory_limit  → 推奨 timeout
      128 M           5  s
      256 M          10  s
      512 M          15  s
      1   G          30  s (default)
      2   G          60  s
      ```
      "デフォルトを下げるな、必要に応じて伸ばせ" と明記。
- [ ] **C3** (optional): default timeout を 60s に上げる検討。30s は ~3 GB が
      上限になり、最近の PHP-FPM ワーカーだと割と簡単に超える。

---

## D. client API の細かい改善

### 現状

`SidecarClient::send()`:
```php
$sock = @stream_socket_client(... $errno, $errstr, ...);  // @ で warning 抑制
if ($sock === false) return null;                         // errno/errstr 捨ててる
...
if ($response === false || $response === '') return null; // timeout / EOF
return SidecarClientResponse::fromJson(trim($response));   // 不正 JSON も null
```

「接続失敗」「timeout」「不正 JSON」が全部 `null` で区別できない。
`MemoryLimitHandler` の `on_error` 文言は固定文字列 `'failed to connect to reli sidecar'`。

### 提案

- [ ] **D1**: `on_error` callback の signature を拡張して errno/errstr/原因を渡す
      (例: `function(string $msg, ?int $errno, ?string $detail)`)
      → BC のため optional parameters で
- [ ] **D2** (optional): エラー種別を表す sentinel 値を導入 (例: 専用例外 or
      `SidecarClientResponse::error(string $reason)` の null 以外の表現)
      → ただし shutdown handler のメモリ予算とトレードオフあり

優先度低め。D1 だけでも運用ログの切り分けには十分役立つ。

---

## E. SidecarServer の broken pipe ノイズ

### 現状

client が timeout で socket 閉じた後、sidecar は dump を完走して JSON を
書き戻そうとするが、socket は閉じているので `fwrite()` が EPIPE を返す。
PHP は警告として
```
PHP Notice: fwrite(): Send of 761 bytes failed with errno=32 Broken pipe
            in src/Inspector/Sidecar/SidecarServer.php on line 134
```
を吐く。dump 自体は disk に完全な状態で保存されている (= orphan dump として
回収可能)。

### 提案

- [ ] **E1**: `SidecarServer.php:134` 付近の `fwrite` を `@fwrite` + 戻り値チェック
      に置き換え、`Log::info('client disconnected before reply (dump still saved at <path>)')`
      のような専用ログを出す。Notice ノイズを消しつつ orphan の発生を可視化。
- [ ] **E2**: docs に「timeout 切れても dump は `--output-dir` に保存される、
      `on_error` 後に `ls` で見つけられる」と明記。これは知らないと気付けない
      安全網。

---

## F. ドキュメント

### F1. Quick Start のソケット親ディレクトリ要件

`docs/monitoring/sidecar.md` の Quick Start は `--socket=/tmp/reli-sidecar.sock`
を例示しているが、`SocketPathResolver::assertParentSafe()` は親 dir mode 0700 を
要求するため、`/tmp` (大抵 0777) では起動失敗する。

```
[RuntimeException]
Sidecar socket parent directory /tmp has mode 0777, expected 0700.
Run: chmod 0700 '/tmp'
```

→ Quick Start を `mkdir -p /tmp/reli-run && chmod 0700 /tmp/reli-run`
   + `--socket=/tmp/reli-run/sidecar.sock` の形に修正、または default の
   `$XDG_RUNTIME_DIR/reli/sidecar.sock` を勧める例にする。

### F2. Packagist 経由インストールの明示

現在 docs は「composer require reliforp/reli-prof」 or 「3 ファイル vendoring」
の 2 択しか書いていないが、実際には `reliforp/reli-prof-sidecar-client` が
独立パッケージとして Packagist にいる (現状 `dev-main` のみ、tag 無し)。
これを推奨経路として書くべき:

```bash
composer require reliforp/reli-prof-sidecar-client:dev-main
```

`minimum-stability: dev` 必須。tag 付け方針も整理したい (semver: 0.1.0 から?)。

### F3. 運用前提の 1 セクション

- `--memory-limit` のサイジング (= max(全 target memory_limit) + 100 MB 程度)
- supervisor (systemd unit example, k8s restartPolicy: Always)
- timeout 指針表 (C2 と統合)
- orphan dump の存在と回収方法 (E2)
- streaming モードを使う場合の sizing (将来)

---

## G. テスト / CI

### G1. End-to-end smoke test

`tests/e2e/sidecar-client/` に:
1. `composer.json` (path repo で `../../reli-prof-sidecar-client` を参照、
   または Packagist の dev-main を pin)
2. `bootstrap.php` + `bench.php` 相当の fixture
3. PHPUnit 1 ケース: `dockerd` 起動 → sidecar background 起動 → fixture 実行
   → dump 出力を assert

これがあると downgrade 後の API ミス (PHP 7.0 で named arg 使ってる等) や
protocol_version の食い違いを PR 単位で拾える。

### G2. bench/sidecar-roundtrip.php

repo に最小デモを同梱。今回 `/home/user/demo-app/{bench,oom,timing,rss_watch}.php`
で書き散らしたものを整理して `bench/` 配下に置く。`docker compose up` 一発で
誰でも再現可能な状態にする。

---

## まとめ: 推奨 PR 順序

1. **A1** (fast-resume default) — 無条件改善、独立 PR。
2. **B1** (pre-flight memory check) — 独立、~10 行。`A1` の前後どちらでもよい。
3. **F1, E2 ドキュメント修正** — F2 と合わせて 1 PR。
4. **A2 + A3 + A4** (dump-mode 切替) — protocol 変更含むので慎重に。
5. **C1, C2** — `MemoryLimitHandler` 引数追加と docs。
6. **E1** (broken pipe ログ整理) — 独立、小。
7. **D1** (on_error 拡張) — 後回し可。
8. **G1, G2** (E2E テストとデモ) — 上が落ち着いてから。

---

## 検証時のメモ (将来の参考用)

- ホスト PHP 8.4 / reli は ^8.5 require → composer install --ignore-platform-req=php
  で動かせる (今回もそうした)。
- Packagist の `dev-main` はちゃんと最新コミット (423ee89) まで追従していた。
  GitHub→Packagist webhook 設定済み。
- `/tmp/reli-dumps/` は `--disk-usage-limit=1G` で自動 rotate される。
  検証で 1.4 GB 残してしまった時は手で `rm -rf` して掃除した。
- demo-app の検証用スクリプトは `/home/user/demo-app/` に残置。整理して
  G2 のベースに使える。
