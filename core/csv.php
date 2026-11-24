<?php
declare(strict_types=1);

function write_spreadsheet_csv_row($stream, array $cells): void
{
    foreach ($cells as &$cell) {
        if (is_string($cell) && preg_match('/^[\p{Z}\p{Cf}\x00-\x20]*[=+@\-\t\r\n]/u', $cell)) {
            $cell = "'" . $cell;
        }
    }
    unset($cell);
    fputcsv($stream, $cells, ',', '"', '');
}
