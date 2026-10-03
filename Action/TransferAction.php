<?php

namespace Omnibank\Files\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Files\Api;
use Omnibank\Request\Request;
use Omnibank\Request\Transfer;

/**
 * The transfer as a pain.001.001.03 file: the result is "generated", the
 * file and its name in it, to upload to the bank's website. Its id is the
 * file's message id, and the end-to-end id when none was given - the one the
 * bank's statement will carry back.
 */
final class TransferAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Transfer;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Transfer);
        $request->setResult($this->api->transfer($request->transfer));
    }
}
