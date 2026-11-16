<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Database;
use App\Csv\ColumnMapper;
use App\Csv\CsvReader;
use App\Import\Importer;
use App\Normalizer\BeehouseResolver;
use App\Normalizer\ColonyResolver;
use App\Normalizer\DateParser;
use App\Normalizer\MemberResolver;
use App\Normalizer\WeightParser;
use PHPUnit\Framework\TestCase;

class ImporterTest extends TestCase
{
    private Database $db;
    private Importer $importer;
    private string $directory;

    protected function setUp(): void
    {
        // In-memory database: fast, and every test starts empty
        $this->db = new Database(':memory:');

        $this->importer = new Importer(
            $this->db,
            new CsvReader(),
            new ColumnMapper(require __DIR__ . '/../../config/kolommen.php'),
            new WeightParser(),
            new DateParser(),
            new BeehouseResolver(require __DIR__ . '/../../config/bijenstanden.php'),
            new MemberResolver(),
            new ColonyResolver(),
        );

        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'importer_' . uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*'));
        rmdir($this->directory);
    }

    private function writeCsv(string $name, string $contents): string
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function countRows(string $table): int
    {
        return (int) $this->db->pdo()->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    public function testStoresNormalizedHarvest(): void
    {
        $path = $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            28-05-2025,"Rik Bos",AdK,volk 12,"21,9"
            CSV);

        $result = $this->importer->importFile($path);

        $this->assertSame(1, $result['imported']);

        $row = $this->db->pdo()->query(
            'SELECT m.naam AS member, b.naam AS beehouse, h.colony_code, h.harvest_date, h.weight_grams,
                    h.source_file, h.source_line
             FROM harvests h
             JOIN members m ON m.id = h.member_id
             JOIN beehouses b ON b.id = h.beehouse_id'
        )->fetch();

        $this->assertSame([
            'member'       => 'Rik Bos',
            'beehouse'     => 'Achter de Kerk',
            'colony_code'  => 'VOLK-12',
            'harvest_date' => '2025-05-28',
            'weight_grams' => 21900,
            'source_file'  => 'a.csv',
            'source_line'  => 2,
        ], $row);
    }

    public function testInvalidRowIsSkippedRestContinues(): void
    {
        $path = $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            2025-05-28,Rik Bos,Bosrand,VOLK-1,10
            2025-05-29,Rik Bos,Dorpsplein,VOLK-2,11
            gisteren,Rik Bos,Bosrand,VOLK-3,12
            2025-05-30,Rik Bos,Bosrand,VOLK-4,13
            CSV);

        $result = $this->importer->importFile($path);

