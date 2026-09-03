<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Pipeline;

/**
 * The three natures of a failure. This is a PIPELINE concept, not a core one — the core
 * (DefaultLeaseManager/storage) never imports it. Determinism and "will a retry help" are two independent
 * axes, hence three categories.
 *
 * @api
 */
enum FailureKind
{
    /** Exception as a valid outcome (422, "no funds") → complete(success=false), cached. */
    case Domain;

    /** User explicitly marked the outcome unrecoverable → error() (abort without re-enqueue) + report. */
    case Bug;

    /** Infra/external, a retry is meaningful → abort() + re-enqueue by the retry middleware. */
    case Infrastructure;
}
