<?php

declare(strict_types=1);

namespace App\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end: runs the real "php bin/honing.php ..." command as a separate process,
 * against a temporary database (HONING_DB). Tests what a user sees: output and exit code.
 */
class HoningCommandTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../bin/honing.php';

    private string $directory;
    private string $database;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cli_' . uniqid();
        $this->database  = $this->directory . DIRECTORY_SEPARATOR . 'test.sqlite';
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*'));
        rmdir($this->directory);
    }

    /**
     * @param list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCommand(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, self::SCRIPT, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['HONING_DB' => $this->database] + getenv()
        );

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function writeCsv(string $name, string $contents): string
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function sampleCsv(): string
    {
        return $this->writeCsv('a.csv', <<<CSV
            Datum,Naam,Bijenstand,VolkID,Oogst_KG
            2025-05-01,Rik Bos,Bosrand,VOLK-1,10
            2025-05-02,Eva Jansen,AdK,VOLK-2,"12,5"
            2025-05-03,Eva Jansen,Dorpsplein,VOLK-2,5
            CSV);
    }

    public function testWithoutCommandShowsHelp(): void
    {
        $result = $this->runCommand([]);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('Gebruik:', $result['stdout']);
    }

    public function testImportExportReportEndToEnd(): void
    {
        $csv = $this->sampleCsv();

        $import = $this->runCommand(['import', $csv, '--fresh']);
        $this->assertSame(0, $import['exit']);
        $this->assertMatchesRegularExpression('/Ingelezen:\s+2 nieuwe oogsten/', $import['stdout']);
        $this->assertMatchesRegularExpression('/Overgeslagen:\s+1/', $import['stdout']);
        $this->assertStringContainsString("Onbekende bijenstand: 'Dorpsplein'", $import['stdout']);

        $outputFile = $this->directory . DIRECTORY_SEPARATOR . 'export.csv';
        $export     = $this->runCommand(['export', $outputFile]);
        $this->assertSame(0, $export['exit']);
        $this->assertStringContainsString('2 oogsten geëxporteerd', $export['stdout']);
        $this->assertStringContainsString('"Eva Jansen","Achter de Kerk",VOLK-2,12.500', file_get_contents($outputFile));

        $report = $this->runCommand(['report']);
        $this->assertSame(0, $report['exit']);
        $this->assertStringContainsString('Top 5 best presterende volken in 2025', $report['stdout']); // latest year with data
        $this->assertMatchesRegularExpression('/1\.\s+Eva Jansen\s+VOLK-2/', $report['stdout']);
    }

    public function testReimportGivesDuplicates(): void
    {
        $csv = $this->sampleCsv();

        $this->runCommand(['import', $csv]);
        $result = $this->runCommand(['import', $csv]);

        $this->assertMatchesRegularExpression('/Ingelezen:\s+0/', $result['stdout']);
        $this->assertMatchesRegularExpression('/Duplicaat:\s+2/', $result['stdout']);
    }

    public function testFailedFileGivesExitCodeOne(): void
    {
        $validFile  = $this->sampleCsv();
        $brokenFile = $this->writeCsv('broken.csv', "Datum,Foo\n2025-05-01,x\n");

        $result = $this->runCommand(['import', $validFile, $brokenFile]);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('MISLUKT, niets opgeslagen', $result['stdout']);
        $this->assertMatchesRegularExpression('/Bestanden:\s+1 verwerkt, 1 mislukt/', $result['stdout']);
    }

    public function testFreshWithInvalidPathKeepsDatabase(): void
    {
        $this->runCommand(['import', $this->sampleCsv()]);

        $result = $this->runCommand(['import', $this->directory . '/does-not-exist.csv', '--fresh']);
        $this->assertSame(1, $result['exit']);

        $export = $this->runCommand(['export', $this->directory . '/export.csv']);
        $this->assertStringContainsString('2 oogsten geëxporteerd', $export['stdout']);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function invalidUsage(): array
    {
        return [
            'import without path'          => [['import'], 'Geef minstens één bestand of map op'],
            'import unknown option'        => [['import', '.', '--frsh'], 'Onbekende optie: --frsh'],
            'export two paths'             => [['export', 'a.csv', 'b.csv'], 'Geef hooguit één uitvoerbestand op'],
            'export with option'           => [['export', '--year=2025'], 'Onbekende optie: --year=2025'],
            'export directory not found'   => [['export', 'does/not/exist/x.csv'], 'Map bestaat niet'],
            'report invalid year'          => [['report', '--year=25'], 'Ongeldig jaar'],
            'report unknown option'        => [['report', '--top=3'], 'Onbekende optie: --top=3'],
        ];
    }

    #[DataProvider('invalidUsage')]
    public function testInvalidUsageGivesMessageAndExitCodeOne(array $arguments, string $message): void
    {
        $result = $this->runCommand($arguments);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Fout: ' . $message, $result['stderr']);
    }
}
