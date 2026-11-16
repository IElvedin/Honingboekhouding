<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Csv\ColumnMapper;
use App\Csv\CsvReader;
use App\Export\Exporter;
use App\Import\FileCollector;
use App\Import\Importer;
use App\Normalizer\BeehouseResolver;
use App\Normalizer\ColonyResolver;
use App\Normalizer\DateParser;
use App\Normalizer\MemberResolver;
use App\Normalizer\WeightParser;
use App\Report\ReportPrinter;
use App\Report\ReportService;

// The database path can be overridden with the HONING_DB environment variable,
// so the tests can run the command against a temporary database.
$db = new Database(getenv('HONING_DB') ?: __DIR__ . '/../var/honing.sqlite');
$arguments = array_slice($argv, 2);

if (isset($argv[1])) {
    $command = $argv[1];
} else {
    $command = 'help';
}

try {
    switch ($command) {
        case 'import':
            echo "Start Import" . PHP_EOL;

            // Separate options (--fresh) from paths (files, directories)
            $paths   = array_values(array_filter($arguments, fn (string $arg): bool => !str_starts_with($arg, '--')));
            $options = array_values(array_filter($arguments, fn (string $arg): bool => str_starts_with($arg, '--')));

            $unknown = array_diff($options, ['--fresh']);
            if ($unknown !== []) {
                throw new InvalidArgumentException('Onbekende optie: ' . implode(', ', $unknown));
            }

            // Find the files first: with an invalid path the database is left untouched
            $files = (new FileCollector())->collect($paths);

            echo count($files) . ' CSV-bestand(en) gevonden' . PHP_EOL;

            if (in_array('--fresh', $options, true)) {
                $db->reset();
                echo 'Database leeggemaakt (--fresh)' . PHP_EOL;
            }

            $aliases = require __DIR__ . '/../config/kolommen.php';

            $importer = new Importer(
                $db,
                new CsvReader(),
                new ColumnMapper($aliases),
                new WeightParser(),
                new DateParser(),
                new BeehouseResolver(require __DIR__ . '/../config/bijenstanden.php'),
                new MemberResolver(),
                new ColonyResolver(),
            );

            $failed = 0;

            // Totals for the summary
            $totals  = ['imported' => 0, 'duplicates' => 0, 'skipped' => 0];
            $reasons = [];

            foreach ($files as $file) {
                try {
                    $result = $importer->importFile($file);

                    printf(
                        "  %-28s %4d ingelezen, %4d duplicaat, %4d overgeslagen\n",
                        $result['file'],
                        $result['imported'],
                        $result['duplicates'],
                        count($result['skipped'])
                    );

                    $totals['imported']   += $result['imported'];
                    $totals['duplicates'] += $result['duplicates'];
                    $totals['skipped']    += count($result['skipped']);

                    foreach ($result['skipped'] as $skipped) {
                        $reasons[$skipped['reason']] = ($reasons[$skipped['reason']] ?? 0) + 1;
                    }
                } catch (Throwable $e) {
                    // The transaction was rolled back: nothing from this file is in the database
                    $failed++;
                    printf("  %-28s MISLUKT, niets opgeslagen: %s\n", basename($file), $e->getMessage());
                }
            }

            // Summary
            $processed = count($files) - $failed;

            echo PHP_EOL . 'Samenvatting' . PHP_EOL;
            printf("  Bestanden:     %6d verwerkt, %d mislukt\n", $processed, $failed);
            printf("  Ingelezen:     %6d nieuwe oogsten\n", $totals['imported']);
            printf("  Duplicaat:     %6d (stonden er al in)\n", $totals['duplicates']);
            printf("  Overgeslagen:  %6d (zie import_log)\n", $totals['skipped']);

            if ($reasons !== []) {
                echo PHP_EOL . 'Redenen overgeslagen' . PHP_EOL;

                arsort($reasons);
                $maxReasons = 10;

                foreach (array_slice($reasons, 0, $maxReasons, true) as $reason => $count) {
                    printf("  %6d  %s\n", $count, $reason);
                }

                if (count($reasons) > $maxReasons) {
                    printf("  ... en nog %d andere redenen\n", count($reasons) - $maxReasons);
                }
            }

            if ($failed > 0) {
                exit(1);
            }

            break;

        case 'export':
            echo "Start Export" . PHP_EOL;

            // At most one argument: the output file. No options.
            $options = array_filter($arguments, fn (string $arg): bool => str_starts_with($arg, '--'));
            if ($options !== []) {
                throw new InvalidArgumentException('Onbekende optie: ' . implode(', ', $options));
            }
            
            if (count($arguments) > 1) {
                throw new InvalidArgumentException('Geef hooguit één uitvoerbestand op');
            }

            // Write to a file by default, not to the screen: in Windows PowerShell 5.1
            // "> export.csv" produces UTF-16, which is no longer a clean CSV.
            $outputFile = $arguments[0] ?? __DIR__ . '/../var/export.csv';

            $count = (new Exporter($db))->export($outputFile);

            printf("%d oogsten geëxporteerd naar %s\n", $count, realpath($outputFile));

            if ($count === 0) {
                echo 'Let op: de database is leeg. Draai eerst "php bin/honing.php import <map>".' . PHP_EOL;
            }

            break;

        case 'report':
            $service = new ReportService($db);

            // Default: the latest year with data (currently 2025). Members submit per season,
            // so the report only moves on to a new year once there is data for it.
            $year = $service->defaultYear((int) date('Y'));

            foreach ($arguments as $argument) {
                if (preg_match('/^--year=(\d{4})$/', $argument, $matches)) {
                    $year = (int) $matches[1];
                } elseif (str_starts_with($argument, '--year')) {
                    throw new InvalidArgumentException("Ongeldig jaar: '$argument'. Gebruik bijvoorbeeld --year=2025");
                } else {
                    throw new InvalidArgumentException("Onbekende optie: $argument");
                }
            }

            echo (new ReportPrinter($service))->render($year);
            break;

        case 'help':
        default:
            echo <<<TXT
            Gebruik:
              php bin/honing.php import <bestand|map> [...] [--fresh]
              php bin/honing.php export [uitvoer.csv]     (standaard: var/export.csv)
              php bin/honing.php report [--year=2025]     (standaard: laatste jaar met data)

            TXT;
            break;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Fout: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

exit;
