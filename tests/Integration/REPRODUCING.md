# Reproducing the SQLite Analysis Databases

Each test script in `tests/Integration/*/` is self-contained. To regenerate the
SQLite databases used in the analysis, follow these steps for each target.

## Prerequisites

```bash
# Install reli-prof dependencies (if not done)
cd /path/to/reli-prof
composer install

# Install each test's dependencies
for dir in tests/Integration/*/; do
    if [ -f "$dir/composer.json" ]; then
        (cd "$dir" && composer install)
    fi
done
```

## Recipes

### General Pattern

Every test follows the same pattern:

```bash
# Terminal 1: Start target process (stays alive for analysis)
php -d memory_limit=512M tests/Integration/<Target>/simulate_memory_*.php < /dev/null &

# Wait for "READY_FOR_ANALYSIS" in output, note the PID

# Terminal 2: Run reli-prof (while target is still alive)
php -d memory_limit=2G reli inspector:memory -p <PID> \
    --output-format=sqlite3 --output=/tmp/reli_<target>.sqlite3
```

### Per-Target Commands

#### PrinsFrank/pdfparser (CrossReference PREV chain)
```bash
cd tests/Integration/PdfParserMemoryAnalysis && composer install
cd ../../..
php -d memory_limit=512M tests/Integration/PdfParserMemoryAnalysis/run_analysis.php
# This script handles everything automatically including reli-prof invocation
```

#### Webklex/php-imap (circular references)
```bash
cd tests/Integration/PhpImapMemoryAnalysis && composer install && cd ../../..
php -d memory_limit=512M tests/Integration/PhpImapMemoryAnalysis/simulate_memory_leak.php < /dev/null &
# Wait for READY_FOR_ANALYSIS, get PID
php -d memory_limit=2G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_imap.sqlite3
```

#### smalot/pdfparser (Font tables)
```bash
cd tests/Integration/SmalotPdfParserMemoryAnalysis && composer install && cd ../../..
php tests/Integration/SmalotPdfParserMemoryAnalysis/generate_font_pdf.php
php -d memory_limit=512M tests/Integration/SmalotPdfParserMemoryAnalysis/parse_font_heavy.php < /dev/null &
php -d memory_limit=2G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_smalot.sqlite3
```

#### SimplePie (GC + circular refs)
```bash
cd tests/Integration/SimplePieMemoryAnalysis && composer install && cd ../../..
php -d memory_limit=512M tests/Integration/SimplePieMemoryAnalysis/simulate_memory_leak.php < /dev/null &
php -d memory_limit=2G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_simplepie.sqlite3
```

#### dompdf (large object graph)
```bash
cd tests/Integration/DompdfMemoryAnalysis && composer install && cd ../../..
php -d memory_limit=512M tests/Integration/DompdfMemoryAnalysis/simulate_memory_leak.php < /dev/null &
# NOTE: needs 4-6GB for reli due to 179K objects
php -d memory_limit=6G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_dompdf.sqlite3
```

#### PHPStan (trait circular reference — live attach to spinning process)
```bash
cd tests/Integration/PhpStanMemoryAnalysis && composer install && cd ../../..
# This runs forever (infinite recursion) — attach reli while it spins
php -d memory_limit=128M tests/Integration/PhpStanMemoryAnalysis/run_analysis.php &
sleep 20  # let it build up ReflectionClass objects
php -d memory_limit=2G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_phpstan.sqlite3
kill $PID  # stop the infinite loop after analysis
```

#### PhpSpreadsheet (Cell + IgnoredErrors)
```bash
cd tests/Integration/PhpSpreadsheetMemoryAnalysis && composer install && cd ../../..
php -d memory_limit=512M tests/Integration/PhpSpreadsheetMemoryAnalysis/simulate_memory_leak.php < /dev/null &
# NOTE: JSON output is 424MB. Use SQLite or increase reli memory.
php -d memory_limit=4G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_spreadsheet.sqlite3
```

#### CommonMark (DotAccessData per-node)
```bash
cd tests/Integration/CommonMarkMemoryAnalysis && composer install && cd ../../..
php -d memory_limit=512M tests/Integration/CommonMarkMemoryAnalysis/simulate_memory_usage.php < /dev/null &
# NOTE: JSON OOMs at 2GB (544K objects). Use SQLite.
php -d memory_limit=4G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_commonmark.sqlite3
```

#### nikic/PHP-Parser (Token objects)
```bash
cd tests/Integration/PhpParserMemoryAnalysis && composer install && cd ../../..
# Small version (30 classes, works with JSON too):
php -d memory_limit=512M tests/Integration/PhpParserMemoryAnalysis/simulate_memory_usage.php < /dev/null &
php -d memory_limit=2G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_phpparser.sqlite3

# Large version (200 classes, 185K AST nodes — needs 6GB for reli):
# Edit simulate_memory_usage.php: set $numClasses=200, $methodsPerClass=20
php -d memory_limit=512M tests/Integration/PhpParserMemoryAnalysis/simulate_memory_usage.php < /dev/null &
php -d memory_limit=6G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_phpparser_big.sqlite3
```

#### Twig (compiled bytecode)
```bash
cd tests/Integration/TwigMemoryAnalysis && composer install && cd ../../..
php -d memory_limit=512M tests/Integration/TwigMemoryAnalysis/simulate_memory_usage.php < /dev/null &
php -d memory_limit=2G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_twig.sqlite3
```

#### mpdf (font cache GC)
```bash
cd tests/Integration/MpdfMemoryAnalysis && composer install && cd ../../..
php -d memory_limit=512M tests/Integration/MpdfMemoryAnalysis/simulate_memory_leak.php < /dev/null &
php -d memory_limit=2G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_mpdf.sqlite3
```

