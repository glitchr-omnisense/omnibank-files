<?php

namespace Omnibank\Files\Reader;

use Omnibank\Exception\ProviderException;
use Omnibank\Files\Fingerprints;
use Omnibank\Files\Statement;
use Omnibank\Files\Text;
use Omnibank\Files\Words;
use Omnibank\Model\Account;
use Omnibank\Model\AccountKind;
use Omnibank\Model\Balance;
use Omnibank\Model\BankTransaction;
use Omnibank\Model\Money;

/**
 * OFX statements: 1.x (SGML, leaf tags left open, the CHARSET:1252 of French
 * banks) and 2.x (XML), bank accounts (STMTRS) and cards (CCSTMTRS). One
 * Statement per account statement.
 *
 * A line (STMTTRN) is signed by TRNAMT (a decimal comma is read too), booked
 * on DTPOSTED, its value date DTAVAIL; its label is NAME, MEMO after it when
 * it says more; its id the bank's FITID, else a fingerprint of the line. OFX
 * carries no IBAN: a statement's "account" option gives one.
 */
final class OfxReader implements ReaderInterface
{
    public function format(): string
    {
        return 'ofx';
    }

    public function accepts(string $content, string $name): bool
    {
        $head = substr($content, 0, 4096);

        return str_contains($head, 'OFXHEADER') || (bool) preg_match('/<OFX>/i', $head) || (bool) preg_match('/\.(ofx|qfx)$/i', $name);
    }

    public function read(string $content, string $name, array $options = []): array
    {
        $tree = $this->parse(Text::utf8($content));
        $ofx = $tree['OFX'][0] ?? throw new ProviderException('files', \sprintf('"%s" is not an OFX file: no <OFX>.', $name));

        $statements = [];
        foreach (['BANKMSGSRSV1' => ['STMTTRNRS', 'STMTRS', 'BANKACCTFROM'], 'CREDITCARDMSGSRSV1' => ['CCSTMTTRNRS', 'CCSTMTRS', 'CCACCTFROM']] as $set => [$wrapper, $rs, $from]) {
            foreach ($ofx[$set] ?? [] as $messages) {
                foreach ($messages[$wrapper] ?? [] as $response) {
                    foreach ($response[$rs] ?? [] as $statement) {
                        $statements[] = $this->statement($statement, $statement[$from][0] ?? [], 'CCSTMTRS' === $rs, $name, $options);
                    }
                }
            }
        }
        if (!$statements) {
            throw new ProviderException('files', \sprintf('"%s" holds no OFX statement (STMTRS, CCSTMTRS).', $name));
        }

        return $statements;
    }

