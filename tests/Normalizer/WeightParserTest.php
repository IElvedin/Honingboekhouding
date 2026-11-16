<?php

declare(strict_types=1);

namespace App\Tests\Normalizer;

use App\Normalizer\WeightParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WeightParserTest extends TestCase
{
    public static function validWeights(): array
    {
        return [
            // formats 01 / 05: kilograms with decimal dot, no unit
            'kilos with dot'         => ['21.9', null, 21900],
            // format 02: grams with unit column
            'grams from column'      => ['19509', 'gram', 19509],
            // format 03: integer without unit -> kg
            'integer without unit'      => ['16', null, 16000],
            // format 04: kilograms with unit column
            'kg from column'         => ['11.0', 'kg', 11000],
            // format 06: free text
            'g directly after'       => ['21700g', null, 21700],
            'decimal comma'          => ['14,4', null, 14400],
            'kg with space'          => ['13 kg', null, 13000],
            'g with space'           => ['605 g', null, 605],
            // edge cases
            'upper case'             => ['13 KG', null, 13000],
            'surrounding spaces'     => ['  21.9  ', null, 21900],
            'unit in value wins'     => ['500g', 'kg', 500],
            'empty unit column'      => ['12', '', 12000],
            'rounding'               => ['0.0005', 'kg', 1],
        ];
    }

    #[DataProvider('validWeights')]
    public function testConvertsToGrams(string $value, ?string $unit, int $expected): void
    {
        $parser = new WeightParser();

        $this->assertSame($expected, $parser->parse($value, $unit));
    }

    public static function invalidWeights(): array
    {
        return [
            'empty'             => ['', null],
            'text only'         => ['abc', null],
            'unknown unit'      => ['5 pond', null],
            'unknown column unit' => ['5', 'ons'],
            'negative'          => ['-5', null],
            'two numbers'       => ['5.5.5', null],
        ];
    }

    #[DataProvider('invalidWeights')]
    public function testThrowsOnInvalidWeight(string $value, ?string $unit): void
    {
        $parser = new WeightParser();

        $this->expectException(\InvalidArgumentException::class);

        $parser->parse($value, $unit);
    }
}
