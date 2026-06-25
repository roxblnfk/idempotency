<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Pipeline;

/**
 * Classifies a thrown {@see \Throwable} into a {@see FailureKind}. Lives at the pipeline level — the
 * user may replace it wholesale without touching the core.
 *
 * @api
 */
interface FailureClassifierInterface
{
    public function classify(\Throwable $e): FailureKind;
}
