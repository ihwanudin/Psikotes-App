<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth\Exceptions;

use RuntimeException;

final class EntitlementLocked extends RuntimeException {}
