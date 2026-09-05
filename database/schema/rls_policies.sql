CREATE SCHEMA IF NOT EXISTS app_private;
REVOKE ALL ON SCHEMA app_private FROM PUBLIC;
GRANT USAGE ON SCHEMA app_private TO psikotes_runtime;

CREATE OR REPLACE FUNCTION app_private.app_role()
RETURNS text
LANGUAGE sql
STABLE
PARALLEL SAFE
AS $$
    SELECT NULLIF(current_setting('app.role', true), '')
$$;

CREATE OR REPLACE FUNCTION app_private.app_branch_id()
RETURNS bigint
LANGUAGE sql
STABLE
PARALLEL SAFE
AS $$
    SELECT CASE
        WHEN current_setting('app.branch_id', true) ~ '^[0-9]+$'
        THEN current_setting('app.branch_id', true)::bigint
    END
$$;

CREATE OR REPLACE FUNCTION app_private.app_participant_id()
RETURNS bigint
LANGUAGE sql
STABLE
PARALLEL SAFE
AS $$
    SELECT CASE
        WHEN current_setting('app.participant_id', true) ~ '^[0-9]+$'
        THEN current_setting('app.participant_id', true)::bigint
    END
$$;

REVOKE ALL ON FUNCTION app_private.app_role() FROM PUBLIC;
REVOKE ALL ON FUNCTION app_private.app_branch_id() FROM PUBLIC;
REVOKE ALL ON FUNCTION app_private.app_participant_id() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION app_private.app_role() TO psikotes_runtime;
GRANT EXECUTE ON FUNCTION app_private.app_branch_id() TO psikotes_runtime;
GRANT EXECUTE ON FUNCTION app_private.app_participant_id() TO psikotes_runtime;

ALTER TABLE branches ENABLE ROW LEVEL SECURITY;
ALTER TABLE branches FORCE ROW LEVEL SECURITY;
ALTER TABLE admins ENABLE ROW LEVEL SECURITY;
ALTER TABLE admins FORCE ROW LEVEL SECURITY;
ALTER TABLE participants ENABLE ROW LEVEL SECURITY;
ALTER TABLE participants FORCE ROW LEVEL SECURITY;
ALTER TABLE referral_visits ENABLE ROW LEVEL SECURITY;
ALTER TABLE referral_visits FORCE ROW LEVEL SECURITY;
ALTER TABLE consent_records ENABLE ROW LEVEL SECURITY;
ALTER TABLE consent_records FORCE ROW LEVEL SECURITY;
ALTER TABLE payment_methods ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_methods FORCE ROW LEVEL SECURITY;
ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE orders FORCE ROW LEVEL SECURITY;
ALTER TABLE entitlements ENABLE ROW LEVEL SECURITY;
ALTER TABLE entitlements FORCE ROW LEVEL SECURITY;
ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs FORCE ROW LEVEL SECURITY;
ALTER TABLE outbox_messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE outbox_messages FORCE ROW LEVEL SECURITY;
ALTER TABLE dass.assessments ENABLE ROW LEVEL SECURITY;
ALTER TABLE dass.assessments FORCE ROW LEVEL SECURITY;
ALTER TABLE dass.responses ENABLE ROW LEVEL SECURITY;
ALTER TABLE dass.responses FORCE ROW LEVEL SECURITY;
ALTER TABLE dass.results ENABLE ROW LEVEL SECURITY;
ALTER TABLE dass.results FORCE ROW LEVEL SECURITY;

CREATE POLICY branches_read ON branches FOR SELECT TO psikotes_runtime
USING (
    app_private.app_role() IN ('service', 'super_admin', 'psychologist')
    OR (app_private.app_role() IN ('branch_admin', 'staff') AND id = app_private.app_branch_id())
    OR (app_private.app_role() = 'participant' AND id = app_private.app_branch_id())
);
CREATE POLICY branches_write ON branches FOR ALL TO psikotes_runtime
USING (app_private.app_role() IN ('service', 'super_admin'))
WITH CHECK (app_private.app_role() IN ('service', 'super_admin'));

