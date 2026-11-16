<?php

declare(strict_types=1);

namespace App\Tests\Normalizer;

use App\Normalizer\MemberResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MemberResolverTest extends TestCase
{
    public function testReturnsNameUnchanged(): void
    {
        $resolver = new MemberResolver();

        $this->assertSame('Rik Bos', $resolver->resolve('Rik Bos'));
        $this->assertSame('Fatima El Idrissi', $resolver->resolve('Fatima El Idrissi'));
        $this->assertSame('Samira El-Mansoor', $resolver->resolve('Samira El-Mansoor'));
    }

    public static function spellings(): array
    {
        return [
            'surrounding spaces' => ['  Rik Bos  '],
            'double space'       => ['Rik  Bos'],
            'tab'                => ["Rik\tBos"],
            'lower case'         => ['rik bos'],
            'upper case'         => ['RIK BOS'],
            'mixed'              => ['  rIk   BoS '],
        ];
    }

    #[DataProvider('spellings')]
    public function testLinksVariantToFirstSpelling(string $variant): void
    {
        $resolver = new MemberResolver();
        $resolver->resolve('Rik Bos');

        $this->assertSame('Rik Bos', $resolver->resolve($variant));
    }

    public function testFirstSpellingWins(): void
    {
        $resolver = new MemberResolver();

        $this->assertSame('rik bos', $resolver->resolve('rik bos'));
        $this->assertSame('rik bos', $resolver->resolve('Rik Bos'));
    }

    public function testNamePrefixStaysAsIs(): void
    {
        $resolver = new MemberResolver();

        $this->assertSame('Leonie van den Berg', $resolver->resolve('Leonie van den Berg'));
    }

    public function testDifferentMembersStaySeparate(): void
    {
        $resolver = new MemberResolver();

        // Same surname, different members: each keeps its own name
        $this->assertSame('Nadia Ali', $resolver->resolve('Nadia Ali'));
        $this->assertSame('Youssef Ali', $resolver->resolve('Youssef Ali'));

        // A variant of the first member is still linked to the first member, not the second
        $this->assertSame('Nadia Ali', $resolver->resolve('nadia ali'));
    }

    public static function emptyNames(): array
    {
        return [
            'empty'          => [''],
            'only spaces'    => ['   '],
            'only tab'       => ["\t"],
        ];
    }

    #[DataProvider('emptyNames')]
    public function testThrowsOnEmptyName(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MemberResolver())->resolve($value);
    }
}
