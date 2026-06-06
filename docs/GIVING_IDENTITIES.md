# Giving Identities

This document describes how the ICOBA Endowment API tracks **who** a donor is for leaderboard aggregation, tier recognition, and guest-to-account history linking.

## Problem this solves

Previously, guest donations stored donor type, graduation set, and organization details on each transaction independently. The same email could donate as:

- ICOBA Alumni (Set 1995), then
- Corporate Donor (Acme Ltd), then
- Friend of ICOBA (John Smith)

Leaderboards grouped by email only, which caused:

- **Donor leaderboard** — one row with inconsistent display (wrong set / donor type)
- **Set leaderboard** — the same email's donations credited to multiple sets
- **Registration linking** — all guest history merged by email regardless of identity

Giving identities fix this by enforcing **one canonical profile per email**.

---

## Core concepts

| Concept | Description |
|---|---|
| **Contact email** | Where receipts and reminders are sent |
| **Giving identity** | Canonical donor profile for an email (or registered user) |
| **Aggregation key** | What leaderboards and recognition sum on (`giving_identity_uuid`) |
| **Lock** | After the first **successful payment**, hard profile fields cannot change |

### Identity profile (hard fields)

Hard fields define the identity and must stay consistent for a given email:

| Donor type | Hard fields |
|---|---|
| ICOBA Alumni | `donor_type`, `graduation_set`, `firstname`, `lastname` |
| Corporate Donor | `donor_type`, `organization_name`, `corporate_category` |
| Friend / Relative | `donor_type`, `firstname`, `lastname` |

Soft fields (can be filled in later): `alumni_identifier`, `rc_number`, `tin`, phone.

---

## Rules

### One email → one giving identity

- `john@gmail.com` as Friend **John Smith** is one identity.
- `jane@gmail.com` as Friend **John Smith** is a **different** identity (different email).
- Same name across different emails does **not** merge.

### Blocked on conflict (HTTP 422)

If `john@gmail.com` already donated as Alumni Set 1995, a later checkout as Alumni Set 2005 (or Corporate, or a different name) is rejected:

```json
{
  "message": "This email is already linked to John Doe (ICOBA Alumni, Set 1995). To give under a different identity, use a different email or log in to your account.",
  "errors": {
    "donor_email": ["..."]
  }
}
```

No transaction, pledge, or second identity is created.

### Lock after first successful payment

- Identity is created as `unverified` on first guest checkout/pledge.
- On first **successful** transaction (`TransactionFinalizationService`), `locked_at` is set and status becomes `active`.
- Registered users cannot change hard profile fields after lock (profile update returns 422).

### Registered users

- On registration, a giving identity is created and linked to `users.uuid`.
- Guest history links **by giving identity**, not email alone.
- If guest history on the same email **conflicts** with the registration profile and is **locked**, registration is rejected.

---

## Database

### `giving_identities`

| Column | Purpose |
|---|---|
| `uuid` | Primary public identifier / aggregation key |
| `email_lower` | Unique contact email (nullable when user-only) |
| `user_uuid` | Linked registered account |
| `donor_type_uuid` | Donor type |
| `graduation_set_uuid` | Alumni set |
| `corporate_category_uuid` | Corporate category |
| `organization_name` | Corporate name |
| `firstname`, `lastname` | Individual donors |
| `status` | `unverified`, `active`, `conflict`, `merged` |
| `locked_at` | Set after first successful payment |
| `source` | `guest_checkout`, `registration`, `admin`, `reconciliation`, `pledge` |

### Stamped on records

- `transactions.giving_identity_uuid`
- `pledges.giving_identity_uuid`

Transaction metadata snapshots remain **immutable** for receipts and audit.

---

## Code map

| Component | Role |
|---|---|
| `GivingIdentityResolver` | Create/validate identity on write |
| `GivingIdentityLockService` | Lock on payment; block profile changes |
| `GivingIdentityLinkerService` | Identity-aware guest history linking |
| `LeaderboardService` | Aggregates by `giving_identity_uuid` |
| `DonorCumulativeTotalService` | Recognition totals by identity |
| `ReconcileGivingIdentitiesCommand` | Backfill historical data |

---

## Backfill existing data

Run a dry run first:

```bash
php artisan giving-identities:reconcile --dry-run
```

Apply changes:

```bash
php artisan giving-identities:reconcile
```

Optional limit or single email:

```bash
php artisan giving-identities:reconcile --limit=100
php artisan giving-identities:reconcile --email=donor@example.com
```

