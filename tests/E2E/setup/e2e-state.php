<?php

declare(strict_types=1);

use App\Filament\Widgets\F7OperationalOverview;
use App\Models\Admin;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$command = $argv[1] ?? '';

try {
    $payload = match ($command) {
        'participant-by-email' => participantByEmail((string) ($argv[2] ?? '')),
        'f7-metrics' => f7Metrics((string) ($argv[2] ?? '')),
        default => throw new InvalidArgumentException('Unknown E2E state command.'),
    };

    fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

/** @return array<string, mixed> */
function participantByEmail(string $email): array
{
    $participant = DB::table('participants')->where('email', $email)->first();

    if ($participant === null) {
        throw new RuntimeException("Participant not found for {$email}.");
    }

    $orders = DB::table('orders')
        ->leftJoin('payment_methods', 'payment_methods.id', '=', 'orders.payment_method_id')
        ->where('orders.participant_id', $participant->id)
        ->orderBy('orders.id')
        ->get([
            'orders.id',
            'orders.public_id',
            'orders.status',
            'orders.proof_object_key',
            'orders.paid_at',
            'payment_methods.code as payment_method_code',
        ])
        ->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'publicId' => (string) $row->public_id,
            'status' => (string) $row->status,
            'proofObjectKey' => $row->proof_object_key,
            'paidAt' => $row->paid_at,
            'paymentMethodCode' => $row->payment_method_code,
        ])
        ->all();

    $entitlements = DB::table('entitlements')
        ->where('participant_id', $participant->id)
        ->orderBy('test_type')
        ->get(['test_type', 'status', 'ready_at'])
        ->map(fn (object $row): array => [
            'testType' => (string) $row->test_type,
            'status' => (string) $row->status,
            'readyAt' => $row->ready_at,
        ])
        ->all();

    return [
        'participant' => [
            'id' => (int) $participant->id,
            'fullName' => (string) $participant->full_name,
            'email' => (string) $participant->email,
            'testNumber' => (string) $participant->test_number,
            'birthDate' => substr((string) $participant->birth_date, 0, 10),
            'branchId' => (int) $participant->branch_id,
        ],
        'orders' => $orders,
        'entitlements' => $entitlements,
    ];
}

/** @return array<string, mixed> */
function f7Metrics(string $adminEmail): array
{
    $admin = Admin::query()->where('email', $adminEmail)->first();

    if (! $admin instanceof Admin) {
        throw new RuntimeException("Admin not found for {$adminEmail}.");
    }

    return F7OperationalOverview::metricsFor($admin);
}
