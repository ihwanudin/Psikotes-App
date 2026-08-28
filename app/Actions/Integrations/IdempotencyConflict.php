<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use RuntimeException;

final class IdempotencyConflict extends RuntimeException {}
