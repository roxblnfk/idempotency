<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Bootloader;

use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Core\BinderInterface;
use Spiral\Idempotency\Http\HttpKeySource;
use Spiral\Idempotency\Http\HttpResultCodec;
use Spiral\Idempotency\KeySourceInterface;
use Spiral\Idempotency\ResultCodecInterface;

/**
 * Opt-in HTTP wiring: inside the HTTP request scope binds {@see KeySourceInterface} to
 * {@see HttpKeySource} and {@see ResultCodecInterface} to {@see HttpResultCodec}. The
 * {@see \Spiral\Idempotency\Interceptor\IdempotencyInterceptor}, resolving both from the active scope,
 * then handles HTTP automatically — no proxy needed. Bind different implementations in another
 * transport's scope (gRPC, queue, ...) to make the same interceptor work there.
 *
 * Register alongside {@see IdempotencyBootloader} in an app that exposes the #[Idempotent] attribute
 * over HTTP, then add the interceptor to your domain core's interceptor list.
 *
 * @api
 */
final class HttpIdempotencyBootloader extends Bootloader
{
    public function defineDependencies(): array
    {
        return [IdempotencyBootloader::class];
    }

    public function init(BinderInterface $binder): void
    {
        // 'http-request' is the framework's per-request HTTP scope name.
        $http = $binder->getBinder('http-request');
        $http->bindSingleton(KeySourceInterface::class, HttpKeySource::class);
        $http->bindSingleton(ResultCodecInterface::class, HttpResultCodec::class);
    }
}
