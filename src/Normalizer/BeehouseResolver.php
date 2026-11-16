<?php

declare(strict_types=1);

namespace App\Normalizer;

/**
 * Maps the many spellings of a beehouse to its official name,
 * using config/bijenstanden.php.
 */
class BeehouseResolver
{
    /** @var array<string, string> normalized spelling => official name */
    private readonly array $lookup;

    /**
     * @param array<string, list<string>> $beehouses official name => spellings
     *
     * @throws \InvalidArgumentException when one spelling belongs to two beehouses
     */
    public function __construct(array $beehouses)
    {
        $lookup = [];

        foreach ($beehouses as $officialName => $aliases) {
            // The official name itself also counts as a spelling
            foreach ([$officialName, ...$aliases] as $alias) {
                $key = $this->normalize($alias);

                if (isset($lookup[$key]) && $lookup[$key] !== $officialName) {
                    throw new \InvalidArgumentException(
                        "Schrijfwijze '$alias' staat bij zowel '{$lookup[$key]}' als '$officialName'"
                    );
                }

                $lookup[$key] = $officialName;
            }
        }

        $this->lookup = $lookup;
    }

    /**
     * @param string $value e.g. "AdK", "zonneweide", "Bosrand Noord"
     * @return string the official name, e.g. "Achter de Kerk", "Zonneweide", "Bosrand"
     *
     * @throws \InvalidArgumentException when the spelling is unknown
     */
    public function resolve(string $value): string
    {
        $key = $this->normalize($value);

        if (!isset($this->lookup[$key])) {
            throw new \InvalidArgumentException("Onbekende bijenstand: '$value'");
        }

        return $this->lookup[$key];
    }

    /**
     * Lower case, letters and digits only: "A. de Kerk" -> "adekerk", "ZW-1" -> "zw1".
     */
    private function normalize(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }
}
