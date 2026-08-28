<?php

declare(strict_types=1);

namespace Tests\Feature\F1;

use App\Actions\Payments\SetPaymentMethodActivation;
use App\Actions\Payments\VerifyManualTransfer;
use App\Contracts\Notifier;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Services\Notifications\DeliverParticipantActivation;
use App\Services\Notifications\FakeNotifier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ManualActivationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_payment_flow_activates_participant_once_end_to_end(): void
    {
        Storage::fake('payment-proofs');
        $this->seed(DatabaseSeeder::class);
        DB::table('packages')->where('code', 'IST')->update(['is_active' => true]);
        $superAdmin = Admin::query()->create([
            'name' => 'Super Admin F1',
            'email' => 'super-admin-f1@example.test',
            'password' => 'test-only-password',
            'role' => AdminRole::SuperAdmin,
        ]);
        $manualTransfer = PaymentMethod::query()->where('code', 'manual_transfer')->sole();
        app(SetPaymentMethodActivation::class)->handle($superAdmin, $manualTransfer->id, true);

        $this->assertTrue($manualTransfer->fresh()->is_active);
        $this->assertFalse(PaymentMethod::query()->where('code', 'xendit')->sole()->is_active);

        $this->get('/register')->assertOk();
        $registrationToken = (string) session('registration.token');
        $packageId = (int) DB::table('packages')->where('code', 'IST')->value('id');

        $this->post('/registrations', [
            '_registration_token' => $registrationToken,
            'package_id' => $packageId,
            'payment_method_code' => 'manual_transfer',
            'full_name' => 'Peserta Gerbang F1',
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
            'email' => 'f1-participant@example.test',
            'consent_psychotest' => true,
            'consent_dass' => false,
            'include_consultation' => false,
        ])->assertRedirect('/registration/received');

        $participant = Participant::query()->with(['orders', 'entitlements'])->sole();
        $order = $participant->orders->sole();
        $this->assertSame(99_000, $order->amount);
        $this->assertSame('pending', $order->status->value);
        $this->assertSame(['locked'], $participant->entitlements->pluck('status')->all());

        $this->post('/registration/manual-payment-proof', [
            'payment_proof' => UploadedFile::fake()->createWithContent(
                'transfer.pdf',
                "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF",
            ),
        ])->assertRedirect('/registration/received')->assertSessionHasNoErrors();

        $order->refresh();
        $proofKey = (string) $order->proof_object_key;
        Storage::disk('payment-proofs')->assertExists($proofKey);

        $admin = Admin::query()->create([
            'branch_id' => $participant->branch_id,
            'name' => 'Verifier F1',
            'email' => 'verifier-f1@example.test',
            'password' => 'test-only-password',
            'role' => AdminRole::BranchAdmin,
            'can_verify_payments' => true,
        ]);
        $verification = app(VerifyManualTransfer::class);
        $verification->approve($admin, $order->id, $proofKey);
        $verification->approve($admin, $order->id, $proofKey);

        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertSame(['ready'], $participant->entitlements()->pluck('status')->all());
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseCount('audit_logs', 2);

        $notifier = new FakeNotifier;
        $this->app->instance(Notifier::class, $notifier);
        $message = OutboxMessage::query()->sole();
        $delivery = app(DeliverParticipantActivation::class);
        $delivery->handle($message->message_id);
        $delivery->handle($message->message_id);

        $this->assertCount(1, $notifier->delivered());
        $this->assertSame($participant->test_number, $notifier->delivered()[0]->testNumber);
        $this->assertSame('processed', $message->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 3);

        $login = $this->postJson('/api/auth/participant/login', [
            'test_number' => $participant->test_number,
            'birth_date' => '2001-04-15',
        ])->assertOk()->assertJsonStructure(['jwt']);

        $jwt = (string) $login->json('jwt');
        $this->withToken($jwt)
            ->postJson('/api/sessions/ist/start')
            ->assertStatus(501)
            ->assertJsonPath('error.code', 'SESSION_ENGINE_PENDING');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_method.activation_changed',
            'subject_type' => PaymentMethod::class,
            'subject_id' => (string) $manualTransfer->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'manual_transfer.approved',
            'subject_type' => Order::class,
            'subject_id' => $order->public_id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'participant_notification.delivered',
            'subject_type' => Order::class,
            'subject_id' => $order->public_id,
        ]);
    }
}
