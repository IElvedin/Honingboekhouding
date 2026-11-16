<?php

declare(strict_types=1);

namespace App\Csv;

/**
 * Maps the column names of a CSV header to fixed fields, using the aliases
 * from config/kolommen.php. Matching ignores case and surrounding spaces.
 */
class ColumnMapper
{
    private const array REQUIRED_FIELDS = ['member', 'beehouse', 'colony', 'date', 'weight'];

    /** @var array<string, list<string>> field => possible column names (lower case) */
    private readonly array $aliases;

    /**
     * @param array<string, list<string>> $aliases field => possible column names (lower case)
     */
    public function __construct(array $aliases)
    {
        $this->aliases = $aliases;
    }

    /**
     * @param  list<string> $header  e.g. ['Datum', 'Lidnummer', 'Naam', ...]
     * @return array<string, int>    e.g. ['date' => 0, 'member' => 2, ...]
     *
     * @throws \RuntimeException when a required field has no matching column
     */
    public function map(array $header): array
    {
        $columns = [];

        foreach ($header as $index => $name) {
            $name = strtolower(trim($name));

            foreach ($this->aliases as $field => $possibleNames) {
                if (in_array($name, $possibleNames, true)) {
                    $columns[$field] = $index;
                }
            }
        }

        $missing = array_diff(self::REQUIRED_FIELDS, array_keys($columns));

        if ($missing !== []) {
            throw new \RuntimeException('Onbekende kolommen, mist: ' . implode(', ', $missing));
        }

        return $columns;
    }
}
