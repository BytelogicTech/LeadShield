# Build Prompt: LeadShield (Lead Hygiene, Suppression and Sender Health Platform)

> Paste this whole file into Cursor (Agent mode), or keep it in the repo as `docs/PRD.md` and tell the agent: "Read docs/PRD.md and start Phase 0."
> Reference implementation of the current cleaning logic: `docs/reference/lead_cleaner.php`. Port its lists and rules; do not copy its structure.

---

## 0. Your role and working rules

You are a senior Laravel engineer building a production-grade internal tool for Bytelogic Technologies (a software and digital marketing agency in Noida, India). The tool will later become a feature of a commercial cold email SaaS, so build it **multi-tenant ready** from day one.

Working rules:

1. **Work phase by phase** (Section 12). At the end of each phase: run tests, run static analysis, summarise what was built, list open questions, and stop for my review before the next phase.
2. **Never invent third-party API endpoints, payloads or webhook event names.** For Smartlead, verifier APIs and blacklist services, read the official docs first. If you cannot access them, build the integration behind an interface, mark the unknown parts with `// TODO(verify-docs)`, and tell me exactly what you need confirmed.
3. Ask before adding any paid service or any dependency that is not in Section 2.
4. Every business rule must be configurable (config file or database setting), not hard-coded.
5. Write Pest tests for every cleaning stage, every classifier and every integration client (with HTTP fakes).
6. Keep controllers thin. Business logic lives in Actions / Services. Long work runs in queued jobs.
7. No em dashes in UI copy.

---

## 1. Problem we are solving

We run cold email outreach through Smartlead with ~190 Microsoft 365 sender mailboxes across ~39 sending domains. Recent results:

- 37 of 39 sending domains got listed on **SURBL multi** (domain blocklist).
- ~64,000 emails sent, near-zero positive replies.
- High **sender bounces** (~2.8%): recipient security gateways (Mimecast, Proofpoint etc.) rejected mail as spam, even though addresses were valid and lists were verified by ListClean.
- Data problems: stale domains (merged companies), group/distribution addresses, role addresses, re-shooting the same leads across campaigns, no central record of who was contacted, bounced or unsubscribed.
- Lookalike sender domain clusters (bytelogic-tech.in, bytelogic-pvtltd.in ...) were burned together.

**Goal:** a single platform that (a) cleans and risk-scores every lead list before it reaches Smartlead, (b) keeps a permanent suppression and contact-history database, (c) syncs outcomes back from Smartlead automatically, and (d) monitors the health of our own sending domains and mailboxes so problems are caught in hours, not weeks.

---

## 2. Tech stack

- Latest stable **Laravel** and **PHP 8.3+** (check versions with composer; do not assume).
- **MySQL 8** (primary), **Redis** (cache, queues, rate limiting).
- **Laravel Horizon** for queues, **Laravel Scheduler** for periodic jobs.
- Admin UI: latest stable **Filament** (tables, forms, dashboards, imports). Use Livewire only where Filament is not enough.
- File I/O: **spatie/simple-excel** (OpenSpout under the hood) for streaming large XLSX/CSV. No full-file loading into memory.
- Email syntax: **egulias/email-validator** (RFC + DNS-less checks) plus our own stricter regex.
- DNS: PHP `dns_get_record()` behind a `DnsResolver` interface, with an alternative DNS-over-HTTPS driver (Cloudflare / Google) selectable in config, so it works on hosts where system DNS is unreliable.
- Roles: **spatie/laravel-permission**. Audit: **spatie/laravel-activitylog**.
- Tests: **Pest**. Static analysis: **Larastan** (level 6+). Formatting: **Laravel Pint**.
- Local dev: **Laravel Sail** (Docker) with MySQL + Redis + Mailpit.

---

## 3. Multi-tenancy and users

- Every business table has `workspace_id` (single database, row-level tenancy, global scope + policy checks).
- Roles: `owner`, `admin`, `manager`, `sdr` (SDR can upload and download lists, cannot change rules or delete suppression entries).
- API keys for integrations (Smartlead, verifiers, Slack) are stored **encrypted** per workspace (`encrypted` cast).
- Every destructive action (delete suppression, change rules, override a block) is written to the activity log with user and reason.

