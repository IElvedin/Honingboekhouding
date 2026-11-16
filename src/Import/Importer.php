<?php

declare(strict_types=1);

namespace App\Import;

use App\Database;
use App\Csv\ColumnMapper;
use App\Csv\CsvReader;
use App\Normalizer\BeehouseResolver;
use App\Normalizer\ColonyResolver;
use App\Normalizer\DateParser;
use App\Normalizer\MemberResolver;
use App\Normalizer\WeightParser;
use PDO;
use PDOStatement;

/**
 * Reads one CSV file and stores the harvests in normalized form.
 *
 * Each file is one transaction:
 *   - A row with invalid data (unknown beehouse, unreadable date, ...) is
 *     skipped and written to import_log with its reason and raw line;
 *     the rest of the file carries on.
 *   - If the file as a whole fails (unknown columns, database error), the
 *     entire file is rolled back. A half-imported file never ends up in the database.
 */
class Importer
{
    /** @var array<string, int> name => id, so we don't have to look it up for every row */
    private array $memberIds = [];

    /** @var array<string, int> name => id */
    private array $beehouseIds = [];

    private readonly Database $db;

    private readonly CsvReader $csvReader;

    private readonly ColumnMapper $columnMapper;

    private readonly WeightParser $weightParser;

    private readonly DateParser $dateParser;

    private readonly BeehouseResolver $beehouseResolver;

    private readonly MemberResolver $memberResolver;

    private readonly ColonyResolver $colonyResolver;

    public function __construct(
        Database $db,
        CsvReader $csvReader,
        ColumnMapper $columnMapper,
        WeightParser $weightParser,
        DateParser $dateParser,
        BeehouseResolver $beehouseResolver,
        MemberResolver $memberResolver,
        ColonyResolver $colonyResolver,
    ) {
        $this->db               = $db;
        $this->csvReader        = $csvReader;
        $this->columnMapper     = $columnMapper;
        $this->weightParser     = $weightParser;
        $this->dateParser       = $dateParser;
        $this->beehouseResolver = $beehouseResolver;
        $this->memberResolver   = $memberResolver;
        $this->colonyResolver   = $colonyResolver;
    }

    /**
     * @return array{
     *     file: string,
     *     imported: int,
     *     duplicates: int,
     *     skipped: list<array{line: int, reason: string, raw: string}>
     * }
     *
     * @throws \Throwable when the file as a whole fails; the transaction has then been rolled back
     */
    public function importFile(string $path): array
    {
        $fileName = basename($path);

        // The caches may contain ids from a rolled-back transaction, so only
        // keep them once a file has succeeded (see the catch block).
        $memberIdsBefore   = $this->memberIds;
        $beehouseIdsBefore = $this->beehouseIds;

        try {
            return $this->db->transaction(fn (PDO $pdo): array => $this->readAndStore($pdo, $path, $fileName));
        } catch (\Throwable $e) {
            $this->memberIds   = $memberIdsBefore;
            $this->beehouseIds = $beehouseIdsBefore;

            // Record outside the (rolled-back) transaction that the whole file failed.
            // Line 0 = the file as a whole, not a single line.
            $this->logError($this->db->pdo(), $fileName, 0, 'Bestand teruggedraaid: ' . $e->getMessage(), '');

            throw $e;
        }
    }

    /**
     * Writes one invalid row to import_log.
     */
    private function logError(PDO $pdo, string $fileName, int $lineNumber, string $reason, string $rawLine): void
    {
        $pdo->prepare(
            'INSERT INTO import_log (source_file, source_line, reason, raw_row) VALUES (?, ?, ?, ?)'
        )->execute([$fileName, $lineNumber, $reason, $rawLine]);
    }

