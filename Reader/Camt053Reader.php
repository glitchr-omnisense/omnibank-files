<?php

namespace Omnibank\Files\Reader;

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
 * ISO 20022 bank-to-customer statements: camt.053 (BkToCstmrStmt/Stmt), and
 * the same entries in camt.052 reports and camt.054 notifications, whatever
 * the version (001.02 to 001.08 and on). One Statement per Stmt.
 *
 * Each booked entry (Ntry) is a transaction - pending and information-only
 * ones are left out - signed by CdtDbtInd (DBIT is money gone out, a
 * reversal's included: RvslInd only says the parties are the original
 * operation's). A batch entry whose details
 * (TxDtls) carry their own amounts is one transaction per detail. The
 * counterparty is the debtor of a credit and the creditor of a debit; the
 * reference is the remittance information (RmtInf/Ustrd), else the
 * end-to-end id. The id is the bank's (AcctSvcrRef, else NtryRef), else a
 * fingerprint of the line.
 */
final class Camt053Reader implements ReaderInterface
{
    /** Balance codes, as Balance types. */
    private const BALANCES = ['CLBD' => 'booked', 'CLAV' => 'available', 'OPBD' => 'opening', 'ITBD' => 'interim_booked', 'ITAV' => 'interim_available', 'FWAV' => 'forward_available', 'PRCD' => 'previously_closed', 'OPAV' => 'opening_available', 'XPCD' => 'expected'];

    private \DOMXPath $xpath;
    private string $ns = '';

    public function format(): string
    {
        return 'camt053';
    }

    public function accepts(string $content, string $name): bool
    {
        return (bool) preg_match('/<(\w+:)?(BkToCstmrStmt|BkToCstmrAcctRpt|BkToCstmrDbtCdtNtfctn)\b/', substr($content, 0, 4096))
            || (bool) preg_match('/camt\.05[234]/i', $name);
    }

    public function read(string $content, string $name, array $options = []): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($content, \LIBXML_NONET | \LIBXML_NOCDATA);
            $error = libxml_get_last_error();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || null === $document->documentElement) {
            throw new ProviderException('files', \sprintf('"%s" is not XML: %s', $name, $error ? trim($error->message) : 'unreadable'));
        }
        $this->xpath = new \DOMXPath($document);
        $this->ns = (string) $document->documentElement->namespaceURI;
        if ('' !== $this->ns) {
            $this->xpath->registerNamespace('c', $this->ns);
        }

        $statements = [];
        foreach ($this->nodes('//c:BkToCstmrStmt/c:Stmt | //c:BkToCstmrAcctRpt/c:Rpt | //c:BkToCstmrDbtCdtNtfctn/c:Ntfctn') as $stmt) {
            $statements[] = $this->statement($stmt, $name, $options);
        }
        if (!$statements) {
            throw new ProviderException('files', \sprintf('"%s" holds no camt.053 statement (BkToCstmrStmt/Stmt).', $name));
        }

        return $statements;
    }

    private function statement(\DOMElement $stmt, string $name, array $options): Statement
    {
        $iban = Text::iban($this->text('c:Acct/c:Id/c:IBAN', $stmt));
        $currency = $this->text('c:Acct/c:Ccy', $stmt)
            ?? $this->attribute('c:Bal/c:Amt/@Ccy', $stmt)
            ?? $this->attribute('c:Ntry/c:Amt/@Ccy', $stmt)
            ?? (string) ($options['currency'] ?? 'EUR');
        $id = $iban ?? $this->text('c:Acct/c:Id/c:Othr/c:Id', $stmt) ?? pathinfo($name, \PATHINFO_FILENAME);
        $account = new Account(
            id: $id,
            iban: $iban,
            bic: $this->text('c:Acct/c:Svcr/c:FinInstnId/c:BIC | c:Acct/c:Svcr/c:FinInstnId/c:BICFI', $stmt),
            name: $this->text('c:Acct/c:Nm', $stmt) ?? $this->text('c:Acct/c:Ownr/c:Nm', $stmt) ?? $id,
            currency: strtoupper($currency),
            type: $this->text('c:Acct/c:Tp/c:Cd | c:Acct/c:Tp/c:Prtry', $stmt),
            raw: array_filter(['statement' => $this->text('c:Id', $stmt), 'created' => $this->text('c:CreDtTm', $stmt), 'owner' => $this->text('c:Acct/c:Ownr/c:Nm', $stmt), 'file' => $name], static fn ($v) => null !== $v),
            kind: Words::kind($this->text('c:Acct/c:Tp/c:Cd | c:Acct/c:Tp/c:Prtry', $stmt)),
        );

        $balances = [];
        foreach ($this->nodes('c:Bal', $stmt) as $bal) {
            $code = $this->text('c:Tp/c:CdOrPrtry/c:Cd', $bal) ?? $this->text('c:Tp/c:CdOrPrtry/c:Prtry', $bal) ?? 'CLBD';
            $amount = $this->money($bal, $account->currency);
            $at = Text::isoDate($this->text('c:Dt/c:Dt | c:Dt/c:DtTm', $bal));
            if (null !== $amount && null !== $at) {
                $balances[] = new Balance($amount, $at, self::BALANCES[$code] ?? strtolower($code));
            }
        }

        $fingerprints = new Fingerprints();
        $transactions = [];
        foreach ($this->nodes('c:Ntry', $stmt) as $entry) {
            $status = $this->text('c:Sts/c:Cd', $entry) ?? $this->text('c:Sts', $entry) ?? 'BOOK';
            if ('BOOK' !== strtoupper($status)) {
                continue;
            }
            foreach ($this->entry($entry, $account->currency, $fingerprints) as $transaction) {
                $transactions[] = $transaction;
            }
        }

        return new Statement($account, $balances, $transactions);
    }

    /** @return list<BankTransaction> */
    private function entry(\DOMElement $entry, string $currency, Fingerprints $fingerprints): array
    {
        $amount = $this->money($entry, $currency) ?? throw new ProviderException('files', 'An entry has no amount.');
        $bookedOn = Text::isoDate($this->text('c:BookgDt/c:Dt | c:BookgDt/c:DtTm', $entry))
            ?? Text::isoDate($this->text('c:ValDt/c:Dt | c:ValDt/c:DtTm', $entry))
            ?? throw new ProviderException('files', 'An entry has no booking date.');
        $valueOn = Text::isoDate($this->text('c:ValDt/c:Dt | c:ValDt/c:DtTm', $entry));
        $credit = !$amount->isNegative();
        // CdtDbtInd is the entry's own direction, a reversal's too; its parties are the original operation's.
        $reversal = 'true' === strtolower((string) $this->text('c:RvslInd', $entry));
        $entryInfo = $this->text('c:AddtlNtryInf', $entry);

        // The ISO bank transaction code, "domain/family/sub-family": what the line is (a transfer, a withdrawal, a fee).
        $code = $this->text('c:BkTxCd/c:Domn/c:Cd', $entry) ? implode('/', array_filter([$this->text('c:BkTxCd/c:Domn/c:Cd', $entry), $this->text('c:BkTxCd/c:Domn/c:Fmly/c:Cd', $entry), $this->text('c:BkTxCd/c:Domn/c:Fmly/c:SubFmlyCd', $entry)])) : null;

        $details = $this->nodes('c:NtryDtls/c:TxDtls', $entry);
        $split = \count($details) > 1 && \count($details) === \count(array_filter($details, fn (\DOMElement $d) => null !== $this->detailAmount($d, $currency)));

        $transactions = [];
        foreach ($split ? $details : [$details[0] ?? null] as $rank => $detail) {
            $lineAmount = $split ? $this->detailAmount($detail, $currency, $credit) : $amount;
            $party = $credit !== $reversal ? 'Dbtr' : 'Cdtr';
            $counterpartyName = $detail ? ($this->text("c:RltdPties/c:$party/c:Nm | c:RltdPties/c:$party/c:Pty/c:Nm", $detail)) : null;
            $counterpartyIban = $detail ? Text::iban($this->text("c:RltdPties/c:{$party}Acct/c:Id/c:IBAN", $detail)) : null;
            $ustrd = $detail ? implode(' ', array_map(static fn (\DOMNode $n) => trim($n->textContent), $this->nodes('c:RmtInf/c:Ustrd', $detail))) : '';
            $endToEnd = $detail ? $this->text('c:Refs/c:EndToEndId', $detail) : null;
            $endToEnd = null !== $endToEnd && 'NOTPROVIDED' !== strtoupper($endToEnd) ? $endToEnd : null;
            $txInfo = $detail ? $this->text('c:AddtlTxInf', $detail) : null;
            $label = ($split ? ($txInfo ?? $entryInfo) : ($entryInfo ?? $txInfo)) ?? ('' !== $ustrd ? $ustrd : null) ?? $counterpartyName ?? '';

            $bankRef = ($detail ? $this->text('c:Refs/c:AcctSvcrRef', $detail) : null);
            $entryRef = $this->text('c:AcctSvcrRef', $entry) ?? $this->text('c:NtryRef', $entry);
            $id = match (true) {
                null !== $bankRef && (!$split || $bankRef !== $entryRef) => $bankRef,
                null !== $entryRef => $split ? $entryRef.'/'.($rank + 1) : $entryRef,
                default => $fingerprints->id($bookedOn, $lineAmount, $label, $counterpartyName),
            };

            $transactions[] = new BankTransaction(
                id: $id,
                bookedOn: $bookedOn,
                valueOn: $valueOn,
                amount: $lineAmount,
                label: $label,
                counterpartyName: $counterpartyName,
                counterpartyIban: $counterpartyIban,
                reference: '' !== $ustrd ? $ustrd : $endToEnd,
                raw: array_filter([
                    'entryReference' => $this->text('c:NtryRef', $entry),
                    'accountServicerReference' => $bankRef ?? $this->text('c:AcctSvcrRef', $entry),
                    'endToEndId' => $endToEnd,
                    'transactionId' => $detail ? $this->text('c:Refs/c:TxId', $detail) : null,
                    'mandateId' => $detail ? $this->text('c:Refs/c:MndtId', $detail) : null,
                    'bankTransactionCode' => $code ?? $this->text('c:BkTxCd/c:Prtry/c:Cd', $entry),
                    'additionalEntryInfo' => $entryInfo,
                    'additionalTransactionInfo' => $txInfo,
                    'remittance' => '' !== $ustrd ? $ustrd : null,
                    'reversal' => $reversal ?: null,
                ], static fn ($v) => null !== $v),
                category: Words::camtCategory($code),
            );
        }

        return $transactions;
    }

    /** A detail's own amount: TxDtls/Amt (from 001.04), or AmtDtls/TxAmt/Amt. */
    private function detailAmount(?\DOMElement $detail, string $currency, bool $credit = true): ?Money
    {
        if (null === $detail) {
            return null;
        }
        foreach (['c:Amt', 'c:AmtDtls/c:TxAmt/c:Amt', 'c:AmtDtls/c:InstdAmt/c:Amt'] as $path) {
            $node = $this->nodes($path, $detail)[0] ?? null;
            if ($node instanceof \DOMElement && null !== ($decimal = Text::decimal($node->textContent))) {
                $ind = $this->text('c:CdtDbtInd', $detail);
                $negative = null !== $ind ? 'DBIT' === strtoupper($ind) : !$credit;
                $money = Money::fromDecimal($decimal, $node->getAttribute('Ccy') ?: $currency);

                return $negative ? $money->abs()->negate() : $money->abs();
            }
        }

        return null;
    }

    /** An element's Amt, signed by its CdtDbtInd. */
    private function money(\DOMElement $element, string $currency): ?Money
    {
        $node = $this->nodes('c:Amt', $element)[0] ?? null;
        if (!$node instanceof \DOMElement || null === ($decimal = Text::decimal($node->textContent))) {
            return null;
        }
        $money = Money::fromDecimal($decimal, $node->getAttribute('Ccy') ?: $currency)->abs();

        return 'DBIT' === strtoupper((string) $this->text('c:CdtDbtInd', $element)) ? $money->negate() : $money;
    }

    /** @return list<\DOMElement> */
    private function nodes(string $path, ?\DOMNode $context = null): array
    {
        if ('' === $this->ns) {
            $path = str_replace('c:', '', $path);
        }
        $nodes = $this->xpath->query($path, $context);

        return false === $nodes ? [] : array_values(array_filter(iterator_to_array($nodes), static fn ($n) => $n instanceof \DOMElement));
    }

    private function text(string $path, \DOMNode $context): ?string
    {
        $node = $this->nodes($path, $context)[0] ?? null;
        $text = null === $node ? '' : trim(preg_replace('/\s+/', ' ', $node->textContent));

        return '' === $text ? null : $text;
    }

    private function attribute(string $path, \DOMNode $context): ?string
    {
        [$element, $attribute] = explode('/@', $path);
        $node = $this->nodes($element, $context)[0] ?? null;

        return $node instanceof \DOMElement && '' !== $node->getAttribute($attribute) ? $node->getAttribute($attribute) : null;
    }
}