---

## 4. Core data model (minimum; propose improvements)

```
workspaces            id, name, settings(json), timestamps
users                 id, workspace_id, name, email, password, role
lead_sources          id, workspace_id, name, type(enum: bought, scraped, platform, inbound, manual),
                      vendor, acquired_at, notes, quality_score(nullable)
import_batches        id, workspace_id, user_id, lead_source_id, original_filename, storage_path,
                      column_mapping(json), status(enum), total_rows, processed_rows,
                      stats(json), started_at, finished_at
leads                 id, workspace_id, email(normalised, indexed), email_hash(sha256),
                      local_part, domain, first_name, last_name, company, title, country,
                      phone, linkedin_url, custom_fields(json), first_seen_batch_id, timestamps
                      UNIQUE(workspace_id, email)
batch_leads           id, import_batch_id, lead_id(nullable), row_number, raw(json),
                      verdict(enum: safe, risky, gateway, rejected), risk_score(0-100),
                      reasons(json array of {code, message, stage}), suggested_fix(nullable)
domains_intel         domain(PK), mx_hosts(json), mx_provider, is_secure_gateway, dns_status(enum),
                      has_spf, has_dmarc, is_catch_all(nullable), is_free_provider, is_disposable,
                      registered_at(nullable, from RDAP), checked_at, expires_at
suppressions          id, workspace_id, type(enum: email, domain), value, value_hash,
                      reason(enum: unsubscribed, hard_bounce, complaint, not_interested,
                             client, competitor, do_not_contact, dead_domain, gateway_block,
                             spam_trap_suspect, manual),
                      source(enum: smartlead_webhook, smartlead_sync, import, ndr, manual, rule),
                      permanent(bool), expires_at(nullable), note, created_by, timestamps
                      UNIQUE(workspace_id, type, value)
contact_events        id, workspace_id, lead_id, campaign_id, sender_mailbox_id(nullable),
                      event(enum: queued, sent, opened, replied, positive_reply, bounced,
                            sender_bounced, unsubscribed, complaint),
                      occurred_at, payload(json), external_id(unique per provider)
campaigns             id, workspace_id, provider('smartlead'), external_id, name, status,
                      started_at, stats(json), last_synced_at
sender_domains        id, workspace_id, domain, tenant_label, status(enum: active, resting,
                      retired), purchased_at, notes, last_health_check_at, health(json)
sender_mailboxes      id, workspace_id, sender_domain_id, email, provider(enum: m365, google, smtp),
                      external_id(smartlead), daily_limit, warmup_reputation, status, last_synced_at
blacklist_checks      id, sender_domain_id, list_name, listed(bool), response, checked_at
                      (index sender_domain_id, list_name, checked_at)
alerts                id, workspace_id, type, severity, subject_type, subject_id, message,
                      resolved_at, notified_channels(json)
ndr_messages          id, workspace_id, sender_mailbox_id, recipient, smtp_code, enhanced_code,
                      reporting_mta, diagnostic, classification(enum: recipient_invalid,
                      sender_reputation, policy_spam, group_restricted, server_unreachable,
                      mailbox_full, temporary, unknown), received_at, raw_hash
cleaning_rules        id, workspace_id, key, enabled, params(json)  (overrides config defaults)
```

Privacy: for `unsubscribed` and `complaint` suppressions keep `value_hash` and allow a workspace setting "hash-only suppression" that stores no plain email. Add a data-retention setting that purges `batch_leads.raw` after N days.

---

## 5. Lead import and cleaning pipeline

### 5.1 Import
- Upload XLSX/CSV up to 500k rows. Stream, never load fully into memory.
- **Column mapping UI**: auto-detect email, first name, last name, company, title, country, website, LinkedIn. Let the user fix the mapping and **save it as a template** per lead source.
- Every batch must be linked to a **Lead Source** with acquired date. If the data is older than a configurable age (default 180 days), show a warning.
- Processing runs in chunked queued jobs (default 1,000 rows per job) with a live progress bar in Filament.

