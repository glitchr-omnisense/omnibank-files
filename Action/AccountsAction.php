<?php

namespace Omnibank\Files\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Files\Api;
use Omnibank\Files\Statement;
use Omnibank\Request\FetchAccounts;
use Omnibank\Request\Request;

/** The accounts the statement files are about. */
final class AccountsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchAccounts;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchAccounts);
        $request->setResult(array_values(array_map(static fn (Statement $s) => $s->account, $this->api->statements($request->connection))));
    }
}
