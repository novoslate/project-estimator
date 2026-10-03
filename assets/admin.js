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
			addons: []
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
	function rows(listPath, cols, addLabel, canRemove) {
		var list = get(listPath) || [];
		var head = '<tr>' + cols.map(function (c) { return '<th>' + c.label + '</th>'; }).join('') + (canRemove ? '<th></th>' : '') + '</tr>';
		var body = list.map(function (row, i) {
			return '<tr>' + cols.map(function (c) {
				var path = listPath + '.' + i + '.' + c.k;
				if (c.check) {
					return '<td class="nse-c"><input type="checkbox" data-path="' + path + '" data-type="bool"' + (row[c.k] ? ' checked' : '') + '></td>';
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
				text('Send leads to', 'business.notify_email', { type: 'email', placeholder: NSE_ADMIN.adminEmail }) +
				text('Webhook URL (optional)', 'business.webhook_url', { type: 'url', placeholder: 'https://hooks.zapier.com/...' }) +
				text('Accent color', 'business.accent', { type: 'color' }) +
				'</div>',
				'Leads are saved under Estimators > Leads, emailed to the address above, and posted to the webhook if set. Use a dark accent color so white text stays readable.') +

			card('Page text',
				'<div class="nse-grid">' +
				text('Headline', 'headline', { wide: true }) +
				area('Intro', 'intro') +
				text('Project type question', 'project_label', { help: 'Shown when there are 2 or more project types.' }) +
				text('Button text', 'cta_text') +
				text('Round prices to ($)', 'round_to', { type: 'number', num: true }) +
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
			list.push(/\.addons$/.test(b.dataset.list)
				? { name: '', note: '', low: 0, high: 0, per_unit: false, feature: 'none' }
				: { name: '', note: '', low: 0, high: 0, variant: '' });
		} else if (a === 'remove-row') {
			get(b.dataset.list).splice(parseInt(b.dataset.i, 10), 1);
		}
		render();
	});

	render();
})();
