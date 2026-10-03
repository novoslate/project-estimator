=== Project Estimator ===
Contributors: novoslate
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later

Instant price estimators with lead capture for contractors.

== Description ==
Visitors pick an option, set their size with sliders, add extras, and see a live price range. The quote form sends the lead with every project detail attached.

Each estimator holds one or more project types (for example patio cover, pergola, sunroom, patio enclosure). Every project type has its own sizes, choices, extras, and minimum job. With two or more, visitors pick a project type first.

Templates: outdoor living (patio covers, pergolas, sunrooms, enclosures), yard (landscaping, turf, pavers), single-project templates for each, and a blank starter. Any project type can also be added to an estimator from the built-in library.

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

== Settings ==
Estimators > Settings holds defaults for every estimator:

* Lead email defaults: send to, CC, and BCC. An estimator's own fields win when filled in; blank fields use these defaults. Separate multiple addresses with commas.
* Design defaults: style (card, soft shadow, or flat), accent, text, secondary text, background, and border colors, font, corners, max width, and whether to show the business name, step numbers, and a sticky price bar. Text on the accent color switches between white and dark automatically for readability.

Each estimator has a Design card set to "Use global design settings" by default. Switch it to custom to style one estimator differently.

== Live preview ==
Each project type shows a live 3D-style illustration that updates as visitors change the style, size, and extras. Visitors can switch to a top-down view.

Scenes: patio cover, pergola, sunroom, patio enclosure, landscaping, artificial turf, pavers, and fence or wall. Set the scene per project type under "Live preview", then pick a "Preview look" for each choice and how each extra "Shows in preview as" (lights, canopy, fire pit, gates, and more). Extras that are not drawn are listed under the preview.

Existing and custom estimators are matched to scenes, looks, and features automatically from their names. Choose "Top-down plan only" to turn the illustration off.

== Colors ==
Each project type can have a color list (for example frame colors for patio covers, rock colors for landscaping, or paver colors). Visitors pick a color and the preview repaints the main material. A color can carry an upcharge percentage that applies to the base price, not extras (for example Woodgrain +15%).

Templates include default color lists. Project types saved before 1.4.0 get the defaults for their preview scene automatically. Leave the list empty to skip the color step. The chosen color is saved on the lead, included in the email, and sent to the dataLayer as project_color.

== Conversion tracking ==
Every quote request pushes an event to window.dataLayer for Google Tag Manager:

  event: project_estimator_lead (editable per estimator)
  estimator_id, estimator_name, lead_id, project_type, project_option, project_color,
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
= 1.5.0 =
* CC and BCC for lead emails, plus multiple "send to" addresses.
* New Estimators > Settings page with lead email and design defaults.
* Design settings per estimator, with the option to use the global defaults.
* Estimators with a customized accent color keep it as a custom design.

= 1.4.0 =
* Colors per project type with swatches, optional upcharge %, and live preview repainting.
* Default color lists for every template scene.
* Color saved on leads, emails, webhook, and the dataLayer.

= 1.3.0 =
* Live illustrated preview for 8 project scenes, with 3D and top views.
* Preview looks per choice and visual features per extra, auto-detected from names.

= 1.2.0 =
* Project types: one estimator can offer several project types, each with its own pricing.
* New templates: Outdoor living and Yard. Project library for adding types to any estimator.
* Project type included in leads, emails, the dataLayer, and GA4 events.
* Estimators from 1.1.0 migrate automatically.

= 1.1.0 =
* Conversion tracking: dataLayer events, optional GA4 and Google Ads gtag events, enhanced conversions.
* Lead attribution: GCLID, UTM, and ValueTrack capture with a Source column on leads.

= 1.0.0 =
* Initial release.
