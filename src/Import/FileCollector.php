<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Turns the paths given on the command line into a list of CSV files.
 *
 * Accepts:
 *   - single files     dataset/01_standaard_export_01.csv
 *   - directories      dataset           (all *.csv inside, not recursive)
 *   - wildcards        dataset/01_*.csv  (PowerShell does not expand these itself)
 */
class FileCollector
{
    /**
     * @param list<string> $paths as typed, e.g. ['dataset'] or ['a.csv', 'b.csv']
     * @return list<string> full paths to CSV files, without duplicates
     *
     * @throws \InvalidArgumentException when a path does not exist, is not a CSV, or nothing is found
     */
    public function collect(array $paths): array
    {
        if ($paths === []) {
            throw new \InvalidArgumentException('Geef minstens één bestand of map op');
        }

        $files = [];

        foreach ($paths as $path) {
            foreach ($this->filesForPath($path) as $file) {
                // realpath as key: "dataset/a.csv" and "./dataset/a.csv" are the same file
                $files[realpath($file)] = true;
            }
        }

        if ($files === []) {
            throw new \InvalidArgumentException('Geen CSV-bestanden gevonden in: ' . implode(', ', $paths));
        }

        return array_keys($files);
    }

    /**
     * @return list<string>
     */
    private function filesForPath(string $path): array
    {
        // Wildcard: expand it ourselves
        if (strpbrk($path, '*?') !== false) {
            $found = array_filter(glob($path) ?: [], fn (string $file): bool => $this->isCsv($file));
            sort($found);

            return $found;
        }

        if (is_dir($path)) {
            return $this->csvFilesInDirectory($path);
        }

        if (is_file($path)) {
            if (!$this->isCsv($path)) {
                throw new \InvalidArgumentException("Geen CSV-bestand: '$path'");
            }

            return [$path];
        }

        throw new \InvalidArgumentException("Bestand of map bestaat niet: '$path'");
    }

    /**
     * All CSV files directly inside the directory, sorted by name.
     *
     * @return list<string>
     */
    private function csvFilesInDirectory(string $directory): array
    {
        $files = [];

        foreach (scandir($directory) as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;

            if (is_file($path) && $this->isCsv($path)) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * A CSV is a file ending in .csv (also .CSV) that is not a hidden or
     * lock file, such as ".~lock.file.csv#" from LibreOffice.
     */
    private function isCsv(string $path): bool
    {
        $name = basename($path);

        return !str_starts_with($name, '.')
            && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'csv';
    }
}
