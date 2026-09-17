<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Security\RlsContextRunner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final readonly class StoreManualPaymentProof
{
    public function __construct(private RlsContextRunner $runner) {}

    public function handle(int $participantId, UploadedFile $proof): Order
    {
        $disk = (string) config('payments.manual_proof_disk', 'payment-proofs');
        $stored = $this->storeFile($disk, $proof);

        try {
            [$order, $replaced] = $this->runner->runAsService(
                function () use ($participantId, $stored): array {
                    $order = Order::query()
                        ->with('paymentMethod')
                        ->where('participant_id', $participantId)
                        ->whereHas('paymentMethod', fn ($query) => $query->where('code', 'manual_transfer'))
                        ->latest('id')
                        ->lockForUpdate()
                        ->first();

                    if ($order === null || $order->status !== OrderStatus::Pending) {
                        throw ValidationException::withMessages([
                            'payment_proof' => 'Order transfer manual tidak tersedia untuk unggahan bukti.',
                        ]);
                    }

                    $metadata = $order->metadata ?? [];
                    $previousMetadata = $metadata['manual_payment_proof'] ?? null;
                    $replaced = $order->proof_object_key === null
                        ? null
                        : [
                            'disk' => is_array($previousMetadata) && is_string($previousMetadata['disk'] ?? null)
                                ? $previousMetadata['disk']
                                : $stored['disk'],
                            'object_key' => $order->proof_object_key,
                        ];
                    $metadata['manual_payment_proof'] = $stored;
                    $order->proof_object_key = $stored['object_key'];
                    $order->metadata = $metadata;
                    $order->save();

                    return [$order, $replaced];
                },
            );
        } catch (Throwable $exception) {
            $this->deleteSafely($stored['disk'], $stored['object_key']);

            throw $exception;
        }

        if (is_array($replaced)) {
            $this->deleteSafely($replaced['disk'], $replaced['object_key']);
        }

        return $order;
    }

    /** @return array{disk: string, object_key: string, mime_type: string, size_bytes: int, checksum_sha256: string, uploaded_at: string} */
    private function storeFile(string $disk, UploadedFile $file): array
    {
        $mimeType = (string) $file->getMimeType();
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => throw new RuntimeException('Validated payment proof type is not supported.'),
        };
        $random = Str::lower(Str::random(64));
        $filesystem = Storage::disk($disk);
        $key = $filesystem->putFileAs(
            'manual/'.substr($random, 0, 2),
            $file,
            substr($random, 2).'.'.$extension,
            ['visibility' => 'private'],
        );

        if (! is_string($key)) {
            throw new RuntimeException('Payment proof could not be stored.');
        }

        try {
            $filesystem->setVisibility($key, 'private');
            $checksum = hash_file('sha256', $file->getRealPath());

            if (! is_string($checksum)) {
                throw new RuntimeException('Payment proof checksum could not be calculated.');
            }
        } catch (Throwable $exception) {
            $this->deleteSafely($disk, $key);

            throw $exception;
        }

        return [
            'disk' => $disk,
            'object_key' => $key,
            'mime_type' => $mimeType,
            'size_bytes' => $file->getSize(),
            'checksum_sha256' => $checksum,
            'uploaded_at' => now()->utc()->toIso8601String(),
        ];
    }

    private function deleteSafely(string $disk, string $key): void
    {
        try {
            Storage::disk($disk)->delete($key);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
