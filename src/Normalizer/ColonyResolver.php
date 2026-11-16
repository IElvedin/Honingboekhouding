<?php

declare(strict_types=1);

namespace App\Normalizer;

/**
 * Converts a colony code to one fixed notation: PREFIX-ID.
 *
 *   "VOLK-12"    -> "VOLK-12"
 *   "volk 012"   -> "VOLK-12"
 *   "V-100"      -> "V-100"
 *   "v167"       -> "V-167"
 *   "Bijen 48"   -> "BIJEN-48"
 *   "Koningin M" -> "KONINGIN-M"
 *
 * Note: a colony code is only unique within one member. In the dataset, on
 * average 14 to 40 different members use the same code (e.g. "VOLK-12").
 * A colony is therefore always the combination member + code; the importer handles that.
 */
class ColonyResolver
{
    /**
     * @param string $value e.g. "VOLK-12", "v167", "Koningin M"
     * @return string e.g. "VOLK-12", "V-167", "KONINGIN-M"
     *
     * @throws \InvalidArgumentException when the code is empty or cannot be read
     */
    public function resolve(string $value): string
    {
        $clean = strtoupper(trim($value));

        // Prefix (letters) + optional separator + ID (digits)
        if (preg_match('/^([A-Z]+)[\s\-_.]*(\d+)$/', $clean, $matches)) {
            $prefix = $matches[1];
            $number = $matches[2];

            // Strip leading zeros: "VOLK-012" and "VOLK-12" are the same colony
            return $prefix . '-' . (int) $number;
        }

        // Prefix + separator + a single letter as ID ("Koningin M")
        if (preg_match('/^([A-Z]+)[\s\-_.]+([A-Z])$/', $clean, $matches)) {
            $prefix = $matches[1];
            $letter = $matches[2];

            return $prefix . '-' . $letter;
        }

        throw new \InvalidArgumentException("Onbekende volkcode: '$value'");
    }
}
