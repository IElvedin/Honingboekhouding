<?php

declare(strict_types=1);

namespace App\Tests\Report;

use App\Database;
use App\Report\ReportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportServiceTest extends TestCase
{
    private Database $db;
    private ReportService $service;

    protected function setUp(): void
    {
        $this->db      = new Database(':memory:');
        $this->service = new ReportService($this->db);
    }

    /**
     * Adds a harvest; member and beehouse are created when they do not exist yet.
     */
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

    // ---------------------------------------------------------------- years

    public function testAvailableYears(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 1000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2023-05-01', 1000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-06-01', 1000);

        $this->assertSame([2023, 2025], $this->service->availableYears());
    }

    public function testAvailableYearsEmptyDatabase(): void
    {
        $this->assertSame([], $this->service->availableYears());
    }

    // ---------------------------------------------------------------- default year

    public function testDefaultYearIsLastYearWithData(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2024-05-01', 1000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 1000);

        // Still 2025 in 2026 and in 2027, as long as there is no newer data
        $this->assertSame(2025, $this->service->defaultYear(2026));
        $this->assertSame(2025, $this->service->defaultYear(2027));
    }

    public function testDefaultYearMovesOnOnceDataExists(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 1000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2026-05-01', 1000);

        $this->assertSame(2026, $this->service->defaultYear(2027));
    }

    public function testDefaultYearEmptyDatabaseIsPreviousYear(): void
    {
        $this->assertSame(2025, $this->service->defaultYear(2026));
    }

    // ---------------------------------------------------------------- report 1

    public function testYieldPerBeehousePerYear(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 10000);
        $this->addHarvest('Eva Jansen', 'Bosrand', 'VOLK-2', '2025-06-01', 2500);
        $this->addHarvest('Rik Bos', 'Heideveld', 'VOLK-1', '2025-07-01', 605);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2024-05-01', 8000);

        $this->assertSame([
            ['jaar' => 2024, 'bijenstand' => 'Bosrand',   'aantal_oogsten' => 1, 'totaal_gram' => 8000,  'totaal_kg' => 8.0],
            ['jaar' => 2025, 'bijenstand' => 'Bosrand',   'aantal_oogsten' => 2, 'totaal_gram' => 12500, 'totaal_kg' => 12.5],
            ['jaar' => 2025, 'bijenstand' => 'Heideveld', 'aantal_oogsten' => 1, 'totaal_gram' => 605,   'totaal_kg' => 0.605],
        ], $this->service->yieldPerBeehousePerYear());
    }

    public function testYieldEmptyDatabase(): void
    {
        $this->assertSame([], $this->service->yieldPerBeehousePerYear());
    }

    // ---------------------------------------------------------------- report 2

    public function testTopColoniesByTotalYield(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 10000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-08-01', 15000);   // Rik's VOLK-1: 25 kg
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-2', '2025-05-01', 20000);   // 20 kg
        $this->addHarvest('Eva Jansen', 'Heideveld', 'VOLK-1', '2025-05-01', 30000); // other member, same code: 30 kg

        $top = $this->service->topColonies(2025);

        $this->assertSame(
            [[1, 'Eva Jansen', 'VOLK-1', 30000], [2, 'Rik Bos', 'VOLK-1', 25000], [3, 'Rik Bos', 'VOLK-2', 20000]],
            array_map(fn (array $v): array => [$v['positie'], $v['lid'], $v['volk'], $v['totaal_gram']], $top)
        );
        $this->assertSame(2, $top[1]['aantal_oogsten']);
        $this->assertSame(25.0, $top[1]['totaal_kg']);
    }

    public function testSameCodeForTwoMembersIsTwoColonies(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-12', '2025-05-01', 1000);
        $this->addHarvest('Eva Jansen', 'Bosrand', 'VOLK-12', '2025-05-01', 1000);

        $this->assertCount(2, $this->service->topColonies(2025));
    }

    public function testTopColoniesAtMostFive(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->addHarvest('Rik Bos', 'Bosrand', "VOLK-$i", '2025-05-01', $i * 1000);
        }

        $top = $this->service->topColonies(2025);

        $this->assertCount(5, $top);
        $this->assertSame(['VOLK-8', 'VOLK-7', 'VOLK-6', 'VOLK-5', 'VOLK-4'], array_column($top, 'volk'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($top, 'positie'));
    }

    public function testTopColoniesOtherCount(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->addHarvest('Rik Bos', 'Bosrand', "VOLK-$i", '2025-05-01', $i * 1000);
        }

        $this->assertCount(3, $this->service->topColonies(2025, 3));
    }

    public function testTopColoniesOnlyChosenYear(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2024-12-31', 99000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-2', '2025-01-01', 1000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-3', '2025-12-31', 2000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-4', '2026-01-01', 99000);

        $this->assertSame(['VOLK-3', 'VOLK-2'], array_column($this->service->topColonies(2025), 'volk'));
    }

    public function testEqualYieldSharesPlaceOrderedByNameAndCode(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-2', '2025-05-01', 5000);
        $this->addHarvest('Eva Jansen', 'Bosrand', 'VOLK-9', '2025-05-01', 5000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 5000);

        $this->assertSame(
            ['1 Eva Jansen VOLK-9', '1 Rik Bos VOLK-1', '1 Rik Bos VOLK-2'],
            array_map(fn (array $v): string => "{$v['positie']} {$v['lid']} {$v['volk']}", $this->service->topColonies(2025))
        );
    }

    public function testPlaceIsSkippedAfterSharedPlace(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 9000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-2', '2025-05-01', 7000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-3', '2025-05-01', 7000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-4', '2025-05-01', 5000);

        // 1, 2, 2, 4 (like a sports ranking), not 1, 2, 2, 3
        $this->assertSame([1, 2, 2, 4], array_column($this->service->topColonies(2025), 'positie'));
    }

    public function testSharedLastPlaceIsFullyIncluded(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 9000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-2', '2025-05-01', 8000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-3', '2025-05-01', 7000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-4', '2025-05-01', 6000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-5', '2025-05-01', 5000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-6', '2025-05-01', 5000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-7', '2025-05-01', 4000);

        $top = $this->service->topColonies(2025);

        // Two colonies share place 5: both are in the top 5, so 6 rows
        $this->assertSame([1, 2, 3, 4, 5, 5], array_column($top, 'positie'));
        $this->assertSame(['VOLK-5', 'VOLK-6'], array_column(array_slice($top, 4), 'volk'));
    }

    public function testSharedPlaceBeyondLimitIsExcluded(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->addHarvest('Rik Bos', 'Bosrand', "VOLK-$i", '2025-05-01', 10000 - $i * 1000);
        }
        // Place 6, shared: falls outside the top 5
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-6', '2025-05-01', 1000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-7', '2025-05-01', 1000);

        $this->assertCount(5, $this->service->topColonies(2025));
    }

    public function testRelocatedColonyShowsAllBeehouses(): void
    {
        $this->addHarvest('Fatima El Idrissi', 'Zonneweide', 'V-100', '2025-07-22', 18500);
        $this->addHarvest('Fatima El Idrissi', 'Bosrand', 'V-100', '2025-05-10', 11000);

        $top = $this->service->topColonies(2025);

        $this->assertSame(['Bosrand', 'Zonneweide'], $top[0]['bijenstanden']);
        $this->assertSame(29500, $top[0]['totaal_gram']);
    }

    public function testTopColoniesYearWithoutHarvests(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 1000);

        $this->assertSame([], $this->service->topColonies(2020));
    }

    public function testTopColoniesThrowsOnCountZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->topColonies(2025, 0);
    }

    // ---------------------------------------------------------------- report 3

    public function testMembersWithoutSubmission(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2024-06-01', 1000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2024-08-15', 1000);
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 1000);   // does submit in 2025
        $this->addHarvest('Eva Jansen', 'Bosrand', 'VOLK-2', '2024-07-01', 1000); // only 2024
        $this->addHarvest('Amal Hassan', 'Bosrand', 'VOLK-3', '2023-05-01', 1000); // only 2023

        $this->assertSame([
            ['lid' => 'Amal Hassan', 'laatste_aanlevering' => '2023-05-01'],
            ['lid' => 'Eva Jansen',  'laatste_aanlevering' => '2024-07-01'],
        ], $this->service->membersWithoutSubmission(2025));
    }

    public function testLastSubmissionIgnoresLaterSeasons(): void
    {
        $this->addHarvest('Eva Jansen', 'Bosrand', 'VOLK-2', '2023-07-01', 1000);
        $this->addHarvest('Eva Jansen', 'Bosrand', 'VOLK-2', '2026-07-01', 1000);

        // For season 2025: the last submission before 2025, not the one from 2026
        $this->assertSame(
            [['lid' => 'Eva Jansen', 'laatste_aanlevering' => '2023-07-01']],
            $this->service->membersWithoutSubmission(2025)
        );
    }

    public function testEveryoneSubmittedGivesEmptyList(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 1000);
        $this->addHarvest('Eva Jansen', 'Bosrand', 'VOLK-2', '2025-06-01', 1000);

        $this->assertSame([], $this->service->membersWithoutSubmission(2025));
    }

    public function testNewSeasonWithoutHarvestsGivesAllMembers(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 1000);
        $this->addHarvest('Eva Jansen', 'Bosrand', 'VOLK-2', '2025-06-01', 1000);

        $this->assertSame(
            ['Eva Jansen', 'Rik Bos'],
            array_column($this->service->membersWithoutSubmission(2026), 'lid')
        );
    }

    public function testMemberWithoutEarlierHarvestHasNoLastSubmission(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2026-05-01', 1000);

        $this->assertSame(
            [['lid' => 'Rik Bos', 'laatste_aanlevering' => null]],
            $this->service->membersWithoutSubmission(2025)
        );
    }

    // ---------------------------------------------------------------- year validation

    public static function invalidYears(): array
    {
        return ['zero' => [0], 'negative' => [-2025], 'two digits' => [25], 'too large' => [30000]];
    }

    #[DataProvider('invalidYears')]
    public function testTopColoniesThrowsOnInvalidYear(int $year): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->topColonies($year);
    }

    #[DataProvider('invalidYears')]
    public function testMembersWithoutSubmissionThrowsOnInvalidYear(int $year): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->membersWithoutSubmission($year);
    }
}
