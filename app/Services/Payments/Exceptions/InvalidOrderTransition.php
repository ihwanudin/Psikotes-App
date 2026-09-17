<?php

declare(strict_types=1);

namespace App\Services\Payments\Exceptions;

use DomainException;

final class InvalidOrderTransition extends DomainException {}
