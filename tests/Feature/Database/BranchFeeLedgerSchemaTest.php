<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class BranchFeeLedgerSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_fee_ledger_tables_are_created_with_expected_columns(): void
    {
        foreach ([
            'branch_fee_rules',
            'commission_entries',
            'withdrawal_requests',
            'withdrawal_request_items',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertTrue(Schema::hasColumns('branch_fee_rules', [
            'branch_id',
            'rate_basis',
            'rate_type',
            'percentage_bps',
            'fixed_amount',
            'effective_from',
            'effective_until',
            'created_by_admin_id',
        ]));
        $this->assertTrue(Schema::hasColumns('commission_entries', [
            'branch_id',
            'participant_id',
            'assessment_bill_id',
            'assessment_bill_item_id',
            'order_id',
            'source_type',
            'source_id',
            'period_month',
            'paid_at',
            'fee_rule_id',
            'rate_basis_amount',
            'gross_amount',
            'commission_amount',
            'voided_at',
            'void_reason',
        ]));
        $this->assertTrue(Schema::hasColumns('withdrawal_requests', [
            'branch_id',
            'period_month',
            'public_reference',
            'status',
            'requested_amount',
            'approved_amount',
            'requested_by_admin_id',
            'approved_by_admin_id',
            'paid_by_admin_id',
            'submitted_at',
            'approved_at',
            'paid_at',
            'rejected_at',
            'rejection_reason',
        ]));
        $this->assertTrue(Schema::hasColumns('withdrawal_request_items', [
            'branch_id',
            'withdrawal_request_id',
            'commission_entry_id',
            'amount_snapshot',
            'currency',
        ]));
    }
}
