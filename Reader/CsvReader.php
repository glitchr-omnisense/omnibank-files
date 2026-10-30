<?php

namespace Omnibank\Files\Reader;

use Omnibank\Exception\InvalidConfigException;
use Omnibank\Exception\ProviderException;
use Omnibank\Files\Fingerprints;
use Omnibank\Files\Statement;
use Omnibank\Files\Text;
use Omnibank\Files\Words;
use Omnibank\Model\Account;
use Omnibank\Model\Balance;
use Omnibank\Model\BankTransaction;
use Omnibank\Model\Money;

/**
 * A bank's CSV export, read through a column map. The "csv" option (the
 * gateway's, a statement's own over it):
 *
 *   delimiter: null          # ";", ",", "\t"...; found from the header line when null
 *   enclosure: '"'
 *   encoding: null           # UTF-8 when the file is, Windows-1252 otherwise (French exports)
 *   decimal: null            # "," or "."; found per amount when null ("1 234,56", "1,234.56")
 *   date_format: [d/m/Y, Y-m-d, d/m/y, d.m.Y, d-m-Y, Y/m/d]   # tried in order
 *   header: true             # false: no header line, the columns are 0-based indexes
 *   columns:                 # a header's name (accents and case aside), a list of names, or an index
 *     date: ...              # the booking date (required)
 *     value_date: ...
 *     label: ...             # required
 *     amount: ...            # signed; or debit and credit, two columns
 *     debit: ...             # gone out: made negative, whatever its sign in the file
 *     credit: ...
 *     counterparty: ...
 *     counterparty_iban: ...
 *     reference: ...
 *     currency: ...
 *     balance: ...           # the balance after the line: the latest one is the account's
 *     id: ...                # the bank's id for the line, when the export has one
 *     category: ...          # Omnibank's category words (income, housing, transfer...): any other is "unknown"
 *
 * Each column has defaults that read the usual French and English exports
 * (Crédit Mutuel, CIC, BNP, Société Générale, LCL, La Banque Postale,
 * Boursorama...). The lines above the header (an export's title, the account
 * number) are skipped; so are lines with no amount. A line with an amount and
 * a date that reads in none of the formats is an error, never skipped.
 *
 * The account is the statement's "account" option (id, iban, bic, name,
 * currency, type - ISO 20022 or OFX words, or Omnibank's kinds), else the
 * file's name.
 */
final class CsvReader implements ReaderInterface
{
    /** The header names each column is looked for under, by default. */
    public const COLUMNS = [
        'date' => ['date', 'date operation', 'date d operation', 'date de l operation', 'date comptable', 'date de comptabilisation', 'booking date', 'transaction date', 'posted date', 'dateop'],
        'value_date' => ['date de valeur', 'date valeur', 'value date', 'dateval'],
        'label' => ['libelle', 'libelle operation', 'libelle de l operation', 'label', 'description', 'details', 'motif', 'intitule', 'operation'],
        'amount' => ['montant', 'montant eur', 'montant en euros', 'montant(eur)', 'amount', 'montant de l operation'],
        'debit' => ['debit', 'debit eur', 'debit euros', 'debits', 'withdrawal', 'money out'],
        'credit' => ['credit', 'credit eur', 'credit euros', 'credits', 'deposit', 'money in'],
        'counterparty' => ['tiers', 'beneficiaire', 'contrepartie', 'counterparty', 'payee', 'nom du tiers'],
        'counterparty_iban' => ['iban tiers', 'iban beneficiaire', 'iban contrepartie', 'counterparty iban'],
        'reference' => ['reference', 'ref', 'reference operation', 'information complementaire', 'informations complementaires'],
        'currency' => ['devise', 'currency', 'monnaie'],
        'balance' => ['solde', 'balance', 'solde eur'],
        'id' => ['id', 'transaction id', 'numero operation', 'n operation'],
        'category' => ['categorie', 'category'],
    ];

    public const DATE_FORMATS = ['d/m/Y', 'Y-m-d', 'd/m/y', 'd.m.Y', 'd-m-Y', 'Y/m/d', 'Y-m-d H:i:s', 'd/m/Y H:i:s', 'd/m/Y H:i'];

    public function format(): string
    {
        return 'csv';
    }

    public function accepts(string $content, string $name): bool
    {
        return true;
    }

