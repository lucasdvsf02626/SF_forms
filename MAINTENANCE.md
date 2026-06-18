# SF Lead Form — Maintenance Runbook

Everything here runs on **your own systems only** — WordPress (the bespoke `sf-lead-form` plugin) talking
directly to **HubSpot** (account `14516909`) via a Private App token. **No Zapier, no Make, no third party.**

- Live form: `https://supplementfactoryuk.com/sf_forms/`
- Admin: **WordPress → Settings → SF Lead Form** (Settings / Logs / Failed leads tabs)
- Code: `https://github.com/lucasdvsf02626/SF_forms`

---

## 1. HubSpot token — rotation & renewal

HubSpot Private App access tokens **do not expire on their own.** They only change when someone
**rotates** them (manually, or HubSpot forces it for security). After a rotation, the **old** token keeps
working for a **7-day grace period**, during which *both* old and new tokens are valid — so a passing
Test Connection does **not** prove WordPress is on the new one.

**When you get a "token rotated" email, do this (≈3 min):**
1. HubSpot → **Settings → Integrations → Private Apps → SF Lead Form → Auth** tab.
2. Click **Show token**, then **Copy** (this is the current token).
3. WordPress → **Settings → SF Lead Form** → paste into **HubSpot Access Token** → **Save Settings** →
   **Test HubSpot Connection** → expect ✅.
4. Back in HubSpot, **manually expire the old token**.
5. In WordPress, click **Test HubSpot Connection** once more — still ✅ **proves** you're on the live token.

> While the token is broken, **no leads are lost** — they're captured locally and auto-retried (see §3).

---

## 2. Routine monitoring (≈2 min, weekly is plenty)

- **Settings tab** shows a green/red **HubSpot connection** banner from the automatic **daily health-check**.
  Green = fine. Red = the token/connection is failing (you'll also get an email — see §3).
- **Logs tab** — recent submissions (emails masked, no raw PII). `success` + `created`/`updated` = healthy.
- **Failed leads tab** — should normally be empty. Anything here is a lead awaiting/again-failing sync (see §3).
- **Optional external uptime check:** point a free monitor (e.g. UptimeRobot) at
  `https://supplementfactoryuk.com/wp-json/sf-lead-form/v1/health` — it returns `{status:"ok", ...}` (a cheap
  liveness check; HubSpot connectivity itself is covered by the daily health-check above).

---

## 3. "Never lose a lead" — how failures are handled

Every submission is saved in WordPress **first**, then sent to HubSpot:
- **HubSpot OK** → marked **synced**.
- **HubSpot fails** (bad/rotated token, outage) → the lead stays **pending**, the visitor **still sees the
  Thank-you screen**, you get a **throttled email alert** (≤1 / 15 min) with the lead's contact details, and
  it **auto-retries hourly** until it syncs.

**To recover manually:** Settings → SF Lead Form → **Failed leads** tab →
- **Retry all now** (or per-row **Retry**) — re-attempt immediately,
- **Export CSV** — download the full leads to follow up by hand,
- **Delete** — remove a junk/test row.

Synced rows are auto-pruned after 30 days. Set the alert recipient under **Settings → Alert email**
(defaults to the WordPress admin email).

---

## 4. Deploying a plugin update

Claude is read-only on GitHub, so updates are delivered as zips and you apply them:
1. Get the new **`sf-lead-form.zip`** from Claude.
2. WordPress → **Plugins → Add New → Upload Plugin** → choose the zip → **Replace current** → activate if needed.
3. Confirm the **Version** shown on the Plugins screen matches the new release (e.g. **1.1.0**).
   - New DB tables/cron jobs are created automatically on first load (an upgrade routine handles this — no
     deactivate/reactivate needed).
4. To keep GitHub in sync, push from your Mac with the **`SF_forms_final.zip`** Claude sends:
   ```bash
   cd ~/Downloads/SF_forms_repo
   unzip -o ~/Desktop/SF_forms_final.zip -d .
   git add -A
   git commit -m "describe the change"
   git push -u origin main
   ```

---

## 5. Adding or changing a form field (keep 4 places in sync)

A choice field's allowed values must match in **all** of these, or HubSpot will reject it (the plugin will
then strip the bad field and still save the lead, but the data won't map):

1. **`sf-lead-form/public/sf-lead-form.js`** — the `STEPS` array (option `value`s and order).
2. **`sf-lead-form/includes/class-validator.php`** — the `ENUMS` whitelist (must match the JS `value`s exactly).
3. **`sf-lead-form/includes/class-rest-handler.php`** — `map_to_hubspot()` (maps the field → a HubSpot property).
4. **HubSpot** — the matching Contact property must exist (and, for dropdown/checkbox properties, allow those
   option values). Free-text properties accept anything.

Then bump the version and redeploy (§4).

### Current HubSpot contact properties used
`firstname`, `lastname`, `email`, `phone`, `company`, `message`, `lifecyclestage` (= Lead),
`enquiry_type`, `product_type`, `unit_quantity`, `manufacturing_budget`, `manufacturing_experience`,
`journey_stage`.

---

## 6. Where things live / ownership
- **Code:** GitHub `lucasdvsf02626/SF_forms` (source of truth) + the active plugin on the WordPress server.
- **HubSpot token:** stored **encrypted** in WordPress options; never in git, never in the browser.
- **Leads:** in HubSpot (primary) + the local lead store (recent backup / retry queue, full data, 30-day
  retention for synced rows).

---

## 7. Behavioral tracking — Microsoft Clarity gate funnel (v1.1.1)

The form fires a Microsoft Clarity custom **event** as the visitor reaches each gate, plus a custom
**tag** for each choice. **No PII is ever sent** — contact fields are never tagged.

**Events (in order)** — use these to build a Clarity **Funnel**:
`sf_form_started` → `sf_g1_enquiry_type` → `sf_g2_product_type` → `sf_g3_unit_quantity` →
`sf_g4_manufacturing_budget` → `sf_g5_manufacturing_experience` → `sf_g6_journey_stage` →
`sf_g7_contact` → `sf_form_submit_attempt` → `sf_form_submitted`
(`sf_form_error` fires if a submission errors.)

**Tags** (filter recordings by these): `sf_step_reached` (every gate a session reached),
`sf_enquiry_type`, `sf_product_type`, `sf_unit_quantity`, `sf_manufacturing_budget`,
`sf_manufacturing_experience`, `sf_journey_stage`.

**Set up the funnel:** Clarity dashboard → **Funnels → New funnel** → add the events above in order →
Save. Drop-off between consecutive steps = where visitors abandon. To watch the people who bailed at,
say, budget: **Recordings** → filter where tag `sf_step_reached` contains `4_manufacturing_budget`.
The same events also fire to **GA4 / GTM** when present (via `gtag` / `dataLayer`), so drop-off ties
back to AdWords campaign & keyword.

## 8. Future option (not built yet): partial / abandoned-lead capture — no Zapier
Capture drop-offs by saving name/phone on field `blur` to a new plugin endpoint
(`POST /wp-json/sf-lead-form/v1/partial`) — entirely first-party, no Zapier. To be useful it needs the
contact step moved to the **front** of the form (otherwise earlier steps are anonymous) plus a
**consent/privacy notice** (UK GDPR/PECR). Ask Claude to enable it when wanted.