    private function statement(array $statement, array $from, bool $card, string $name, array $options): Statement
    {
        $currency = strtoupper(self::leaf($statement, 'CURDEF') ?? (string) ($options['currency'] ?? 'EUR'));
        $number = self::leaf($from, 'ACCTID') ?? pathinfo($name, \PATHINFO_FILENAME);
        $account = new Account(
            id: $number,
            iban: null,
            bic: null,
            name: $number,
            currency: $currency,
            type: $card ? 'card' : (null !== ($type = self::leaf($from, 'ACCTTYPE')) ? strtolower($type) : null),
            raw: array_filter(['bank' => self::leaf($from, 'BANKID'), 'branch' => self::leaf($from, 'BRANCHID'), 'number' => $number, 'key' => self::leaf($from, 'ACCTKEY'), 'file' => $name], static fn ($v) => null !== $v),
            kind: $card ? AccountKind::CARD : Words::kind(self::leaf($from, 'ACCTTYPE')),
        );

        $balances = [];
        foreach (['LEDGERBAL' => 'booked', 'AVAILBAL' => 'available'] as $tag => $type) {
            $balance = $statement[$tag][0] ?? null;
            $amount = $balance ? Text::decimal((string) self::leaf($balance, 'BALAMT')) : null;
            $at = $balance ? self::date(self::leaf($balance, 'DTASOF')) : null;
            if (null !== $amount && null !== $at) {
                $balances[] = new Balance(Money::fromDecimal($amount, $currency), $at, $type);
            }
        }

        $fingerprints = new Fingerprints();
        $transactions = [];
        foreach ($statement['BANKTRANLIST'][0]['STMTTRN'] ?? [] as $line) {
            $decimal = Text::decimal((string) self::leaf($line, 'TRNAMT'));
            $bookedOn = self::date(self::leaf($line, 'DTPOSTED')) ?? self::date(self::leaf($line, 'DTUSER'));
            if (null === $decimal || null === $bookedOn) {
                throw new ProviderException('files', \sprintf('"%s": a line (FITID %s) has no amount or no date.', $name, self::leaf($line, 'FITID') ?? '?'));
            }
            $amount = Money::fromDecimal($decimal, self::leaf($line['CURRENCY'][0] ?? [], 'CURSYM') ?? $currency);
            $title = self::leaf($line, 'NAME') ?? self::leaf($line['PAYEE'][0] ?? [], 'NAME');
            $memo = self::leaf($line, 'MEMO');
            $label = trim(implode(' ', array_unique(array_filter([$title, $memo !== $title ? $memo : null]))));
            $counterparty = self::leaf($line['PAYEE'][0] ?? [], 'NAME');
            $toAccount = self::leaf($line['BANKACCTTO'][0] ?? [], 'ACCTID');

            $transactions[] = new BankTransaction(
                id: self::leaf($line, 'FITID') ?? $fingerprints->id($bookedOn, $amount, $label, $counterparty),
                bookedOn: $bookedOn,
                valueOn: self::date(self::leaf($line, 'DTAVAIL')),
                amount: $amount,
                label: $label,
                counterpartyName: $counterparty,
                counterpartyIban: Text::isIban($toAccount) ? Text::iban($toAccount) : null,
                reference: self::leaf($line, 'REFNUM') ?? self::leaf($line, 'CHECKNUM'),
                raw: array_filter(array_map(static fn ($v) => \is_array($v) ? null : $v, array_map(static fn (array $values) => $values[0] ?? null, $line)), static fn ($v) => null !== $v),
                category: Words::ofxCategory(self::leaf($line, 'TRNTYPE')),
            );
        }

        return new Statement($account, $balances, $transactions);
    }

    /**
     * The OFX body as nested arrays: each tag a list of its occurrences, an
     * aggregate an array, a leaf its text. SGML leaves (no closing tag) and
     * XML both read.
     *
     * @return array<string, list<mixed>>
     */
    private function parse(string $content): array
    {
        $start = stripos($content, '<OFX>');
        if (false === $start) {
            return [];
        }
        preg_match_all('/<(\/?)([A-Za-z0-9_.]+)[^>]*>([^<]*)/', substr($content, $start), $tokens, \PREG_SET_ORDER);

        // An aggregate always closes, in SGML as in XML; a leaf may not.
        $closed = [];
        foreach ($tokens as $token) {
            if ('/' === $token[1]) {
                $closed[strtoupper($token[2])] = true;
            }
        }

        $root = [];
        $stack = [&$root];
        $names = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            [, $closing, $tag, $text] = $tokens[$i];
            $tag = strtoupper($tag);
            $text = trim(html_entity_decode($text, \ENT_QUOTES | \ENT_XML1, 'UTF-8'));
            $current = &$stack[\count($stack) - 1];
            if ('/' === $closing) {
                // Closes the aggregate it names, and any left open inside it.
                $at = array_search($tag, array_reverse($names, true), true);
                if (false !== $at) {
                    array_splice($stack, $at + 1);
                    array_splice($names, $at);
                }
                unset($current);
                continue;
            }
            $closesNext = '/' === ($tokens[$i + 1][1] ?? null) && strtoupper($tokens[$i + 1][2]) === $tag;
            if ('' !== $text || $closesNext || !isset($closed[$tag])) {
                if ('' !== $text) {
                    $current[$tag][] = $text;
                }
                if ($closesNext) {
                    ++$i;
                }
                unset($current);
                continue;
            }
            $current[$tag][] = [];
            $stack[] = &$current[$tag][\count($current[$tag]) - 1];
            $names[] = $tag;
            unset($current);
        }

        return $root;
    }

    private static function leaf(array $aggregate, string $tag): ?string
    {
        $value = $aggregate[$tag][0] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /** An OFX date: YYYYMMDD, with a time and a zone ("20260903120000.000[+2:CEST]") or not; the day is kept. */
    private static function date(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || !preg_match('/^(\d{8})/', $value, $m)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Ymd', $m[1]) ?: null;
    }
}
