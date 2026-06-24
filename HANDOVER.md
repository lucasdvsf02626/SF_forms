# SF Lead Form — Project Handover & Long-Term Maintenance

**Plugin:** SF Lead Form (`sf-lead-form`)  ·  **Current version:** 1.2.6
**Repo:** `lucasdvsf02626/SF_forms` (source of truth)  ·  **Site:** supplementfactoryuk.com
**Purpose:** A self-owned, multi-step lead-capture form that replaces the third-party
GrowForms embed and pushes every enquiry straight into **HubSpot CRM** — server-side,
with no third party in the middle.

> This is the master reference (what was built + the HubSpot API integration + how to
> run it long term). `MAINTENANCE.md` is the short operational cheat-sheet; `README.md`
> is the quick start. When they disagree, **this document wins.**

---

## 1. What was built (and why)

**The problem.** Enquiries came through GrowForms (a paid third-party tool). Leads could
be lost, data wasn't owned, and there was no view of *where* people dropped off — yet ad
spend was paying to bring those people in.

**The solution.** A WordPress plugin, owned by SF, that:

- Renders a clean multi-step enquiry form with `[sf_lead_form]`.
- Sends each submission to HubSpot as a contact (created or updated), **server-side**, so
  the HubSpot token never touches the browser.
- Comes in **two interchangeable variants** (same plugin, chosen per page):
  - **Standard** — `[sf_lead_form]` — contact details collected **last** (classic order).
  - **Progressive / email-first** — `[sf_lead_form mode="progressive"]` — name + email +
    phone collected **first** (with a consent tick), then each qualifying question is
    saved to HubSpot **as the visitor advances**. Result: even people who *don't finish*
    are captured in the CRM — the enquiries we pay ad spend to attract are no longer lost.
- **Never-lose-a-lead:** if HubSpot is unreachable, the lead is stored locally and retried
  automatically; the admin is alerted; a daily health-check catches a broken token.
- **Behavioural tracking:** fires Microsoft Clarity (and GA4/GTM) events at every step so
  drop-off is visible. **No PII is ever sent to analytics.**

**Key wins:** SF owns the form and the data; zero ongoing form-tool cost; leads land in
HubSpot automatically; abandoners are captured; full drop-off visibility.

---

## 2. How it works — architecture & data flow

```
Browser (vanilla JS, no deps)                WordPress (PHP)                 HubSpot CRM
┌───────────────────────────┐   POST JSON   ┌───────────────────────┐  HTTPS  ┌──────────┐
│ sf-lead-form.js            │ ───────────▶  │ REST: /submit          │ ──────▶ │ Contacts │
│  • multi-step state machine│  +X-WP-Nonce  │ REST: /partial (V2)     │  v3 API │ (dedupe  │
│  • standard | progressive  │               │  → validate → map →     │         │  on email)│
│  • Clarity/GA4 events       │               │     HubSpot upsert       │         └──────────┘
│  • mounts in #sf-lead-form-root              │  → on fail: Lead Store   │
└───────────────────────────┘               │     (retry queue)        │
                                             └───────────┬─────────────┘
                                                         │ WP-Cron
                          ┌──────────────────────────────┼───────────────────────────┐
                          │ hourly: retry pending leads   │ daily: prune logs/leads,  │
                          │                               │        health-check token │
                          └───────────────────────────────────────────────────────────┘
```

**The journey of one submission:**

1. The JS posts the collected fields to a WordPress REST endpoint with a nonce.
2. The server **validates + sanitises** every field (mirrors the client checks).
3. Fields are **mapped to HubSpot properties** and **upserted** (create, or update if the
   email already exists).
4. **On success** → the visitor sees the thank-you screen; the result is logged (masked).
5. **On failure** (HubSpot down / rate-limited) → the lead is written to the local **Lead
   Store**, the admin is alerted, and the **hourly retry** cron syncs it later.

**Components (in `sf-lead-form/includes/`):**

| File | Responsibility |
|---|---|
| `class-rest-handler.php` | REST routes `/submit`, `/partial`, `/health`; rate limiting; honeypot; field→HubSpot mapping |
| `class-validator.php` | Server-side validation + sanitisation of every field |
| `class-hubspot-service.php` | HubSpot CRM v3 client (token encryption, create/update, error recovery) |
| `class-lead-store.php` | Local DB table — retry queue / recent-lead backup |
| `class-logger.php` | Masked, privacy-safe activity log (DB table) |
| `class-notifier.php` | Throttled admin email alerts on sync/health failures |
| `class-admin.php` | Settings page, Test Connection, Logs & Failed-leads tabs |
| `../public/sf-lead-form.{js,css}` | Front-end form + styles |
| `../sf-lead-form.php` | Bootstrap, shortcode, cron wiring, ACF render filter |

