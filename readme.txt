=== Project Estimator ===
Contributors: novoslate
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.13.0
License: GPLv2 or later

Instant price estimators with lead capture for contractors.

== Description ==
Visitors pick an option, set their size with sliders, add extras, and see a live price range. The quote form sends the lead with every project detail attached.

Each estimator holds one or more project types (for example patio cover, pergola, sunroom, patio enclosure). Every project type has its own sizes, choices, extras, and minimum job. With two or more, visitors pick a project type first.

Templates: outdoor living (patio covers, pergolas, sunrooms, enclosures), yard (landscaping, turf, pavers), single-project templates for each, and a blank starter. Any project type can also be added to an estimator from the built-in library.

Leads are:
* Saved under Estimators > Leads
* Emailed to the address set on each estimator (falls back to the site admin email)
* Posted as JSON to an optional webhook (Zapier, Make, HubSpot workflows, etc.) with retries and a delivery log

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

== CRM webhook ==
Set a Webhook URL on an estimator (for example a Zapier "Catch Hook" that creates a lead in the client's CRM) and every quote request is sent there as JSON.

* Sent right after the visitor's confirmation, so it never slows the form.
* Anything other than a 2xx response is retried after 1 minute, 5 minutes, 30 minutes, 2 hours, and 6 hours. After the last try, an alert is emailed to "Webhook failure alerts" under Estimators > Settings (the site admin email by default).
* Each lead shows its delivery status and every attempt (time, response code, message) in the "CRM webhook" box, with a "Resend now" button. The Leads list has a CRM column, a "View failed leads" notice, and a "Resend to CRM webhook" bulk action for after a Zap is fixed.
* "Send test lead" next to the Webhook URL sends a sample lead named "Test Lead" with test: true.
* The JSON has every lead field (contact, project, estimate, source, campaign, click IDs, pdf_url) plus event, lead_id, status, delivery_attempt, test, and site. Headers: X-PE-Event, X-PE-Lead-ID, X-PE-Attempt.
* Only public addresses are allowed; internal network addresses are refused.

Retries run on WP-Cron, which fires when the site gets visits. On low-traffic sites, a real server cron job calling wp-cron.php every few minutes keeps retries on schedule.

== Spam protection ==
Every form has a hidden honeypot field, a minimum fill time, and a per-visitor rate limit. For more protection, turn on Google reCAPTCHA under Estimators > Settings > Spam protection:

* v2 Checkbox: visitors check "I'm not a robot" before sending.
* v3 Invisible: no checkbox. Google scores each request from 0.0 (bot) to 1.0 (person) and requests under the minimum score (0.5 by default) are blocked. Each lead's score is saved and included in the CSV export. The floating badge can be hidden; the required Google notice is then shown under the form.

Create keys at google.com/recaptcha/admin with the same type you choose (v2 and v3 keys are different) and add the site's domain. Tokens are verified on the server and the secret key is never sent to visitors. Google's script loads only on pages with an estimator. If Google cannot be reached, leads are accepted and the problem is written to the PHP error log.

== Import / Export ==
Estimators > Import / Export copies setups between sites:

* Export downloads a JSON file with the estimators you pick (project types, pricing, colors, previews, form fields, tracking, design) and, optionally, the site-wide settings (design defaults, PDF and customer email text, spam protection options, and the logo, embedded in the file).
* "Include business details" adds business name, phone, lead emails, CC/BCC, reply-to, webhook URLs, and the PDF website. Leave it unchecked when copying a setup to a different client. reCAPTCHA keys are never exported.
* Import shows a review step first. Each estimator can be created as new, replace an existing one (keeping its title and shortcode), or be skipped. A site that already has reCAPTCHA keys keeps its keys and mode.

The Estimators list also has Duplicate (copies an estimator as a draft) and Export links on each row.

== Dashboard ==
Estimators > Dashboard shows how estimators perform for any date range (last 7, 30, or 90 days, this month, last month, this year, or custom), for all estimators or one:

* Views, starts, quote requests, conversion rate, booked jobs, close rate, pipeline value, booked value, and average estimate, each compared with the previous period. Month and year ranges compare with the matching calendar period.
* A daily chart of views and quote requests, and the funnel from view to booked.
* Where quote requests come from (Google Ads, organic search, direct, and more), with bookings per source and top campaigns.
* The most chosen project types, styles, colors, and extras.
* A plain-text summary with a Copy button for client reports.

A 30-day summary also appears on the main WordPress dashboard.

Views and starts are counted in the visitor's browser, so they work with page caching. Each visitor counts once per estimator per day, and logged-in editors and admins are not counted. Only daily totals are stored. Quote requests, bookings, and choices come from leads, so they include all history.

== Lead status and export ==
Every lead has a status: New, Contacted, Quoted, Booked, or Lost. Change it on the lead screen or for many leads at once with the bulk actions on Estimators > Leads, and filter the list by status. Booked leads can store the real contract amount as "Booked job value". The date each status was first reached is recorded.

Estimators > Export downloads a CSV:

* All lead details: contact info, project selections, estimate, status, booked value, source, campaign, keyword, click IDs (GCLID, GBRAID, WBRAID, MSCLKID, FBCLID), landing page, and PDF link. Filter by date range, estimator, and status. "Export to CSV" is also a bulk action on the Leads list.
* Google Ads offline conversions: booked leads with a GCLID, in Google's upload template. Conversion time is when the lead was marked Booked, in the site time zone. Value is the booked job value, or the middle of the estimate when none is set. Create an "Import" conversion action in Google Ads first and use its exact name.

Text cells that start with =, +, -, or @ are prefixed with an apostrophe so spreadsheets never run them as formulas.

== PDF estimates ==
Every quote request creates a branded, one-page PDF: logo on the accent color band, the price range, the 3D illustration exactly as the customer configured it, project details (type, style, color, size, extras), the customer's information, next steps, and the fine print.

* The customer gets an email with the PDF attached when they enter an email address. Replies go to the "Reply-to address" setting, or the first "Send leads to" address when it is blank. Edit the subject and message under Estimators > Settings, using {first_name}, {name}, {business}, {phone}, {project}, {low}, and {high}.
* The business lead email gets the same PDF attached.
* The confirmation screen shows a "Download your estimate (PDF)" button.
* Each lead in the admin has a "View PDF estimate" button, and the webhook payload includes pdf_url.

The website shown in the PDF header and footer defaults to this site's domain. Set "Website shown on PDF" under Estimators > Settings to show a different one, for example the main website when the estimator runs on an ads subdomain.

The illustration is captured in the visitor's browser when they submit. The server validates and re-encodes it, and recalculates every price itself. PDFs are stored in wp-content/uploads/pe-estimates with random file names and served only through signed links. Deleting a lead deletes its PDF.

Uses FPDF (lib/fpdf), which is free to use, modify, and distribute.

== Customer photos ==
Turn on Photos in an estimator's Quote form card (Hidden, Optional, or Required). Visitors can add up to 6 photos of their space from the camera roll or camera.

* Photos are shrunk in the browser to 1600 px before upload (a 12 MB phone photo becomes a few hundred KB), which keeps uploads fast and well under hosting limits. This also removes hidden photo data such as GPS location.
* The server keeps only real JPEG, PNG, or WebP images and re-saves each one as a clean JPEG.
* Photos appear on the lead screen, are attached to the business lead email, fill a "Your photos" page in the PDF, and are included as links in the CRM webhook (photos) and the CSV export.
* Photos are stored privately with random names and open only through signed links. Deleting a lead deletes its photos.

New estimators from templates start with Photos set to Optional. Estimators saved before 1.13.0 keep Photos hidden until it is turned on.

== Step by step layout ==
The Layout design setting (site-wide under Estimators > Settings, or per estimator with a custom design) chooses how the estimator is shown:

* Step by step on phones (default): one question per screen with a progress bar when the estimator is narrower than 640 px, including narrow page columns on desktop. Wider layouts show the single page.
* Step by step on all screens.
* Single page on all screens.

In step by step mode, tapping a project type, style, or color moves on automatically, Back and Next buttons move between steps, and the 3D preview stays on screen above the style, color, size, and extras steps. The sticky price bar stays visible, and its button jumps to the contact step. Each step sends a project_estimator_step event (step, step_number, step_total) to the dataLayer for funnel reports in GA4.

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
= 1.13.0 =
* Customer photo uploads (up to 6), shrunk in the browser with location data removed.
* Photos on the lead screen, in the business email, on a PDF page, and as links in the webhook and CSV.
* Photos field setting (Hidden, Optional, Required) per estimator.

= 1.12.0 =
* Step by step layout: one question per screen with a progress bar, auto-advance, and the 3D preview kept on screen. On by default for phones and narrow columns.
* New Layout design setting (phones only, all screens, or single page).
* project_estimator_step dataLayer events for funnel reports.
* More compact price bar on small phones.

= 1.11.0 =
* New Estimators > Import / Export for copying estimators and site-wide settings (including the logo) between sites, with a review step before importing.
* Duplicate and Export row actions on the Estimators list.
* The leads export page is now named "Export leads".

= 1.10.0 =
* New Estimators > Dashboard with views, starts, quote requests, conversion and close rates, pipeline and booked value, sources, campaigns, and popular choices, compared with the previous period.
* Copyable summary for client reports and a 30-day widget on the WordPress dashboard.
* Privacy-friendly view and start tracking that works with page caching.

= 1.9.0 =
* Reliable CRM webhook delivery: sent after the response, retried with backoff for about 9 hours, with a failure alert email.
* Delivery log and "Resend now" on each lead, a CRM column, failed-lead notice and filter, and a "Resend to CRM webhook" bulk action.
* "Send test lead" button next to the Webhook URL.
* Webhooks can only go to public addresses.

= 1.8.0 =
* Google reCAPTCHA v2 Checkbox and v3 Invisible, with server-side verification.
* v3 score threshold, saved per lead and in the CSV export, plus an option to hide the badge.

= 1.7.1 =
* New "Reply-to address" setting for the customer estimate email.

= 1.7.0 =
* Lead status (New, Contacted, Quoted, Booked, Lost) with a Status column, filter, bulk actions, and status history.
* Booked job value on each lead.
* CSV export of all lead details, with date, estimator, and status filters, plus a bulk "Export to CSV" action.
* Google Ads offline conversions export for booked leads from ad clicks.

= 1.6.3 =
* PDF: the illustration spans the full content width, using a wider capture made for the page.
* PDF: headings, underlines, and detail rows share one left edge, with consistent spacing under each heading.
* PDF: tighter header and price box so long selections still fit on one page; "Next steps" never splits from its text.

= 1.6.2 =
* New "Website shown on PDF" setting for the PDF header and footer.
* Better spacing around the PDF download button on the confirmation screen.
* Confirmation heading stays bold and proportional in any theme.

= 1.6.1 =
* Fix: quote submissions failed with a critical error in 1.6.0 (an admin-only WordPress function was used while building the PDF).
* A PDF problem can no longer block a lead or its emails. Problems are written to the PHP error log instead.
* Visitors see a friendly message instead of raw HTML if the server ever returns an error.
* Settings page shows "Settings saved", and the logo preview uses the accent color like the PDF.

= 1.6.0 =
* Branded PDF estimates with the project illustration, emailed to the customer and attached to the lead email.
* Download button on the confirmation screen and a PDF link on each lead.
* Logo, PDF, and customer email settings under Estimators > Settings.

= 1.5.1 =
* Preview: side beams under both roof edges on patio covers and enclosures.
* Preview: enclosure and sunroom side walls now follow the roof slope with no gap at the house.

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
