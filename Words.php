<?php

namespace Omnibank\Files;

use Omnibank\Model\AccountKind;
use Omnibank\Model\TransactionCategory;

/**
 * The statements' own words read as Omnibank's: an account's type (ISO
 * 20022's ExternalCashAccountType1Code in CAMT.053, OFX's ACCTTYPE or a
 * credit card statement, the CSV's "type" option) as its kind, a line's
 * bank transaction code (CAMT.053's BkTxCd domain, family and sub-family)
 * or OFX's TRNTYPE as its category. What says nothing of what was paid (a
 * card payment, a direct debit) leaves the line uncategorised; a word not
 * listed is UNKNOWN for a kind, nothing for a category.
 */
final class Words
{
    public const ACCOUNT_KINDS = [
        // ISO 20022 ExternalCashAccountType1Code
        'cacc' => AccountKind::CHECKING,
        'cash' => AccountKind::CHECKING,
        'slry' => AccountKind::CHECKING,
        'trans' => AccountKind::CHECKING,
        'svgs' => AccountKind::SAVINGS,
        'llsv' => AccountKind::SAVINGS,
        'moma' => AccountKind::SAVINGS,
        'ondp' => AccountKind::TERM_DEPOSIT,
        'card' => AccountKind::CARD,
        'loan' => AccountKind::LOAN,
        'mgld' => AccountKind::LOAN,
        'odft' => AccountKind::REVOLVING,
        // OFX ACCTTYPE
        'checking' => AccountKind::CHECKING,
        'savings' => AccountKind::SAVINGS,
        'moneymrkt' => AccountKind::SAVINGS,
        'cd' => AccountKind::TERM_DEPOSIT,
        'creditline' => AccountKind::REVOLVING,
    ];

    /** CAMT.053 bank transaction codes: "domain/family/sub-family", the longest prefix wins. */
    public const TRANSACTION_CODES = [
        'PMNT/ICDT' => TransactionCategory::TRANSFER,
        'PMNT/RCDT' => TransactionCategory::TRANSFER,
        'PMNT/IRCT' => TransactionCategory::TRANSFER,
        'PMNT/RRCT' => TransactionCategory::TRANSFER,
        'PMNT/CCRD/CWDL' => TransactionCategory::CASH,
        'PMNT/CNTR' => TransactionCategory::CASH,
        'PMNT/CHCK' => TransactionCategory::CASH,
        'PMNT/RCHQ' => TransactionCategory::CASH,
        'PMNT/MCRD/CWDL' => TransactionCategory::CASH,
        'LDAS' => TransactionCategory::LOAN,
        'SECU' => TransactionCategory::SAVINGS,
        'ACMT' => TransactionCategory::BANK_FEES,
    ];

    /** CAMT.053 sub-families that are the bank's charges, whatever the family. */
    public const FEE_SUBFAMILIES = ['CHRG', 'FEES', 'COMM', 'COME', 'COMI'];

    /** OFX TRNTYPE. */
    public const OFX_TYPES = [
        'XFER' => TransactionCategory::TRANSFER,
        'ATM' => TransactionCategory::CASH,
        'CASH' => TransactionCategory::CASH,
        'CHECK' => TransactionCategory::CASH,
        'FEE' => TransactionCategory::BANK_FEES,
        'SRVCHG' => TransactionCategory::BANK_FEES,
        'INT' => TransactionCategory::INCOME,
        'DIV' => TransactionCategory::INCOME,
        'DIRECTDEP' => TransactionCategory::INCOME,
    ];

    public static function kind(?string $type): AccountKind
    {
        $type = strtolower(trim((string) $type));

        return self::ACCOUNT_KINDS[$type] ?? AccountKind::of($type);
    }

    /** A CAMT.053 bank transaction code ("PMNT/RCDT/ESCT"); null when it says nothing of what was paid. */
    public static function camtCategory(?string $code): ?TransactionCategory
    {
        if (null === $code || '' === $code) {
            return null;
        }
        $parts = explode('/', strtoupper($code));
        if (\in_array($parts[2] ?? '', self::FEE_SUBFAMILIES, true)) {
            return TransactionCategory::BANK_FEES;
        }
        for ($n = \count($parts); $n > 0; --$n) {
            $prefix = implode('/', \array_slice($parts, 0, $n));
            if (isset(self::TRANSACTION_CODES[$prefix])) {
                return self::TRANSACTION_CODES[$prefix];
            }
        }

        return null;
    }

    public static function ofxCategory(?string $type): ?TransactionCategory
    {
        return self::OFX_TYPES[strtoupper(trim((string) $type))] ?? null;
    }

    /** A CSV's category column: Omnibank's own words (income, housing...), UNKNOWN for another, null for none. */
    public static function csvCategory(?string $value): ?TransactionCategory
    {
        return TransactionCategory::of(null === $value ? null : str_replace([' ', '-'], '_', strtolower(trim($value))));
    }
}
