<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Key;

use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\Internal\Key\DefaultKeyResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(DefaultKeyResolver::class)]
final class DefaultKeyResolverTest
{
    public function trimsRawMaterial(): void
    {
        $resolver = new DefaultKeyResolver();

        Assert::same($resolver->resolve('  order-1  '), 'order-1');
    }

    public function composesHierarchyWithSeparator(): void
    {
        $resolver = new DefaultKeyResolver(separator: ':');

        Assert::same($resolver->resolve('step', 'parent'), 'parent:step');
    }

    public function isDeterministicForSameInput(): void
    {
        $resolver = new DefaultKeyResolver();

        Assert::same($resolver->resolve('abc', 'p'), $resolver->resolve('abc', 'p'));
    }

    public function hashesWhenComposedKeyExceedsMaxLength(): void
    {
        $resolver = new DefaultKeyResolver(maxLength: 8, hashAlgo: 'sha256');

        $key = $resolver->resolve('this-is-a-long-key');

        Assert::same(\strlen($key), 64);
        Assert::same($key, \hash('sha256', 'this-is-a-long-key'));
    }

    public function hashesAlwaysWhenConfigured(): void
    {
        $resolver = new DefaultKeyResolver(hashAlways: true);

        Assert::same($resolver->resolve('short'), \hash('sha256', 'short'));
    }

    public function rejectsNullMaterial(): never
    {
        Expect::exception(MissingKeyException::class);

        (new DefaultKeyResolver())->resolve(null);
    }

    public function rejectsBlankMaterial(): never
    {
        Expect::exception(MissingKeyException::class);

        (new DefaultKeyResolver())->resolve('   ');
    }
}
