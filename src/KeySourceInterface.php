<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

/**
 * Extracts the *raw material* of the key from a transport object. One per transport:
 * only the source is transport-dependent, normalization is shared in {@see KeyResolverInterface}.
 *
 * HttpKeySource:  $request->getHeaderLine('Idempotency-Key')
 * JobKeySource:   dot-path over the job payload (payload.transactionId)
 * EventKeySource: dot-path over the event object
 *
 * @api
 */
interface KeySourceInterface
{
    /**
     * @return string|null raw key material (header / payload-path), or null if absent
     */
    public function extract(mixed $endpointContext): ?string;
}
