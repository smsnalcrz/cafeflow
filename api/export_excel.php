<?php
/**
 * export_excel.php - Exports transaction records into an Excel-compatible .xlsx file.
 *
 * GET ?date=YYYY-MM-DD
 * GET ?start=YYYY-MM-DD&end=YYYY-MM-DD
 * GET ?all=1
 */
require_once __DIR__ . '/../core/db.php';

require_method('GET');

$pdo = db();
$tz = new DateTimeZone('Asia/Manila');

$dateParam  = $_GET['date']  ?? null;
$startParam = $_GET['start'] ?? null;
$endParam   = $_GET['end']   ?? null;
$allParam   = !empty($_GET['all']);

$where = '';
$params = [];
$periodLabel = '';
$filenameSuffix = '';

if ($allParam) {
    $where = 'WHERE 1=1';
    $periodLabel = 'All Available Transactions';
    $filenameSuffix = 'All_' . date('Ymd_His');
} elseif ($startParam && $endParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endParam)) {
    $where = 'WHERE `timestamp` >= :start AND `timestamp` < :end';
    $params[':start'] = $startParam . ' 00:00:00';
    $params[':end']   = (new DateTimeImmutable($endParam, $tz))->modify('+1 day')->format('Y-m-d 00:00:00');
    $periodLabel = "$startParam to $endParam";
    $filenameSuffix = "{$startParam}_to_{$endParam}";
} elseif ($dateParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $where = 'WHERE `timestamp` >= :start AND `timestamp` < :end';
    $params[':start'] = $dateParam . ' 00:00:00';
    $params[':end']   = (new DateTimeImmutable($dateParam, $tz))->modify('+1 day')->format('Y-m-d 00:00:00');
    $periodLabel = $dateParam;
    $filenameSuffix = $dateParam;
} else {
    // Default today
    $today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    $where = 'WHERE `timestamp` >= :start AND `timestamp` < :end';
    $params[':start'] = $today . ' 00:00:00';
    $params[':end']   = (new DateTimeImmutable($today, $tz))->modify('+1 day')->format('Y-m-d 00:00:00');
    $periodLabel = $today;
    $filenameSuffix = $today;
}

$st = $pdo->prepare(
    "SELECT t.transaction_id, t.total_amount, t.amount_paid, t.change_due,
            t.status, t.payment_method, t.`timestamp`,
            ti.product_id, ti.product_name, ti.quantity, ti.unit_price,
            mp.name AS current_name, mp.category AS current_category, mp.price AS current_price
       FROM transactions t
       JOIN transaction_items ti ON ti.transaction_id = t.transaction_id
       LEFT JOIN menu_products mp ON mp.product_id = ti.product_id
      $where
      ORDER BY t.`timestamp` ASC, t.transaction_id ASC, ti.id ASC"
);
$st->execute($params);
$rows = $st->fetchAll();

if (empty($rows)) {
    // Return friendly error response so client-side can display toast
    if (isset($_GET['format']) && $_GET['format'] === 'json') {
        fail(404, "No transaction records found for $periodLabel to export.");
    }
    // Also handle direct link download
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => "No transaction records found for $periodLabel to export.",
    ]);
    exit;
}

// Prepare Excel table headers and data
$headers = [
    'Transaction #',
    'Date & Time',
    'Status',
    'Payment Method',
    'Product Name',
    'Category',
    'Unit Price (₱)',
    'Quantity',
    'Line Total (₱)',
    'Order Total (₱)',
    'Amount Paid (₱)',
    'Change Due (₱)',
];

$excelRows = [];
$totalRevenue = 0;
$totalQuantity = 0;
$seenTx = [];

foreach ($rows as $r) {
    $tId = (int) $r['transaction_id'];
    $isFirstLine = !isset($seenTx[$tId]);
    $seenTx[$tId] = true;

    $dt = new DateTimeImmutable($r['timestamp'], $tz);
    $name = !empty($r['product_name']) ? $r['product_name'] : (!empty($r['current_name']) ? $r['current_name'] : 'Product #' . $r['product_id']);
    $category = !empty($r['current_category']) ? $r['current_category'] : 'General';
    $unitPrice = (float) $r['unit_price'] > 0 ? (float) $r['unit_price'] : (float) ($r['current_price'] ?? 0);
    $qty = (int) $r['quantity'];
    $lineTotal = round($unitPrice * $qty, 2);
    $orderTotal = (float) $r['total_amount'];
    $paid = $r['amount_paid'] !== null ? (float) $r['amount_paid'] : $orderTotal;
    $change = $r['change_due'] !== null ? (float) $r['change_due'] : max(0, $paid - $orderTotal);

    if ($isFirstLine) {
        $totalRevenue += $orderTotal;
    }
    $totalQuantity += $qty;

    $excelRows[] = [
        sprintf('#%05d', $tId),
        $dt->format('Y-m-d H:i:s'),
        $r['status'],
        $r['payment_method'],
        $name,
        $category,
        $unitPrice,
        $qty,
        $lineTotal,
        $orderTotal,
        $paid,
        $change,
    ];
}

// Add blank row and summary totals row
$excelRows[] = ['', '', '', '', '', '', '', '', '', '', '', ''];
$excelRows[] = [
    'TOTALS',
    count($seenTx) . ' Orders',
    '',
    '',
    count($rows) . ' Lines',
    '',
    '',
    $totalQuantity,
    $totalRevenue,
    $totalRevenue,
    '',
    '',
];