CREATE POLICY admins_read ON admins FOR SELECT TO psikotes_runtime
USING (
    app_private.app_role() IN ('service', 'super_admin')
    OR (app_private.app_role() IN ('branch_admin', 'staff') AND branch_id = app_private.app_branch_id())
);
CREATE POLICY admins_write ON admins FOR ALL TO psikotes_runtime
USING (app_private.app_role() IN ('service', 'super_admin'))
WITH CHECK (app_private.app_role() IN ('service', 'super_admin'));

CREATE POLICY participants_read ON participants FOR SELECT TO psikotes_runtime
USING (
    app_private.app_role() IN ('service', 'super_admin', 'psychologist')
    OR (app_private.app_role() IN ('branch_admin', 'staff') AND branch_id = app_private.app_branch_id())
    OR (app_private.app_role() = 'participant' AND id = app_private.app_participant_id())
);
CREATE POLICY participants_write ON participants FOR ALL TO psikotes_runtime
USING (
    app_private.app_role() IN ('service', 'super_admin')
    OR (app_private.app_role() IN ('branch_admin', 'staff') AND branch_id = app_private.app_branch_id())
)
WITH CHECK (
    app_private.app_role() IN ('service', 'super_admin')
    OR (app_private.app_role() IN ('branch_admin', 'staff') AND branch_id = app_private.app_branch_id())
);

CREATE POLICY referral_visits_read ON referral_visits FOR SELECT TO psikotes_runtime
USING (
    app_private.app_role() IN ('service', 'super_admin')
    OR (app_private.app_role() IN ('branch_admin', 'staff') AND branch_id = app_private.app_branch_id())
);
CREATE POLICY referral_visits_write ON referral_visits FOR ALL TO psikotes_runtime
USING (app_private.app_role() = 'service')
WITH CHECK (app_private.app_role() = 'service');

CREATE POLICY consent_records_read ON consent_records FOR SELECT TO psikotes_runtime
USING (
    app_private.app_role() IN ('service', 'psychologist')
    OR (app_private.app_role() = 'participant' AND participant_id = app_private.app_participant_id())
    OR (
        consent_type <> 'dass'
        AND (
            app_private.app_role() = 'super_admin'
            OR (
                app_private.app_role() IN ('branch_admin', 'staff')
                AND EXISTS (
                    SELECT 1 FROM participants
                    WHERE participants.id = consent_records.participant_id
                      AND participants.branch_id = app_private.app_branch_id()
                )
            )
        )
    )
);
-- consent_records_write is permissive FOR ALL, so this SELECT guard prevents it
-- from granting DASS reads that consent_records_read intentionally withholds.
CREATE POLICY consent_records_dass_privacy ON consent_records AS RESTRICTIVE FOR SELECT TO psikotes_runtime
USING (
    consent_type <> 'dass'
    OR app_private.app_role() IN ('service', 'psychologist')
    OR (app_private.app_role() = 'participant' AND participant_id = app_private.app_participant_id())
);
CREATE POLICY consent_records_write ON consent_records FOR ALL TO psikotes_runtime
USING (app_private.app_role() IN ('service', 'super_admin'))
WITH CHECK (app_private.app_role() IN ('service', 'super_admin'));

CREATE POLICY payment_methods_read ON payment_methods FOR SELECT TO psikotes_runtime
USING (app_private.app_role() IN ('service', 'super_admin', 'branch_admin', 'staff', 'psychologist', 'participant'));
CREATE POLICY payment_methods_write ON payment_methods FOR ALL TO psikotes_runtime
USING (app_private.app_role() IN ('service', 'super_admin'))
WITH CHECK (app_private.app_role() IN ('service', 'super_admin'));

-- finance_policy_start
CREATE POLICY orders_read ON orders FOR SELECT TO psikotes_runtime
USING (
    app_private.app_role() IN ('service', 'super_admin')
    OR (app_private.app_role() = 'participant' AND participant_id = app_private.app_participant_id())
    OR (
        app_private.app_role() IN ('branch_admin', 'staff')
        AND EXISTS (
            SELECT 1 FROM participants
            WHERE participants.id = orders.participant_id
              AND participants.branch_id = app_private.app_branch_id()
        )
    )
);
CREATE POLICY orders_write ON orders FOR ALL TO psikotes_runtime
USING (app_private.app_role() IN ('service', 'super_admin'))
WITH CHECK (app_private.app_role() IN ('service', 'super_admin'));

