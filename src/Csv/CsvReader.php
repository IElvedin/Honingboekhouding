<?php

declare(strict_types=1);

namespace App\Csv;

/**
 * Detects which delimiter a CSV file uses, based on its header line.
 */
class CsvReader
{
    private const array DELIMITERS = [',', ';', '|', "\t"];

    /**
     * The delimiter that splits the header line into the most columns wins.
     */
    public function detectDelimiter(string $headerLine): string
    {
        $best  = ',';
        $count = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $columns = count(str_getcsv($headerLine, $delimiter, '"', ''));

            if ($columns > $count) {
                $best  = $delimiter;
                $count = $columns;
            }
        }

        return $best;
    }
}
