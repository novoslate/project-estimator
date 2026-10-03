/* Project Estimator: captures ad click IDs and UTM parameters so leads can be tied to campaigns. */
(function () {
	'use strict';
	var NAME = 'pe_attr';
	var DAYS = 90; // Google Ads click IDs are valid for offline conversion import for 90 days.
	var KEYS = [
		'gclid', 'gbraid', 'wbraid', 'msclkid', 'fbclid',
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
		'campaignid', 'adgroupid', 'keyword', 'matchtype', 'device'
	];

	function read() {
		var m = document.cookie.match(/(?:^|; )pe_attr=([^;]*)/);
		if (!m) return null;
		try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { return null; }
	}

	function write(o) {
		document.cookie = NAME + '=' + encodeURIComponent(JSON.stringify(o)) +
			'; max-age=' + DAYS * 86400 + '; path=/; SameSite=Lax' +
			(location.protocol === 'https:' ? '; Secure' : '');
	}

	function externalReferrer() {
		if (!document.referrer) return '';
		try {
			return new URL(document.referrer).host !== location.host ? document.referrer.slice(0, 300) : '';
		} catch (e) { return ''; }
	}

	var params, found = {}, hasParams = false;
	try { params = new URLSearchParams(location.search); } catch (e) { params = null; }
	if (params) {
		KEYS.forEach(function (k) {
			var v = params.get(k);
			if (v) { found[k] = v.slice(0, 200); hasParams = true; }
		});
	}

	var current = read();
	var stamp = {
		landing_page: (location.origin + location.pathname).slice(0, 300),
		referrer: externalReferrer(),
		captured_at: new Date().toISOString()
	};

	if (hasParams) {
		// A new tagged visit (ad click or campaign link) replaces the previous source.
		write(Object.assign(found, stamp));
	} else if (!current && stamp.referrer) {
		// First untagged visit from another site: record it as organic or referral.
		write(stamp);
	}

	window.peAttribution = function () { return read() || {}; };
})();
