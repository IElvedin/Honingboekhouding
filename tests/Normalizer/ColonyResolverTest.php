<?php

declare(strict_types=1);

namespace App\Tests\Normalizer;

use App\Normalizer\ColonyResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ColonyResolverTest extends TestCase
{
    public static function validCodes(): array
    {
        return [
            // one example per format from the dataset
            'format 01/05: VOLK-12'       => ['VOLK-12', 'VOLK-12'],
            'format 02: Koningin M'       => ['Koningin M', 'KONINGIN-M'],
            'format 03: v167'             => ['v167', 'V-167'],
            'format 04: V-100'            => ['V-100', 'V-100'],
            'format 06: Bijen 48'         => ['Bijen 48', 'BIJEN-48'],
            // edge cases
            'lower case'                  => ['volk-12', 'VOLK-12'],
            'space instead of hyphen'     => ['VOLK 12', 'VOLK-12'],
            'underscore'                  => ['VOLK_12', 'VOLK-12'],
            'dot'                         => ['VOLK.12', 'VOLK-12'],
            'without separator'           => ['VOLK12', 'VOLK-12'],
            'leading zeros'               => ['VOLK-012', 'VOLK-12'],
            'zero'                        => ['VOLK-0', 'VOLK-0'],
            'surrounding spaces'          => ['  Bijen 48  ', 'BIJEN-48'],
            'multiple spaces'             => ['Bijen   48', 'BIJEN-48'],
            'lower case koningin'         => ['koningin m', 'KONINGIN-M'],
        ];
    }

    #[DataProvider('validCodes')]
    public function testConvertsToFixedNotation(string $value, string $expected): void
    {
        $this->assertSame($expected, (new ColonyResolver())->resolve($value));
    }

    public static function invalidCodes(): array
    {
        return [
            'empty'                      => [''],
            'only spaces'                => ['   '],
            'number only'                => ['12'],
            'prefix only'                => ['VOLK'],
            'letter without separator'   => ['KoninginM'],
            'two letters as ID'          => ['Koningin MN'],
            'number first'               => ['12-VOLK'],
            'odd characters'             => ['VOLK#12'],
        ];
    }

    #[DataProvider('invalidCodes')]
    public function testThrowsOnInvalidCode(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ColonyResolver())->resolve($value);
    }
}
