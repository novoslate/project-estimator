/* Project Estimator: admin config editor */
(function () {
	'use strict';
	var root = document.getElementById('nse-editor');
	var input = document.getElementById('nse_config_json');
	if (!root || !input || typeof NSE_ADMIN === 'undefined') return;

	var T = NSE_ADMIN.templates;
	var clone = function (o) { return JSON.parse(JSON.stringify(o)); };
	var cfg = NSE_ADMIN.config ? clone(NSE_ADMIN.config) : clone(NSE_ADMIN.defaults);

	var esc = function (s) {
		return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	};
	var get = function (path) {
		return path.split('.').reduce(function (o, k) { return o == null ? undefined : o[k]; }, cfg);
	};
	var set = function (path, val) {
		var ks = path.split('.'), o = cfg;
		for (var i = 0; i < ks.length - 1; i++) {
			if (o[ks[i]] == null) o[ks[i]] = {};
			o = o[ks[i]];
		}
		o[ks[ks.length - 1]] = val;
	};
	var sync = function () { input.value = JSON.stringify(cfg); };

	function normalize() {
		cfg.dims = cfg.dims || [];
		var need = cfg.pricing_model === 'linear' ? 1 : 2;
		while (cfg.dims.length < need) {
			cfg.dims.push({ label: cfg.dims.length ? 'Length (ft)' : 'Width (ft)', min: 5, max: 100, step: 1, default: 20 });
		}
		cfg.dims = cfg.dims.slice(0, need);
		cfg.options = cfg.options || [];
		cfg.addons = cfg.addons || [];
		cfg.fields = cfg.fields || {};
		cfg.timeline_choices = cfg.timeline_choices || [];
		cfg.tracking = cfg.tracking || JSON.parse(JSON.stringify(NSE_ADMIN.defaults.tracking));
	}

	/* Field builders */
	function text(label, path, o) {
		o = o || {};
		return '<label class="nse-f' + (o.wide ? ' nse-wide' : '') + '"><span>' + label + '</span>' +
			'<input type="' + (o.type || 'text') + '" data-path="' + path + '" data-type="' + (o.num ? 'number' : 'text') + '"' +
			(o.step ? ' step="' + o.step + '"' : '') +
			(o.placeholder ? ' placeholder="' + esc(o.placeholder) + '"' : '') +
			' value="' + esc(get(path)) + '"></label>';
	}
	function area(label, path, o) {
		o = o || {};
		var v = get(path);
		if (o.lines && Array.isArray(v)) v = v.join('\n');
		return '<label class="nse-f nse-wide"><span>' + label + '</span>' +
			'<textarea rows="' + (o.rows || 2) + '" data-path="' + path + '" data-type="' + (o.lines ? 'lines' : 'text') + '">' + esc(v) + '</textarea>' +
			(o.help ? '<em>' + o.help + '</em>' : '') + '</label>';
	}
	function select(label, path, choices, rerender) {
		var v = get(path);
		return '<label class="nse-f"><span>' + label + '</span><select data-path="' + path + '" data-type="text"' + (rerender ? ' data-rerender="1"' : '') + '>' +
			choices.map(function (c) {
				return '<option value="' + esc(c[0]) + '"' + (c[0] === v ? ' selected' : '') + '>' + esc(c[1]) + '</option>';
			}).join('') + '</select></label>';
	}
	function check(label, path, help) {
		return '<label class="nse-check nse-wide"><input type="checkbox" data-path="' + path + '" data-type="bool"' + (get(path) ? ' checked' : '') + '> ' +
			'<span><strong>' + label + '</strong>' + (help ? '<em>' + help + '</em>' : '') + '</span></label>';
	}
	function rows(key, cols, addLabel, canRemove) {
		var list = cfg[key] || [];
		var head = '<tr>' + cols.map(function (c) { return '<th>' + c.label + '</th>'; }).join('') + (canRemove ? '<th></th>' : '') + '</tr>';
		var body = list.map(function (row, i) {
			return '<tr>' + cols.map(function (c) {
				var path = key + '.' + i + '.' + c.k;
				if (c.check) {
					return '<td class="nse-c"><input type="checkbox" data-path="' + path + '" data-type="bool"' + (row[c.k] ? ' checked' : '') + '></td>';
				}
				return '<td><input type="' + (c.num ? 'number' : 'text') + '" step="any" data-path="' + path + '" data-type="' + (c.num ? 'number' : 'text') + '" value="' + esc(row[c.k]) + '"></td>';
			}).join('') +
			(canRemove ? '<td><button type="button" class="button-link nse-del" data-action="remove" data-key="' + key + '" data-i="' + i + '" aria-label="Remove row">Remove</button></td>' : '') +
			'</tr>';
		}).join('');
		return '<table class="nse-table"><thead>' + head + '</thead><tbody>' + body + '</tbody></table>' +
			(addLabel ? '<p><button type="button" class="button" data-action="add" data-key="' + key + '">' + addLabel + '</button></p>' : '');
	}
	function card(title, inner, desc) {
		return '<div class="nse-card"><h3>' + title + '</h3>' + (desc ? '<p class="description">' + desc + '</p>' : '') + inner + '</div>';
	}

	function render() {
		normalize();
		var unit = esc(cfg.unit_label || 'unit');
		var fieldChoices = [['off', 'Hidden'], ['optional', 'Optional'], ['required', 'Required']];
		var tplOptions = Object.keys(T).map(function (k) {
			return '<option value="' + k + '"' + (k === cfg.template ? ' selected' : '') + '>' + esc(T[k].label) + '</option>';
		}).join('');

		root.innerHTML =
			card('Start from a template',
				'<div class="nse-row"><select id="nse-tpl">' + tplOptions + '</select> <button type="button" class="button" data-action="load">Load template</button></div>',
				'Loading a template replaces the page text, pricing, measurements, and extras. Business and tracking settings are kept.') +

			card('Business',
				'<div class="nse-grid">' +
				text('Business name', 'business.name') +
				text('Phone', 'business.phone') +
				text('Send leads to', 'business.notify_email', { type: 'email', placeholder: NSE_ADMIN.adminEmail }) +
				text('Webhook URL (optional)', 'business.webhook_url', { type: 'url', placeholder: 'https://hooks.zapier.com/...' }) +
				text('Accent color', 'business.accent', { type: 'color' }) +
				'</div>',
				'Leads are saved under Estimators > Leads, emailed to the address above, and posted to the webhook if set. Use a dark accent color so white text stays readable.') +

			card('Page text',
				'<div class="nse-grid">' +
				text('Headline', 'headline', { wide: true }) +
				area('Intro', 'intro') +
				text('Choices heading', 'options_label') +
				text('Extras heading', 'addons_label') +
				text('Button text', 'cta_text') +
				area('Message after submitting', 'success_message') +
				area('Fine print', 'disclaimer') +
				'</div>') +

			card('Pricing and measurements',
				'<div class="nse-grid">' +
				select('Price by', 'pricing_model', [['area', 'Area (width x length)'], ['linear', 'Length only (fences, edging)']], true) +
				text('Unit name', 'unit_label', { placeholder: 'sq ft' }) +
				text('Minimum job ($)', 'min_job', { type: 'number', num: true, step: 'any' }) +
				text('Round prices to ($)', 'round_to', { type: 'number', num: true }) +
				'</div>' +
				rows('dims', [
					{ k: 'label', label: 'Slider label' },
					{ k: 'min', label: 'Min', num: true },
					{ k: 'max', label: 'Max', num: true },
					{ k: 'step', label: 'Step', num: true },
					{ k: 'default', label: 'Starts at', num: true }
				], null, false)) +

			card('Choices',
				rows('options', [
					{ k: 'name', label: 'Name' },
					{ k: 'note', label: 'Short description' },
					{ k: 'low', label: 'Low $ per ' + unit, num: true },
					{ k: 'high', label: 'High $ per ' + unit, num: true }
				], 'Add choice', true),
				'Visitors pick one. Price is multiplied by the measured ' + unit + '.') +

			card('Extras',
				rows('addons', [
					{ k: 'name', label: 'Name' },
					{ k: 'note', label: 'Short description' },
					{ k: 'low', label: 'Low $', num: true },
					{ k: 'high', label: 'High $', num: true },
					{ k: 'per_unit', label: 'Per ' + unit, check: true }
				], 'Add extra', true),
				'Flat price unless "Per ' + unit + '" is checked.') +

			card('Quote form',
				'<div class="nse-grid">' +
				select('Email', 'fields.email', fieldChoices) +
				select('ZIP code', 'fields.zip', fieldChoices) +
				select('Project address', 'fields.address', fieldChoices) +
				select('Timeline', 'fields.timeline', fieldChoices) +
				select('Project details', 'fields.notes', fieldChoices) +
				area('Timeline choices', 'timeline_choices', { lines: true, rows: 4, help: 'One per line.' }) +
				'</div>',
				'Name and phone are always required.') +

			card('Conversion tracking',
				'<div class="nse-grid">' +
				text('dataLayer event name', 'tracking.event_name', { placeholder: 'project_estimator_lead' }) +
				select('Conversion value', 'tracking.value', [['midpoint', 'Middle of estimate'], ['low', 'Low end of estimate'], ['high', 'High end of estimate'], ['none', 'No value']]) +
				text('Google Ads conversion (send_to)', 'tracking.ads_send_to', { placeholder: 'AW-123456789/AbCdEfGhIjK' }) +
				check('Send GA4 generate_lead event', 'tracking.ga4', 'Fires through gtag.js if it is on the page. Skip this if GA4 is handled in GTM.') +
				check('Enhanced conversions', 'tracking.enhanced', 'Adds the lead\'s email and phone to the conversion so Google can match it. Mention this in the site privacy policy.') +
				'</div>',
				'On every quote request the plugin pushes the event above to the dataLayer for GTM. Fill in send_to only if the site uses gtag.js directly instead of GTM. Ad click IDs and UTM tags are saved with every lead automatically.');

		sync();
	}

	function onEdit(e) {
		var el = e.target;
		if (!el.dataset || !el.dataset.path) return;
		var t = el.dataset.type, v;
		if (t === 'bool') v = el.checked;
		else if (t === 'number') v = el.value === '' ? 0 : parseFloat(el.value);
		else if (t === 'lines') v = el.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
		else v = el.value;
		set(el.dataset.path, v);
		sync();
		if (e.type === 'change' && el.dataset.rerender) render();
	}
	root.addEventListener('input', onEdit);
	root.addEventListener('change', onEdit);

	root.addEventListener('click', function (e) {
		var b = e.target.closest('[data-action]');
		if (!b) return;
		e.preventDefault();
		var a = b.dataset.action;
		if (a === 'load') {
			var key = document.getElementById('nse-tpl').value;
			if (!T[key]) return;
			if (!window.confirm('Replace the current pricing and text with the "' + T[key].label + '" template?')) return;
			var business = cfg.business, tracking = cfg.tracking;
			cfg = clone(T[key].config);
			cfg.business = business;
			cfg.tracking = tracking;
			cfg.template = key;
		} else if (a === 'add') {
			var k = b.dataset.key;
			cfg[k].push(k === 'addons'
				? { name: '', note: '', low: 0, high: 0, per_unit: false }
				: { name: '', note: '', low: 0, high: 0 });
		} else if (a === 'remove') {
			cfg[b.dataset.key].splice(parseInt(b.dataset.i, 10), 1);
		}
		render();
	});

	render();
})();
