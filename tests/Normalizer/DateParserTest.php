<?php

declare(strict_types=1);

namespace App\Tests\Normalizer;

use App\Normalizer\DateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateParserTest extends TestCase
{
    public static function validDates(): array
    {
        return [
            // year first (formats 01, 03, 04, 05, 06)
            'iso'                    => ['2025-05-28', '2025-05-28'],
            'year first with slash'  => ['2025/07/05', '2025-07-05'],
            // day first (formats 03, 06)
            'day first with hyphen'  => ['02-07-2025', '2025-07-02'],
            'day first with slash'   => ['01/09/2025', '2025-09-01'],
            'day first with dot'     => ['26.07.2025', '2025-07-26'],
            'day greater than 12'    => ['19/06/2025', '2025-06-19'],
            // month name (formats 02, 03, 06)
            'month in full'          => ['14 september 2025', '2025-09-14'],
            'month abbreviated'      => ['7 jul 2025', '2025-07-07'],
            'mei'                    => ['19 mei 2025', '2025-05-19'],
            'abbreviation with dot'  => ['3 okt. 2026', '2026-10-03'],
            'sept'                   => ['1 sept 2025', '2025-09-01'],
            // edge cases
            'upper case'             => ['14 September 2025', '2025-09-14'],
            'surrounding spaces'     => ['  2025-05-28  ', '2025-05-28'],
            'single digits'          => ['1-4-2025', '2025-04-01'],
            'leap day'               => ['29-02-2024', '2024-02-29'],
        ];
    }

    #[DataProvider('validDates')]
    public function testConvertsToIso(string $value, string $expected): void
    {
        $parser = new DateParser();

        $this->assertSame($expected, $parser->parse($value));
    }

    public static function invalidDates(): array
    {
        return [
            'empty'                    => [''],
            'text only'                => ['gisteren'],
            'unknown month'            => ['14 smarch 2025'],
            'english month'            => ['14 may 2025'],
            'month 13'                 => ['01-13-2025'],
            'american order'           => ['09/30/2025'],
            '31 february'              => ['31-02-2025'],
            'not a leap year'          => ['29-02-2025'],
            'two-digit year'           => ['01-09-25'],
            'without day'              => ['2025-05'],
        ];
    }

    #[DataProvider('invalidDates')]
    public function testThrowsOnInvalidDate(string $value): void
    {
        $parser = new DateParser();

        $this->expectException(\InvalidArgumentException::class);

        $parser->parse($value);
    }
}
