<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;

final class N8nWorkflowDefinitionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $json = file_get_contents(
            dirname(__DIR__, 3).'/n8n/workflows/participant-activation-waha.n8n.json',
        );
        $this->assertIsString($json);
        $this->workflow = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_webhook_is_authenticated_and_uses_explicit_responses(): void
    {
        $webhook = $this->node('Participant Activation Webhook');

        $this->assertSame('n8n-nodes-base.webhook', $webhook['type']);
        $this->assertSame(2.1, $webhook['typeVersion']);
        $this->assertSame('headerAuth', $webhook['parameters']['authentication']);
        $this->assertSame('responseNode', $webhook['parameters']['responseMode']);
        $this->assertFalse($this->workflow['active']);
    }

    public function test_atomic_claim_guards_same_key_and_different_payload(): void
    {
        $claim = $this->node('Claim Idempotency Key');
        $query = $claim['parameters']['query'];

        $this->assertStringContainsString('ON CONFLICT (idempotency_key) DO UPDATE', $query);
        $this->assertStringContainsString('(claim_token = $3) AS claimed', $query);
        $this->assertStringContainsString('(request_hash = md5($2)) AS payload_matches', $query);
        $this->assertSame(422, $this->node('Respond Payload Mismatch')['parameters']['options']['responseCode']);
        $this->assertSame(503, $this->node('Respond Uncertain')['parameters']['options']['responseCode']);
    }

    public function test_waha_request_contains_no_embedded_secret_and_execution_data_is_not_saved(): void
    {
        $waha = $this->node('Send WAHA Message');
        $serialized = json_encode($this->workflow, JSON_THROW_ON_ERROR);

        $this->assertSame('genericCredentialType', $waha['parameters']['authentication']);
        $this->assertSame('httpHeaderAuth', $waha['parameters']['genericAuthType']);
        $this->assertSame('https://REPLACE-WAHA-HOST/api/sendText', $waha['parameters']['url']);
        $this->assertStringNotContainsString('X-Api-Key:', $serialized);
        $this->assertStringContainsString('Bearer <N8N_WEBHOOK_TOKEN>', $serialized);
        $this->assertStringNotContainsString('change-this', $serialized);
        $this->assertSame('none', $this->workflow['settings']['saveDataErrorExecution']);
        $this->assertSame('none', $this->workflow['settings']['saveDataSuccessExecution']);
    }

    /** @return array<string, mixed> */
    private function node(string $name): array
    {
        foreach ($this->workflow['nodes'] as $node) {
            if (($node['name'] ?? null) === $name) {
                return $node;
            }
        }

        $this->fail("Workflow node [{$name}] was not found.");
    }
}
