<?php
/**
 * Generate a PDF with many incremental updates (deep PREV chain)
 * to reproduce the memory exhaustion issue from PrinsFrank/pdfparser#301.
 *
 * Each incremental update adds a cross-reference table with a PREV pointer
 * to the previous one, creating a chain that CrossReferenceSourceParser
 * will follow, accumulating CrossReferenceSection objects in memory.
 */

$numIncrementalUpdates = 500; // Number of incremental updates (PREV chain depth)
$objectsPerUpdate = 200;      // Number of xref entries per update

$outputFile = __DIR__ . '/test_deep_prev_chain.pdf';
$pdf = '';

// === Initial PDF structure ===
$pdf .= "%PDF-1.4\n";

// Object 1: Catalog
$obj1Offset = strlen($pdf);
$pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

// Object 2: Pages
$obj2Offset = strlen($pdf);
$pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

// Object 3: Page
$obj3Offset = strlen($pdf);
$pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n";

// Generate many dummy objects to fill xref entries
$dummyObjOffsets = [];
for ($i = 4; $i < 4 + $objectsPerUpdate; $i++) {
    $dummyObjOffsets[$i] = strlen($pdf);
    $str = str_repeat('A', 200); // Some content to make objects substantial
    $pdf .= "$i 0 obj\n<< /Type /DummyObject /Data ($str) >>\nendobj\n";
}

// === Initial cross-reference table ===
$xrefOffset = strlen($pdf);
$pdf .= "xref\n";
$pdf .= "0 " . (4 + $objectsPerUpdate) . "\n";
$pdf .= sprintf("0000000000 65535 f \n");
$pdf .= sprintf("%010d 00000 n \n", $obj1Offset);
$pdf .= sprintf("%010d 00000 n \n", $obj2Offset);
$pdf .= sprintf("%010d 00000 n \n", $obj3Offset);
for ($i = 4; $i < 4 + $objectsPerUpdate; $i++) {
    $pdf .= sprintf("%010d 00000 n \n", $dummyObjOffsets[$i]);
}

$pdf .= "trailer\n";
$pdf .= "<< /Size " . (4 + $objectsPerUpdate) . " /Root 1 0 R >>\n";
$pdf .= "startxref\n";
$pdf .= "$xrefOffset\n";
$pdf .= "%%EOF\n";

// === Incremental updates ===
$prevXrefOffset = $xrefOffset;
$nextObjNum = 4 + $objectsPerUpdate;

for ($update = 0; $update < $numIncrementalUpdates; $update++) {
    // Add new dummy objects for this update
    $updateObjOffsets = [];
    for ($i = 0; $i < $objectsPerUpdate; $i++) {
        $objNum = $nextObjNum + $i;
        $updateObjOffsets[$objNum] = strlen($pdf);
        $str = str_repeat('B', 200);
        $pdf .= "$objNum 0 obj\n<< /Type /DummyUpdate /Iteration $update /Data ($str) >>\nendobj\n";
    }

    // Cross-reference table for this update
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n";
    $pdf .= "$nextObjNum $objectsPerUpdate\n";
    for ($i = 0; $i < $objectsPerUpdate; $i++) {
        $objNum = $nextObjNum + $i;
        $pdf .= sprintf("%010d 00000 n \n", $updateObjOffsets[$objNum]);
    }

    $totalSize = $nextObjNum + $objectsPerUpdate;
    $pdf .= "trailer\n";
    $pdf .= "<< /Size $totalSize /Root 1 0 R /Prev $prevXrefOffset >>\n";
    $pdf .= "startxref\n";
    $pdf .= "$xrefOffset\n";
    $pdf .= "%%EOF\n";

    $prevXrefOffset = $xrefOffset;
    $nextObjNum += $objectsPerUpdate;
}

file_put_contents($outputFile, $pdf);
$sizeMB = round(strlen($pdf) / 1024 / 1024, 2);
echo "Generated PDF: $outputFile\n";
echo "Size: $sizeMB MB\n";
echo "Incremental updates: $numIncrementalUpdates\n";
echo "Objects per update: $objectsPerUpdate\n";
echo "Total objects: $nextObjNum\n";
echo "PREV chain depth: $numIncrementalUpdates\n";
