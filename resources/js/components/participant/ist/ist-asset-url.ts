/**
 * Pure types for `GET /sessions/:id/assets/{assetId}/url` — no React, no
 * fetch. Verified against `GetAssessmentSessionAssetUrlController.php`
 * (commit `1987505`, already merged), not guessed: success response is
 * `{url, expires_at}` (`DATE_ATOM` format); TTL is
 * `min(now + 10 minutes, session ends_at)`. Error codes:
 * `SESSION_NOT_FOUND` (404), `SESSION_NOT_STARTED`/`SESSION_CLOSED`/
 * `DEADLINE_EXCEEDED` (409, "readable exactly when writable" — same gate
 * as `/items`), `ASSET_NOT_FOUND` (404, no reference row for this
 * instrument+assetId).
 *
 * This is the general-purpose asset-URL contract every image-bearing
 * subtest (FA/WU, once their reader ships) will use — nothing here is
 * specific to which subtest asked for the image.
 */

export type IstAssetUrlOutcome =
    | { type: 'available'; url: string; expiresAt: string }
    | { type: 'session_not_found' }
    | { type: 'session_not_started' }
    | { type: 'session_closed' }
    | { type: 'deadline_exceeded' }
    | { type: 'asset_not_found' }
    | { type: 'network_error' };

export type FetchIstAssetUrl = (assetId: string) => Promise<IstAssetUrlOutcome>;

/** True once `expiresAt` (an RFC 3339 / `DATE_ATOM` string) is at or
 * before `now` — a plain point-in-time comparison, not a ticking clock:
 * this is used once, right before deciding whether to trust an
 * already-fetched URL, never polled on an interval. Matches the
 * project's "the client never owns time" rule the same way displaying a
 * server-given `remaining_seconds` does — this reads a server-given
 * instant, it does not compute or guess one. */
export function isIstAssetUrlExpired(expiresAt: string, now: Date): boolean {
    return now.getTime() >= new Date(expiresAt).getTime();
}
