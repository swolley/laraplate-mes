<?php

declare(strict_types=1);

namespace Modules\MES\Exceptions;

use RuntimeException;

/**
 * Thrown when a bill of materials is edited while locked by a released
 * production order.
 */
final class BomLockedException extends RuntimeException {}