        $this->assertSame(2, $result['imported']);
        $this->assertCount(2, $result['skipped']);
        $this->assertSame(3, $result['skipped'][0]['line']);
        $this->assertStringContainsString('Onbekende bijenstand', $result['skipped'][0]['reason']);
        $this->assertSame(4, $result['skipped'][1]['line']);
        $this->assertSame(2, $this->countRows('harvests'));
    }

    public function testZeroWeightIsSkipped(): void
    {
        $path = $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            2025-05-28,Rik Bos,Bosrand,VOLK-1,0
            CSV);

        $result = $this->importer->importFile($path);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['skipped']);
    }

    public function testRowWithWrongColumnCountIsSkipped(): void
    {
        $path = $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            2025-05-28,Rik Bos,Bosrand
            CSV);

        $result = $this->importer->importFile($path);

        $this->assertStringContainsString('Verwacht 5 kolommen', $result['skipped'][0]['reason']);
    }

    public function testSecondImportGivesDuplicates(): void
    {
        $path = $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            2025-05-28,Rik Bos,Bosrand,VOLK-1,10
            2025-05-29,Rik Bos,Bosrand,VOLK-2,11
            CSV);

        $this->importer->importFile($path);
        $result = $this->importer->importFile($path);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(2, $result['duplicates']);
        $this->assertSame(2, $this->countRows('harvests'));
    }

    public function testSameHarvestInOtherFormatIsDuplicate(): void
    {
        $commaFile = $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            2025-05-28,Rik Bos,Bosrand,VOLK-12,21.9
            CSV);
        $tabFile = $this->writeCsv('b.csv', "Lid\tStand\tVolk\tDatum\tKilo\nRik Bos\tBosrand\tVOLK-12\t2025-05-28\t21.9\n");

        $this->importer->importFile($commaFile);
        $result = $this->importer->importFile($tabFile);

        $this->assertSame(1, $result['duplicates']);
        $this->assertSame(1, $this->countRows('harvests'));
    }

    public function testMembersAndBeehousesAreCreatedOnce(): void
    {
        $path = $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            2025-05-28,Rik Bos,Bosrand,VOLK-1,10
            2025-05-29,rik bos,De Bosrand,VOLK-2,11
            2025-05-30,Eva Jansen,Bosrand Noord,VOLK-3,12
            CSV);

        $this->importer->importFile($path);

        $this->assertSame(2, $this->countRows('members'));
        $this->assertSame(1, $this->countRows('beehouses'));
    }

    public function testUnknownColumnsRollBackWholeFile(): void
    {
        $path = $this->writeCsv('a.csv', <<<CSV
            Datum,Foo,Bar
            2025-05-28,x,y
            CSV);

        try {
            $this->importer->importFile($path);
            $this->fail('Expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Onbekende kolommen', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('harvests'));
        $this->assertSame(0, $this->countRows('members'));
    }

    public function testFailedFileKeepsEarlierFiles(): void
    {
        $validFile = $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            2025-05-28,Rik Bos,Bosrand,VOLK-1,10
            CSV);
        $brokenFile = $this->writeCsv('b.csv', "Datum,Foo\n2025-05-28,x\n");

        $this->importer->importFile($validFile);

        try {
            $this->importer->importFile($brokenFile);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(1, $this->countRows('harvests'));
    }

    public function testDatabaseErrorHalfwayRollsBackWholeFile(): void
    {
        $path = $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            2025-05-28,Rik Bos,Bosrand,VOLK-1,10
            2025-05-29,Rik Bos,Bosrand,VOLK-2,11
            2025-05-30,Rik Bos,Bosrand,VOLK-3,12
            CSV);

        // Trigger that makes the third harvest fail, like a database error halfway through
        $this->db->pdo()->exec(
            "CREATE TRIGGER stop BEFORE INSERT ON harvests WHEN NEW.colony_code = 'VOLK-3'
             BEGIN SELECT RAISE(ABORT, 'database error'); END"
        );

        try {
            $this->importer->importFile($path);
            $this->fail('Expected an exception');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('database error', $e->getMessage());
        }

        // The first two harvests have been rolled back as well
        $this->assertSame(0, $this->countRows('harvests'));
        $this->assertSame(0, $this->countRows('members'));
    }

    /** @return list<array{source_file: string, source_line: int, reason: string, raw_row: string}> */
    private function importLog(): array
    {
        return $this->db->pdo()
            ->query('SELECT source_file, source_line, reason, raw_row FROM import_log ORDER BY id')
            ->fetchAll();
    }

    public function testInvalidRowIsWrittenToImportLog(): void
    {
        $path = $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            2025-05-28,Rik Bos,Bosrand,VOLK-1,10
            2025-05-29,"Rik Bos",Dorpsplein,VOLK-2,"11,5"
            CSV);

        $this->importer->importFile($path);

        $this->assertSame([[
            'source_file' => 'a.csv',
            'source_line' => 3,
            'reason'      => "Onbekende bijenstand: 'Dorpsplein'",
            // exactly as in the file, including quotes
            'raw_row'     => '2025-05-29,"Rik Bos",Dorpsplein,VOLK-2,"11,5"',
        ]], $this->importLog());
    }

    public function testEachInvalidRowLoggedSeparately(): void
    {
        $path = $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            gisteren,Rik Bos,Bosrand,VOLK-1,10
            2025-05-29,Rik Bos,Bosrand,VOLK-2,abc
            2025-05-30,Rik Bos,Bosrand
            CSV);

        $this->importer->importFile($path);

        $log = $this->importLog();
        $this->assertCount(3, $log);
        $this->assertSame([2, 3, 4], array_column($log, 'source_line'));
        $this->assertStringContainsString('datumformaat', $log[0]['reason']);
        $this->assertStringContainsString('Ongeldig gewicht', $log[1]['reason']);
        $this->assertStringContainsString('Verwacht 5 kolommen', $log[2]['reason']);
    }

    public function testDuplicateIsNotLogged(): void
    {
        $path = $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            2025-05-28,Rik Bos,Bosrand,VOLK-1,10
            2025-05-28,Rik Bos,Bosrand,VOLK-1,10
            CSV);

        $this->importer->importFile($path);

        $this->assertSame([], $this->importLog());
    }

    public function testReimportReplacesLogOfThatFile(): void
    {
        $a = $this->writeCsv('a.csv', "Datum,Naam,Bijenstand,VolkID,Oogst_KG\ngisteren,Rik Bos,Bosrand,VOLK-1,10\n");
        $b = $this->writeCsv('b.csv', "Datum,Naam,Bijenstand,VolkID,Oogst_KG\nmorgen,Rik Bos,Bosrand,VOLK-1,10\n");

        $this->importer->importFile($a);
        $this->importer->importFile($b);
        $this->importer->importFile($a);
        $this->importer->importFile($a);

        // One row per file, not growing with every run
        $this->assertSame(['b.csv', 'a.csv'], array_column($this->importLog(), 'source_file'));
    }

    public function testFailedFileIsLoggedWithLineZero(): void
    {
        $path = $this->writeCsv('a.csv', "Datum,Foo\n2025-05-28,x\n");

        try {
            $this->importer->importFile($path);
        } catch (\RuntimeException) {
            // expected
        }

        $log = $this->importLog();
        $this->assertCount(1, $log);
        $this->assertSame(0, $log[0]['source_line']);
        $this->assertStringStartsWith('Bestand teruggedraaid: Onbekende kolommen', $log[0]['reason']);
    }

    public function testLogRowsOfRolledBackFileDisappear(): void
    {
        $path = $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            gisteren,Rik Bos,Bosrand,VOLK-1,10
            2025-05-30,Rik Bos,Bosrand,VOLK-3,12
            CSV);

        $this->db->pdo()->exec(
            "CREATE TRIGGER stop BEFORE INSERT ON harvests WHEN NEW.colony_code = 'VOLK-3'
             BEGIN SELECT RAISE(ABORT, 'database error'); END"
        );

        try {
            $this->importer->importFile($path);
        } catch (\PDOException) {
            // expected
        }

        // The log row for "gisteren" was rolled back too; only the file error remains
        $log = $this->importLog();
        $this->assertCount(1, $log);
        $this->assertSame(0, $log[0]['source_line']);
    }

    public function testNextFileStillWorksAfterRollback(): void
    {
        $path = $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            2025-05-28,Rik Bos,Bosrand,VOLK-1,10
            2025-05-29,Rik Bos,Bosrand,VOLK-3,12
            CSV);

        $pdo = $this->db->pdo();
        $pdo->exec(
            "CREATE TRIGGER stop BEFORE INSERT ON harvests WHEN NEW.colony_code = 'VOLK-3'
             BEGIN SELECT RAISE(ABORT, 'database error'); END"
        );

        try {
            $this->importer->importFile($path);
        } catch (\PDOException) {
            // expected
        }

        $pdo->exec('DROP TRIGGER stop');

        // Member and beehouse must be created again (the ids from the
        // rolled-back transaction no longer exist)
        $result = $this->importer->importFile($path);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, $this->countRows('harvests'));
    }
}
