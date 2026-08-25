<?php

declare(strict_types=1);

namespace App\Services\Payments\Exceptions;

use RuntimeException;

final class PaymentAmountMismatch extends RuntimeException {}