CREATE POLICY entitlements_read ON entitlements FOR SELECT TO psikotes_runtime
USING (
    app_private.app_role() IN ('service', 'super_admin', 'psychologist')
    OR (app_private.app_role() = 'participant' AND participant_id = app_private.app_participant_id())
    OR (
        app_private.app_role() IN ('branch_admin', 'staff')
        AND EXISTS (
            SELECT 1 FROM participants
            WHERE participants.id = entitlements.participant_id
              AND participants.branch_id = app_private.app_branch_id()
        )
    )
);
CREATE POLICY entitlements_write ON entitlements FOR ALL TO psikotes_runtime
USING (app_private.app_role() IN ('service', 'super_admin'))
WITH CHECK (app_private.app_role() IN ('service', 'super_admin'));
-- finance_policy_end

CREATE POLICY audit_logs_read ON audit_logs FOR SELECT TO psikotes_runtime
USING (app_private.app_role() IN ('service', 'super_admin'));
CREATE POLICY audit_logs_write ON audit_logs FOR ALL TO psikotes_runtime
USING (app_private.app_role() = 'service')
WITH CHECK (app_private.app_role() = 'service');

CREATE POLICY outbox_messages_service ON outbox_messages FOR ALL TO psikotes_runtime
USING (app_private.app_role() = 'service')
WITH CHECK (app_private.app_role() = 'service');

-- dass_policy_start
CREATE POLICY dass_assessments_read ON dass.assessments FOR SELECT TO psikotes_runtime
USING (
    app_private.app_role() IN ('service', 'psychologist')
    OR (app_private.app_role() = 'participant' AND participant_id = app_private.app_participant_id())
);
CREATE POLICY dass_assessments_write ON dass.assessments FOR ALL TO psikotes_runtime
USING (
    app_private.app_role() = 'service'
    OR (app_private.app_role() = 'participant' AND participant_id = app_private.app_participant_id())
)
WITH CHECK (
    app_private.app_role() = 'service'
    OR (app_private.app_role() = 'participant' AND participant_id = app_private.app_participant_id())
);

CREATE POLICY dass_responses_read ON dass.responses FOR SELECT TO psikotes_runtime
USING (
    app_private.app_role() IN ('service', 'psychologist')
    OR (
        app_private.app_role() = 'participant'
        AND EXISTS (
            SELECT 1 FROM dass.assessments
            WHERE dass.assessments.id = dass.responses.assessment_id
              AND dass.assessments.participant_id = app_private.app_participant_id()
        )
    )
);
CREATE POLICY dass_responses_write ON dass.responses FOR ALL TO psikotes_runtime
USING (
    app_private.app_role() = 'service'
    OR (
        app_private.app_role() = 'participant'
        AND EXISTS (
            SELECT 1 FROM dass.assessments
            WHERE dass.assessments.id = dass.responses.assessment_id
              AND dass.assessments.participant_id = app_private.app_participant_id()
        )
    )
)
WITH CHECK (
    app_private.app_role() = 'service'
    OR (
        app_private.app_role() = 'participant'
        AND EXISTS (
            SELECT 1 FROM dass.assessments
            WHERE dass.assessments.id = dass.responses.assessment_id
              AND dass.assessments.participant_id = app_private.app_participant_id()
        )
    )
);

CREATE POLICY dass_results_read ON dass.results FOR SELECT TO psikotes_runtime
USING (
    app_private.app_role() IN ('service', 'psychologist')
    OR (
        app_private.app_role() = 'participant'
        AND EXISTS (
            SELECT 1 FROM dass.assessments
            WHERE dass.assessments.id = dass.results.assessment_id
              AND dass.assessments.participant_id = app_private.app_participant_id()
        )
    )
);
CREATE POLICY dass_results_write ON dass.results FOR ALL TO psikotes_runtime
USING (app_private.app_role() = 'service')
WITH CHECK (app_private.app_role() = 'service');
-- dass_policy_end
