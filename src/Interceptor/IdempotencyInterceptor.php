<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Interceptor;

use Spiral\Interceptors\InterceptorInterface;

/**
 * Stable container alias for the transport-flavored {@see PipelineIdempotencyInterceptor}.
 *
 * Applications list THIS interface in their domain-core interceptor stack. The root container holds
 * only a scoped {@see \Spiral\Core\Config\Proxy} for it (registered by
 * {@see \Spiral\Idempotency\Bootloader\HttpIdempotencyBootloader}), so a domain core may be built in
 * any scope — each intercept() call resolves the real interceptor from the ACTIVE dispatcher scope
 * (`http`, later `queue`, ...), where the transport integration bound its flavor. Invoking it outside
 * such a scope fails fast with a {@see \Spiral\Idempotency\Exception\MisconfigurationException}
 * instead of silently picking a wrong transport.
 *
 * @api
 */
interface IdempotencyInterceptor extends InterceptorInterface {}
