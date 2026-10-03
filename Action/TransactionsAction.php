<?php

namespace Omnibank\Files\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Exception\ProviderException;
use Omnibank\Files\Api;
use Omnibank\Model\BankTransaction;
use Omnibank\Request\FetchTransactions;
use Omnibank\Request\Request;

/** The account's lines from every statement, each once, oldest first, between the dates asked. */
final class TransactionsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchTransactions;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchTransactions);
        $statement = $this->api->statements($request->connection)[$request->account->id]
            ?? throw new ProviderException('files', \sprintf('No statement for the account "%s".', $request->account->id));
        $request->setResult(array_values(array_filter($statement->transactions, static fn (BankTransaction $t) => $request->covers($t->bookedOn))));
    }
}
