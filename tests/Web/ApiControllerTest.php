<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Database;
use App\Report\ReportService;
use App\Web\ApiController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApiControllerTest extends TestCase
{
    private Database $db;
    private ApiController $controller;

    protected function setUp(): void
    {
        $this->db         = new Database(':memory:');
        $this->controller = new ApiController(new ReportService($this->db), 2026);
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

    private function get(array $query = [], string $path = '/api/report'): array
    {
        return $this->controller->handle('GET', $path, $query);
    }

    public function testWithoutParametersAllReportsForDefaultYear(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 1000);
        $this->addHarvest('Eva Jansen', 'Bosrand', 'VOLK-2', '2024-05-01', 2000);

        $response = $this->get();

        $this->assertSame(200, $response['status']);
        $this->assertSame(2025, $response['body']['jaar']); // latest year with data, not 2026
        $this->assertSame([2024, 2025], $response['body']['beschikbare_jaren']);
        $this->assertCount(2, $response['body']['opbrengst_per_bijenstand_per_jaar']);
        $this->assertSame('Rik Bos', $response['body']['top_volken'][0]['lid']);
        $this->assertSame('Eva Jansen', $response['body']['leden_zonder_aanlevering'][0]['lid']);
    }

    public function testChosenYear(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 1000);
        $this->addHarvest('Eva Jansen', 'Bosrand', 'VOLK-2', '2024-05-01', 2000);

        $body = $this->get(['year' => '2024'])['body'];

        $this->assertSame(2024, $body['jaar']);
        $this->assertSame('Eva Jansen', $body['top_volken'][0]['lid']);
        $this->assertSame('Rik Bos', $body['leden_zonder_aanlevering'][0]['lid']);
    }

    public static function types(): array
    {
        return [
            'opbrengst' => ['opbrengst', 'opbrengst_per_bijenstand_per_jaar'],
            'top'       => ['top', 'top_volken'],
            'leden'     => ['leden', 'leden_zonder_aanlevering'],
        ];
    }

    #[DataProvider('types')]
    public function testTypeReturnsOnlyThatReport(string $type, string $key): void
    {
        $body = $this->get(['type' => $type])['body'];

        $this->assertSame(['jaar', 'beschikbare_jaren', $key], array_keys($body));
    }

    public function testEmptyDatabase(): void
    {
        $response = $this->get();

        $this->assertSame(200, $response['status']);
        $this->assertSame(2025, $response['body']['jaar']); // previous year
        $this->assertSame([], $response['body']['top_volken']);
    }

    public function testJsonIsValidAndContainsNumbersAsNumbers(): void
    {
        $this->addHarvest('Rik Bos', 'Bosrand', 'VOLK-1', '2025-05-01', 605);

        $json = json_encode($this->get()['body'], JSON_THROW_ON_ERROR);
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(605, $data['top_volken'][0]['totaal_gram']);
        $this->assertSame(0.605, $data['top_volken'][0]['totaal_kg']);
    }

    public static function invalidRequests(): array
    {
        return [
            'year two digits'      => [['year' => '25'], 400, 'Ongeldig jaar'],
            'year as text'         => [['year' => 'abc'], 400, 'Ongeldig jaar'],
            'year as array'        => [['year' => ['2025']], 400, 'Ongeldig jaar'],
            'year out of range'    => [['year' => '0025'], 400, 'Ongeldig jaar: 25'],
            'unknown type'         => [['type' => 'alles'], 400, 'Ongeldig type'],
            'unknown parameter'    => [['jaar' => '2025'], 400, 'Onbekende parameter: jaar'],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidRequestGives400WithErrorMessage(array $query, int $status, string $message): void
    {
        $response = $this->get($query);

        $this->assertSame($status, $response['status']);
        $this->assertStringContainsString($message, $response['body']['fout']);
    }

    public function testUnknownPathGives404(): void
    {
        $response = $this->get([], '/api/rapport');

        $this->assertSame(404, $response['status']);
        $this->assertArrayHasKey('fout', $response['body']);
    }

    public function testOtherMethodGives405(): void
    {
        $response = $this->controller->handle('POST', '/api/report', []);

        $this->assertSame(405, $response['status']);
    }
}
