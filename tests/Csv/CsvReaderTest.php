<?php

namespace App\Tests\Csv;

use App\Csv\CsvReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvReaderTest extends TestCase
{

    public static function headers(): array
    {
        return [
            'comma'     => ['Datum,Lidnummer,Naam,Bijenstand', ','],
            'semicolon' => ['Bijenstand;VolkNaam;Imker;OogstDatum', ';'],
            'pipe'      => ['MemberName|MemberID|Apiary', '|'],
            'tab'       => ["Lid\tStand\tVolk\tDatum", "\t"],
        ];
    }

    #[DataProvider('headers')]
    public function testDetectsDelimiter(string $header, string $expected): void
    {
        $this->assertSame($expected, (new CsvReader())->detectDelimiter($header));
    }
}
