# Memory Report Implementation Handoff

**Audience**: the next (implementation) session. Delete this file once
the fixes land — it's transient directive content, not reference docs.

**Companion reading**: `docs/internals/memory-report-ux-improvements.md`
in the same commit history. That's the *why* document with 25 real
reports of evidence; this file is the *what to build, in what order,
with what gotchas* document.

## What this branch is

`claude/improve-memory-report-NKJ41` contains **no code changes** yet —
only the `docs/internals/memory-report-ux-improvements.md` investigation.
The investigation ran `inspector:memory:report` against 25 real-world
workloads (WordPress bootstrap, PhpSpreadsheet, Doctrine-like UoW,
Laravel queue envelopes, Closure captures, Eloquent hydration, PDO
fetchAll, etc.) and identified concrete bugs + presentation issues
each backed by a specific captured report.

Each claim carries a code line reference (e.g.,
`DrillDownPass.php:110`) and a "primary evidence report" name
(e.g., `rw2_json-decode-huge.report.txt`).

## Priority ordering

From the `N16` table at the bottom of the UX doc, ranked by
"how many of the 25 reports does this fix help" × "how wrong does
it look to a reader":

**Tier 1 — fix once, helps most reports, small surface.**
- **B1** (class/method names lower-cased in path labels) — 19+/25
- **B3** (preview newline escape in Top Strings) — 2/25 but table
  layout breaks visibly when it hits
- **N1** (`empty_object` should filter internal classes) — 9/25

**Tier 2 — correctness fixes, biggest "numbers look wrong".**
- **B8/B9** (bottleneck_path leaf path + root size mismatch) — 25/25
- **B2/B6/N10** (`retained × count` inflation; dedup heterogeneous
  bucket) — 4/25 but produces "722 MB impact on 11 MB heap" nonsense

**Tier 3 — narrative improvements for noisy reports.**
- **S12** (cluster findings by target across detector kinds)
- **N2** (positive-signal findings labelled as warnings)
- **N4** (collapse uniform sibling rows in Top Arrays / Top Strings)

Ship T1 first, T2 second, T3 third. T1 alone probably makes ~20/25
reports noticeably cleaner, with almost no risk.

## Artifact reuse for verification

All 25 dump/db pairs are saved at:

    /tmp/memreport-out/
    ├── rw_{phpunit,symfony-console,twig,parsedown,logger-stack,laravel-collections,psr-7-stack}.{rmem,db,report.txt}
    ├── rw2_{json-decode-huge,csv-mega,xml-dom-huge,error-context-capture,eloquent-hydration,guzzle-buffered,spreadsheet-xlsx}.{rmem,db,report.txt}
    ├── rw3_{doctrine-uow,static-cache,graphql-shape,reflection-heavy,messenger-envelopes,closure-leak}.{rmem,db,report.txt}
    └── rw4_{wordpress-bootstrap,generator-leak,graph-recursion,enum-collections,pdo-result-hoarding}.{rmem,db,report.txt}

**After a fix, the full regeneration is**:

    ./reli inspector:memory:report <path>.db --output-format=report \
      --output=<path>.report.new.txt --memory-limit=4G

No dump, no analyze, no docker — the .db is the cached post-analyze
state, and `inspector:memory:report` re-reads it cheaply (seconds).

So a verification loop for any report-level fix is:

1. Rerun `inspector:memory:report` against all 25 .db files in
   parallel.
2. `diff -u /tmp/memreport-out/rw*.report.txt
            /tmp/memreport-out/rw*.report.new.txt`
3. Check the diff matches the expected changes for the shipped tier.

For B1 (collector-side) the .rmem dumps would need to be
re-analyzed into fresh .db files (`inspector:memory:analyze`),
because the labels live in the substrate. That path needs docker
and takes minutes per scenario.

Schema of this handoff:
- **§Tier 1 implementation notes** — B1, B3, N1
- **§Tier 2 implementation notes** — B8/B9, B2/B6/N10
- **§Tier 3 implementation notes** — S12, N2, N4
- **§Verification checklist** — what to grep for after each fix
- **§Things to avoid** — walk-backs collected in the investigation
  session that the implementation session should not re-make