#### Monolog BufferHandler
```bash
# Uses reli-prof's own monolog dependency (no separate composer install needed)
php -d memory_limit=512M tests/Integration/MonologMemoryAnalysis/simulate_memory_usage.php < /dev/null &
php -d memory_limit=2G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_monolog.sqlite3
```

#### Symfony Forms / OptionsResolver
```bash
cd tests/Integration/SymfonyFormsMemoryAnalysis && composer install && cd ../../..
php -d memory_limit=512M tests/Integration/SymfonyFormsMemoryAnalysis/simulate_memory_usage.php < /dev/null &
php -d memory_limit=4G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_symforms.sqlite3
```

#### Doctrine DBAL QueryBuilder
```bash
cd tests/Integration/DoctrineDbalMemoryAnalysis && composer install && cd ../../..
php -d memory_limit=512M tests/Integration/DoctrineDbalMemoryAnalysis/simulate_memory_usage.php < /dev/null &
php -d memory_limit=2G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_dbal.sqlite3
```

#### GraphQL-PHP
```bash
cd tests/Integration/GraphqlPhpMemoryAnalysis && composer install && cd ../../..
php -d memory_limit=512M tests/Integration/GraphqlPhpMemoryAnalysis/simulate_memory_usage.php < /dev/null &
php -d memory_limit=2G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_graphql.sqlite3
```

#### PHPUnit
```bash
# Uses reli-prof's own PHPUnit dependency
php -d memory_limit=512M tests/Integration/PhpUnitMemoryAnalysis/simulate_memory_usage.php &
# PID is in stderr. Wait for READY_FOR_ANALYSIS
php -d memory_limit=2G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_phpunit.sqlite3
```

#### PHP-DI Container
```bash
# Uses reli-prof's own PHP-DI dependency
php -d memory_limit=512M tests/Integration/PhpDiMemoryAnalysis/simulate_memory_usage.php < /dev/null &
php -d memory_limit=2G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_phpdi.sqlite3
```

#### Intervention Image (clean — no leak)
```bash
cd tests/Integration/InterventionImageMemoryAnalysis && composer install && cd ../../..
php -d memory_limit=256M tests/Integration/InterventionImageMemoryAnalysis/simulate_memory_usage.php < /dev/null &
php -d memory_limit=2G reli inspector:memory -p $PID \
    --output-format=sqlite3 --output=/tmp/reli_intervention.sqlite3
```

#### Self-Diagnosing OOM
```bash
cd tests/Integration/DompdfMemoryAnalysis && composer install && cd ../../..
# This one triggers OOM and runs reli-prof automatically in shutdown function
php tests/Integration/SelfDiagnosingOom/self_diagnosing_oom.php
# Output goes to tests/Integration/SelfDiagnosingOom/oom_analysis_*.json
```

## Useful Queries After Acquisition

See `docs/design-auto-analysis-report.md` for the full query catalog, but the
most important ones:

```bash
# Top classes
sqlite3 -header -column foo.sqlite3 \
    "SELECT class_name, count, round(memory_usage/1024.0,2) as kb
     FROM class_objects_summary ORDER BY memory_usage DESC LIMIT 15;"

# Top arrays with 3-hop path
sqlite3 -header foo.sqlite3 \
    "SELECT round(a.total_size/1024.0,2) as kb, a.element_count,
            e1.link_name, e2.link_name, e3.link_name, e4.link_name
     FROM v_arrays a
     LEFT JOIN context_edges e1 ON e1.child_node_id=a.node_id AND e1.is_tree=1
     LEFT JOIN context_edges e2 ON e2.child_node_id=e1.parent_node_id AND e2.is_tree=1
     LEFT JOIN context_edges e3 ON e3.child_node_id=e2.parent_node_id AND e3.is_tree=1
     LEFT JOIN context_edges e4 ON e4.child_node_id=e3.parent_node_id AND e4.is_tree=1
     ORDER BY a.total_size DESC LIMIT 10;"

# Circular references
sqlite3 -header foo.sqlite3 \
    "SELECT link_name, count(*) as cnt
     FROM context_edges WHERE is_tree=0
     GROUP BY link_name HAVING cnt > 10
     ORDER BY cnt DESC LIMIT 10;"

# Non-tree edge pattern classification
sqlite3 -header foo.sqlite3 \
    "SELECT e.link_name, count(*) as refs,
            count(DISTINCT e.child_node_id) as targets,
            CASE WHEN count(DISTINCT e.child_node_id)=1 THEN 'SINGLETON'
                 WHEN cast(count(*) as real)/count(DISTINCT e.child_node_id)>2.0
                      THEN 'FAN-IN'
                 ELSE 'SCATTERED' END as pattern
     FROM context_edges e
     JOIN context_nodes cn ON cn.node_id=e.parent_node_id
     WHERE e.is_tree=0 AND cn.type='ObjectPropertiesContext'
       AND e.link_name NOT IN ('name','key','value','object_handlers')
     GROUP BY e.link_name HAVING count(*)>50
     ORDER BY refs DESC LIMIT 15;"
```

## Notes

- All scripts require `sudo` or `CAP_SYS_PTRACE` for reli-prof to attach
- Target processes use `< /dev/null` and stdin-based keepalive to stay running
- For large targets (dompdf, PHP-Parser big, CommonMark), reli-prof itself
  needs significant memory (4-6GB) due to context tree construction
- JSON output OOMs for targets > ~100K objects; use SQLite instead
- Results are deterministic for the same target state, so regenerated
  databases will have very similar content (exact addresses will differ)
