<?php

declare(strict_types=1);

namespace App\Tests\Csv;

use App\Csv\ColumnMapper;
use PHPUnit\Framework\TestCase;

final class ColumnMapperTest extends TestCase
{
    public function testThrowsOnMissingColumn(): void
    {
        $mapper = new ColumnMapper(require __DIR__ . '/../../config/kolommen.php');

        $this->expectException(\RuntimeException::class);

        $mapper->map(['Datum', 'Foo', 'Bar']);
    }
}