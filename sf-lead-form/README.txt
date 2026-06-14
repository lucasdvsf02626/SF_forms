=== SF Lead Form ===
Contributors: supplementfactory
Tags: hubspot, crm, lead form, multi-step, contact form
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 1.0.0
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
4. Create the 5 custom contact properties in HubSpot (see FAQ).
5. Add the shortcode `[sf_lead_form]` to any page.

== Frequently Asked Questions ==

= Which HubSpot scopes does the token need? =

`crm.objects.contacts.read` and `crm.objects.contacts.write`.

= Which custom properties must I create in HubSpot? =

Create these as Single-line text contact properties (Settings → Properties → Contact properties):
`enquiry_type`, `product_type`, `manufacturing_experience`, `unit_quantity`, `manufacturing_budget`.

= Where does the token live? =

In `wp_options`, encrypted at rest. It is only ever decrypted server-side for the API call.

== Changelog ==

= 1.0.0 =
* Initial release: 7-step form, HubSpot CRM v3 integration, admin settings + logs.
