=== Project Estimator ===
Contributors: novoslate
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.1.0
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

== Conversion tracking ==
Every quote request pushes an event to window.dataLayer for Google Tag Manager:

  event: project_estimator_lead (editable per estimator)
  estimator_id, estimator_name, lead_id, project_option,
  estimate_low, estimate_high, value, currency
  user_data { email, phone_number } when Enhanced conversions is on

A project_estimator_start event fires on the first interaction with the estimator.

GTM setup: create a Custom Event trigger for project_estimator_lead, then fire a Google Ads Conversion tag (value = {{DLV - value}}, transaction ID = {{DLV - lead_id}}) and a GA4 generate_lead event.

Without GTM: if gtag.js is on the page, the plugin can fire GA4 generate_lead and a Google Ads conversion directly. Set the send_to value under Conversion tracking.

== Lead attribution ==
A small script on every page saves ad click IDs (gclid, gbraid, wbraid, msclkid, fbclid), UTM tags, and Google Ads ValueTrack params (campaignid, adgroupid, keyword, matchtype, device) in a first-party cookie for 90 days. Each lead stores them, shows a Source column in the admin, and includes them in the email and webhook.

Suggested Google Ads tracking template:
{lpurl}?utm_source=google&utm_medium=cpc&utm_campaign={campaignid}&utm_term={keyword}&campaignid={campaignid}&adgroupid={adgroupid}&keyword={keyword}&matchtype={matchtype}&device={device}

Disable capture with: add_filter( 'pe_capture_attribution', '__return_false' );

== Changelog ==
= 1.1.0 =
* Conversion tracking: dataLayer events, optional GA4 and Google Ads gtag events, enhanced conversions.
* Lead attribution: GCLID, UTM, and ValueTrack capture with a Source column on leads.

= 1.0.0 =
* Initial release.
