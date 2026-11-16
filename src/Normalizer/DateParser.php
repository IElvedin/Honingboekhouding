<?php

declare(strict_types=1);

namespace App\Normalizer;

/**
 * Converts a date from a CSV cell to ISO format (YYYY-MM-DD).
 */
class DateParser
{
    // Dutch month names, in full and abbreviated
    private const array MONTHS = [
        'januari'   => 1,  'jan'  => 1,
        'februari'  => 2,  'feb'  => 2,
        'maart'     => 3,  'mrt'  => 3, 'mar' => 3,
        'april'     => 4,  'apr'  => 4,
        'mei'       => 5,
        'juni'      => 6,  'jun'  => 6,
        'juli'      => 7,  'jul'  => 7,
        'augustus'  => 8,  'aug'  => 8,
        'september' => 9,  'sep'  => 9, 'sept' => 9,
        'oktober'   => 10, 'okt'  => 10,
        'november'  => 11, 'nov'  => 11,
        'december'  => 12, 'dec'  => 12,
    ];

    /**
     * Supports:
     *   2025-05-28   2025/07/05            (year first)
     *   02-07-2025   01/09/2025  26.07.2025 (day first, Dutch order)
     *   14 september 2025   7 jul 2025    (Dutch month name)
     *
     * @throws \InvalidArgumentException when the date cannot be read or does not exist
     */
    public function parse(string $value): string
    {
        $clean = strtolower(trim($value));

        if (preg_match('#^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$#', $clean, $matches)) {
            // Year first: 2025-05-28, 2025/07/05
            $year  = $matches[1];
            $month = $matches[2];
            $day   = $matches[3];
        } elseif (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$#', $clean, $matches)) {
            // Day first: 02-07-2025, 01/09/2025, 26.07.2025
            $day   = $matches[1];
            $month = $matches[2];
            $year  = $matches[3];
        } elseif (preg_match('#^(\d{1,2})\s+([a-z]+)\.?\s+(\d{4})$#', $clean, $matches)) {
            // Month name: 14 september 2025, 7 jul 2025
            $day       = $matches[1];
            $monthName = $matches[2];
            $year      = $matches[3];

            if (!isset(self::MONTHS[$monthName])) {
                throw new \InvalidArgumentException("Onbekende maand '$monthName' in datum '$value'");
            }

            $month = self::MONTHS[$monthName];
        } else {
            throw new \InvalidArgumentException("Onbekend datumformaat: '$value'");
        }

        $day   = (int) $day;
        $month = (int) $month;
        $year  = (int) $year;

        // Catches e.g. 31-02-2025 and a 13th month
        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException("Ongeldige datum: '$value'");
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
