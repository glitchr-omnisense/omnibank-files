<?php

namespace Omnibank\Files\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Model\Consent;
use Omnibank\Model\ConnectResult;
use Omnibank\Request\Connect;
use Omnibank\Request\Request;

/** Nothing to open: the files are the access. The connection comes back as it is, its consent active. */
final class ConnectAction implements ActionInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof Connect;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Connect);
        $request->setResult(new ConnectResult(null, $request->connection->withConsent(Consent::active())));
    }
}
