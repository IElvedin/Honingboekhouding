<?php

declare(strict_types=1);

namespace App\Report;

use App\Database;

/**
 * The three reports for the annual meeting.
 *
 * Returns data only (arrays), no formatting. That way both the terminal
 * (report command) and the browser (JSON endpoint) can use the same service.
 *
 * The array keys are Dutch on purpose: they are passed straight through as
 * the keys of the public JSON endpoint.
 *
 * Weights are returned in grams (exact, integer) and in kilograms (rounded
 * to 3 decimals), so the presentation layer can choose.
 */
class ReportService
{
    private readonly Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * All years in which harvests were recorded, ascending.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $years = $this->db->pdo()
            ->query('SELECT DISTINCT CAST(substr(harvest_date, 1, 4) AS INTEGER) FROM harvests ORDER BY 1')
            ->fetchAll(\PDO::FETCH_COLUMN);

        return array_map('intval', $years);
    }

    /**
     * The year the reports show by default: the latest year for which harvests
     * have been submitted. Members submit per season, so the report only moves
     * on to a new year once there is data for it.
     *
     * With an empty database: the previous calendar year.
     *
     * @param int $currentYear passed in instead of calling date('Y'), so it can be tested
     */
    public function defaultYear(int $currentYear): int
    {
        $years = $this->availableYears();

        return $years === [] ? $currentYear - 1 : max($years);
    }

    /**
     * Report 1: total yield per beehouse per year.
     *
     * @return list<array{jaar: int, bijenstand: string, aantal_oogsten: int, totaal_gram: int, totaal_kg: float}>
     */
    public function yieldPerBeehousePerYear(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT CAST(substr(h.harvest_date, 1, 4) AS INTEGER) AS jaar,
                    b.naam                                       AS bijenstand,
                    COUNT(*)                                     AS aantal_oogsten,
                    SUM(h.weight_grams)                          AS totaal_gram
             FROM harvests h
             JOIN beehouses b ON b.id = h.beehouse_id
             GROUP BY jaar, b.naam
             ORDER BY jaar, b.naam'
        )->fetchAll();

        return array_map(fn (array $row): array => [
            'jaar'           => (int) $row['jaar'],
            'bijenstand'     => $row['bijenstand'],
            'aantal_oogsten' => (int) $row['aantal_oogsten'],
            'totaal_gram'    => (int) $row['totaal_gram'],
            'totaal_kg'      => $this->toKilograms((int) $row['totaal_gram']),
        ], $rows);
    }

    /**
     * Report 2: the best performing colonies of a year, by total yield.
     *
     * A colony is the combination member + colony code (see README, "Volken"):
     * the same code is used by many different members.
     *
     * Equal yield = shared place, like in a sports ranking: 1, 2, 2, 4, 5.
     * When colonies share the last place, all of them are included, so the list
     * can be longer than $limit. Within a shared place, colonies are ordered by
     * member name and then by colony code.
     *
     * @return list<array{
     *     positie: int, lid: string, volk: string, bijenstanden: list<string>,
     *     aantal_oogsten: int, totaal_gram: int, totaal_kg: float
     * }>
     *
     * @throws \InvalidArgumentException on an invalid year or limit
     */
    public function topColonies(int $year, int $limit = 5): array
    {
        $this->assertValidYear($year);

        if ($limit < 1) {
            throw new \InvalidArgumentException("Aantal moet minstens 1 zijn, gekregen: $limit");
        }

        // A colony can move during the season, so show all of its beehouses.
        // GROUP_CONCAT(DISTINCT ...) separates with a comma; beehouse names contain no commas.
        // RANK() gives equal totals the same place and then skips places (1, 2, 2, 4).
        // Filter on the place instead of LIMIT, so shared last places are all included.
        $statement = $this->db->pdo()->prepare(
            "SELECT * FROM (
                 SELECT RANK() OVER (ORDER BY SUM(h.weight_grams) DESC) AS positie,
                        m.naam                                      AS lid,
                        h.colony_code                               AS volk,
                        GROUP_CONCAT(DISTINCT b.naam)               AS bijenstanden,
                        COUNT(*)                                    AS aantal_oogsten,
                        SUM(h.weight_grams)                         AS totaal_gram
                 FROM harvests h
                 JOIN members   m ON m.id = h.member_id
                 JOIN beehouses b ON b.id = h.beehouse_id
                 WHERE h.harvest_date BETWEEN :from AND :to
                 GROUP BY h.member_id, h.colony_code
             )
             WHERE positie <= :limit
             ORDER BY positie, lid, volk"
        );
        $statement->bindValue('from', sprintf('%04d-01-01', $year));
        $statement->bindValue('to', sprintf('%04d-12-31', $year));
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        $result = [];

        foreach ($statement->fetchAll() as $row) {
            $beehouses = explode(',', $row['bijenstanden']);
            sort($beehouses);

            $result[] = [
                'positie'        => (int) $row['positie'],
                'lid'            => $row['lid'],
                'volk'           => $row['volk'],
                'bijenstanden'   => $beehouses,
                'aantal_oogsten' => (int) $row['aantal_oogsten'],
                'totaal_gram'    => (int) $row['totaal_gram'],
                'totaal_kg'      => $this->toKilograms((int) $row['totaal_gram']),
            ];
        }

        return $result;
    }

    /**
     * Report 3: members who have not submitted anything yet in a season.
     *
     * There is no member register. "All members" are therefore all members who
     * have ever submitted a harvest (table members). A new member who has never
     * submitted anything is unknown and does not show up here.
     *
     * @return list<array{lid: string, laatste_aanlevering: ?string}>
     *         laatste_aanlevering = date of the last harvest before this season, or null
     *
     * @throws \InvalidArgumentException on an invalid year
     */
    public function membersWithoutSubmission(int $season): array
    {
        $this->assertValidYear($season);

        $statement = $this->db->pdo()->prepare(
            'SELECT m.naam AS lid,
                    (SELECT MAX(h.harvest_date) FROM harvests h
                     WHERE h.member_id = m.id AND h.harvest_date < :from) AS laatste_aanlevering
             FROM members m
             WHERE NOT EXISTS (
                 SELECT 1 FROM harvests h
                 WHERE h.member_id = m.id AND h.harvest_date BETWEEN :from AND :to
             )
             ORDER BY m.naam'
        );
        $statement->execute([
            'from' => sprintf('%04d-01-01', $season),
            'to'   => sprintf('%04d-12-31', $season),
        ]);

        return array_map(fn (array $row): array => [
            'lid'                 => $row['lid'],
            'laatste_aanlevering' => $row['laatste_aanlevering'],
        ], $statement->fetchAll());
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertValidYear(int $year): void
    {
        if ($year < 1900 || $year > 2999) {
            throw new \InvalidArgumentException("Ongeldig jaar: $year");
        }
    }

    private function toKilograms(int $grams): float
    {
        return round($grams / 1000, 3);
    }
}
