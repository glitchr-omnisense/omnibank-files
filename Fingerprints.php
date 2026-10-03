<?php

namespace Omnibank\Files;

use Omnibank\Model\Money;

/**
 * Ids for the lines a file gives no reference to: a hash of the date, the
 * amount, the label and the counterparty, so the same line read from two
 * files (two exports that overlap) is one transaction. Two identical lines in
 * one file - two coffees the same day - are told apart by their rank: the
 * second is "<hash>#2", and it is #2 in the next export too.
 */
final class Fingerprints
{
    /** @var array<string, int> */
    private array $seen = [];

    public function id(\DateTimeInterface $bookedOn, Money $amount, string $label, ?string $counterparty = null): string
    {
        $hash = substr(sha1(implode('|', [$bookedOn->format('Y-m-d'), $amount->amount, $amount->currency, Text::key($label), Text::key((string) $counterparty)])), 0, 32);
        $rank = $this->seen[$hash] = ($this->seen[$hash] ?? 0) + 1;

        return 1 === $rank ? $hash : $hash.'#'.$rank;
    }
}
