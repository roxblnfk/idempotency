<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Renders a DOMAIN failure (an exception that is a valid negative outcome) into the HTTP response the
 * client should receive — so the outcome is cached as a response snapshot and an idempotent replay
 * returns the very same status and body instead of a different one.
 *
 * Why this exists: a thrown domain exception bypasses {@see HttpOutcomeMiddleware} on the first attempt
 * (the application exception handler renders it), while on replay the driver rethrows either the exact
 * type (see {@see \Spiral\Idempotency\ReplayableFailureInterface}) or a
 * {@see \Spiral\Idempotency\Exception\CachedDomainFailureException} — a type the handler does not know,
 * typically rendered as `500`. Two attempts, two statuses. Binding this interface moves the rendering
 * INSIDE the idempotent operation: the failure becomes a response before it is cached, so the first
 * attempt and every replay are byte-identical (and replays carry the `Idempotency-Replay` header).
 *
 * Only failures classified as {@see \Spiral\Idempotency\Pipeline\FailureKind::Domain} are passed here.
 * Infrastructure and Bug failures are never rendered — they must stay exceptions so the driver releases
 * the key (a retry re-runs) instead of caching a transient error as the outcome.
 *
 * Contract:
 *  - return a {@see ResponseInterface} for failures this renderer owns — it is treated exactly like a
 *    response returned by the action (the `cacheable` predicate of {@see HttpOutcomeMiddleware} still
 *    applies, so a rendered 5xx is NOT cached and re-runs);
 *  - return `null` for anything else: the exception is rethrown untouched, preserving the current
 *    behaviour (the application exception handler renders it, replay may differ).
 *
 * Optional: absent a container binding, {@see HttpOutcomeMiddleware} keeps the throw-through behaviour.
 *
 * @api
 */
interface DomainFailureRendererInterface
{
    /**
     * @param \Throwable $failure a failure classified as Domain
     * @return ResponseInterface|null the response to cache and replay, or null to rethrow the failure
     */
    public function render(\Throwable $failure): ?ResponseInterface;
}
