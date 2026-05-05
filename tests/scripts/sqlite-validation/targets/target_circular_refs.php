<?php
// Reproduces PHPOffice/PHPExcel#583 + php-meminfo#83 + recurrent
// "circular reference" memory leak issue shape. Many objects with
// parent ↔ child cycles, sibling cross-references, and one big
// arrays-of-arrays cycle. This stresses the graph traversal in
// reli's MemoryLocationsCollector — any infinite loop or duplicate-
// emit bug should surface either in the rmem sample or in the
// SQLite output's context_edges row count.

class Cell {
    public ?Worksheet $sheet = null;
    public ?Cell $left = null;
    public ?Cell $right = null;
    public ?Cell $above = null;
    public ?Cell $below = null;
    public string $value;
    public function __construct(string $value) { $this->value = $value; }
}

class Worksheet {
    public ?Workbook $book = null;
    public array $cells = [];        // grid[row][col] -> Cell
    public ?AutoFilter $filter = null;
    public string $name;
    public function __construct(string $name) { $this->name = $name; }
}

class AutoFilter {
    public ?Worksheet $sheet = null; // back-ref creates a cycle
    public array $rules = [];
}

class Workbook {
    public array $sheets = [];
    public ?CalcEngine $engine = null;
    public ?StyleSupervisor $styles = null;
}

class CalcEngine {
    public ?Workbook $book = null;   // back-ref → cycle
    public array $cache = [];
}

class StyleSupervisor {
    public ?Workbook $book = null;   // back-ref → cycle
    public array $styles = [];
}

$sheets = (int)($argv[1] ?? 6);
$rows   = (int)($argv[2] ?? 30);
$cols   = (int)($argv[3] ?? 20);

$book = new Workbook();
$book->engine = new CalcEngine();
$book->engine->book = $book;
$book->styles = new StyleSupervisor();
$book->styles->book = $book;

for ($s = 0; $s < $sheets; $s++) {
    $sheet = new Worksheet("sheet_$s");
    $sheet->book = $book;
    $sheet->filter = new AutoFilter();
    $sheet->filter->sheet = $sheet;

    $grid = [];
    for ($r = 0; $r < $rows; $r++) {
        $row = [];
        for ($c = 0; $c < $cols; $c++) {
            $cell = new Cell("s{$s}r{$r}c{$c}");
            $cell->sheet = $sheet;
            $row[] = $cell;
        }
        $grid[] = $row;
    }
    // wire up neighbour references — every interior cell points to its
    // four neighbours, neighbours point back. This is a *very* dense
    // cycle pattern.
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            $cell = $grid[$r][$c];
            $cell->left  = $c > 0          ? $grid[$r][$c-1] : null;
            $cell->right = $c < $cols - 1  ? $grid[$r][$c+1] : null;
            $cell->above = $r > 0          ? $grid[$r-1][$c] : null;
            $cell->below = $r < $rows - 1  ? $grid[$r+1][$c] : null;
        }
    }
    $sheet->cells = $grid;
    $book->sheets[] = $sheet;
}

// Plus a "sibling" cycle: each sheet keeps an array of every other
// sheet, and CalcEngine caches a reference to every cell.
foreach ($book->sheets as $sheet) {
    foreach ($book->sheets as $other) {
        if ($other !== $sheet) {
            $sheet->filter->rules[] = $other; // sheet → AutoFilter → other Worksheet
        }
    }
    foreach ($sheet->cells as $row) {
        foreach ($row as $cell) {
            $book->engine->cache[] = $cell;
        }
    }
}

// And an array-cycle (no objects): nested arrays referencing each
// other through array references. Reli's array-graph code should
// terminate.
$a = ['next' => null];
$b = ['next' => &$a];
$a['next'] = &$b;
$cycle_root = ['a' => &$a, 'b' => &$b];

$total_cells = $sheets * $rows * $cols;

fwrite(STDERR, "READY pid=" . getmypid()
    . " sheets=$sheets cells=$total_cells engine_cache=" . count($book->engine->cache)
    . " mem=" . memory_get_usage() . "\n");
file_put_contents(getenv('RELI_TARGET_PID_FILE') ?: "/tmp/sqlite-validation/target.pid", getmypid());

while (true) { sleep(60); }