    private function readAndStore(PDO $pdo, string $path, string $fileName): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Kan '$fileName' niet openen");
        }

        try {
            $headerLine = fgets($handle);
            if ($headerLine === false) {
                throw new \RuntimeException("'$fileName' is leeg");
            }

            // Strip the BOM (invisible marker Excel sometimes adds) and the line ending
            $headerLine = rtrim(preg_replace('/^\xEF\xBB\xBF/', '', $headerLine), "\r\n");

            $delimiter = $this->csvReader->detectDelimiter($headerLine);
            $header    = str_getcsv($headerLine, $delimiter, '"', '');
            $columns   = $this->columnMapper->map($header);   // throws when a required column is missing

            $insert = $pdo->prepare(
                'INSERT OR IGNORE INTO harvests
                    (member_id, beehouse_id, colony_code, harvest_date, weight_grams, source_file, source_line)
                 VALUES
                    (:member, :beehouse, :colony, :date, :weight, :file, :line)'
            );

            // On re-import: remove this file's old log entries, so the log always
            // shows the latest import and doesn't grow with every run.
            // This happens inside the transaction, so a rollback keeps the old log.
            $pdo->prepare('DELETE FROM import_log WHERE source_file = ?')->execute([$fileName]);

            $result = [
                'file'       => $fileName,
                'imported'   => 0,
                'duplicates' => 0,
                'skipped'    => [],
            ];

            $lineNumber = 1; // the header is line 1

            // Read line by line with fgets (instead of fgetcsv), so the raw line
            // ends up in import_log exactly as it appears in the file.
            // Assumption: no values contain a line break (none do in the dataset).
            while (($rawLine = fgets($handle)) !== false) {
                $lineNumber++;
                $rawLine = rtrim($rawLine, "\r\n");

                if (trim($rawLine) === '') {
                    continue; // empty line
                }

                $row = str_getcsv($rawLine, $delimiter, '"', '');

                try {
                    $harvest = $this->normalizeRow($row, $columns, count($header));
                } catch (\InvalidArgumentException $e) {
                    $this->logError($pdo, $fileName, $lineNumber, $e->getMessage(), $rawLine);

                    $result['skipped'][] = [
                        'line'   => $lineNumber,
                        'reason' => $e->getMessage(),
                        'raw'    => $rawLine,
                    ];
                    continue;
                }

                $this->store($pdo, $insert, $harvest, $fileName, $lineNumber)
                    ? $result['imported']++
                    : $result['duplicates']++;
            }

            return $result;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<string|null>   $row
     * @param array<string, int>  $columns
     * @return array{member: string, beehouse: string, colony: string, date: string, weight: int}
     *
     * @throws \InvalidArgumentException when the row is not usable
     */
    private function normalizeRow(array $row, array $columns, int $columnCount): array
    {
        if (count($row) !== $columnCount) {
            throw new \InvalidArgumentException(
                "Verwacht $columnCount kolommen, gevonden " . count($row)
            );
        }

        $value = fn (string $field): string => (string) ($row[$columns[$field]] ?? '');

        $unit   = isset($columns['unit']) ? $value('unit') : null;
        $weight = $this->weightParser->parse($value('weight'), $unit);

        if ($weight <= 0) {
            throw new \InvalidArgumentException("Gewicht moet groter dan 0 zijn: '{$value('weight')}'");
        }

        return [
            'member'   => $this->memberResolver->resolve($value('member')),
            'beehouse' => $this->beehouseResolver->resolve($value('beehouse')),
            'colony'   => $this->colonyResolver->resolve($value('colony')),
            'date'     => $this->dateParser->parse($value('date')),
            'weight'   => $weight,
        ];
    }

    /**
     * @return bool true when the harvest is new, false when it already existed
     */
    private function store(PDO $pdo, PDOStatement $insert, array $harvest, string $fileName, int $lineNumber): bool
    {
        $insert->execute([
            'member'   => $this->idFor($pdo, 'members', $harvest['member'], $this->memberIds),
            'beehouse' => $this->idFor($pdo, 'beehouses', $harvest['beehouse'], $this->beehouseIds),
            'colony'   => $harvest['colony'],
            'date'     => $harvest['date'],
            'weight'   => $harvest['weight'],
            'file'     => $fileName,
            'line'     => $lineNumber,
        ]);

        // INSERT OR IGNORE: 0 rows inserted means this harvest already existed
        return $insert->rowCount() === 1;
    }

    /**
     * Looks up the id of a member or beehouse, creating it when it doesn't exist yet.
     *
     * @param 'members'|'beehouses' $table
     * @param array<string, int>    $cache
     */
    private function idFor(PDO $pdo, string $table, string $name, array &$cache): int
    {
        if (!isset($cache[$name])) {
            $pdo->prepare("INSERT OR IGNORE INTO $table (naam) VALUES (?)")->execute([$name]);

            $select = $pdo->prepare("SELECT id FROM $table WHERE naam = ?");
            $select->execute([$name]);

            $cache[$name] = (int) $select->fetchColumn();
        }

        return $cache[$name];
    }
}
