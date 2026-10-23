<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when Gemini returns a 429 RESOURCE_EXHAUSTED quota error, as
 * distinct from a transient failure (503, connection timeout) — retrying a
 * quota error is pointless until the quota window resets (often hours away
 * for the free tier's daily caps), so callers surface this message directly
 * to the user instead of the generic "try again" fallback.
 */
class GeminiQuotaExceededException extends RuntimeException
{
}
