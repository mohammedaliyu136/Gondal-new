<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The shape every report returns, and the one way it becomes a file.
 *
 * §15.5 deferred reporting and NG-7 deferred BI, which left a real hole: the
 * documented workaround for the missing reports was that the work "happens in a
 * spreadsheet today", and there was no export anywhere in the system, so it
 * could not. A report nobody can take away is a report nobody can use in a
 * meeting.
 */
final class Report
{
    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public static function of(array $columns, array $rows, array $totals = []): array
    {
        return ['columns' => $columns, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * Streams a report as CSV.
     *
     * Streamed rather than built in memory because a year of deliveries is not a
     * string anybody should hold, and NFR-2's objection to unbounded collections
     * applies to the response as much as to the query.
     *
     * @param  array{columns: array<int, string>, rows: array<int, array<string, mixed>>, totals: array<string, mixed>}  $report
     */
    public static function csv(array $report, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report): void {
            $handle = fopen('php://output', 'wb');

            /*
             * Excel reads a CSV as the system's legacy codepage unless the file
             * says otherwise, and every Nigerian place name with a diacritic —
             * and the ₦ in a money column — arrives mangled. The BOM is what
             * makes a double-click open it correctly, which is the only way this
             * file will ever actually be opened.
             */
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $report['columns']);

            foreach ($report['rows'] as $row) {
                fputcsv($handle, array_map(
                    static fn (string $column) => $row[$column] ?? '',
                    $report['columns'],
                ));
            }

            if ($report['totals'] !== []) {
                fputcsv($handle, []);
                fputcsv($handle, array_map(
                    static fn (string $column) => $column === $report['columns'][0]
                        ? 'Total'
                        : ($report['totals'][$column] ?? ''),
                    $report['columns'],
                ));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
