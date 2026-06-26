=== SF Lead Form ===
Contributors: supplementfactory
Tags: hubspot, crm, lead form, multi-step, contact form
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 1.2.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-owned multi-step lead capture form (GrowForms replacement) that sends submissions straight to HubSpot CRM as contacts. Vanilla JS + PHP, zero dependencies.

== Description ==

SF Lead Form renders a 7-step multi-step enquiry form via the shortcode `[sf_lead_form]` and creates/updates a HubSpot CRM contact for each submission using the HubSpot CRM v3 API and a Private App token.

* Vanilla JavaScript front-end (no React/Vue/jQuery on the front-end), plain CSS.
* HubSpot token stays server-side and is stored encrypted — never exposed to the browser.
* Duplicate emails are handled automatically (create → 409 → find → update).
* Missing custom properties never lose a lead (offending properties are stripped and the contact is still created/updated; dropped fields are logged).
* Per-IP rate limiting, honeypot, and nonce/secret protection.
* Admin settings page with a "Test Connection" button and a submission log viewer (emails masked, no raw PII stored).

== Installation ==

1. Upload the `sf-lead-form` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to Settings → SF Lead Form and paste your HubSpot Private App token.
4. Ensure the contact properties the plugin writes to exist in HubSpot (see FAQ).
5. Add the shortcode `[sf_lead_form]` to any page.

== Frequently Asked Questions ==

= Which HubSpot scopes does the token need? =

`crm.objects.contacts.read` and `crm.objects.contacts.write`.

= Which contact properties does the plugin write to? =

`enquiry_type` (dropdown), `product_format` (dropdown), `product_quantity` (number),
`enquiry_budget` (single-line text), `new_vs_existing` (radio), `journey` (radio),
`product_brief` (multi-line text), plus the standard `firstname`, `lastname`, `email`,
`phone`, `company`, `lifecyclestage` and `hs_lead_status`.

The form's option values are translated to each property's stored options automatically
(e.g. `Capsules` → `Capsule`, budget brackets → "£500 – £2,000"). Any property HubSpot
doesn't recognise is skipped and noted in the Logs tab, so a mismatch never costs the lead.

= Where does the token live? =

In `wp_options`, encrypted at rest. It is only ever decrypted server-side for the API call.

== Changelog ==

= 1.2.9 =
* Fixed: the consent legal basis was sent as `CONSENT_WITH_NOTICE`, which isn't a valid option on the live portal, so `hs_legal_basis` stayed blank. Now sends `Freely given consent from contact` (still overridable via the `sf_lead_form_legal_basis_value` filter).
* Fixed: phone numbers where the visitor typed the international prefix (e.g. "+44 7700…") while the country selector was also set duplicated the country code ("+44 447…"). The full number is now de-duplicated before sending.

= 1.2.8 =
* Added: when the visitor ticks the consent box, the GDPR legal basis is recorded on the contact (`hs_legal_basis`, default `CONSENT_WITH_NOTICE`). The property and value are filterable via `sf_lead_form_legal_basis_property` / `sf_lead_form_legal_basis_value` so they can be matched to your portal's exact "Legal basis" option without a code change. Note: the consent checkbox currently only appears on the progressive (email-first) variant.

= 1.2.7 =
* Fixed: product, quantity, budget, new-vs-existing, journey and brief were written to duplicate HubSpot properties that aren't shown on the contact record, so they looked blank. They now map to the properties actually used on the contact: product → `product_format`, quantity → `product_quantity` (number), budget → `enquiry_budget`, experience → `new_vs_existing`, journey → `journey`, brief → `product_brief`. Form option values are translated to each property's stored options (e.g. `Capsules` → `Capsule`).
* Added: properties HubSpot ignores (missing or invalid option) are now recorded in the Logs tab "Error" column, so a future mapping mismatch can't fail silently.
* The HubSpot form mirror (1.2.6) now sends the same corrected field names/values.

= 1.2.6 =
* Completed enquiries can now be mirrored to a HubSpot form (Forms API) so they trigger CRM workflows/automations (deal creation, automated outreach). Set the form GUID under Settings → SF Lead Form → HubSpot Form GUID; leave blank to disable. No automation trigger is lost to a missing form — the field is optional and the CRM contact is still created as before.

= 1.0.0 =
* Initial release: 7-step form, HubSpot CRM v3 integration, admin settings + logs.