### Report conflicts

Inspect conflicting emails side-by-side:

```bash
php artisan giving-identities:report-conflicts
php artisan giving-identities:report-conflicts --email=donor@example.com
php artisan giving-identities:report-conflicts --json
```

### Resolve conflicts (align records to tied identity)

When the backfill flagged `status=conflict`, align all transactions and pledges for that email to the **existing giving identity profile** (the canonical tied identity), then set status back to `active`:

```bash
# Preview
php artisan giving-identities:report-conflicts --resolve --dry-run

# Resolve all conflict identities
php artisan giving-identities:report-conflicts --resolve

# Resolve one email
php artisan giving-identities:report-conflicts --email=donor@example.com --resolve
```

Or during reconcile:

```bash
php artisan giving-identities:reconcile --resolve-conflicts
php artisan giving-identities:reconcile --email=donor@example.com --resolve-conflicts --dry-run
```

Resolution updates `donor_type_uuid`, set/org/name fields, `guest_donor_profile` metadata, and `giving_identity_uuid` on all matching transactions and pledges. It does not change the giving identity row itself — that row remains the source of truth.

The command:

1. Groups historical transactions and pledges by email
2. Creates `giving_identities` where missing
3. Stamps `giving_identity_uuid` on records
4. Marks emails with multiple conflicting profiles as `conflict` for admin review

**Note:** The backfill does not rewrite paid transaction metadata (receipts stay intact).

---

## API impact

### Guest checkout / bank transfer / pledge

No request shape changes. Conflicting identity returns **422** on `donor_email`.

### Customer profile update

Hard fields return **422** after the user's identity is locked.

### Leaderboards

- `/api/v1/public/leaderboard`
- `/api/v1/public/leaderboard/sets`
- `/api/v1/public/leaderboard/top-sets`

These now aggregate using `giving_identity_uuid` when present (after backfill).

---

## Business decisions (confirm with stakeholders)

### 1. One email, one identity forever?

**Current implementation:** Yes, with admin override planned.

- Shared inboxes (`info@company.com`) cannot represent multiple givers under one email.
- **Alternative:** Allow multiple identities per email with explicit "acting as" selection at checkout (more complex UX).

### 2. Lock timing

**Current:** Lock on first **successful payment**, not on form submit.

- Reduces impact of typos before payment.
- **Alternative:** Lock on first pledge commitment (stricter).

### 3. Registration vs conflicting guest history

**Current:**

- Unlocked guest history that conflicts → registration profile wins (guest identity updated).
- Locked guest history that conflicts → registration **blocked** (422).

- **Alternative:** Always allow registration; never auto-link conflicting history.

### 4. Anonymous donations

**Current:** Email still required for guest checkout; totals aggregate under the identity. Display may show "Anonymous".

- **Alternative:** Exclude anonymous donations from public leaderboards entirely.

### 5. Name / organization normalization

**Current:** Case-insensitive trimmed comparison; `"Acme Corp"` ≠ `"Acme Corporation"`.

- **Alternative:** Fuzzy matching (higher false-merge risk).

### 6. Admin correction workflow

**Current:** Conflicts marked `conflict`; no admin UI yet.

- **Needed:** Admin tools to merge, split, or reassign identities (Phase 2).

### 7. Guest email verification before checkout

**Current:** Not required (email is not proven before payment).

- **Risk:** First submitter can claim an email identity.
- **Alternative:** OTP verification before first guest checkout.

### 8. Corporate RC/TIN changes

**Current:** Soft fields; can be updated after lock.

- **Alternative:** Treat RC/TIN as hard fields for tax compliance.

---

## Deployment checklist

1. Run migration: `php artisan migrate`
2. Deploy application code
3. Dry-run backfill: `php artisan giving-identities:reconcile --dry-run`
4. Review conflict report
5. Run backfill: `php artisan giving-identities:reconcile`
6. Verify leaderboards on staging/production

---

## FAQ

**Q: Two people named John Smith — are they one identity?**  
A: No. Identity is keyed by **email**, not name. Different emails = different identities.

**Q: Someone typo'd their set on the first donation — can they fix it?**  
A: Before payment: submit again with the correct set. After payment: contact support (identity is locked).

**Q: Can a logged-in user donate as a different donor type?**  
A: No. Their account has one donor type and one giving identity.

**Q: What happens to old transactions without `giving_identity_uuid`?**  
A: Leaderboards fall back to `user_uuid` / email until backfill runs.
