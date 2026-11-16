<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Turns the reports from ReportService into text for the terminal.
 */
class ReportPrinter
{
    private readonly ReportService $service;

    public function __construct(ReportService $service)
    {
        $this->service = $service;
    }

    /**
     * @param int $year the season for the top 5 and for "nothing submitted yet"
     */
    public function render(int $year): string
    {
        $availableYears = $this->service->availableYears();

        $text  = $this->renderYieldPerBeehouse();
        $text .= PHP_EOL . $this->renderTopColonies($year, $availableYears);
        $text .= PHP_EOL . $this->renderMembersWithoutSubmission($year);

        return $text;
    }

    private function renderYieldPerBeehouse(): string
    {
        $text = $this->heading('Totale opbrengst per bijenstand per jaar');

        $rows = $this->service->yieldPerBeehousePerYear();
        if ($rows === []) {
            return $text . '  Geen oogsten in de database. Draai eerst "php bin/honing.php import <map>".' . PHP_EOL;
        }

        $table = [];
        $currentYear = null;
        $yearGrams = 0;
        $yearHarvests = 0;

        foreach ($rows as $row) {
            if ($currentYear !== null && $row['jaar'] !== $currentYear) {
                $table[] = ['', 'Totaal', $this->formatNumber($yearHarvests), $this->formatKg($yearGrams)];
                $table[] = null; // empty line between years
                $yearGrams = 0;
                $yearHarvests = 0;
            }

            $table[] = [
                $row['jaar'] === $currentYear ? '' : (string) $row['jaar'],
                $row['bijenstand'],
                $this->formatNumber($row['aantal_oogsten']),
                $this->formatKg($row['totaal_gram']),
            ];

            $currentYear   = $row['jaar'];
            $yearGrams    += $row['totaal_gram'];
            $yearHarvests += $row['aantal_oogsten'];
        }
        $table[] = ['', 'Totaal', $this->formatNumber($yearHarvests), $this->formatKg($yearGrams)];

        return $text . $this->table(['Jaar', 'Bijenstand', 'Oogsten', 'Opbrengst'], $table, rightAligned: [2, 3]);
    }

    /**
     * @param list<int> $availableYears
     */
    private function renderTopColonies(int $year, array $availableYears): string
    {
        $text = $this->heading("Top 5 best presterende volken in $year");

        $colonies = $this->service->topColonies($year);
        if ($colonies === []) {
            return $text . $this->renderNoHarvests($year, $availableYears);
        }

        $table = array_map(fn (array $colony): array => [
            $colony['positie'] . '.',
            $colony['lid'],
            $colony['volk'],
            $this->formatNumber($colony['aantal_oogsten']),
            $this->formatKg($colony['totaal_gram']),
            implode(', ', $colony['bijenstanden']),
        ], $colonies);

        return $text
            . $this->table(['#', 'Lid', 'Volk', 'Oogsten', 'Opbrengst', 'Bijenstand(en)'], $table, rightAligned: [0, 3, 4])
            . '  Een volk = lid + volkcode; gelijke opbrengst = gedeelde plaats.' . PHP_EOL;
    }

    private function renderMembersWithoutSubmission(int $year): string
    {
        $text = $this->heading("Leden die in $year nog niets hebben aangeleverd");

        $members = $this->service->membersWithoutSubmission($year);
        if ($members === []) {
            return $text . '  Alle bekende leden hebben aangeleverd.' . PHP_EOL;
        }

        $table = array_map(fn (array $member): array => [
            $member['lid'],
            $member['laatste_aanlevering'] !== null ? $this->formatDate($member['laatste_aanlevering']) : '-',
        ], $members);

        return $text
            . $this->table(['Lid', 'Laatste aanlevering'], $table)
            . sprintf('  %d lid/leden. Alleen leden die ooit een oogst hebben aangeleverd zijn bekend.', count($members))
            . PHP_EOL;
    }

    /**
     * @param list<int> $availableYears
     */
    private function renderNoHarvests(int $year, array $availableYears): string
    {
        $text = "  Geen oogsten in $year.";

        if ($availableYears !== []) {
            $text .= ' Jaren met oogsten: ' . implode(', ', $availableYears)
                . '. Kies een jaar met --year=' . end($availableYears) . '.';
        }

        return $text . PHP_EOL;
    }

    private function heading(string $title): string
    {
        return $title . PHP_EOL . str_repeat('=', mb_strlen($title)) . PHP_EOL;
    }

    /**
     * Simple text table with each column as wide as its widest value.
     *
     * @param list<string>             $headers
     * @param list<list<string>|null>  $rows          null = empty line
     * @param list<int>                $rightAligned  columns aligned to the right (numbers)
     */
    private function table(array $headers, array $rows, array $rightAligned = []): string
    {
        $widths = array_map('mb_strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row ?? [] as $i => $value) {
                $widths[$i] = max($widths[$i], mb_strlen($value));
            }
        }

        $line = function (array $values) use ($widths, $rightAligned): string {
            $cells = [];
            
            foreach ($values as $i => $value) {
                $cells[] = mb_str_pad($value, $widths[$i], ' ', in_array($i, $rightAligned, true) ? STR_PAD_LEFT : STR_PAD_RIGHT);
            }

            return rtrim('  ' . implode('   ', $cells)) . PHP_EOL;
        };

        $text  = $line($headers);
        $text .= $line(array_map(fn (int $width): string => str_repeat('-', $width), $widths));

        foreach ($rows as $row) {
            $text .= $row === null ? PHP_EOL : $line($row);
        }

        return $text;
    }

    /** 43794261 -> "43.794,3 kg" (Dutch notation) */
    private function formatKg(int $grams): string
    {
        return number_format($grams / 1000, 1, ',', '.') . ' kg';
    }

    /** 3283 -> "3.283" (Dutch notation) */
    private function formatNumber(int $number): string
    {
        return number_format($number, 0, ',', '.');
    }

    /** 2025-09-29 -> "29-09-2025" */
    private function formatDate(string $iso): string
    {
        $parts = explode('-', $iso);
        $year  = $parts[0];
        $month = $parts[1];
        $day   = $parts[2];

        return "$day-$month-$year";
    }
}
