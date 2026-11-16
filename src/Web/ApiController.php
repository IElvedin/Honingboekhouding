<?php

declare(strict_types=1);

namespace App\Web;

use App\Report\ReportService;

/**
 * The JSON endpoint for the reports in the browser.
 *
 *   GET /api/report                       all three reports for the default year
 *   GET /api/report?year=2025             same, for a chosen year
 *   GET /api/report?type=top&year=2025    a single report (opbrengst | top | leden)
 *
 * Knows nothing about $_GET, header() or echo: it receives the request and
 * returns a status + data. That makes it testable without a web server;
 * public/index.php does the sending.
 *
 * The JSON keys, type values and error messages are Dutch: they form the
 * public API for a Dutch association.
 */
class ApiController
{
    private const array TYPES = ['opbrengst', 'top', 'leden'];

    private readonly ReportService $service;

    private readonly int $currentYear;

    public function __construct(ReportService $service, int $currentYear)
    {
        $this->service     = $service;
        $this->currentYear = $currentYear;
    }

    /**
     * @param array<string, mixed> $query the query parameters, e.g. $_GET
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $method, string $path, array $query): array
    {
        if ($path !== '/api/report') {
            return $this->error(404, "Onbekend endpoint: $path. Gebruik /api/report");
        }

        if ($method !== 'GET') {
            return $this->error(405, "Methode $method niet toegestaan, gebruik GET");
        }

        $unknown = array_diff(array_keys($query), ['year', 'type']);
        if ($unknown !== []) {
            return $this->error(400, 'Onbekende parameter: ' . implode(', ', $unknown));
        }

        // Year: 4 digits, otherwise the default year (latest year with data)
        if (isset($query['year'])) {
            if (!is_string($query['year']) || !preg_match('/^\d{4}$/', $query['year'])) {
                return $this->error(400, 'Ongeldig jaar, gebruik 4 cijfers, bv. ?year=2025');
            }
            
            $year = (int) $query['year'];
        } else {
            $year = $this->service->defaultYear($this->currentYear);
        }

        $type = $query['type'] ?? null;
        if ($type !== null && !in_array($type, self::TYPES, true)) {
            return $this->error(400, 'Ongeldig type, kies uit: ' . implode(', ', self::TYPES));
        }

        try {
            $body = [
                'jaar'              => $year,
                'beschikbare_jaren' => $this->service->availableYears(),
            ];

            if ($type === null || $type === 'opbrengst') {
                $body['opbrengst_per_bijenstand_per_jaar'] = $this->service->yieldPerBeehousePerYear();
            }
            if ($type === null || $type === 'top') {
                $body['top_volken'] = $this->service->topColonies($year);
            }
            if ($type === null || $type === 'leden') {
                $body['leden_zonder_aanlevering'] = $this->service->membersWithoutSubmission($year);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->error(400, $e->getMessage());
        }

        return ['status' => 200, 'body' => $body];
    }

    /**
     * @return array{status: int, body: array{fout: string}}
     */
    private function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['fout' => $message]];
    }
}
