<?php

declare(strict_types=1);

namespace App\Tests\Report;

use App\Database;
use App\Report\ReportPrinter;
use App\Report\ReportService;
use PHPUnit\Framework\TestCase;

class ReportPrinterTest extends TestCase
{
    private Database $db;
    private ReportPrinter $printer;

    protected function setUp(): void
    {
        $this->db      = new Database(':memory:');
        $this->printer = new ReportPrinter(new ReportService($this->db));
    }

    private function addHarvest(string $member, string $beehouse, string $colony, string $date, int $grams): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT OR IGNORE INTO members (naam) VALUES (?)')->execute([$member]);
        $pdo->prepare('INSERT OR IGNORE INTO beehouses (naam) VALUES (?)')->execute([$beehouse]);

        static $line = 1;
        $pdo->prepare(
            'INSERT INTO harvests (member_id, beehouse_id, colony_code, harvest_date, weight_grams, source_file, source_line)
             VALUES ((SELECT id FROM members WHERE naam = ?), (SELECT id FROM beehouses WHERE naam = ?), ?, ?, ?, ?, ?)'
        )->execute([$member, $beehouse, $colony, $date, $grams, 'test.csv', $line++]);
    }

    public function testShowsThreeReportsWithHeadings(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 10000);

        $text = $this->printer->render(2025);

        $this->assertStringContainsString('Totale opbrengst per bijenstand per jaar', $text);
        $this->assertStringContainsString('Top 5 best presterende volken in 2025', $text);
        $this->assertStringContainsString('Leden die in 2025 nog niets hebben aangeleverd', $text);
    }

    public function testYieldWithDutchNumbersAndYearTotal(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 43794261);
        $this->addHarvest('Rik Bos', 'Heideveld', 'VOLK-1', '2025-05-02', 1000);

        $text = $this->printer->render(2025);

        $this->assertMatchesRegularExpression('/2025\s+Bosrand\s+1\s+43\.794,3 kg/', $text);
        $this->assertMatchesRegularExpression('/Totaal\s+2\s+43\.795,3 kg/', $text);
    }

    public function testTotalPerYearWithMultipleYears(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2024-05-01', 1000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 2000);
        $this->addHarvest('Rik Bos', 'Heideveld', 'VOLK-1', '2025-05-01', 3000);

        $text = $this->printer->render(2025);

        $this->assertSame(2, substr_count($text, 'Totaal'));
        $this->assertMatchesRegularExpression('/2024\s+Bosrand/', $text);
        $this->assertMatchesRegularExpression('/Totaal\s+2\s+5,0 kg/', $text);
    }

    public function testTopColoniesWithSharedPlace(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 9000);
        $this->addHarvest('Eva Jansen', 'Bosrand', 'VOLK-2', '2025-05-01', 7000);
        $this->addHarvest('Rik Bos', 'Heideveld', 'VOLK-3', '2025-05-01', 7000);

        $text = $this->printer->render(2025);

        $this->assertMatchesRegularExpression('/1\.\s+Rik Bos\s+VOLK-1\s+1\s+9,0 kg\s+Bosrand/', $text);
        $this->assertMatchesRegularExpression('/2\.\s+Eva Jansen\s+VOLK-2/', $text);
        $this->assertMatchesRegularExpression('/2\.\s+Rik Bos\s+VOLK-3/', $text);
    }

    public function testYearWithoutHarvestsPointsToAvailableYears(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 1000);

        $text = $this->printer->render(2026);

        $this->assertStringContainsString('Geen oogsten in 2026. Jaren met oogsten: 2025. Kies een jaar met --year=2025.', $text);
    }

    public function testMembersWithoutSubmissionWithLastDate(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-09-29', 1000);
        $this->addHarvest('Eva Jansen', 'Bosrand', 'VOLK-1', '2026-05-01', 1000);

        $text = $this->printer->render(2026);

        $this->assertMatchesRegularExpression('/Rik Bos\s+29-09-2025/', $text);
        $this->assertStringNotContainsString('Eva Jansen', explode('nog niets hebben aangeleverd', $text)[1]);
        $this->assertStringContainsString('1 lid/leden.', $text);
    }

    public function testEveryoneSubmitted(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 1000);

        $this->assertStringContainsString('Alle bekende leden hebben aangeleverd.', $this->printer->render(2025));
    }

    public function testEmptyDatabase(): void
    {
        $text = $this->printer->render(2025);

        $this->assertStringContainsString('Geen oogsten in de database.', $text);
        $this->assertStringContainsString('Geen oogsten in 2025.', $text);
        $this->assertStringContainsString('Alle bekende leden hebben aangeleverd.', $text);
    }

    public function testColumnsAreAligned(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 1000);
        $this->addHarvest('Leonie van den Berg', 'Achter de Kerk', 'KONINGIN-M', '2025-05-01', 123456);

        $lines = explode(PHP_EOL, $this->printer->render(2025));
        $colonies = array_values(array_filter($lines, fn (string $r): bool => str_contains($r, 'VOLK-1') || str_contains($r, 'KONINGIN-M')));

        // The "Volk" column starts at the same position on both lines
        $this->assertSame(strpos($colonies[0], 'KONINGIN-M'), strpos($colonies[1], 'VOLK-1'));
    }
}