    public function read(string $content, string $name, array $options = []): array
    {
        $csv = (array) ($options['csv'] ?? []);
        $content = Text::utf8($content, $csv['encoding'] ?? null);
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $delimiter = $csv['delimiter'] ?? self::delimiter($lines);
        $enclosure = (string) ($csv['enclosure'] ?? '"');
        $formats = array_values((array) ($csv['date_format'] ?? self::DATE_FORMATS));
        $decimal = $csv['decimal'] ?? null;
        $map = array_replace(self::COLUMNS, (array) ($csv['columns'] ?? []));

        $rows = [];
        foreach ($lines as $number => $line) {
            if ('' !== trim($line)) {
                $rows[$number + 1] = str_getcsv($line, $delimiter, $enclosure, '');
            }
        }

        [$columns, $start] = false === ($csv['header'] ?? true) ? [$this->indexes($map), 0] : $this->header($rows, $map, $name);

        $accountOptions = (array) ($options['account'] ?? []);
        $currency = strtoupper((string) ($accountOptions['currency'] ?? $options['currency'] ?? 'EUR'));
        $iban = Text::iban($accountOptions['iban'] ?? null);
        $id = (string) ($accountOptions['id'] ?? $iban ?? pathinfo($name, \PATHINFO_FILENAME));
        $account = new Account($id, $iban, $accountOptions['bic'] ?? null, (string) ($accountOptions['name'] ?? $id), $currency, $accountOptions['type'] ?? null, ['file' => $name], Words::kind($accountOptions['type'] ?? null));

        $fingerprints = new Fingerprints();
        $transactions = [];
        $balances = [];
        foreach ($rows as $number => $row) {
            if ($number <= $start) {
                continue;
            }
            $cell = static fn (string $column): ?string => isset($columns[$column]) && '' !== trim((string) ($row[$columns[$column]] ?? '')) ? trim((string) $row[$columns[$column]]) : null;
            $amount = $this->amount($cell, $decimal);
            if (null === $amount) {
                continue;
            }
            $bookedOn = Text::date((string) $cell('date'), $formats);
            if (null === $bookedOn) {
                throw new ProviderException('files', \sprintf('"%s", line %d: the date "%s" reads in none of the formats (%s); set the csv "date_format" option.', $name, $number, (string) $cell('date'), implode(', ', $formats)));
            }
            $money = Money::fromDecimal($amount, $cell('currency') ?? $currency);
            $label = $cell('label') ?? '';
            $counterparty = $cell('counterparty');

            $transactions[] = new BankTransaction(
                id: $cell('id') ?? $fingerprints->id($bookedOn, $money, $label, $counterparty),
                bookedOn: $bookedOn,
                valueOn: null !== $cell('value_date') ? Text::date((string) $cell('value_date'), $formats) : null,
                amount: $money,
                label: $label,
                counterpartyName: $counterparty,
                counterpartyIban: Text::iban($cell('counterparty_iban')),
                reference: $cell('reference'),
                raw: ['line' => $number, 'row' => $row],
                category: Words::csvCategory($cell('category')),
            );
            if (null !== ($balance = $cell('balance')) && null !== ($balance = Text::decimal($balance, $decimal))) {
                $balances[] = new Balance(Money::fromDecimal($balance, $cell('currency') ?? $currency), $bookedOn, 'booked');
            }
        }

        // The balance after the latest line: the first of the latest day when the export runs newest first, the last otherwise.
        $latest = null;
        if ($balances) {
            $newestFirst = $balances[0]->at > $balances[\count($balances) - 1]->at;
            foreach ($balances as $balance) {
                if (null === $latest || $balance->at > $latest->at || (!$newestFirst && $balance->at == $latest->at)) {
                    $latest = $balance;
                }
            }
        }

        return [new Statement($account, $latest ? [$latest] : [], $transactions)];
    }

    /** The line's signed amount, as a decimal: the amount column, or debit and credit. */
    private function amount(\Closure $cell, ?string $decimal): ?string
    {
        if (null !== ($amount = $cell('amount'))) {
            return Text::decimal($amount, $decimal);
        }
        if (null !== ($debit = $cell('debit')) && null !== ($debit = Text::decimal($debit, $decimal)) && 0.0 !== (float) $debit) {
            return '-'.ltrim($debit, '-');
        }
        if (null !== ($credit = $cell('credit')) && null !== ($credit = Text::decimal($credit, $decimal))) {
            return ltrim($credit, '-');
        }

        return isset($debit) && null !== $debit ? $debit : null;
    }

    /**
     * The header line: the first, among the first 50, where the date, the
     * label and an amount (or debit and credit) columns are all found.
     *
     * @param array<int, list<?string>> $rows
     *
     * @return array{array<string, int>, int} the columns' indexes, the header's line number
     */
    private function header(array $rows, array $map, string $name): array
    {
        foreach (\array_slice($rows, 0, 50, true) as $number => $row) {
            $keys = array_map(static fn ($cell) => Text::key((string) $cell), $row);
            $columns = [];
            foreach ($map as $column => $names) {
                foreach ((array) $names as $wanted) {
                    $at = \is_int($wanted) ? $wanted : array_search(Text::key((string) $wanted), $keys, true);
                    if (false !== $at && isset($row[$at])) {
                        $columns[$column] = $at;
                        break;
                    }
                }
            }
            if (isset($columns['date'], $columns['label']) && (isset($columns['amount']) || isset($columns['debit']) || isset($columns['credit']))) {
                return [$columns, $number];
            }
        }

        throw new ProviderException('files', \sprintf('"%s": no header line with a date, a label and an amount (or debit and credit) column; set the csv "columns" option.', $name));
    }

    /** @return array<string, int> */
    private function indexes(array $map): array
    {
        $columns = array_filter(array_map(static fn ($v) => \is_int($v) ? $v : null, $map), static fn ($v) => null !== $v);
        if (!isset($columns['date'], $columns['label']) || !(isset($columns['amount']) || isset($columns['debit']) || isset($columns['credit']))) {
            throw new InvalidConfigException('A CSV without a header needs its columns as indexes: date, label, and amount or debit and credit.');
        }

        return $columns;
    }

    /**
     * The delimiter the first lines agree on: the one found the same number
     * of times on most of them (";" over "," when amounts have decimal commas).
     */
    private static function delimiter(array $lines): string
    {
        $sample = \array_slice(array_values(array_filter($lines, static fn ($l) => '' !== trim($l))), 0, 20);
        $best = ';';
        $bestScore = 0;
        foreach ([';', "\t", ',', '|'] as $candidate) {
            $counts = array_filter(array_map(static fn (string $line) => substr_count($line, $candidate), $sample));
            if (!$counts) {
                continue;
            }
            $score = max(array_count_values($counts));
            if ($score > $bestScore) {
                [$best, $bestScore] = [$candidate, $score];
            }
        }

        return $best;
    }
}