---

## 3. HubSpot API integration (the detail)

All HubSpot calls live in **`class-hubspot-service.php`**. It is a thin wrapper over the
**HubSpot CRM v3 REST API** using WordPress's own HTTP layer (`wp_remote_request`).

### Authentication
- **Private App access token** (Bearer auth: `Authorization: Bearer <token>`).
- **Stored encrypted at rest** in `wp_options` — AES-256-CBC keyed off the site's
  `AUTH_KEY` + `AUTH_SALT` (values are prefixed `enc::`). It is **never** output to the
  browser and never logged.
- **Required scopes:** `crm.objects.contacts.read` **and** `crm.objects.contacts.write`.
- **Base URL:** `https://api.hubapi.com`  ·  **Timeout:** 15s  ·  **Portal:** `14516909`.

### Endpoints used
| Purpose | Method & path |
|---|---|
| Test connection | `GET /crm/v3/objects/contacts?limit=1` |
| Create contact | `POST /crm/v3/objects/contacts` |
| Find by email | `GET /crm/v3/objects/contacts/{email}?idProperty=email` |
| Update contact | `PATCH /crm/v3/objects/contacts/{id}` |

### Create-or-update flow (dedupe on email)
1. `POST` to create the contact.
2. If HubSpot returns **409 (duplicate)** → look the contact up by email → **PATCH** it.
   So the same person submitting twice updates one record rather than duplicating.
