<?php

declare(strict_types=1);

namespace Modules\MES\Machine\Normalizers;

use RuntimeException;

/**
 * The payload cannot be read by the source's normaliser; retrying will not help.
 */
final class UnreadableMachinePayload extends RuntimeException {}
