<?php

declare(strict_types=1);

namespace App\Tests\Normalizer;

use App\Normalizer\BeehouseResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BeehouseResolverTest extends TestCase
{
    private function resolverFromConfig(): BeehouseResolver
    {
        return new BeehouseResolver(require __DIR__ . '/../../config/bijenstanden.php');
    }

    /** The 29 spellings from the dataset that map to a fixed beehouse. */
    public static function datasetSpellings(): array
    {
        return [
            ['Achter de Kerk', 'Achter de Kerk'],
            ['Achter de kerk', 'Achter de Kerk'],
            ['achter d kerk', 'Achter de Kerk'],
            ['A. de Kerk', 'Achter de Kerk'],
            ['AdK', 'Achter de Kerk'],
            ['Kerk', 'Achter de Kerk'],
            ['Kerkhof', 'Achter de Kerk'],
            ['Bosrand', 'Bosrand'],
            ['bosrand', 'Bosrand'],
            ['De Bosrand', 'Bosrand'],
            ['Bosrand Noord', 'Bosrand'],
            ['Bosrand Zuid', 'Bosrand'],
            ['Heideveld', 'Heideveld'],
            ['Heideveld Zuid', 'Heideveld'],
            ['De Heide', 'Heideveld'],
            ['HV', 'Heideveld'],
            ['Klaproos', 'Klaproos'],
            ['Klaproosplein', 'Klaproos'],
            ['Klaproosweg', 'Klaproos'],
            ['Molenstraat', 'Molenstraat'],
            ['Molen', 'Molenstraat'],
            ['Molenpad', 'Molenstraat'],
            ['Molenweide', 'Molenstraat'],
            ['Zonneweide', 'Zonneweide'],
            ['zonneweide', 'Zonneweide'],
            ['Zonne weide', 'Zonneweide'],
            ['Zonneweide West', 'Zonneweide'],
            ['ZW', 'Zonneweide'],
            ['ZW-1', 'Zonneweide'],
        ];
    }

    #[DataProvider('datasetSpellings')]
    public function testRecognisesSpellingFromDataset(string $value, string $expected): void
    {
        $this->assertSame($expected, $this->resolverFromConfig()->resolve($value));
    }

    public function testIgnoresCaseSpacesAndPunctuation(): void
    {
        $resolver = $this->resolverFromConfig();

        $this->assertSame('Achter de Kerk', $resolver->resolve('  ACHTER-DE-KERK  '));
        $this->assertSame('Zonneweide', $resolver->resolve('zw 1'));
        $this->assertSame('Achter de Kerk', $resolver->resolve('a de kerk'));
    }

    public static function unknownBeehouses(): array
    {
        return [
            'empty'         => [''],
            'unknown'       => ['Dorpsplein'],
            'only spaces'    => ['   '],
            // Deliberately not mapped (yet), see README
            'Klaasveld'      => ['Klaasveld'],
        ];
    }

    #[DataProvider('unknownBeehouses')]
    public function testThrowsOnUnknownBeehouse(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolverFromConfig()->resolve($value);
    }

    public function testThrowsOnDuplicateSpellingInConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BeehouseResolver([
            'Molenstraat' => ['Molen'],
            'Molenweide'  => ['molen'],
        ]);
    }

    public function testWorksWithCustomConfig(): void
    {
        $resolver = new BeehouseResolver(['Bosrand' => ['BR']]);

        $this->assertSame('Bosrand', $resolver->resolve('br'));
        $this->assertSame('Bosrand', $resolver->resolve('Bosrand'));
    }
}
