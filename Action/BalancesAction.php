<?php

namespace Omnibank\Files\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Exception\ProviderException;
use Omnibank\Files\Api;
use Omnibank\Request\FetchBalances;
use Omnibank\Request\Request;

/** The latest balance of each type the account's statements give (closing booked, available...). */
final class BalancesAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchBalances;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchBalances);
        $statement = $this->api->statements($request->connection)[$request->account->id]
            ?? throw new ProviderException('files', \sprintf('No statement for the account "%s".', $request->account->id));
        $request->setResult($statement->balances);
    }
}
