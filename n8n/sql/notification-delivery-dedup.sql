CREATE TABLE IF NOT EXISTS notification_delivery_dedup (
    idempotency_key VARCHAR(26) PRIMARY KEY,
    request_hash CHAR(32) NOT NULL,
    claim_token VARCHAR(64) NOT NULL,
    state VARCHAR(16) NOT NULL,
    first_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    sent_at TIMESTAMPTZ NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    CONSTRAINT notification_delivery_dedup_state_check
        CHECK (state IN ('processing', 'sent'))
);

CREATE INDEX IF NOT EXISTS notification_delivery_dedup_expires_at_index
    ON notification_delivery_dedup (expires_at);

-- Run periodically only after the Laravel outbox retention window has elapsed.
-- DELETE FROM notification_delivery_dedup WHERE expires_at < NOW();
