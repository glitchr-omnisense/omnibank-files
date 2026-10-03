<?php

namespace Omnibank\Files;

use Omnibank\Model\Account;
use Omnibank\Model\Balance;
use Omnibank\Model\BankTransaction;

/** One account's statement as a file tells it: the account, its balances, its lines. */
final readonly class Statement
{
    /**
     * @param list<Balance>         $balances
     * @param list<BankTransaction> $transactions
     */
    public function __construct(
        public Account $account,
        public array $balances = [],
        public array $transactions = [],
    ) {
    }
}