### 5.2 Pipeline
Implement as Laravel Pipeline of stage classes, each implementing:

```php
interface CleaningStage {
    public function key(): string;            // e.g. 'role_address'
    public function handle(LeadContext $ctx, Closure $next): LeadContext;
}
```

`LeadContext` carries the row, normalised email, domain, accumulated reasons, risk points and a hard-reject flag. A stage can **hard reject** (verdict `rejected`) or **add risk points** with a reason. Every reason has a stable code (for reports) and a human message.

Stages, in order (each enabled/disabled and parameterised via `cleaning_rules`):

| # | Stage | Behaviour |
|---|---|---|
| 1 | Normalise | trim, lowercase, strip `mailto:`, quotes, brackets, spaces, trailing dots; Unicode/IDN handling |
| 2 | Syntax | egulias RFC validation + strict regex; reject on fail |
| 3 | In-file duplicate | reject later duplicates |
| 4 | Role / group address | role local parts (port list from reference file) incl. prefix match (`sales.team`, `info+uae`); reject |
| 5 | Typo domain | known typos + Levenshtein against popular providers; reject with `suggested_fix`; optional "auto-fix and keep" setting |
| 6 | Disposable / fake | maintained list; add a scheduled job that refreshes from a public disposable-domains list (configurable URL); reject |
| 7 | Free provider | configurable: reject, add risk, or allow |
| 8 | Suppression | email or domain in `suppressions` (respect `expires_at`); reject with the suppression reason |
| 9 | Contact cooldown | lead was `sent` in the last N days (default 90) or completed a sequence without reply; reject with last contact date and campaign |
| 10 | Protected domains | our clients, partners, competitors, our own domains; reject |
| 11 | Domain intel (DNS) | from `domains_intel` cache or fresh lookup: NXDOMAIN, null MX, no mail, A-only; reject or risk per config |
| 12 | Domain age | RDAP registration date; domains younger than N days add risk |
| 13 | Secure gateway | Mimecast, Proofpoint, Barracuda, Cisco IronPort, Symantec, Trend Micro, Sophos, Forcepoint etc. from MX; verdict `gateway` (not rejected) |
| 14 | Per-company cap | max N leads per company domain per batch and per rolling 30 days across batches |
| 15 | External verifier | optional, see 5.3; map provider statuses to our verdicts |
| 16 | Spam-trap risk heuristics | add risk for: very old source, scraped source type, role-like patterns, local parts that look generated, domains with historic NDR spam blocks, leads never engaged after 2+ sequences |
| 17 | Final scoring | risk_score 0-100 from accumulated points; thresholds (config) decide `safe` / `risky` |

DNS safety (mandatory): before any DNS stage runs, resolve canary domains (gmail.com, microsoft.com, google.com). If they fail, **skip DNS stages for the batch**, flag the batch, and alert. If more than 50% of a batch's domains fail DNS, mark results "needs review" instead of rejecting.

`domains_intel` is a shared cache across batches with a TTL (default 14 days), refreshed by a scheduled job, and looked up concurrently (use `Http::pool` for the DoH driver).

### 5.3 External verifiers (driver pattern)
`VerifierDriver` interface with `verify(array $emails): array<VerificationResult>` and batch/async support. Ship drivers for providers whose APIs you can confirm from official docs (candidates: ZeroBounce, MillionVerifier, NeverBounce, ListClean if it has a public API). Normalise statuses to: `valid`, `invalid`, `catch_all`, `unknown`, `disposable`, `spamtrap`, `abuse`, `role`. Only send leads that passed stages 1 to 14 (saves credits). Cache results per email for 30 days.

### 5.4 Outputs
- Batch result page: totals, breakdown by reason code, by mail provider, by verdict, by country; sample rows per reason.
- Downloads: Smartlead-ready CSV (safe only), Gateway CSV, Risky CSV, Rejected CSV with reasons, full XLSX report.
- Column order and custom-field mapping for the Smartlead CSV configurable per workspace.
- One-click **"Push safe leads to Smartlead campaign"** (Section 6) which also writes `queued` contact events.

