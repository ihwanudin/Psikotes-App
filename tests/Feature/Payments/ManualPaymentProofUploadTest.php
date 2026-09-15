<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Participant;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DirectPublicPaymentFixture;
use Tests\TestCase;

final class ManualPaymentProofUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('payment-proofs');
    }

    public function test_registration_session_stores_an_image_privately_for_its_manual_order(): void
    {
        [$participant, $order] = $this->manualOrder();

        $this->withSession($this->authorizedSession($participant))
            ->post('/registration/manual-payment-proof', [
                'payment_proof' => UploadedFile::fake()->image('transfer-ayu.jpg', 1200, 800)->size(450),
            ])
            ->assertRedirect('/registration/received')
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertNotNull($order->proof_object_key);
        Storage::disk('payment-proofs')->assertExists($order->proof_object_key);
        $this->assertSame('private', config('filesystems.disks.payment-proofs.visibility'));
        $this->assertStringNotContainsStringIgnoringCase('ayu', $order->proof_object_key);
        $this->assertStringNotContainsStringIgnoringCase('transfer', $order->proof_object_key);
        $this->assertSame('payment-proofs', $order->metadata['manual_payment_proof']['disk'] ?? null);
        $this->assertSame('image/jpeg', $order->metadata['manual_payment_proof']['mime_type'] ?? null);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) ($order->metadata['manual_payment_proof']['checksum_sha256'] ?? ''),
        );
    }

    public function test_valid_pdf_payment_proof_is_accepted(): void
    {
        [$participant, $order] = $this->manualOrder();
        $pdf = UploadedFile::fake()->createWithContent(
            'transfer.pdf',
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF",
        );

        $this->withSession($this->authorizedSession($participant))
            ->post('/registration/manual-payment-proof', ['payment_proof' => $pdf])
            ->assertRedirect('/registration/received')
            ->assertSessionHasNoErrors();

        $order->refresh();
        Storage::disk('payment-proofs')->assertExists((string) $order->proof_object_key);
        $this->assertSame('application/pdf', $order->metadata['manual_payment_proof']['mime_type'] ?? null);
    }

    public function test_upload_requires_a_current_registration_bound_session(): void
    {
        [$participant, $order] = $this->manualOrder();
        $proof = UploadedFile::fake()->image('proof.jpg', 800, 800);

        $this->post('/registration/manual-payment-proof', ['payment_proof' => $proof])
            ->assertForbidden();

        $this->withSession([
            'registration.participant_id' => $participant->id,
            'registration.evidence_authorized_until' => now()->subSecond()->getTimestamp(),
        ])->post('/registration/manual-payment-proof', [
            'payment_proof' => UploadedFile::fake()->image('proof.jpg', 800, 800),
        ])->assertForbidden();

        $this->assertNull($order->fresh()->proof_object_key);
        Storage::disk('payment-proofs')->assertDirectoryEmpty('/');
    }

    public function test_spoofed_file_is_rejected(): void
    {
        $this->assertProofRejected(
            UploadedFile::fake()->create('proof.jpg', 20, 'text/x-php'),
        );
    }

    public function test_unsupported_gif_is_rejected(): void
    {
        $this->assertProofRejected(UploadedFile::fake()->image('proof.gif', 800, 800));
    }

    public function test_oversized_file_is_rejected(): void
    {
        $this->assertProofRejected(
            UploadedFile::fake()->image('proof.png', 800, 800)->size(5_001),
        );
    }

    public function test_xendit_and_terminal_orders_cannot_receive_manual_proofs(): void
    {
        [$xenditParticipant, $xenditOrder] = $this->order('xendit');

        $this->withSession($this->authorizedSession($xenditParticipant))
            ->post('/registration/manual-payment-proof', [
                'payment_proof' => UploadedFile::fake()->image('proof.jpg', 800, 800),
            ])
            ->assertSessionHasErrors('payment_proof');

        [$paidParticipant, $paidOrder] = $this->order('manual_transfer', 'paid');

        $this->withSession($this->authorizedSession($paidParticipant))
            ->post('/registration/manual-payment-proof', [
                'payment_proof' => UploadedFile::fake()->image('proof.jpg', 800, 800),
            ])
            ->assertSessionHasErrors('payment_proof');

        $this->assertNull($xenditOrder->fresh()->proof_object_key);
        $this->assertNull($paidOrder->fresh()->proof_object_key);
        Storage::disk('payment-proofs')->assertDirectoryEmpty('/');
    }

    public function test_replacing_a_pending_proof_removes_the_old_private_object(): void
    {
        [$participant, $order] = $this->manualOrder();
        $session = $this->authorizedSession($participant);

        $this->withSession($session)->post('/registration/manual-payment-proof', [
            'payment_proof' => UploadedFile::fake()->image('first.jpg', 800, 800),
        ])->assertSessionHasNoErrors();
        $firstKey = (string) $order->fresh()->proof_object_key;

        $this->withSession($session)->post('/registration/manual-payment-proof', [
            'payment_proof' => UploadedFile::fake()->image('second.png', 800, 800),
        ])->assertSessionHasNoErrors();
        $secondKey = (string) $order->fresh()->proof_object_key;

        $this->assertNotSame($firstKey, $secondKey);
        Storage::disk('payment-proofs')->assertMissing($firstKey);
        Storage::disk('payment-proofs')->assertExists($secondKey);
    }

    public function test_received_page_exposes_only_the_session_bound_manual_order_state(): void
    {
        [$participant, $order] = $this->manualOrder();
        $session = $this->authorizedSession($participant);

        $this->withSession($session)
            ->get('/registration/received')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('registration/received')
                ->where('manualPayment.required', true)
                ->where('manualPayment.proofUploaded', false)
                ->where('manualPayment.status', 'pending')
                ->where('manualPayment.amount', 250_000)
                ->where('manualPayment.currency', 'IDR')
                ->where('manualPayment.rejectionReason', null)
            );

        $this->withSession($session)->post('/registration/manual-payment-proof', [
            'payment_proof' => UploadedFile::fake()->image('proof.jpg', 800, 800),
        ])->assertSessionHasNoErrors();

        $this->get('/registration/received')
            ->assertInertia(fn (Assert $page) => $page
                ->where('manualPayment.proofUploaded', true)
                ->where('status', 'manual-payment-proof-stored')
            );

        $this->flushSession();
        $this->get('/registration/received')
            ->assertInertia(fn (Assert $page) => $page
                ->where('manualPayment.required', false)
                ->where('manualPayment.proofUploaded', false)
                ->where('manualPayment.status', null)
                ->where('manualPayment.amount', null)
                ->where('manualPayment.currency', null)
                ->where('manualPayment.rejectionReason', null)
            );

        $this->assertSame('pending', $order->fresh()->status->value);
    }

    /** @return array{Participant, Order} */
    private function manualOrder(): array
    {
        return $this->order('manual_transfer');
    }

    /** @return array{Participant, Order} */
    private function order(string $methodCode, string $status = 'pending'): array
    {
        $branch = Branch::query()->create([
            'code' => 'BR-'.Str::upper(Str::random(8)),
            'name' => 'Cabang Uji',
            'ref_code' => 'REF-'.Str::upper(Str::random(8)),
        ]);
        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'default',
            'full_name' => 'Ayu Pratiwi',
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
        ]);
        $orderPublicId = (string) Str::ulid();
        $case = DirectPublicPaymentFixture::caseFor($participant, $branch, $orderPublicId, 250_000);
        $method = new PaymentMethod;
        $method->forceFill([
            'code' => $methodCode,
            'display_name' => Str::headline($methodCode),
            'is_active' => true,
        ])->save();
        $order = Order::query()->create([
            'public_id' => $orderPublicId,
            'participant_id' => $participant->id,
            'assessment_case_id' => $case->id,
            'payment_method_id' => $method->id,
            'status' => $status,
            'amount' => 250_000,
            'currency' => 'IDR',
        ]);

        return [$participant, $order];
    }

    /** @return array<string, int> */
    private function authorizedSession(Participant $participant): array
    {
        return [
            'registration.participant_id' => $participant->id,
            'registration.evidence_authorized_until' => now()->addHours(2)->getTimestamp(),
        ];
    }

    private function assertProofRejected(UploadedFile $proof): void
    {
        [$participant, $order] = $this->manualOrder();

        $this->withSession($this->authorizedSession($participant))
            ->post('/registration/manual-payment-proof', ['payment_proof' => $proof])
            ->assertSessionHasErrors('payment_proof');

        $this->assertNull($order->fresh()->proof_object_key);
        Storage::disk('payment-proofs')->assertDirectoryEmpty('/');
    }
}
