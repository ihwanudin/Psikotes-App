# Callback and Reconciliation Runbook

Assessment result events are committed in `outbox_messages` in the same transaction as the result-version transition. The scheduler runs `integrations:dispatch-outbox` every minute and `integrations:reconcile-callbacks` every five minutes; workers consume the `integrations` queue.

For each callback, verify `X-Client-Id`, the timestamp tolerance, HMAC of the exact raw body, and `Idempotency-Key=eventId`. Store the receiver's claim atomically before applying the event. Replays of the same event ID must return the already-applied outcome.

If the sender receives a definitive 2xx, the delivery and outbox become delivered/processed. A definitive non-2xx becomes failed and may retry with the same event ID. A timeout or connection loss after send becomes `UNKNOWN`; no retry is allowed. Reconciliation calls the registry-controlled `reconciliationPath` with the same event ID:

- `received=true`: mark delivered and close the outbox;
- event not found: mark failed/pending for a later retry using the same event ID;
- reconciliation timeout: remain `UNKNOWN` and investigate network/receiver health.

Operational checks must use event/client/status identifiers only. Never paste HMAC secrets, request profile data, raw answers, evidence, internal narrative, object keys, signed URLs, or remote response bodies into logs or tickets. Rotating a credential requires updating the secret store reference atomically with the receiver and testing a signed staging event before enabling production delivery.
