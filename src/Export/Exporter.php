<?php

declare(strict_types=1);

namespace App\Export;

use App\Database;

/**
 * Writes all normalized harvests to one clean CSV file.
 *
 * Format (RFC 4180):
 *   - comma as delimiter, values containing a comma or quote wrapped in "..."
 *   - UTF-8 without BOM, line ending \n
 *   - date as YYYY-MM-DD, weight in kg with a dot as decimal separator
 *   - sorted by date, member, colony
 *
 * The file can be imported again (its column names are in config/kolommen.php).
 */
class Exporter
{
    /** Column names of the export file. Dutch, because the file is meant for the treasurer. */
    public const array COLUMNS = [
        'datum',
        'jaar',
        'lid',
        'bijenstand',
        'volk',
        'gewicht_kg',
        'bronbestand',
        'bronregel',
    ];

    private readonly Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @return int number of exported harvests
     *
     * @throws \RuntimeException when the file cannot be written
     */
    public function export(string $path): int
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new \RuntimeException("Map bestaat niet: '$directory'");
        }

        // Write to a temporary file first, then rename it. If something goes wrong
        // halfway, no half file (or half-overwritten old file) is left behind.
        $temporaryFile = $path . '.tmp';

        $handle = @fopen($temporaryFile, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Kan '$path' niet schrijven");
        }

        try {
            $this->writeRow($handle, self::COLUMNS);

            $harvests = $this->db->pdo()->query(
                "SELECT h.harvest_date,
                        substr(h.harvest_date, 1, 4) AS year,
                        m.naam                       AS member,
                        b.naam                       AS beehouse,
                        h.colony_code,
                        h.weight_grams,
                        h.source_file,
                        h.source_line
                 FROM harvests h
                 JOIN members   m ON m.id = h.member_id
                 JOIN beehouses b ON b.id = h.beehouse_id
                 ORDER BY h.harvest_date, m.naam, h.colony_code, h.id"
            );

            $count = 0;

            foreach ($harvests as $harvest) {
                $this->writeRow($handle, [
                    $harvest['harvest_date'],
                    $harvest['year'],
                    // Text that comes from the members' files: neutralise spreadsheet formulas
                    $this->neutraliseFormula($harvest['member']),
                    $this->neutraliseFormula($harvest['beehouse']),
                    $this->neutraliseFormula($harvest['colony_code']),
                    $this->gramsToKilograms((int) $harvest['weight_grams']),
                    $this->neutraliseFormula($harvest['source_file']),
                    $harvest['source_line'],
                ]);
                $count++;
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($temporaryFile);
            throw $e;
        }

        fclose($handle);

        if (!rename($temporaryFile, $path)) {
            @unlink($temporaryFile);
            throw new \RuntimeException("Kan '$path' niet schrijven (bestand in gebruik?)");
        }

        return $count;
    }

    /**
     * Protection against CSV injection: a value starting with = + - @ (or a tab or
     * carriage return) is treated as a formula by Excel and LibreOffice, e.g.
     * =HYPERLINK("http://...") in a member name. A leading apostrophe makes the
     * spreadsheet show the value as plain text. Other values are left untouched.
     *
     * Only used for text from the members' files; dates and weights are generated
     * by this application and never start with these characters.
     */
    private function neutraliseFormula(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    /**
     * 21900 -> "21.900", 605 -> "0.605". Three decimals: exact to the gram.
     */
    private function gramsToKilograms(int $grams): string
    {
        return sprintf('%d.%03d', intdiv($grams, 1000), $grams % 1000);
    }

    /**
     * @param resource          $handle
     * @param list<string|int>  $fields
     */
    private function writeRow($handle, array $fields): void
    {
        // escape '' = standard CSV (double the quote), avoids the PHP 8.4 deprecation
        if (fputcsv($handle, $fields, ',', '"', '', "\n") === false) {
            throw new \RuntimeException('Schrijven naar het exportbestand mislukt');
        }
    }
}