// Pure PHP XLSX Builder
if (!class_exists('ExcelWorkbookBuilder')) {
class ExcelWorkbookBuilder
{
    private array $files = [];

    public function addFile(string $path, string $content): void
    {
        $this->files[$path] = $content;
    }

    public function build(): string
    {
        $zipData = '';
        $cdData = '';
        $offset = 0;

        foreach ($this->files as $name => $content) {
            $uncompressedSize = strlen($content);
            $crc = crc32($content);
            $compressed = gzdeflate($content);
            $compressedSize = strlen($compressed);
            $method = 8;

            $dostime = (16 << 25) | (10 << 21) | (16 << 16) | (12 << 11) | (0 << 5) | 0;
            $timeLow = $dostime & 0xFFFF;
            $timeHigh = ($dostime >> 16) & 0xFFFF;

            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034b50, 20, 0, $method,
                $timeLow, $timeHigh, $crc,
                $compressedSize, $uncompressedSize,
                strlen($name), 0
            ) . $name . $compressed;

            $zipData .= $localHeader;

            $cdEntry = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50, 20, 20, 0, $method,
                $timeLow, $timeHigh, $crc,
                $compressedSize, $uncompressedSize,
                strlen($name), 0, 0, 0, 0, 0,
                $offset
            ) . $name;

            $cdData .= $cdEntry;
            $offset = strlen($zipData);
        }

        $cdOffset = strlen($zipData);
        $cdSize = strlen($cdData);
        $count = count($this->files);

        $eocd = pack(
            'VvvvvVVv',
            0x06054b50, 0, 0, $count, $count,
            $cdSize, $cdOffset, 0
        );

        return $zipData . $cdData . $eocd;
    }

    public static function generate(array $headers, array $rows, string $sheetTitle = 'Transactions'): string
    {
        $xlsx = new self();

        // 1. [Content_Types].xml
        $xlsx->addFile('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '</Types>');

        // 2. _rels/.rels
        $xlsx->addFile('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>');

        // 3. xl/_rels/workbook.xml.rels
        $xlsx->addFile('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>');

        // 4. xl/workbook.xml
        $xlsx->addFile('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets><sheet name="' . htmlspecialchars($sheetTitle, ENT_XML1, 'UTF-8') . '" sheetId="1" r:id="rId1"/></sheets>' .
            '</workbook>');

        // 5. xl/styles.xml (with espresso header style)
        $xlsx->addFile('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="3">' .
            '<font><sz val="11"/><name val="Segoe UI"/></font>' .
            '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>' .
            '<font><b/><sz val="11"/><color rgb="FF2C1A0E"/><name val="Segoe UI"/></font>' .
            '</fonts>' .
            '<fills count="4">' .
            '<fill><patternFill patternType="none"/></fill>' .
            '<fill><patternFill patternType="gray125"/></fill>' .
            '<fill><patternFill patternType="solid"><fgColor rgb="FF2C1A0E"/></patternFill></fill>' . // Dark espresso
            '<fill><patternFill patternType="solid"><fgColor rgb="FFF2EBE0"/></patternFill></fill>' . // Parchment totals
            '</fills>' .
            '<borders count="1"><border><left/><right/><top/><bottom/></border></borders>' .
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
            '<cellXfs count="3">' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' . // Normal
            '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' . // Header
            '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' . // Totals
            '</cellXfs>' .
            '</styleSheet>');

        // 6. xl/worksheets/sheet1.xml
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetData>';

        $rowIndex = 1;
        // Header Row
        $sheetXml .= '<row r="' . $rowIndex . '">';
        $colIndex = 0;
        foreach ($headers as $h) {
            $colLetter = self::colLetter($colIndex);
            $sheetXml .= '<c r="' . $colLetter . $rowIndex . '" s="1" t="inlineStr"><is><t>' . htmlspecialchars((string)$h, ENT_XML1, 'UTF-8') . '</t></is></c>';
            $colIndex++;
        }
        $sheetXml .= '</row>';
        $rowIndex++;

        // Data Rows
        $rowCount = count($rows);
        for ($i = 0; $i < $rowCount; $i++) {
            $row = $rows[$i];
            $isTotalRow = ($i === $rowCount - 1);
            $styleAttr = $isTotalRow ? ' s="2"' : '';

            $sheetXml .= '<row r="' . $rowIndex . '">';
            $colIndex = 0;
            foreach ($row as $val) {
                $colLetter = self::colLetter($colIndex);
                if (is_numeric($val) && !preg_match('/^0\d+/', (string)$val)) {
                    $sheetXml .= '<c r="' . $colLetter . $rowIndex . '"' . $styleAttr . '><v>' . (float)$val . '</v></c>';
                } else {
                    $sheetXml .= '<c r="' . $colLetter . $rowIndex . '"' . $styleAttr . ' t="inlineStr"><is><t>' . htmlspecialchars((string)($val ?? ''), ENT_XML1, 'UTF-8') . '</t></is></c>';
                }
                $colIndex++;
            }
            $sheetXml .= '</row>';
            $rowIndex++;
        }

        $sheetXml .= '</sheetData></worksheet>';
        $xlsx->addFile('xl/worksheets/sheet1.xml', $sheetXml);

        return $xlsx->build();
    }

    private static function colLetter(int $index): string
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(($index % 26) + 65) . $letter;
            $index = intdiv($index, 26) - 1;
        }
        return $letter;
    }
}
}

$xlsxData = ExcelWorkbookBuilder::generate($headers, $excelRows, 'DK Cafe Sales');

// Send download headers
$filename = 'DK_Cafe_Transactions_' . $filenameSuffix . '.xlsx';
if (!headers_sent()) {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($xlsxData));
    header('Cache-Control: max-age=0');
}
echo $xlsxData;
exit;
