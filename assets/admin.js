/* Project Estimator: admin config editor */
(function () {
	'use strict';
	var root = document.getElementById('nse-editor');
	var input = document.getElementById('nse_config_json');
	if (!root || !input || typeof NSE_ADMIN === 'undefined') return;

	var T = NSE_ADMIN.templates;
	var LIB = NSE_ADMIN.library || [];
	var PV = NSE_ADMIN.preview || { scenes: { plan: { label: 'Top-down plan only', variants: [] } }, features: [['none', 'Not shown']] };
	var clone = function (o) { return JSON.parse(JSON.stringify(o)); };
	var cfg = NSE_ADMIN.config ? clone(NSE_ADMIN.config) : clone(NSE_ADMIN.defaults);
	var ap = 0; // Active project tab.

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

	function blankProject() {
		return {
			name: 'New project', note: '', pricing_model: 'area', unit_label: 'sq ft', min_job: 2500, preview: 'plan',
			options_label: 'Choose a style', addons_label: 'Add extras',
			dims: [{ label: 'Width (ft)', min: 5, max: 100, step: 1, default: 20 }, { label: 'Length (ft)', min: 5, max: 100, step: 1, default: 20 }],
			options: [{ name: 'Option 1', note: '', low: 10, high: 20 }],
			addons: [],
			colors_label: 'Choose a color',
			colors: []
		};
	}

	function normalize() {
		// Configs saved before 1.2.0 kept one project at the top level.
		if (!Array.isArray(cfg.projects) || !cfg.projects.length) {
			var legacy = blankProject();
			['pricing_model', 'unit_label', 'min_job', 'options_label', 'addons_label', 'dims', 'options', 'addons'].forEach(function (k) {
				if (cfg[k] !== undefined) legacy[k] = cfg[k];
			});
			legacy.name = 'Project';
			cfg.projects = [legacy];
		}
		['pricing_model', 'unit_label', 'min_job', 'options_label', 'addons_label', 'dims', 'options', 'addons'].forEach(function (k) { delete cfg[k]; });
		cfg.projects.forEach(function (p) {
			p.dims = p.dims || [];
			var need = p.pricing_model === 'linear' ? 1 : 2;
			while (p.dims.length < need) {
				p.dims.push({ label: p.dims.length ? 'Length (ft)' : 'Width (ft)', min: 5, max: 100, step: 1, default: 20 });
			}
			p.dims = p.dims.slice(0, need);
			p.options = p.options || [];
			p.addons = p.addons || [];
			if (!PV.scenes[p.preview]) p.preview = 'plan';
			p.colors = Array.isArray(p.colors) ? p.colors : [];
			if (p.colors_label === undefined) p.colors_label = 'Choose a color';
			var looks = PV.scenes[p.preview].variants.map(function (v) { return v[0]; });
			p.options.forEach(function (o) {
				if (looks.length && looks.indexOf(o.variant) === -1) o.variant = looks[0];
			});
			var feats = PV.features.map(function (f) { return f[0]; });
			p.addons.forEach(function (a) {
				if (feats.indexOf(a.feature) === -1) a.feature = 'none';
			});
		});
		if (ap >= cfg.projects.length) ap = cfg.projects.length - 1;
		if (ap < 0) ap = 0;
		cfg.fields = cfg.fields || {};
		cfg.timeline_choices = cfg.timeline_choices || [];
		cfg.tracking = cfg.tracking || clone(NSE_ADMIN.defaults.tracking);
		if (cfg.project_label === undefined) cfg.project_label = 'What are you planning?';
		if (['range', 'starting', 'gated'].indexOf(cfg.price_display) === -1) cfg.price_display = 'range';
		cfg.booking = cfg.booking && typeof cfg.booking === 'object' ? cfg.booking : {};
		if (cfg.booking.url === undefined) cfg.booking.url = '';
		if (!cfg.booking.label) cfg.booking.label = 'Book your free on-site visit';
		cfg.booking.embed = !!cfg.booking.embed;
		cfg.business = cfg.business || {};
		['cc', 'bcc', 'notify_email'].forEach(function (k) { if (cfg.business[k] === undefined) cfg.business[k] = ''; });
		delete cfg.business.accent;
		if (cfg.design_mode !== 'custom') cfg.design_mode = 'global';
		if (!cfg.design || typeof cfg.design !== 'object') cfg.design = clone(G.design || {});
	}

	/* Field builders */
	function text(label, path, o) {
		o = o || {};
		return '<label class="nse-f' + (o.wide ? ' nse-wide' : '') + '"><span>' + label + '</span>' +
			'<input type="' + (o.type || 'text') + '" data-path="' + path + '" data-type="' + (o.num ? 'number' : 'text') + '"' +
			(o.step ? ' step="' + o.step + '"' : '') +
			(o.placeholder ? ' placeholder="' + esc(o.placeholder) + '"' : '') +
			(o.rerenderTabs ? ' data-tabs="1"' : '') +
			' value="' + esc(get(path)) + '">' + (o.help ? '<em>' + o.help + '</em>' : '') + '</label>';
	}
	function area(label, path, o) {
		o = o || {};
		var v = get(path);
		if (o.lines && Array.isArray(v)) v = v.join('\n');
		return '<label class="nse-f nse-wide"><span>' + label + '</span>' +
			'<textarea rows="' + (o.rows || 2) + '" data-path="' + path + '" data-type="' + (o.lines ? 'lines' : 'text') + '">' + esc(v) + '</textarea>' +
			(o.help ? '<em>' + o.help + '</em>' : '') + '</label>';
	}
	function select(label, path, choices, rerender, numeric) {
		var v = get(path);
		return '<label class="nse-f"><span>' + label + '</span><select data-path="' + path + '" data-type="' + (numeric ? 'number' : 'text') + '"' + (rerender ? ' data-rerender="1"' : '') + '>' +
			choices.map(function (c) {
				return '<option value="' + esc(c[0]) + '"' + (String(c[0]) === String(v) ? ' selected' : '') + '>' + esc(c[1]) + '</option>';
			}).join('') + '</select></label>';
	}
	function check(label, path, help) {
		return '<label class="nse-check nse-wide"><input type="checkbox" data-path="' + path + '" data-type="bool"' + (get(path) ? ' checked' : '') + '> ' +
			'<span><strong>' + label + '</strong>' + (help ? '<em>' + help + '</em>' : '') + '</span></label>';
	}
	function rows(listPath, cols, addLabel, canRemove) {
		var list = get(listPath) || [];
		var head = '<tr>' + cols.map(function (c) { return '<th>' + c.label + '</th>'; }).join('') + (canRemove ? '<th></th>' : '') + '</tr>';
		var body = list.map(function (row, i) {
			return '<tr>' + cols.map(function (c) {
				var path = listPath + '.' + i + '.' + c.k;
				if (c.check) {
					return '<td class="nse-c"><input type="checkbox" data-path="' + path + '" data-type="bool"' + (row[c.k] ? ' checked' : '') + '></td>';
				}
				if (c.color) {
					return '<td class="nse-c"><input type="color" data-path="' + path + '" data-type="text" value="' + esc(row[c.k] || '#FFFFFF') + '"></td>';
				}
				if (c.choices) {
					return '<td><select data-path="' + path + '" data-type="text">' + c.choices.map(function (ch) {
						return '<option value="' + esc(ch[0]) + '"' + (ch[0] === row[c.k] ? ' selected' : '') + '>' + esc(ch[1]) + '</option>';
					}).join('') + '</select></td>';
				}
				return '<td><input type="' + (c.num ? 'number' : 'text') + '" step="any" data-path="' + path + '" data-type="' + (c.num ? 'number' : 'text') + '" value="' + esc(row[c.k]) + '"></td>';
			}).join('') +
			(canRemove ? '<td><button type="button" class="button-link nse-del" data-action="remove-row" data-list="' + listPath + '" data-i="' + i + '">Remove</button></td>' : '') +
			'</tr>';
		}).join('');
		return '<table class="nse-table"><thead>' + head + '</thead><tbody>' + body + '</tbody></table>' +
			(addLabel ? '<p><button type="button" class="button" data-action="add-row" data-list="' + listPath + '">' + addLabel + '</button></p>' : '');
	}
	function card(title, inner, desc) {
		return '<div class="nse-card"><h3>' + title + '</h3>' + (desc ? '<p class="description">' + desc + '</p>' : '') + inner + '</div>';
	}

	function projectEditor() {
		var base = 'projects.' + ap;
		var p = cfg.projects[ap];
		var unit = esc(p.unit_label || 'unit');
		var many = cfg.projects.length > 1;
		var looks = (PV.scenes[p.preview] || { variants: [] }).variants;

		var tabs = '<div class="nse-tabs" role="tablist">' + cfg.projects.map(function (proj, i) {
			return '<button type="button" role="tab" class="nse-tab' + (i === ap ? ' is-active' : '') + '" aria-selected="' + (i === ap) + '" data-action="tab" data-i="' + i + '">' + esc(proj.name || 'Untitled') + '</button>';
		}).join('') + '</div>';

		var adder = '<div class="nse-row nse-adder"><select id="nse-lib"><option value="blank">Blank project type</option>' +
			LIB.map(function (l, i) { return '<option value="' + i + '">' + esc(l.name) + '</option>'; }).join('') +
			'</select> <button type="button" class="button" data-action="add-project">Add project type</button></div>';

		var tools = '<div class="nse-row nse-ptools">' +
			'<button type="button" class="button button-small" data-action="move" data-dir="-1"' + (ap === 0 ? ' disabled' : '') + '>Move left</button>' +
			'<button type="button" class="button button-small" data-action="move" data-dir="1"' + (ap === cfg.projects.length - 1 ? ' disabled' : '') + '>Move right</button>' +
			'<button type="button" class="button button-small" data-action="duplicate">Duplicate</button>' +
			'<button type="button" class="button-link nse-del" data-action="remove-project"' + (many ? '' : ' disabled') + '>Remove this project type</button></div>';

		return tabs + '<div class="nse-panel">' + tools +
			'<div class="nse-grid">' +
			text('Project type name', base + '.name', { rerenderTabs: true }) +
			text('Short description', base + '.note', { help: many ? 'Shown under the name on the project picker.' : 'Only shown when there are 2 or more project types.' }) +
			select('Price by', base + '.pricing_model', [['area', 'Area (width x length)'], ['linear', 'Length only (fences, edging)']], true) +
			select('Live preview', base + '.preview', Object.keys(PV.scenes).map(function (k) { return [k, PV.scenes[k].label]; }), true) +
			text('Unit name', base + '.unit_label', { placeholder: 'sq ft' }) +
			text('Minimum job ($)', base + '.min_job', { type: 'number', num: true, step: 'any' }) +
			text('Choices heading', base + '.options_label') +
			text('Extras heading', base + '.addons_label') +
			'</div>' +
			'<h4>Measurements</h4>' +
			rows(base + '.dims', [
				{ k: 'label', label: 'Slider label' },
				{ k: 'min', label: 'Min', num: true },
				{ k: 'max', label: 'Max', num: true },
				{ k: 'step', label: 'Step', num: true },
				{ k: 'default', label: 'Starts at', num: true }
			], null, false) +
			'<h4>Choices</h4><p class="description">Visitors pick one. Price is multiplied by the measured ' + unit + '.</p>' +
			rows(base + '.options', [
				{ k: 'name', label: 'Name' },
				{ k: 'note', label: 'Short description' },
				{ k: 'low', label: 'Low $ per ' + unit, num: true },
				{ k: 'high', label: 'High $ per ' + unit, num: true }
			].concat(looks.length ? [{ k: 'variant', label: 'Preview look', choices: looks }] : []), 'Add choice', true) +
			'<h4>Colors</h4><p class="description">Optional. Visitors pick one, and the preview repaints the main material. Upcharge % applies to the base price, not extras. Leave the list empty to skip this step.</p>' +
			'<div class="nse-grid">' + text('Colors heading', base + '.colors_label', { placeholder: 'Frame color' }) + '</div>' +
			rows(base + '.colors', [
				{ k: 'name', label: 'Color name' },
				{ k: 'hex', label: 'Swatch', color: true },
				{ k: 'upcharge', label: 'Upcharge %', num: true }
			], 'Add color', true) +
			((NSE_ADMIN.colors && NSE_ADMIN.colors[p.preview] && NSE_ADMIN.colors[p.preview][1].length)
				? '<p><button type="button" class="button-link" data-action="default-colors">Use default ' + esc(PV.scenes[p.preview].label.toLowerCase()) + ' colors</button></p>' : '') +
			'<h4>Extras</h4><p class="description">Flat price unless "Per ' + unit + '" is checked.</p>' +
			rows(base + '.addons', [
				{ k: 'name', label: 'Name' },
				{ k: 'note', label: 'Short description' },
				{ k: 'low', label: 'Low $', num: true },
				{ k: 'high', label: 'High $', num: true },
				{ k: 'per_unit', label: 'Per ' + unit, check: true }
			].concat(p.preview !== 'plan' ? [{ k: 'feature', label: 'Shows in preview as', choices: PV.features }] : []), 'Add extra', true) +
			'</div>' + adder;
	}

	var G = NSE_ADMIN.global || { design: {} };
	var designEdited = NSE_ADMIN.config && NSE_ADMIN.config.design_mode === 'custom';
	var DC = NSE_ADMIN.designChoices || {};
	function pairs(obj) { return Object.keys(obj || {}).map(function (k) { return [k, obj[k]]; }); }

	function designCard() {
		var custom = cfg.design_mode === 'custom';
		var modes = '<div class="nse-row nse-modes">' +
			'<label><input type="radio" name="nse-design-mode" value="global" data-action="design-mode"' + (custom ? '' : ' checked') + '> Use global design settings</label>' +
			'<label><input type="radio" name="nse-design-mode" value="custom" data-action="design-mode"' + (custom ? ' checked' : '') + '> Custom design for this estimator</label>' +
			'</div>';
		if (!custom) {
			return card('Design', modes, 'This estimator uses the defaults from <a href="' + esc(NSE_ADMIN.settingsUrl) + '">Estimators > Settings</a>. Switch to custom to change colors and style just for this estimator.');
		}
		return card('Design', modes +
			'<div class="nse-grid">' +
			select('Style', 'design.style', pairs(DC.style)) +
			select('Layout', 'design.layout', pairs(DC.layout)) +
			text('Accent color', 'design.accent', { type: 'color', help: 'Buttons, selections, and the price bar.' }) +
			text('Text color', 'design.text', { type: 'color' }) +
			text('Secondary text color', 'design.muted', { type: 'color' }) +
			text('Background color', 'design.background', { type: 'color' }) +
			text('Border color', 'design.border', { type: 'color' }) +
			select('Font', 'design.font', pairs(DC.font)) +
			select('Corners', 'design.radius', pairs(DC.radius), false, true) +
			text('Max width (px)', 'design.max_width', { type: 'number', num: true }) +
			check('Show business name', 'design.show_business_name') +
			check('Show step numbers', 'design.show_step_numbers') +
			check('Sticky price bar', 'design.sticky_bar', 'Keeps the price visible at the bottom of the screen while scrolling.') +
			'</div><p><button type="button" class="button-link" data-action="design-reset">Copy the current global design into this estimator</button></p>',
			'Custom design applies only to this estimator.');
	}

	function render() {
		normalize();
		var fieldChoices = [['off', 'Hidden'], ['optional', 'Optional'], ['required', 'Required']];
		var tplOptions = Object.keys(T).map(function (k) {
			return '<option value="' + k + '"' + (k === cfg.template ? ' selected' : '') + '>' + esc(T[k].label) + '</option>';
		}).join('');

		root.innerHTML =
			card('Start from a template',
				'<div class="nse-row"><select id="nse-tpl">' + tplOptions + '</select> <button type="button" class="button" data-action="load">Load template</button></div>',
				'Loading a template replaces the page text and all project types. Business and tracking settings are kept.') +

			card('Business',
				'<div class="nse-grid">' +
				text('Business name', 'business.name') +
				text('Phone', 'business.phone') +
				text('Send leads to', 'business.notify_email', { placeholder: G.notify_email || NSE_ADMIN.adminEmail, help: 'Blank uses the global default.' }) +
				text('CC', 'business.cc', { placeholder: G.cc || 'name@example.com', help: 'Blank uses the global CC.' }) +
				text('BCC', 'business.bcc', { placeholder: G.bcc || 'name@example.com', help: 'Blank uses the global BCC.' }) +
				text('Webhook URL (optional)', 'business.webhook_url', { type: 'url', placeholder: 'https://hooks.zapier.com/...' }) +
				'<div class="nse-f nse-wh-test"><span>&nbsp;</span><div><button type="button" class="button" data-action="webhook-test">Send test lead</button> <span class="nse-wh-result" role="status"></span></div></div>' +
				'</div>',
				'Leads are saved under Estimators > Leads, emailed to the addresses above, and posted to the webhook if set. Separate multiple emails with commas. Defaults live in <a href="' + esc(NSE_ADMIN.settingsUrl) + '">Estimators > Settings</a>.') +

			card('After the quote',
				'<div class="nse-grid">' +
				text('Booking link', 'booking.url', { type: 'url', wide: true, placeholder: (G.booking_url || 'https://calendly.com/your-company/on-site-visit'), help: G.booking_url ? 'Blank uses the site-wide link from Settings.' : 'Calendly, a Google Calendar booking page, Cal.com, Acuity, and others. You can also set one site-wide link in Settings.' }) +
				text('Button text', 'booking.label', { placeholder: 'Book your free on-site visit' }) +
				check('Show the booking page on the confirmation screen', 'booking.embed', 'Customers pick a time without leaving the page. Some booking tools do not allow this; the button always works.') +
				'</div>',
				'Shown right after a quote request, in the customer email, and in the PDF. Calendly and Cal.com links get the customer\'s name and email filled in.') +

			designCard() +

			card('Page text',
				'<div class="nse-grid">' +
				text('Headline', 'headline', { wide: true }) +
				area('Intro', 'intro') +
				text('Project type question', 'project_label', { help: 'Shown when there are 2 or more project types.' }) +
				text('Button text', 'cta_text') +
				text('Round prices to ($)', 'round_to', { type: 'number', num: true }) +
				select('Price display', 'price_display', [['range', 'Show the price range as they go'], ['starting', 'Show "Starting at" only, full range after the request'], ['gated', 'Hide the price until they request a quote']]) +
				area('Message after submitting', 'success_message') +
				area('Fine print', 'disclaimer') +
				'</div>') +

			card('Project types', projectEditor(),
				'Each project type has its own sizes, choices, extras, and minimum job. With 2 or more, visitors pick a project type first.') +

			card('Quote form',
				'<div class="nse-grid">' +
				select('Email', 'fields.email', fieldChoices) +
				select('ZIP code', 'fields.zip', fieldChoices) +
				select('Project address', 'fields.address', fieldChoices) +
				select('Timeline', 'fields.timeline', fieldChoices) +
				select('Project details', 'fields.notes', fieldChoices) +
				select('Photos', 'fields.photos', fieldChoices) +
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
				'On every quote request the plugin pushes the event above to the dataLayer for GTM, including the project type. Fill in send_to only if the site uses gtag.js directly instead of GTM. Ad click IDs and UTM tags are saved with every lead automatically.');

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
		if (el.dataset.path.indexOf('design.') === 0) designEdited = true;
		sync();
		if (el.dataset.tabs) {
			var tab = root.querySelector('.nse-tab.is-active');
			if (tab) tab.textContent = v || 'Untitled';
		}
		if (e.type === 'change' && el.dataset.rerender) render();
	}
	root.addEventListener('input', onEdit);
	root.addEventListener('change', onEdit);

	root.addEventListener('click', function (e) {
		var b = e.target.closest('[data-action]');
		if (!b || b.disabled) return;
		e.preventDefault();
		var a = b.dataset.action;
		var P = cfg.projects;

		if (a === 'load') {
			var key = document.getElementById('nse-tpl').value;
			if (!T[key]) return;
			if (!window.confirm('Replace the page text and all project types with the "' + T[key].label + '" template?')) return;
			var keep = { business: cfg.business, tracking: cfg.tracking };
			cfg = clone(T[key].config);
			cfg.business = keep.business;
			cfg.tracking = keep.tracking;
			cfg.template = key;
			ap = 0;
		} else if (a === 'tab') {
			ap = parseInt(b.dataset.i, 10);
		} else if (a === 'add-project') {
			if (P.length >= 12) { window.alert('An estimator can have up to 12 project types.'); return; }
			var pick = document.getElementById('nse-lib').value;
			P.push(pick === 'blank' ? blankProject() : clone(LIB[parseInt(pick, 10)]));
			ap = P.length - 1;
		} else if (a === 'duplicate') {
			if (P.length >= 12) { window.alert('An estimator can have up to 12 project types.'); return; }
			var copy = clone(P[ap]);
			copy.name = copy.name + ' (copy)';
			P.splice(ap + 1, 0, copy);
			ap = ap + 1;
		} else if (a === 'move') {
			var to = ap + parseInt(b.dataset.dir, 10);
			if (to < 0 || to >= P.length) return;
			var item = P.splice(ap, 1)[0];
			P.splice(to, 0, item);
			ap = to;
		} else if (a === 'remove-project') {
			if (P.length < 2) return;
			if (!window.confirm('Remove the "' + P[ap].name + '" project type?')) return;
			P.splice(ap, 1);
		} else if (a === 'add-row') {
			var list = get(b.dataset.list);
			if (/\.colors$/.test(b.dataset.list)) { list.push({ name: '', hex: '#FFFFFF', upcharge: 0 }); render(); return; }
			list.push(/\.addons$/.test(b.dataset.list)
				? { name: '', note: '', low: 0, high: 0, per_unit: false, feature: 'none' }
				: { name: '', note: '', low: 0, high: 0, variant: '' });
		} else if (a === 'webhook-test') {
			var out = root.querySelector('.nse-wh-result');
			var url = (cfg.business && cfg.business.webhook_url) || '';
			if (!url) { out.textContent = 'Enter a webhook URL first.'; out.className = 'nse-wh-result is-bad'; return; }
			b.disabled = true; out.textContent = 'Sending...'; out.className = 'nse-wh-result';
			var fd = new FormData();
			fd.append('action', 'pe_webhook_test'); fd.append('nonce', NSE_ADMIN.webhookNonce);
			fd.append('url', url); fd.append('estimator', NSE_ADMIN.postId || 0);
			fetch(NSE_ADMIN.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					out.textContent = (j && j.data && j.data.message) || 'Unexpected response.';
					out.className = 'nse-wh-result ' + (j && j.success ? 'is-ok' : 'is-bad');
				})
				.catch(function () { out.textContent = 'Could not reach WordPress. Try again.'; out.className = 'nse-wh-result is-bad'; })
				.then(function () { b.disabled = false; });
			return;
		} else if (a === 'design-mode') {
			var mode = b.value;
			if (mode === cfg.design_mode) return;
			if (mode === 'custom' && !designEdited) {
				cfg.design = clone(G.design);
			}
			cfg.design_mode = mode;
		} else if (a === 'design-reset') {
			if (!window.confirm('Replace this estimator\'s design with the current global design?')) return;
			cfg.design = clone(G.design);
		} else if (a === 'default-colors') {
			var dc = NSE_ADMIN.colors[P[ap].preview];
			if (P[ap].colors.length && !window.confirm('Replace this project type\'s colors with the defaults?')) return;
			P[ap].colors_label = dc[0];
			P[ap].colors = clone(dc[1]);
		} else if (a === 'remove-row') {
			get(b.dataset.list).splice(parseInt(b.dataset.i, 10), 1);
		}
		render();
	});

	render();
})();