---

## 6. Smartlead integration

Read the official Smartlead API docs first. Build a `SmartleadClient` (Laravel HTTP client, retries with backoff, rate limiter, typed DTOs).

Required capabilities (confirm each exists before implementing):
1. List campaigns and their stats; store in `campaigns`.
2. List email accounts (mailboxes) with daily limit, warmup reputation, status; store in `sender_mailboxes`.
3. Add leads to a campaign (chunked, idempotent, respects their per-request limits).
4. Fetch leads per campaign with status (sent, replied, bounced, unsubscribed, sender bounced if exposed) for **backfill and periodic reconciliation**.
5. **Webhooks**: receive events (sent, reply, unsubscribe, bounce, category change, whatever Smartlead actually supports). Verify authenticity using whatever mechanism Smartlead provides (if none, use a secret token in the URL path and IP/rate limiting). Store raw payload, deduplicate by event id, process in a queue.

Event effects:
- `unsubscribed` → permanent email suppression.
- `bounced` (hard) → permanent email suppression.
- reply categorised "not interested" / "do not contact" → permanent suppression.
- reply categorised positive → mark lead positive; optional block on the whole company domain for other campaigns (avoid emailing colleagues of an active prospect).
- `sender_bounced` → no suppression; record event; feed sender health (Section 7).
- Sequence completed without reply → cooldown (Stage 9).

Scheduled jobs: campaigns + mailboxes sync every 30 min, lead status reconciliation nightly.

---

## 7. Sender health monitoring

For every row in `sender_domains` (scheduled, default every 6 hours):

1. **Blocklists**: query DNS-based domain blocklists (SURBL multi `multi.surbl.org`, Spamhaus DBL, URIBL, and others you can confirm). Important: some lists block queries from public resolvers or require a registered key for commercial use (Spamhaus DQS). Make each list a configurable driver with its own resolver and key, and treat "blocked query" responses as **unknown**, never as "listed".
2. **Authentication**: SPF present and includes the sending provider, DMARC present with policy, DKIM selector check for configured selectors, MX present.
3. **Website**: domain resolves and returns 2xx/3xx over HTTPS (a 403 or no site is a warning).
4. **Smartlead signals**: warmup reputation trend per mailbox, sender bounce rate per domain over 24h / 7d.

Alerts (Slack webhook + email; WhatsApp optional later):
- Domain newly listed on any blocklist → **critical**. Offer an action "Pause this domain's mailboxes in Smartlead" only if the API supports it; never do it automatically unless the workspace enables auto-pause.
- Warmup reputation of a mailbox drops below threshold (default 85%) → warning.
- Sender bounce rate over 2% (warn) / 5% (critical) per Smartlead's own guidance.
- Lookalike cluster risk: when a domain is listed, flag sibling domains sharing the same brand stem.

Dashboard: domain grid with status colours, listing history timeline (first seen listed date, delisted date), and a "listed since" field so we always know when a listing started.

---

## 8. NDR (bounce message) intelligence

Smartlead mixes recipient bounces and sender bounces. Build an importer that accepts:
- Exchange Online **message trace** CSV exports (extended report), and
- optionally IMAP fetch of `Undeliverable:` messages from sender mailboxes (configurable, read-only, credentials encrypted).

Classify each NDR with a rule table (regex + SMTP / enhanced codes), editable in the admin:

| Pattern examples | Class |
|---|---|
| `5.1.1`, `5.1.10`, `doesn't exist`, `wasn't found`, `recipient address rejected` | recipient_invalid |
| `suspects your message is spam`, `5.7.1` with spam wording, `SURBL`, `Spamhaus`, `blacklist`, `reputation` | policy_spam / sender_reputation |
| `only accepts messages from people in its organization` | group_restricted |
| `didn't respond`, `timed out`, `connection refused` | server_unreachable |
| `mailbox full`, `quota` | mailbox_full |
| `4.x.x` codes | temporary |

