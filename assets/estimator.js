/* Project Estimator: front-end widget */
(function () {
	'use strict';
	var SVGNS = 'http://www.w3.org/2000/svg';

	function h(tag, attrs, kids) {
		var el = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'text') el.textContent = v;
			else if (k === 'class') el.className = v;
			else if (k.indexOf('on') === 0) el.addEventListener(k.slice(2), v);
			else el.setAttribute(k, v === true ? '' : v);
		});
		(kids || []).forEach(function (c) {
			if (c) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return el;
	}
	function s(tag, attrs, text) {
		var el = document.createElementNS(SVGNS, tag);
		Object.keys(attrs || {}).forEach(function (k) { el.setAttribute(k, attrs[k]); });
		if (text) el.textContent = text;
		return el;
	}
	function money(n) { return '$' + Math.round(n).toLocaleString('en-US'); }
	function num(n) { return (Math.round(n * 10) / 10).toLocaleString('en-US'); }

	/* Tracking helpers */
	function push(obj) {
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push(obj);
	}
	function e164(phone) {
		var d = String(phone || '').replace(/\D/g, '');
		if (d.length === 10) return '+1' + d;
		if (d.length === 11 && d.charAt(0) === '1') return '+' + d;
		return d ? '+' + d : '';
	}
	function attribution() {
		try { return typeof window.peAttribution === 'function' ? window.peAttribution() : {}; } catch (e) { return {}; }
	}

	function init(root) {
		var cfg;
		try { cfg = JSON.parse(root.getAttribute('data-config')); } catch (e) { return; }
		root.innerHTML = '';

		var uid = 'nse' + Math.random().toString(36).slice(2, 8);
		var projects = Array.isArray(cfg.projects) && cfg.projects.length ? cfg.projects : [{
			name: 'Project', pricing_model: cfg.pricing_model, unit_label: cfg.unit_label, min_job: cfg.min_job,
			options_label: cfg.options_label, addons_label: cfg.addons_label,
			dims: cfg.dims || [], options: cfg.options || [], addons: cfg.addons || []
		}];
		var multi = projects.length > 1;
		var biz = cfg.business || {};
		var proj, opts, addons, dims, unit, linear, colors;
		var state = { proj: 0, opt: 0, color: 0, dims: [], addons: {} };

		function useProject(i) {
			state.proj = i;
			proj = projects[i];
			opts = proj.options || [];
			addons = proj.addons || [];
			dims = proj.dims || [];
			unit = proj.unit_label || 'sq ft';
			linear = proj.pricing_model === 'linear';
			colors = proj.colors || [];
			state.opt = 0;
			state.color = 0;
			state.dims = dims.map(function (d) { return Number(d.default); });
			state.addons = {};
		}
		useProject(0);

		var track = cfg.tracking || {};
		var estId = Number(root.getAttribute('data-id'));
		var estName = root.getAttribute('data-name') || '';
		var started = false;
		function markStarted() {
			if (started) return;
			started = true;
			push({ event: 'project_estimator_start', estimator_id: estId, estimator_name: estName, project_type: proj.name });
		}
		root.addEventListener('click', markStarted, { once: true });
		root.addEventListener('input', markStarted, { once: true });

		/* Same math as NSE_Config::estimate() in PHP */
		function qty() { return linear ? state.dims[0] : state.dims[0] * (state.dims[1] || 0); }
		function estimate() {
			var q = qty(), o = opts[state.opt] || { low: 0, high: 0 };
			var col = colors[state.color];
			var pct = col ? (Number(col.upcharge) || 0) / 100 : 0;
			var low = q * o.low * (1 + pct), high = q * o.high * (1 + pct);
			addons.forEach(function (a, i) {
				if (!state.addons[i]) return;
				var m = a.per_unit ? q : 1;
				low += a.low * m; high += a.high * m;
			});
			var r = Math.max(1, cfg.round_to || 1);
			var rnd = function (n) { return Math.round(n / r) * r; };
			var min = proj.min_job || 0;
			return {
				low: Math.max(rnd(low), min),
				high: Math.max(rnd(high), rnd(min * 1.3))
			};
		}

		function stepTitle(label, id) {
			return h('h3', { class: 'nse-step', id: id }, [h('span', { class: 'nse-n', 'aria-hidden': 'true' }), label]);
		}
		function renumber() {
			var ns = root.querySelectorAll('.nse-n');
			for (var i = 0; i < ns.length; i++) ns[i].textContent = String(i + 1);
		}

		/* Header */
		root.appendChild(h('div', { class: 'nse-head' }, [
			biz.name && (!cfg.design || cfg.design.show_business_name !== false) ? h('div', { class: 'nse-biz', text: biz.name }) : null,
			h('h2', { class: 'nse-title', text: cfg.headline }),
			cfg.intro ? h('p', { class: 'nse-intro', text: cfg.intro }) : null
		]));

		/* Step: project type (only with 2 or more) */
		var projBtns = [];
		if (multi) {
			root.appendChild(h('section', { class: 'nse-sec', 'aria-labelledby': uid + '-p' }, [
				stepTitle(cfg.project_label || 'What are you planning?', uid + '-p'),
				h('div', { class: 'nse-options nse-projects' }, projects.map(function (p, i) {
					var b = h('button', { type: 'button', class: 'nse-opt nse-proj', onclick: function () {
						if (i === state.proj) return;
						useProject(i);
						buildProject();
						update();
					} }, [
						h('strong', { text: p.name }),
						p.note ? h('span', { text: p.note }) : null
					]);
					projBtns.push(b);
					return b;
				}))
			]));
		}

		/* Project-specific steps: rebuilt when the project type changes */
		var projBody = h('div', { class: 'nse-project-body' });
		root.appendChild(projBody);
		var optBtns = [], colorBtns = [], outs = [], svg, qtyEl, chipsEl, viewBtns = [];
		var view = '3d';
		function has3d() { return (proj.preview || 'plan') !== 'plan' && !!window.PEPreview; }

		function buildProject() {
			projBody.innerHTML = '';
			optBtns = [];
			outs = [];

			/* Choices */
			if (opts.length) {
				projBody.appendChild(h('section', { class: 'nse-sec', 'aria-labelledby': uid + '-o' }, [
					stepTitle(proj.options_label || 'Choose a style', uid + '-o'),
					h('div', { class: 'nse-options' }, opts.map(function (o, i) {
						var b = h('button', { type: 'button', class: 'nse-opt', onclick: function () { state.opt = i; update(); } }, [
							h('strong', { text: o.name }),
							o.note ? h('span', { text: o.note }) : null
						]);
						optBtns.push(b);
						return b;
					}))
				]));
			}

			/* Colors */
			colorBtns = [];
			if (colors.length) {
				projBody.appendChild(h('section', { class: 'nse-sec', 'aria-labelledby': uid + '-c' }, [
					stepTitle(proj.colors_label || 'Choose a color', uid + '-c'),
					h('div', { class: 'nse-colors' }, colors.map(function (c, i) {
						var b = h('button', { type: 'button', class: 'nse-color', onclick: function () { state.color = i; update(); } }, [
							h('span', { class: 'nse-swatch', style: 'background:' + c.hex, 'aria-hidden': 'true' }),
							h('span', { class: 'nse-color-t' }, [
								h('span', { text: c.name }),
								Number(c.upcharge) ? h('small', { text: '+' + Number(c.upcharge) + '%' }) : null
							])
						]);
						colorBtns.push(b);
						return b;
					}))
				]));
			}

			/* Measurements */
			svg = s('svg', { viewBox: '0 0 400 220', class: 'nse-plan', role: 'img', 'aria-label': 'Diagram of your project size' });
			var sliders = dims.map(function (d, i) {
				var out = h('output', {});
				outs.push(out);
				var inp = h('input', {
					type: 'range', min: d.min, max: d.max, step: d.step, value: d.default,
					'aria-label': d.label,
					oninput: function (e) { state.dims[i] = parseFloat(e.target.value); update(); }
				});
				return h('label', { class: 'nse-range' }, [h('span', { class: 'nse-range-top' }, [h('span', { text: d.label }), out]), inp]);
			});
			qtyEl = h('p', { class: 'nse-qty' });
			chipsEl = h('p', { class: 'nse-chips', hidden: true });
			viewBtns = [];
			var toggle = null;
			if (has3d()) {
				toggle = h('div', { class: 'nse-views', role: 'group', 'aria-label': 'Preview view' }, [['3d', '3D view'], ['plan', 'Top view']].map(function (v) {
					var b = h('button', { type: 'button', class: 'nse-view', 'data-view': v[0], text: v[1], onclick: function () { view = v[0]; update(); } });
					viewBtns.push(b);
					return b;
				}));
			}
			projBody.appendChild(h('section', { class: 'nse-sec', 'aria-labelledby': uid + '-m' }, [
				stepTitle('Set the size', uid + '-m'),
				h('div', { class: 'nse-box' }, [
					h('div', { class: 'nse-visual' }, [svg, toggle]),
					chipsEl,
					has3d() ? h('p', { class: 'nse-caption', text: 'Illustration for reference. Colors and details are finalized with you on site.' }) : null,
					h('div', { class: 'nse-sliders' }, sliders), qtyEl
				])
			]));

			/* Extras */
			if (addons.length) {
				projBody.appendChild(h('section', { class: 'nse-sec', 'aria-labelledby': uid + '-a' }, [
					stepTitle(proj.addons_label || 'Add extras', uid + '-a'),
					h('div', { class: 'nse-addons' }, addons.map(function (a, i) {
						return h('label', { class: 'nse-addon' }, [
							h('input', { type: 'checkbox', onchange: function (e) { state.addons[i] = e.target.checked; update(); } }),
							h('span', { class: 'nse-addon-t' }, [h('span', { text: a.name }), a.note ? h('small', { text: a.note }) : null]),
							h('span', { class: 'nse-addon-p', text: a.per_unit ? money(a.low) + ' per ' + unit : '+' + money(a.low) })
						]);
					}))
				]));
			}
			renumber();
		}

		function drawPlan() {
			while (svg.firstChild) svg.removeChild(svg.firstChild);
			svg.appendChild(s('rect', { x: 0, y: 0, width: 400, height: 220, class: 'nse-ground' }));
			if (linear) {
				var maxL = dims[0].max, len = Math.max(8, (state.dims[0] / maxL) * 360), x0 = (400 - len) / 2;
				svg.appendChild(s('rect', { x: x0, y: 96, width: len, height: 14, class: 'nse-shape' }));
				var posts = Math.max(2, Math.round(state.dims[0] / 8) + 1);
				for (var i = 0; i < posts; i++) {
					var px = x0 + (len - 6) * (i / (posts - 1));
					svg.appendChild(s('rect', { x: px, y: 88, width: 6, height: 30, class: 'nse-post' }));
				}
				svg.appendChild(s('text', { x: 200, y: 150, 'text-anchor': 'middle', class: 'nse-dim' }, num(state.dims[0]) + ' ft'));
				return;
			}
			var scale = Math.min(330 / dims[0].max, 170 / dims[1].max);
			var w = Math.max(6, state.dims[0] * scale), hh = Math.max(6, state.dims[1] * scale);
			var x = (400 - w) / 2 - 10, y = (200 - hh) / 2;
			svg.appendChild(s('rect', { x: x + 6, y: y + 6, width: w, height: hh, class: 'nse-shadow' }));
			svg.appendChild(s('rect', { x: x, y: y, width: w, height: hh, class: 'nse-shape' }));
			svg.appendChild(s('text', { x: x + w / 2, y: y + hh + 18, 'text-anchor': 'middle', class: 'nse-dim' }, num(state.dims[0]) + ' ft'));
			svg.appendChild(s('text', { x: x + w + 10, y: y + hh / 2 + 4, class: 'nse-dim' }, num(state.dims[1]) + ' ft'));
		}

		/* Step: quote form */
		var F = cfg.fields || {};
		var inputs = {};
		function field(key, label, el, mode, full) {
			inputs[key] = el;
			el.id = uid + '-' + key;
			if (mode === 'required') el.setAttribute('aria-required', 'true');
			return h('div', { class: 'nse-field' + (full ? ' nse-full' : '') }, [
				h('label', { for: el.id, text: label + (mode === 'optional' ? ' (optional)' : '') }), el
			]);
		}
		var formKids = [
			field('name', 'Name', h('input', { autocomplete: 'name' }), 'required'),
			field('phone', 'Phone', h('input', { type: 'tel', autocomplete: 'tel' }), 'required')
		];
		if (F.email && F.email !== 'off') formKids.push(field('email', 'Email', h('input', { type: 'email', autocomplete: 'email' }), F.email));
		if (F.zip && F.zip !== 'off') formKids.push(field('zip', 'ZIP code', h('input', { inputmode: 'numeric', autocomplete: 'postal-code', maxlength: '5' }), F.zip));
		if (F.address && F.address !== 'off') formKids.push(field('address', 'Project address', h('input', { autocomplete: 'street-address' }), F.address, true));
		if (F.timeline && F.timeline !== 'off' && (cfg.timeline_choices || []).length) {
			var sel = h('select', {}, [F.timeline === 'optional' ? h('option', { value: '', text: 'Choose one' }) : null]
				.concat(cfg.timeline_choices.map(function (t) { return h('option', { value: t, text: t }); })));
			formKids.push(field('timeline', 'When do you want it done?', sel, F.timeline, true));
		}
		if (F.notes && F.notes !== 'off') formKids.push(field('notes', 'Anything we should know?', h('textarea', { rows: '3' }), F.notes, true));

		var hp = h('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' });
		formKids.push(h('div', { class: 'nse-hp', 'aria-hidden': 'true' }, [hp]));
		var err = h('p', { class: 'nse-err', role: 'alert' });
		var btn = h('button', { type: 'submit', class: 'nse-btn', text: cfg.cta_text || 'Send my quote request' });
		formKids.push(err, h('div', { class: 'nse-full' }, [btn]));

		var form = h('form', { class: 'nse-form', novalidate: true }, formKids);
		var done = h('div', { class: 'nse-done', tabindex: '-1', hidden: true });
		var quoteSec = h('section', { class: 'nse-sec', 'aria-labelledby': uid + '-q' }, [
			stepTitle('Get your exact quote', uid + '-q'), form, done,
			cfg.disclaimer || biz.phone ? h('p', { class: 'nse-fine', text: [cfg.disclaimer, biz.phone ? 'Prefer to talk? Call ' + biz.phone + '.' : ''].filter(Boolean).join(' ') }) : null
		]);
		root.appendChild(quoteSec);
		buildProject();

		/* Sticky price bar */
		var rangeEl = h('strong', {});
		root.appendChild(h('div', { class: 'nse-bar', role: 'status', 'aria-live': 'polite' }, [
			h('div', {}, [h('small', { text: 'Estimated price range' }), rangeEl]),
			h('button', { type: 'button', class: 'nse-bar-btn', text: 'Get exact quote', onclick: function () {
				quoteSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
				if (inputs.name && !form.hidden) setTimeout(function () { inputs.name.focus({ preventScroll: true }); }, 400);
			} })
		]));

		function previewOpts() {
			var o = opts[state.opt];
			return {
				scene: proj.preview,
				variant: o && o.variant ? o.variant : '',
				dims: state.dims.slice(),
				max: dims.map(function (d) { return Number(d.max); }),
				features: addons.filter(function (a, i) { return state.addons[i]; }).map(function (a) { return a.feature || 'none'; }),
				color: colors[state.color] ? colors[state.color].hex : null
			};
		}

		/* Render the 3D illustration off screen and return it as a JPEG data URL for the PDF ('' if unavailable). */
		function snapshot() {
			return new Promise(function (resolve) {
				var done = false;
				function finish(v) { if (!done) { done = true; resolve(v); } }
				try {
					if (!has3d()) return finish('');
					/* Wider frame than on screen so the PDF image spans the page width without taking the whole page. */
					var W = 1600, H = 720;
					var off = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
					var po = previewOpts();
					po.height = 180;
					window.PEPreview.render(off, po);
					off.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
					off.setAttribute('width', W);
					off.setAttribute('height', H);
					var img = new Image();
					img.onload = function () {
						try {
							var cv = document.createElement('canvas');
							cv.width = W; cv.height = H;
							var ctx = cv.getContext('2d');
							ctx.fillStyle = '#E7E0CF';
							ctx.fillRect(0, 0, W, H);
							ctx.drawImage(img, 0, 0, W, H);
							var url = cv.toDataURL('image/jpeg', 0.86);
							finish(url.length < 2900000 ? url : '');
						} catch (e) { finish(''); }
					};
					img.onerror = function () { finish(''); };
					img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(off));
					setTimeout(function () { finish(''); }, 4000);
				} catch (e) { finish(''); }
			});
		}

		function drawVisual() {
			var picked = addons.filter(function (a, i) { return state.addons[i]; });
			if (has3d() && view === '3d') {
				var o = opts[state.opt];
				window.PEPreview.render(svg, previewOpts());
				svg.setAttribute('aria-label', 'Illustration of your ' + proj.name.toLowerCase() + (o ? ', ' + o.name : '') + (colors[state.color] ? ', ' + colors[state.color].name : ''));
			} else {
				svg.setAttribute('viewBox', '0 0 400 220');
				svg.setAttribute('aria-label', 'Top-down diagram of your project size');
				drawPlan();
			}
			viewBtns.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-view') === view ? 'true' : 'false'); });
			chipsEl.textContent = picked.length ? 'Includes: ' + picked.map(function (a) { return a.name; }).join(', ') : '';
			chipsEl.hidden = !picked.length;
		}

		function fireConversion(result, payload) {
			var low = Number(result.low) || 0, high = Number(result.high) || 0;
			var value = { low: low, high: high, midpoint: Math.round((low + high) / 2), none: 0 }[track.value || 'midpoint'] || 0;
			var leadId = result.lead_id ? String(result.lead_id) : '';
			var opt = opts[state.opt];
			var userData = null;
			if (track.enhanced) {
				userData = {};
				if (payload.email) userData.email = payload.email.toLowerCase();
				if (payload.phone) userData.phone_number = e164(payload.phone);
			}

			var evt = {
				event: track.event_name || 'project_estimator_lead',
				estimator_id: estId,
				estimator_name: estName,
				lead_id: leadId,
				project_type: proj.name,
				project_option: opt ? opt.name : '',
				project_color: colors[state.color] ? colors[state.color].name : '',
				estimate_low: low,
				estimate_high: high,
				value: value,
				currency: 'USD'
			};
			if (userData) evt.user_data = userData;
			push(evt);

			if (typeof window.gtag === 'function') {
				if (userData) window.gtag('set', 'user_data', userData);
				if (track.ga4) {
					window.gtag('event', 'generate_lead', { value: value, currency: 'USD', lead_id: leadId, estimator_name: estName, project_type: proj.name });
				}
				if (track.ads_send_to) {
					window.gtag('event', 'conversion', { send_to: track.ads_send_to, value: value, currency: 'USD', transaction_id: leadId });
				}
			}
		}

		function update() {
			projBtns.forEach(function (b, i) { b.setAttribute('aria-pressed', i === state.proj ? 'true' : 'false'); });
			optBtns.forEach(function (b, i) { b.setAttribute('aria-pressed', i === state.opt ? 'true' : 'false'); });
			colorBtns.forEach(function (b, i) { b.setAttribute('aria-pressed', i === state.color ? 'true' : 'false'); });
			outs.forEach(function (o, i) { o.textContent = num(state.dims[i]) + ' ft'; });
			qtyEl.textContent = num(qty()) + ' ' + unit;
			var e = estimate();
			rangeEl.textContent = money(e.low) + ' to ' + money(e.high);
			drawVisual();
		}

		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var v = function (k) { return inputs[k] ? inputs[k].value.trim() : ''; };
			var fail = function (msg, k) { err.textContent = msg; if (k && inputs[k]) inputs[k].focus(); };
			err.textContent = '';
			if (!v('name')) return fail('Enter your name.', 'name');
			if (v('phone').replace(/\D/g, '').length < 10) return fail('Enter a 10 digit phone number.', 'phone');
			if (v('email') && !/^\S+@\S+\.\S+$/.test(v('email'))) return fail('Check the email address format.', 'email');
			if (v('zip') && !/^\d{5}$/.test(v('zip'))) return fail('Enter a 5 digit ZIP code.', 'zip');
			var labels = { email: 'email address', zip: 'ZIP code', address: 'project address', timeline: 'timeline', notes: 'project details' };
			for (var k in labels) {
				if (F[k] === 'required' && inputs[k] && !v(k)) return fail('Enter your ' + labels[k] + '.', k);
			}

			btn.disabled = true;
			btn.textContent = 'Sending...';
			var payload = {
				estimator_id: Number(root.getAttribute('data-id')),
				ts: Number(root.getAttribute('data-ts')),
				website: hp.value,
				name: v('name'), phone: v('phone'), email: v('email'), zip: v('zip'),
				address: v('address'), timeline: v('timeline'), notes: v('notes'),
				project: state.proj,
				option: state.opt,
				color: state.color,
				dims: state.dims,
				addons: Object.keys(state.addons).filter(function (i) { return state.addons[i]; }).map(Number),
				page: window.location.href,
				attribution: attribution()
			};
			snapshot()
				.then(function (illustration) {
					payload.illustration = illustration;
					return fetch(root.getAttribute('data-endpoint'), {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(payload)
					});
				})
				.then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
				.then(function (res) {
					if (!res.ok || !res.j.ok) {
						var msg = res.j && res.j.message ? String(res.j.message) : '';
						/* Server crashes come back as HTML; never show that to visitors. */
						throw new Error(/[<>]/.test(msg) || msg.length > 200 ? '' : msg);
					}
					form.hidden = true;
					var o = opts[state.opt];
					var picked = addons.filter(function (a, i) { return state.addons[i]; }).map(function (a) { return a.name; });
					var list = h('ul', {}, [
						h('li', { text: proj.name + (o ? ': ' + o.name : '') + (colors[state.color] ? ' in ' + colors[state.color].name : '') + ', ' + num(qty()) + ' ' + unit }),
						h('li', { text: picked.length ? 'Extras: ' + picked.join(', ') : 'No extras' }),
						res.j.low ? h('li', { text: 'Estimate: ' + money(res.j.low) + ' to ' + money(res.j.high) }) : null
					]);
					done.appendChild(h('h4', { text: 'Request sent' }));
					done.appendChild(h('p', { text: cfg.success_message }));
					done.appendChild(list);
					if (res.j.pdf_url) {
						done.appendChild(h('p', { class: 'nse-pdf' }, [
							h('a', { class: 'nse-pdf-btn', href: res.j.pdf_url, target: '_blank', rel: 'noopener', text: 'Download your estimate (PDF)' })
						]));
						if (payload.email) done.appendChild(h('p', { class: 'nse-pdf-note', text: 'We also emailed a copy to ' + payload.email + '.' }));
					}
					done.hidden = false;
					done.focus();
					fireConversion(res.j, payload);
				})
				.catch(function (x) {
					fail(x.message || ('Your request did not go through. Please try again' + (biz.phone ? ' or call ' + biz.phone : '') + '.'));
					btn.disabled = false;
					btn.textContent = cfg.cta_text || 'Send my quote request';
				});
		});

		update();
	}

	function boot() {
		var els = document.querySelectorAll('.nse[data-config]');
		for (var i = 0; i < els.length; i++) init(els[i]);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();
