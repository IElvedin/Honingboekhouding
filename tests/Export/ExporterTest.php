<?php

declare(strict_types=1);

namespace App\Tests\Export;

use App\Database;
use App\Csv\ColumnMapper;
use App\Csv\CsvReader;
use App\Export\Exporter;
use App\Import\Importer;
use App\Normalizer\BeehouseResolver;
use App\Normalizer\ColonyResolver;
use App\Normalizer\DateParser;
use App\Normalizer\MemberResolver;
use App\Normalizer\WeightParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExporterTest extends TestCase
{
    private Database $db;
    private string $directory;
    private string $outputFile;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');

        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'exporter_' . uniqid();
        mkdir($this->directory);
        $this->outputFile = $this->directory . DIRECTORY_SEPARATOR . 'export.csv';
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*'));
        rmdir($this->directory);
    }

    private function createImporter(): Importer
    {
        return new Importer(
            $this->db,
            new CsvReader(),
            new ColumnMapper(require __DIR__ . '/../../config/kolommen.php'),
            new WeightParser(),
            new DateParser(),
            new BeehouseResolver(require __DIR__ . '/../../config/bijenstanden.php'),
            new MemberResolver(),
            new ColonyResolver(),
        );
    }

    private function importCsv(string $name, string $contents): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);
        $this->createImporter()->importFile($path);
    }

    /** @return list<string> */
    private function lines(): array
    {
        return explode("\n", rtrim(file_get_contents($this->outputFile), "\n"));
    }

    public function testWritesHeaderAndNormalizedHarvest(): void
    {
        $this->importCsv('a.csv', "Datum,Naam,Bijenstand,VolkID,Oogst_KG\n28-05-2025,Rik Bos,AdK,volk 12,\"21,9\"\n");

        $count = (new Exporter($this->db))->export($this->outputFile);

        $this->assertSame(1, $count);
        $this->assertSame([
            'datum,jaar,lid,bijenstand,volk,gewicht_kg,bronbestand,bronregel',
            '2025-05-28,2025,"Rik Bos","Achter de Kerk",VOLK-12,21.900,a.csv,2',
        ], $this->lines());
    }

    public function testWeightInKilogramsWithThreeDecimals(): void
    {
        $this->importCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Gewicht,Eenheid
            2025-05-01,Rik Bos,Bosrand,VOLK-1,605,g
            2025-05-02,Rik Bos,Bosrand,VOLK-1,19509,g
            2025-05-03,Rik Bos,Bosrand,VOLK-1,13000,g
            CSV);

        (new Exporter($this->db))->export($this->outputFile);

        $weights = array_map(fn (string $line): string => str_getcsv($line, ',', '"', '')[5], array_slice($this->lines(), 1));
        $this->assertSame(['0.605', '19.509', '13.000'], $weights);
    }

    public function testSortedByDateMemberColony(): void
    {
        $this->importCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            2025-06-01,Rik Bos,Bosrand,VOLK-2,10
            2025-05-01,Rik Bos,Bosrand,VOLK-1,10
            2025-06-01,Eva Jansen,Bosrand,VOLK-9,10
            2025-06-01,Rik Bos,Bosrand,VOLK-1,10
            CSV);

        (new Exporter($this->db))->export($this->outputFile);

        $order = array_map(
            fn (string $line): string => implode(' ', array_slice(str_getcsv($line, ',', '"', ''), 0, 5)),
            array_slice($this->lines(), 1)
        );

        $this->assertSame([
            '2025-05-01 2025 Rik Bos Bosrand VOLK-1',
            '2025-06-01 2025 Eva Jansen Bosrand VOLK-9',
            '2025-06-01 2025 Rik Bos Bosrand VOLK-1',
            '2025-06-01 2025 Rik Bos Bosrand VOLK-2',
        ], $order);
    }

    public function testCommaAndQuotesInNameAreWrittenCorrectly(): void
    {
        // Directly in the database: the importer would accept such a name as well
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO members (naam) VALUES ('Bos, Rik \"de imker\"')");
        $pdo->exec("INSERT INTO beehouses (naam) VALUES ('Bosrand')");
        $pdo->exec("INSERT INTO harvests (member_id, beehouse_id, colony_code, harvest_date, weight_grams, source_file, source_line)
                    VALUES (1, 1, 'VOLK-1', '2025-05-01', 1000, 'a.csv', 2)");

        (new Exporter($this->db))->export($this->outputFile);

        $this->assertSame('2025-05-01,2025,"Bos, Rik ""de imker""",Bosrand,VOLK-1,1.000,a.csv,2', $this->lines()[1]);
        $this->assertSame('Bos, Rik "de imker"', str_getcsv($this->lines()[1], ',', '"', '')[2]);
    }

    /**
     * @return array<string, array{string, string}> value in the database => value in the export
     */
    public static function formulaValues(): array
    {
        return [
            'equals sign'      => ['=HYPERLINK("http://evil.example","klik")', '\'=HYPERLINK("http://evil.example","klik")'],
            'plus sign'        => ['+31 6 12345678', "'+31 6 12345678"],
            'minus sign'       => ['-1+1', "'-1+1"],
            'at sign'          => ['@SUM(A1:A9)', "'@SUM(A1:A9)"],
            'tab'              => ["\t=1+1", "'\t=1+1"],
            'normal name'      => ['Rik Bos', 'Rik Bos'],
            'sign not first'   => ['Rik = Bos', 'Rik = Bos'],
            'name with hyphen' => ['Samira El-Mansoor', 'Samira El-Mansoor'],
        ];
    }

    #[DataProvider('formulaValues')]
    public function testFormulaAtStartOfMemberNameIsNeutralised(string $name, string $expected): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO members (naam) VALUES (?)')->execute([$name]);
        $pdo->exec("INSERT INTO beehouses (naam) VALUES ('Bosrand')");
        $pdo->exec("INSERT INTO harvests (member_id, beehouse_id, colony_code, harvest_date, weight_grams, source_file, source_line)
                    VALUES (1, 1, 'VOLK-1', '2025-05-01', 1000, 'a.csv', 2)");

        (new Exporter($this->db))->export($this->outputFile);

        $this->assertSame($expected, str_getcsv($this->lines()[1], ',', '"', '')[2]);
    }

    public function testFormulaInBeehouseColonyAndSourceFileIsNeutralised(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO members (naam) VALUES ('Rik Bos')");
        $pdo->exec("INSERT INTO beehouses (naam) VALUES ('=1+1')");
        $pdo->exec("INSERT INTO harvests (member_id, beehouse_id, colony_code, harvest_date, weight_grams, source_file, source_line)
                    VALUES (1, 1, '@VOLK', '2025-05-01', 1000, '+evil.csv', 2)");

        (new Exporter($this->db))->export($this->outputFile);

        $fields = str_getcsv($this->lines()[1], ',', '"', '');
        $this->assertSame("'=1+1", $fields[3]);
        $this->assertSame("'@VOLK", $fields[4]);
        $this->assertSame("'+evil.csv", $fields[6]);
    }

    public function testDatesAndWeightsAreNeverPrefixed(): void
    {
        $this->importCsv('a.csv', "Datum,Naam,Bijenstand,VolkID,Oogst_KG\n2025-05-01,Rik Bos,Bosrand,VOLK-1,0.605\n");

        (new Exporter($this->db))->export($this->outputFile);

        $this->assertSame('2025-05-01,2025,"Rik Bos",Bosrand,VOLK-1,0.605,a.csv,2', $this->lines()[1]);
    }

    public function testEmptyDatabaseGivesOnlyHeader(): void
    {
        $count = (new Exporter($this->db))->export($this->outputFile);

        $this->assertSame(0, $count);
        $this->assertSame([implode(',', Exporter::COLUMNS)], $this->lines());
    }

    public function testNoBomAndUnixLineEndings(): void
    {
        $this->importCsv('a.csv', "Datum,Naam,Bijenstand,VolkID,Oogst_KG\n2025-05-01,Rik Bos,Bosrand,VOLK-1,10\n");

        (new Exporter($this->db))->export($this->outputFile);

        $contents = file_get_contents($this->outputFile);
        $this->assertStringStartsWith('datum,', $contents);
        $this->assertStringNotContainsString("\r", $contents);
    }

    public function testOverwritesExistingFileAndLeavesNoTemporaryFile(): void
    {
        file_put_contents($this->outputFile, 'old contents');

        (new Exporter($this->db))->export($this->outputFile);

        $this->assertStringStartsWith('datum,', file_get_contents($this->outputFile));
        $this->assertFileDoesNotExist($this->outputFile . '.tmp');
    }

    public function testThrowsWhenDirectoryDoesNotExist(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Map bestaat niet');

        (new Exporter($this->db))->export($this->directory . '/does-not-exist/export.csv');
    }

    public function testExportCanBeReimportedWithoutDifference(): void
    {
        $this->importCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            28-05-2025,Rik Bos,AdK,volk 12,"21,9"
            2025-06-01,Eva Jansen,ZW-1,v167,0.605
            CSV);

        (new Exporter($this->db))->export($this->outputFile);

        // Import the export again: everything must be recognised as a duplicate
        $result = $this->createImporter()->importFile($this->outputFile);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(2, $result['duplicates']);
        $this->assertSame([], $result['skipped']);
    }
}