3. If HubSpot returns **400 (unknown/invalid property)** → the offending property names are
   parsed out, **stripped**, and the request is **retried once**. This means a missing
   custom property (or a dropdown value that isn't a configured option) **never costs a
   lead** — the contact still saves with the remaining fields, and the dropped fields are
   recorded in the log.

### Field → HubSpot property mapping
| Form field | HubSpot property | Notes |
|---|---|---|
| First / last name | `firstname`, `lastname` | standard |
| Email | `email` | dedupe key |
| Phone | `phone` | standard |
| Company | `company` | standard |
| Product brief | `message` | standard |
| Enquiry type | `enquiry_type` | **custom** — matched to your dropdown |
| Product type | `product_type` | **custom** — matched to your dropdown |
| Units | `unit_quantity` | **custom** |
| Budget | `manufacturing_budget` | **custom** |
| Experience | `manufacturing_experience` | **custom** |
| Journey stage | `journey_stage` | **custom** |
| — | `lifecyclestage` = `lead`, `hs_lead_status` = `NEW` | set automatically |

Custom properties marked above must exist in the HubSpot portal to be stored; if one is
missing (or a dropdown value isn't an allowed option), the integration **strips it and
retries in a loop**, so the lead still saves with every remaining field (dropped names are
logged). Source/progress tagging is available via the portal's existing **`enquiry_source`**
property ("Web enquiry form" option) and a **`form_progress`** property (create as
single-line text) — both can be wired in on request; they are intentionally not sent until
they exist, to avoid wasted calls.

### Error handling returned to the caller
`network` (couldn't reach HubSpot), `unauthorized` (401 — bad token), `rate_limited`
(429), or `hubspot_error` (other). Anything that isn't a clean success is queued + retried.

### Triggering CRM automations (form-submission mirror) — v1.2.6
The CRM API creates/updates the **contact** but does **not** raise a HubSpot **form-submission
event** — so workflows enrolled on *"submitted form X"* (deal creation, automated outreach) never
fire from an API contact. To fix this, a **completed** enquiry is also mirrored to a HubSpot **form**
via the Forms API: `POST https://api.hsforms.com/submissions/v3/integration/submit/{portalId}/{formGuid}`
(see `SF_Lead_Form_HubSpot_Service::submit_form()`). That logs a real submission on the contact
timeline and triggers any workflow enrolled on that form. **No token needed** (the endpoint is keyed
by portal + form GUID). It runs **only** when a **Form GUID** is set on the Settings tab (blank = off),
fires on `/submit` only (not per-gate `/partial`), and is best-effort + logged (`action = form_submit`)
so a failure never costs the lead. To switch on: create a HubSpot form whose fields match the contact
properties above, publish it, paste its GUID into Settings, and enrol the deal/email workflows on
*"submitted form: <that form>"*. (Automated **marketing** emails still require the contact to be a
marketing contact with valid consent.)

---

## 4. Configuration

### Admin settings page
**WP Admin → Settings → SF Lead Form** (direct: `/wp-admin/admin.php?page=sf-lead-form`).
Three tabs:
- **Settings** — HubSpot **Access Token**, **Portal ID** (pre-filled `14516909`),
  **HubSpot Form GUID** (enables the automation-triggering form-submission mirror; blank = off),
  Webhook/Test Secret, **Alert email**, and a **Test Connection** button + a coloured
  **health banner** (green OK / red FAILING).
- **Logs** — recent masked activity (success/error per action).
- **Failed leads** — anything not yet synced, with **Retry** / **Retry all**.

### Shortcodes
- `[sf_lead_form]` — standard form.
- `[sf_lead_form mode="progressive"]` — email-first progressive form.
- Works in normal page content **and inside ACF fields** (the plugin runs `do_shortcode()`
  on any ACF value containing `[sf_lead_form]` — that's how it renders in the theme's hero
  "Form" field).

### Consent wording (progressive form)
The tick-box text is set in PHP and is **overridable with no code change** via a filter:
```php
add_filter( 'sf_lead_form_consent_text', function () {
    return 'Your legally-approved wording…';
} );
```
> ⚠️ The shipped wording is a **placeholder pending sign-off**. Get the final text approved
> (data/legal) before driving real UK traffic to the progressive form.

### Options stored in `wp_options`
`sf_lead_form_hubspot_token` (encrypted), `..._portal_id`, `..._form_guid`, `..._secret`,
`..._alert_email`, `..._health` (last health-check result), `..._db_version` (migration marker).

---

## 5. Front-end behaviour & analytics

- Vanilla JS state machine, **zero dependencies**, mounts into `#sf-lead-form-root`.
  Steps are data-driven arrays (`STEPS_STANDARD`, `STEPS_PROGRESSIVE`) — easy to reorder.
- **Honeypot** + nonce + server validation guard against spam/abuse.
- **Analytics (no PII):** fires events to **Microsoft Clarity** and, when present, GA4 via
  `gtag`/`dataLayer`. The V2 (progressive) funnel events, in order:
  `sf_form_started → sf_g1_details → sf_g2_enquiry_type → sf_g3_product_type →
  sf_g4_unit_quantity → sf_g5_manufacturing_budget → sf_g6_manufacturing_experience →
  sf_g7_journey_stage → sf_g8_finish → sf_form_submit_attempt → sf_form_submitted`
  (`sf_form_error` on error). Standard form uses `sf_g1_enquiry_type …`.
- **Analytics IDs on site:** Clarity (live); GTM `GTM-NC62FS7`; GA4 `G-F86886YZSP`; Google
  Ads `AW-807565592`. To see `sf_*` events in **GA4**, add a GTM Custom-Event trigger
  (`sf_.*`, regex) + a GA4 Event tag forwarding `{{Event}}` to `G-F86886YZSP`, then publish.

---

## 6. Operations & long-term maintenance

### Routine monitoring (lightweight)
- **Monthly (or after any HubSpot/site change):** open the settings page → confirm the
  banner is 🟢 **OK** and **Failed leads = 0**.
- Make sure the **Alert email** is set to a monitored inbox — you'll be emailed if syncing
  fails or the daily health-check finds a broken token.

### Rotating / replacing the HubSpot token
HubSpot Private App tokens can be rotated. When that happens:
1. HubSpot → Settings → Integrations → Private Apps → (app) → rotate/copy new token.
2. WP → Settings → SF Lead Form → paste new token → Save → **Test Connection** (expect OK).
3. If any leads queued while the old token was dead → **Failed leads → Retry all**.

### Deploying a plugin update
1. Get the new versioned zip (from the repo: `git archive` or a release).
2. WP → Plugins → Add New → Upload Plugin → **Replace current with uploaded**.
3. If **Autoptimize** (or any cache) is active → **clear its cache**, then hard-refresh.
4. DB tables/cron self-migrate on update (no activation step needed). Confirm the new
   version number on the Plugins screen.
- **Rollback:** re-upload an older versioned zip the same way (every version is in git
  history). Leads, settings and the token live in the **database** — updating/rolling back
  plugin files never touches them.

### Scheduled jobs (WP-Cron)
| Hook | Frequency | Does |
|---|---|---|
| `sf_lead_form_retry_failed` | hourly | retry up to 25 unsynced leads |
| `sf_lead_form_daily_cleanup` | daily | prune logs > 90 days; synced leads > 30 days |
| `sf_lead_form_daily_healthcheck` | daily | verify the token; record result; alert on failure |

> WP-Cron only fires on traffic. On a busy site this is fine; if traffic is ever very low,
> consider a real system cron hitting `wp-cron.php`.

### Data retention
- **Masked logs:** auto-pruned after **90 days**.
- **Synced leads** in the local store: auto-pruned after **30 days** (HubSpot is the
  system of record; the store is a backup/retry queue). Unsynced leads are kept until they
  sync.

### Managing HubSpot properties
- The form will populate any mapped **custom property** that exists in the portal (§3).
- To capture a new field: create the property in HubSpot **and** add it to the mapping in
  `class-rest-handler.php`. To capture progressive drop-off depth, create **`form_progress`**
  (single-line text).
- You don't have to pre-create everything — missing properties are stripped + the lead
  still saves (and the drop is logged), so you can add them later without losing data.

### Changing the form questions/options
- Question text, order, and answer options live in the `STEPS_*` arrays in
  `public/sf-lead-form.js`. **Dropdown values must match the HubSpot property options** (or
  they'll be stripped on save). After any JS/CSS change, **bump the plugin version** (it
  cache-busts the asset URL) and clear Autoptimize.

### Backups & source of truth
- **Code:** the GitHub repo is canonical. Tag/keep a zip per released version.
- **Token & leads:** in the WordPress database (token encrypted). Include the DB in normal
  site backups.

### Version history
| Version | What changed |
|---|---|
| 1.0.0 | Initial form → HubSpot integration |
| 1.1.0 | Never-lose-a-lead store + hourly retry + failure alerts + daily health-check |
| 1.1.1 | Microsoft Clarity gate-level tracking (no PII) |
| 1.2.0 | Progressive (email-first) variant + `/partial` progressive capture |
| 1.2.1 | Phone on progressive step 1; trimmed final step; product types reduced |
| 1.2.2 | Full international dialing-code list (UK default) |
| 1.2.3 | Render shortcode inside ACF fields (theme hero "Form" field) |
| 1.2.4 | Transparent form wrapper (blends into host container) |
| 1.2.5 | Removed non-existent `lead_source`/`form_progress` from the mapping; hardened the HubSpot strip-and-retry into a loop so one bad property can't discard the rest |
| 1.2.6 | Completed enquiries can mirror to a HubSpot **form** (Forms API) to trigger CRM workflows/automations (deals, automated outreach). New optional **Form GUID** setting; blank = off |

---

## 7. Troubleshooting runbook

| Symptom | Likely cause → fix |
|---|---|
| Banner red / "FAILING"; Test Connection fails | Token missing/expired/wrong scopes → recreate Private App token (read+write contacts), re-save, Test. Queued leads → Retry all. |
| Leads not in HubSpot but form "succeeds" | Check Failed-leads tab (queued) + Logs. Usually token; fix + Retry all. |
| A field isn't populating on the contact | That custom property is missing/misnamed in HubSpot, or a dropdown value doesn't match an option → create/fix the property (the lead itself still saved). |
| Shortcode shows as raw text `[sf_lead_form…]` | It's in a place that doesn't run shortcodes. In ACF fields it works (v1.2.3+); otherwise use a Shortcode block/widget. |
| Form not rendering / old styling after update | Clear Autoptimize cache + hard refresh; confirm the new version on the Plugins screen. |
| `sf_*` events not in GA4 | GTM forwarding not set up — add the Custom-Event trigger + GA4 Event tag and publish (§5). They still reach Clarity. |
| Duplicate-looking contacts | Shouldn't happen — dedupe is by email. Different emails = different people. |

---

## 8. Security summary
- HubSpot token **encrypted at rest**, server-side only, never in the browser or logs.
- All submissions: **WP nonce** verification, full **sanitisation**, output **escaping**.
- **Honeypot** field + per-IP **rate limiting** (`/submit` ~5 / 10 min; `/partial` ~60 / 10
  min) to curb spam/abuse.
- Logs are **masked** (no full PII). Analytics receive **no PII**.

---

## 9. Quick reference
- **Settings:** `/wp-admin/admin.php?page=sf-lead-form`
- **REST:** `POST /wp-json/sf-lead-form/v1/submit` · `…/partial` · `…/health`
- **Shortcodes:** `[sf_lead_form]` · `[sf_lead_form mode="progressive"]`
- **HubSpot portal:** `14516909` · **Scopes:** contacts read + write
- **Repo:** `lucasdvsf02626/SF_forms` · **Consent filter:** `sf_lead_form_consent_text`
