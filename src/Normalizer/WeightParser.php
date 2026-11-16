<?php

declare(strict_types=1);

namespace App\Normalizer;

/**
 * Converts a weight from a CSV cell to grams.
 */
class WeightParser
{
    /** Recognised units and their factor to grams. Dutch spellings only: "pond" is ambiguous. */
    private const array UNITS = [
        'g'    => 1,
        'gr'   => 1,
        'gram' => 1,
        'kg'   => 1000,
        'kilo' => 1000,
    ];

    private const string DEFAULT_UNIT = 'kg';

    /**
     * @param string      $value e.g. "21.9", "19509", "21700g", "14,4", "13 kg", "605 g"
     * @param string|null $unit  from the Eenheid/Unit column (e.g. "gram", "kg"), null when the file has no such column
     * @return int weight in grams
     *
     * @throws \InvalidArgumentException when the value or unit cannot be read
     */
    public function parse(string $value, ?string $unit = null): int
    {
        // Remove spaces, lower case, decimal comma becomes a dot: "14,4" -> "14.4", "13 kg" -> "13kg"
        $clean = strtolower(str_replace([' ', ','], ['', '.'], trim($value)));

        // A number, optionally followed by a unit
        if (!preg_match('/^(\d+(?:\.\d+)?)([a-z]*)$/', $clean, $matches)) {
            throw new \InvalidArgumentException("Ongeldig gewicht: '$value'");
        }

        $number = (float) $matches[1];

        // Priority: unit in the value itself > unit column > default (kg)
        if ($matches[2] !== '') {
            $chosenUnit = $matches[2];
        } elseif ($unit !== null && trim($unit) !== '') {
            $chosenUnit = strtolower(trim($unit));
        } else {
            $chosenUnit = self::DEFAULT_UNIT;
        }

        if (!isset(self::UNITS[$chosenUnit])) {
            throw new \InvalidArgumentException("Onbekende eenheid '$chosenUnit' bij gewicht '$value'");
        }

        return (int) round($number * self::UNITS[$chosenUnit]);
    }
}
