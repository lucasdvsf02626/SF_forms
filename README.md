# SF_forms — Supplement Factory Lead Form → HubSpot CRM

A self-owned WordPress plugin that **replaces GrowForms**. It renders a custom
**7-step multi-step lead form** (Vanilla JS + plain CSS) and sends every
submission straight to **HubSpot CRM** (portal `14516909`) as a created/updated
contact via the **CRM v3 API with a Private App token**.

- **No GrowForms. No HubSpot MCP. No OAuth.** One portal, one Private App token, server-side PHP.
- Zero front-end dependencies (no React/Vue/jQuery), no build step.
- Token stays server-side, stored **encrypted**; never reaches the browser.

---

## How it works

```
Browser (Vanilla JS state machine)
  └─ user completes steps 1–6
  └─ fetch() POST  →  /wp-json/sf-lead-form/v1/submit   (X-WP-Nonce)
                         │
WordPress (PHP)          ▼
  └─ validate + sanitize + rate-limit + honeypot
  └─ map fields → HubSpot properties
  └─ wp_remote_post() → HubSpot CRM v3   (Bearer pat-… , server-side only)
        ├─ 201 → created
        └─ 409 → GET by email → PATCH → updated
  └─ log result (email masked)
  └─ return { success, action, vid }
        │
Browser  ▼
  └─ success → Step 7 "Thank you"   |   failure → inline error + Try Again
```

## Repo structure

```
SF_forms/
├── sf-lead-form/                     ← the WordPress plugin (drop into wp-content/plugins/)
│   ├── sf-lead-form.php              ← bootstrap: constants, hooks, shortcode, asset enqueue
│   ├── includes/
│   │   ├── class-validator.php       ← sanitize + validate (enum whitelists)
│   │   ├── class-hubspot-service.php ← CRM v3 client: encrypt token, 409 flow, 400 auto-recovery
│   │   ├── class-rest-handler.php    ← REST routes, nonce/secret, rate limit, field mapping
│   │   ├── class-logger.php          ← {prefix}sf_lead_log table (masked email, no PII)
│   │   └── class-admin.php           ← Settings + Logs + Test Connection (AJAX)
│   ├── public/
│   │   ├── sf-lead-form.js           ← multi-step state machine (data-driven STEPS)
│   │   └── sf-lead-form.css          ← brand styles (CSS custom properties)
│   ├── admin/
│   │   ├── settings-page.php  logs-page.php  admin.css  admin.js
│   ├── uninstall.php
│   └── README.txt
├── .gitignore
└── README.md
```

---

## Setup

### 1. HubSpot — create the Private App token

1. HubSpot portal `14516909` → **Settings → Integrations → Private Apps → Create a private app**.
2. Name it e.g. *SF Lead Form*.
3. **Scopes:** `crm.objects.contacts.read` + `crm.objects.contacts.write`.
4. Create, then **copy the token** (starts with `pat-na1-` or `pat-eu1-`).

### 2. HubSpot — create the 5 custom properties (do this first)

**Settings → Properties → Contact properties → Create property.** Type = **Single-line text** for all:

| Internal name | Label | Used for |
|---|---|---|
| `enquiry_type` | Enquiry Type | White Label vs Private Label |
| `product_type` | Product Type | Powder / Capsule / Tablet / LiquidGel / Gummy |
| `manufacturing_experience` | Manufacturing Experience | First product vs existing brand |
| `unit_quantity` | Unit Quantity | 200 … 10000+ |
| `manufacturing_budget` | Manufacturing Budget | £500–£2,000 … £100,000+ |

> The standard properties `firstname`, `lastname`, `email`, `phone`, `company`, `message`, `lifecyclestage`, `hs_lead_status` already exist. `lead_source` is set to `Website Form` (optional custom property — created or skipped automatically).
>
> **If you skip this step**, leads still save: the plugin detects HubSpot's “property does not exist” (400) response, strips the unknown fields, retries, and logs exactly which fields were dropped.

### 3. WordPress — install & configure

1. Copy the `sf-lead-form/` folder into `wp-content/plugins/` (or upload `sf-lead-form.zip` via **Plugins → Add New → Upload Plugin**).
2. **Activate** it.
3. **Settings → SF Lead Form** → paste the token → **Save** → click **Test HubSpot Connection** (expect ✅).
4. Add `[sf_lead_form]` to the landing page where GrowForms used to be.

---

## Customising the form

- **Reorder / edit steps:** everything lives in the `STEPS` array at the top of `public/sf-lead-form.js`. To match the *live screenshots* (Contact collected **last**), move the `contact` entry to the end of `STEPS`.
- **Solid-red option cards** (screenshot look): add the class `sf-lf--solid-cards` to the `.sf-lf` wrapper. Default is white cards / red-when-selected (per spec).
- **Colours / sizing:** override the CSS custom properties on `.sf-lf` (e.g. `--sf-red`, `--sf-bg`, `--sf-card-max`) in your theme.

## REST API

| Method | Route | Auth | Purpose |
|---|---|---|---|
| `POST` | `/wp-json/sf-lead-form/v1/submit` | `X-WP-Nonce` **or** `?key=SECRET` | Create/update a contact |
| `GET` | `/wp-json/sf-lead-form/v1/health` | none | `{status, portal, version}` |

Responses: `{ "success": true, "action": "created"|"updated", "vid": "123" }` or
`{ "success": false, "code": "...", "error": "..." }`. Processed submissions
return **HTTP 200** so the form never breaks on a downstream HubSpot error.

### curl test

The on-page form uses a nonce. For a standalone curl test, use the shared
secret from **Settings → SF Lead Form → Webhook / Test Secret**:

```bash
curl -X POST "https://YOURSITE.com/wp-json/sf-lead-form/v1/submit?key=YOUR_SECRET" \
  -H "Content-Type: application/json" \
  -d '{
    "firstname": "John",
    "lastname": "Doe",
    "email": "john@supplementtest.com",
    "phone": "+441234567890",
    "company_name": "Test Nutrition Ltd",
    "product_brief": "Looking for private label capsules",
    "enquiry_type": "private_label",
    "product_type": "capsule",
    "manufacturing_experience": "first_product",
    "unit_quantity": "1000",
    "manufacturing_budget": "5000-10000"
  }'
```

### After a successful test

In HubSpot portal `14516909`: **CRM → Contacts** → search `john@supplementtest.com` →
confirm the 5 custom properties are populated and **Lifecycle stage = Lead**.
Then check **Settings → SF Lead Form → Logs** for a `created` / `updated` row.

---

## Security

- HubSpot token: `wp_options`, **encrypted** (AES-256-CBC keyed off `AUTH_KEY`/`AUTH_SALT`); never output to the browser or committed to git.
- Nonce (`wp_rest`) verified on submit; optional shared secret for server-to-server.
- Per-IP rate limit (5 / 10 min) via transients; honeypot field; JSON-only.
- Logs store a **masked** email (`j***@d***.com`) and `$wpdb->prepare()` everywhere — no raw PII.
- Admin pages gated by `current_user_can('manage_options')`, nonce-protected, all output escaped.
- `.gitignore` excludes `wp-config.php`, `.env`, `*.log`.

---

## Push to GitHub

```bash
cd SF_forms
git init
git add .
git commit -m "feat: SF 7-step lead form → HubSpot CRM plugin"
git branch -M main
git remote add origin https://github.com/lucasdvsf02626/SF_forms.git
git push -u origin main
```
