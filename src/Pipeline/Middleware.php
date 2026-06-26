<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Pipeline;

/**
 * Marker for a pipeline middleware. Concrete, phase-named interfaces extend it and declare a typed
 * `process(<Call>, callable $next): mixed`:
 *
 *  - {@see ResolutionMiddleware} over {@see IdempotencyCall} (resolution / outer pipeline);
 *  - {@see ExecutionMiddleware} over {@see ExecutionCall} (execution / inner pipeline).
 *
 * The marker lets the single universal {@see Pipeline} accept either family. Matching the right
 * middleware family to a pipeline's context is the assembler's responsibility (and middleware
 * type-guard their own context), since the marker itself cannot enforce it.
 *
 * @api
 */
interface Middleware {}
