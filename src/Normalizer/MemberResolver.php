<?php

declare(strict_types=1);

namespace App\Normalizer;

/**
 * Determines which member a name belongs to.
 *
 * Members are matched by name, not by member number: in the dataset the same
 * member gets a different LID number on every row, so that number is useless.
 *
 * There is no fixed member list. The first spelling the resolver encounters
 * for a member becomes the official name. Later variants that only differ in
 * case or spacing ("rik  bos", "RIK BOS") are linked to it.
 */
class MemberResolver
{
    /** @var array<string, string> key => official name */
    private array $knownMembers = [];

    /**
     * @param string $value e.g. "Rik Bos", "  rik   bos "
     * @return string the official name, e.g. "Rik Bos"
     *
     * @throws \InvalidArgumentException when the name is empty
     */
    public function resolve(string $value): string
    {
        $name = $this->clean($value);

        if ($name === '') {
            throw new \InvalidArgumentException('Lege naam van lid');
        }

        $key = mb_strtolower($name);

        // First spelling wins; after that the same name is always returned
        if (!isset($this->knownMembers[$key])) {
            $this->knownMembers[$key] = $name;
        }

        return $this->knownMembers[$key];
    }

    /**
     * Trims surrounding whitespace and collapses multiple spaces/tabs into one space.
     */
    private function clean(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }
}
