<?php

namespace Omnibank\Files\Tests;

use Omnibank\Files\FilesGatewayFactory;
use Omnibank\Registry;
use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;

/** Statement files call no service: the policy says so, no limit, no key. */
final class FilesPolicyTest extends TestCase
{
    public function testThePolicyIsDeclaredAsData(): void
    {
        $declared = (new PolicyExport())->folders(\dirname(__DIR__))->entries();
        self::assertCount(1, $declared);
        self::assertSame(['omnibank/files', 'policy.json'], [$declared[0]['package'], $declared[0]['file']]);
        self::assertArrayNotHasKey('error', $declared[0]);
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');

        self::assertSame('files', $policy->provider);
        self::assertSame([], $policy->rateLimits, 'no remote service: no limit');
        self::assertFalse($policy->requirements->key);
        $registry = new Registry([new FilesGatewayFactory()], ['statements' => ['factory' => 'files']]);
        self::assertEquals($policy, $registry->policy('statements'));
    }
}
