<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\FileCollector;
use PHPUnit\Framework\TestCase;

class FileCollectorTest extends TestCase
{
    private string $directory;

    /**
     * Creates a temporary directory for every test:
     *
     *   b.csv  a.csv  C.CSV  notities.txt  .~lock.a.csv#  .verborgen.csv
     *   sub/d.csv
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'filecollector_' . uniqid();
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'sub', recursive: true);

        foreach (['b.csv', 'a.csv', 'C.CSV', 'notities.txt', '.~lock.a.csv#', '.verborgen.csv', 'sub/d.csv'] as $name) {
            file_put_contents($this->directory . DIRECTORY_SEPARATOR . $name, 'x');
        }
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->directory);
    }

    /** @param list<string> $bestanden */
    private function names(array $files): array
    {
        return array_map('basename', $files);
    }

    public function testDirectoryGivesAllCsvFilesSorted(): void
    {
        $files = (new FileCollector())->collect([$this->directory]);

        $this->assertSame(['C.CSV', 'a.csv', 'b.csv'], $this->names($files));
    }

    public function testDirectorySkipsLockHiddenAndOtherFiles(): void
    {
        $namen = $this->names((new FileCollector())->collect([$this->directory]));

        $this->assertNotContains('.~lock.a.csv#', $namen);
        $this->assertNotContains('.verborgen.csv', $namen);
        $this->assertNotContains('notities.txt', $namen);
    }

    public function testDirectoryDoesNotSearchSubdirectories(): void
    {
        $this->assertNotContains('d.csv', $this->names((new FileCollector())->collect([$this->directory])));
    }

    public function testSingleFile(): void
    {
        $files = (new FileCollector())->collect([$this->directory . '/a.csv']);

        $this->assertSame(['a.csv'], $this->names($files));
    }

    public function testMultipleFilesInGivenOrder(): void
    {
        $files = (new FileCollector())->collect([$this->directory . '/b.csv', $this->directory . '/a.csv']);

        $this->assertSame(['b.csv', 'a.csv'], $this->names($files));
    }

    public function testFileAndDirectoryTogether(): void
    {
        $files = (new FileCollector())->collect([$this->directory . '/sub/d.csv', $this->directory]);

        $this->assertSame(['d.csv', 'C.CSV', 'a.csv', 'b.csv'], $this->names($files));
    }

    public function testDuplicateFilesAreCountedOnce(): void
    {
        $files = (new FileCollector())->collect([
            $this->directory . '/a.csv',
            $this->directory . '/./a.csv',
            $this->directory,
        ]);

        $this->assertSame(['a.csv', 'C.CSV', 'b.csv'], $this->names($files));
    }

    public function testWildcard(): void
    {
        $files = (new FileCollector())->collect([$this->directory . '/*.csv']);

        $this->assertSame(['a.csv', 'b.csv'], $this->names($files));
    }

    public function testThrowsWithoutPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FileCollector())->collect([]);
    }

    public function testThrowsOnNonExistentPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bestaat niet');

        (new FileCollector())->collect([$this->directory . '/bestaat-niet.csv']);
    }

    public function testThrowsOnNonCsvFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Geen CSV-bestand');

        (new FileCollector())->collect([$this->directory . '/notities.txt']);
    }

    public function testThrowsWhenDirectoryIsEmpty(): void
    {
        $emptyDirectory = $this->directory . '/leeg';
        mkdir($emptyDirectory);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Geen CSV-bestanden gevonden');

        (new FileCollector())->collect([$emptyDirectory]);
    }
}
