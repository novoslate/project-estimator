=== Project Estimator ===
Contributors: novoslate
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Instant price estimators with lead capture for contractors.

== Description ==
Visitors pick an option, set their size with sliders, add extras, and see a live price range. The quote form sends the lead with every project detail attached.

Templates: patio covers, landscaping, artificial turf, pavers, fencing, and a blank starter.

Leads are:
* Saved under Estimators > Leads
* Emailed to the address set on each estimator (falls back to the site admin email)
* Posted as JSON to an optional webhook (Zapier, Make, HubSpot workflows, etc.)

== Setup ==
1. Upload the zip under Plugins > Add New > Upload Plugin and activate.
2. Go to Estimators > Add New, load a template, and set real prices.
3. Publish, then paste the shortcode into a page or an Elementor Shortcode widget:
   [project_estimator id="123"]

== Notes ==
* Template prices are placeholders. Confirm ranges with each client.
* The server recalculates every estimate, so tampered prices are never stored.
* Spam protection: honeypot field, minimum fill time, and a per-visitor rate limit.
* Use an SMTP plugin on the client site so lead emails actually get delivered.