Effects: `recipient_invalid` → permanent suppression; `group_restricted` → permanent suppression; `server_unreachable` repeated across 2+ attempts → domain suppression `dead_domain`; `policy_spam` → record against the recipient domain in `domains_intel` (gateway pressure) and against our sender domain in health metrics.

---

## 9. Reporting

Filament dashboard widgets and pages:
- **Source quality**: per lead source, % rejected at cleaning, bounce %, sender bounce %, reply %, positive %. Auto-compute `lead_sources.quality_score`. This tells us which data vendors to stop buying from.
- **Campaign performance**: joined with cleaning verdicts (do risky leads bounce more than safe ones? are gateway leads replying?).
- **Domain health**: listings, warmup trends, sender bounce trends.
- **Suppression growth** by reason over time.
- Export any report to CSV.

---

## 10. Admin settings (per workspace)

- All `cleaning_rules` toggles and params with sensible defaults (cooldown 90 days, per-domain cap 3, risk thresholds, free-provider policy, typo auto-fix off, DNS driver, TTLs).
- Protected domains list (clients, partners, competitors, own domains), with bulk import.
- Integrations: Smartlead API key, verifier keys, Slack webhook, blocklist resolver/keys.
- Data retention and hash-only suppression.

---

## 11. Non-functional requirements

- Must process a 100k-row file end-to-end (without external verifier) in under 10 minutes on a 2 vCPU server with Redis queues.
- All jobs idempotent and resumable; a failed chunk can be retried without duplicating leads or events.
- Webhook endpoint responds in under 200 ms (store and queue, process async).
- HTTP clients: timeouts, retries with exponential backoff, circuit breaker on repeated failures, rate limits per provider.
- Security: CSRF, policies on every resource, uploads stored outside public path, signed temporary URLs for downloads, encrypted secrets, no PII in logs.
- Observability: structured logs, Horizon metrics, a `/health` endpoint, failed-job alerts.
- Seeders with realistic fake data so every screen can be demoed.
- `README.md` with setup, env vars, cron and Horizon setup, and how to add a new verifier or blocklist driver.

---

## 12. Delivery phases (stop for review after each)

**Phase 0: Foundation**
Sail setup, auth, workspaces, roles, Filament panel, activity log, settings storage, Pest + Larastan + Pint in CI (GitHub Actions). Propose final schema and wait for approval.

**Phase 1: Cleaning MVP**
Import with column mapping and templates, lead sources, stages 1 to 8, 10, 11 (with DNS safety), 13, 14, 17; outputs and downloads; manual suppression management with bulk import (CSV/TXT of emails and `@domain` lines). Parity test: the same input file must produce the same verdicts as `docs/reference/lead_cleaner.php` for overlapping rules.

**Phase 2: Smartlead sync**
Client, campaigns and mailboxes sync, push-to-campaign, webhooks, backfill import of historic campaign leads and statuses, auto-suppression rules, contact history and cooldown (stage 9).

**Phase 3: Sender health**
Sender domains registry, blocklist drivers, auth checks, alerts, domain health dashboard with listing timeline.

**Phase 4: NDR intelligence and verifiers**
Message trace CSV import, NDR classifier with editable rules, verifier drivers (stage 15), domain age via RDAP (stage 12), spam-trap heuristics (stage 16).

**Phase 5: Reporting and hardening**
Source quality scoring, campaign and suppression reports, performance tuning to meet Section 11, documentation.

---

## 13. Definition of done (every phase)

- Feature works in the Filament UI end-to-end with seeded data.
- Pest tests pass, including edge cases (empty files, BOM/encoding issues, 100k-row stream, duplicate webhooks, DNS outage).
- Larastan clean at the agreed level, Pint formatted.
- No hard-coded secrets, no invented API contracts (or clearly marked `TODO(verify-docs)` with a list sent to me).
- Short changelog of what was built and what is pending.

Start with **Phase 0**. Before writing code, reply with: (1) your proposed folder structure, (2) the final schema with any changes you recommend and why, (3) the packages and versions you will install, (4) questions you need answered.
