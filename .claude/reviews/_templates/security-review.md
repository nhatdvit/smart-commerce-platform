# Security review — SCP-NNN

| | |
| --- | --- |
| **Task** | SCP-NNN |
| **Reviewed** | YYYY-MM-DD |
| **Reviewer** | `security-reviewer` agent |
| **Why reviewed** | which trigger: payments / auth / PII / store-scoped data / infra config |
| **Verdict** | SAFE TO SHIP / CHANGES REQUIRED / BLOCKED |

## Summary

| Severity | Count | Blocking |
| --- | --- | --- |
| CRITICAL | 0 | yes |
| HIGH | 0 | yes |
| MEDIUM | 0 | no |
| LOW | 0 | no |

## Findings

<!--
  State the attack, not the smell: who the attacker is, what they send, what
  they get back. A finding without an attack path is a suspicion - label it.
-->

#### S1 — <one-line claim>

| | |
| --- | --- |
| **Where** | `path/to/file.php:123` |
| **Class** | broken object authorization / store isolation / injection / XSS / CSRF / SSRF / secret exposure / payment integrity |
| **Severity** | CRITICAL / HIGH / MEDIUM / LOW |

**Who the attacker is.** Anonymous / authenticated customer / staff in another
store / staff with a lower role.

**What they send.**

**What they get.**

**What to change.**

## Payment integrity

`CLAUDE.md` §6. Mark N/A only when the diff cannot reach payment at all.

| Check | Status |
| --- | --- |
| No customer-reachable path moves a payment to `PAID` | |
| Confirmation requires the `payment.confirm` permission | |
| Confirmation is idempotent and transactional | |
| An amount mismatch does not auto-resolve to `PAID` | |
| Transfer reference unique per order, not guessable across orders | |
| Bank-account management unreachable by customers | |
| Bank-account changes audited with actor and previous value | |
| No screenshot, upload or customer click wired to a status change | |

## Store isolation

| Check | Status |
| --- | --- |
| Every admin query filtered by the resolved store | |
| Store context from route, host mapping or verified membership only | |
| No `store_id` read from query string or body | |
| Another store's resource answers 404 | |
| Every route parameter authorized, not just authenticated | |

## Guest access and tokens

| Check | Status |
| --- | --- |
| Orders and carts not addressable by id alone | |
| Tokens hashed at rest | |
| Tokens scoped to one resource and expiring | |
| Idempotency key cannot read back another caller's response | |

## Secrets and logging

| Check | Status |
| --- | --- |
| No hardcoded credential in the diff | |
| `.env` not in the diff | |
| No credential, payment payload or PII written to a log | |
| No internal detail in an error response | |

## Dependencies

What was run — `composer audit`, `pnpm audit` — and the result. If the tooling
could not run, say so. Do not leave the impression it came back clean.

## Not defects

Things that look exploitable and are not, with why.
