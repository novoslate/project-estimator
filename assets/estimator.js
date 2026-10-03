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

	function init(root) {
		var cfg;
		try { cfg = JSON.parse(root.getAttribute('data-config')); } catch (e) { return; }
		root.innerHTML = '';

		var uid = 'nse' + Math.random().toString(36).slice(2, 8);
		var opts = cfg.options || [], addons = cfg.addons || [], dims = cfg.dims || [];
		var biz = cfg.business || {};
		var unit = cfg.unit_label || 'sq ft';
		var linear = cfg.pricing_model === 'linear';
		var state = { opt: 0, dims: dims.map(function (d) { return Number(d.default); }), addons: {} };
		if (biz.accent) root.style.setProperty('--nse-accent', biz.accent);

		/* Same math as NSE_Config::estimate() in PHP */
		function qty() { return linear ? state.dims[0] : state.dims[0] * (state.dims[1] || 0); }
		function estimate() {
			var q = qty(), o = opts[state.opt] || { low: 0, high: 0 };
			var low = q * o.low, high = q * o.high;
			addons.forEach(function (a, i) {
				if (!state.addons[i]) return;
				var m = a.per_unit ? q : 1;
				low += a.low * m; high += a.high * m;
			});
			var r = Math.max(1, cfg.round_to || 1);
			var rnd = function (n) { return Math.round(n / r) * r; };
			return {
				low: Math.max(rnd(low), cfg.min_job || 0),
				high: Math.max(rnd(high), rnd((cfg.min_job || 0) * 1.3))
			};
		}

		var stepNo = 0;
		function stepTitle(label, id) {
			stepNo++;
			return h('h3', { class: 'nse-step', id: id }, [h('span', { class: 'nse-n', 'aria-hidden': 'true', text: String(stepNo) }), label]);
		}

		/* Header */
		root.appendChild(h('div', { class: 'nse-head' }, [
			biz.name ? h('div', { class: 'nse-biz', text: biz.name }) : null,
			h('h2', { class: 'nse-title', text: cfg.headline }),
			cfg.intro ? h('p', { class: 'nse-intro', text: cfg.intro }) : null
		]));

		/* Step: choices */
		var optBtns = [];
		if (opts.length) {
			root.appendChild(h('section', { class: 'nse-sec', 'aria-labelledby': uid + '-o' }, [
				stepTitle(cfg.options_label, uid + '-o'),
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

		/* Step: measurements */
		var svg = s('svg', { viewBox: '0 0 400 220', class: 'nse-plan', role: 'img', 'aria-label': 'Diagram of your project size' });
		var outs = [];
		var sliders = dims.map(function (d, i) {
			var out = h('output', {});
			outs.push(out);
			var inp = h('input', {
				type: 'range', min: d.min, max: d.max, step: d.step, value: d.default,
				oninput: function (e) { state.dims[i] = parseFloat(e.target.value); update(); }
			});
			return h('label', { class: 'nse-range' }, [h('span', { class: 'nse-range-top' }, [h('span', { text: d.label }), out]), inp]);
		});
		var qtyEl = h('p', { class: 'nse-qty' });
		root.appendChild(h('section', { class: 'nse-sec', 'aria-labelledby': uid + '-m' }, [
			stepTitle('Set the size', uid + '-m'),
			h('div', { class: 'nse-box' }, [svg, h('div', { class: 'nse-sliders' }, sliders), qtyEl])
		]));

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

		/* Step: extras */
		if (addons.length) {
			root.appendChild(h('section', { class: 'nse-sec', 'aria-labelledby': uid + '-a' }, [
				stepTitle(cfg.addons_label, uid + '-a'),
				h('div', { class: 'nse-addons' }, addons.map(function (a, i) {
					return h('label', { class: 'nse-addon' }, [
						h('input', { type: 'checkbox', onchange: function (e) { state.addons[i] = e.target.checked; update(); } }),
						h('span', { class: 'nse-addon-t' }, [h('span', { text: a.name }), a.note ? h('small', { text: a.note }) : null]),
						h('span', { class: 'nse-addon-p', text: a.per_unit ? money(a.low) + ' per ' + unit : '+' + money(a.low) })
					]);
				}))
			]));
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

		/* Sticky price bar */
		var rangeEl = h('strong', {});
		root.appendChild(h('div', { class: 'nse-bar', role: 'status', 'aria-live': 'polite' }, [
			h('div', {}, [h('small', { text: 'Estimated price range' }), rangeEl]),
			h('button', { type: 'button', class: 'nse-bar-btn', text: 'Get exact quote', onclick: function () {
				quoteSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
				if (inputs.name && !form.hidden) setTimeout(function () { inputs.name.focus({ preventScroll: true }); }, 400);
			} })
		]));

		function update() {
			optBtns.forEach(function (b, i) { b.setAttribute('aria-pressed', i === state.opt ? 'true' : 'false'); });
			outs.forEach(function (o, i) { o.textContent = num(state.dims[i]) + ' ft'; });
			qtyEl.textContent = num(qty()) + ' ' + unit;
			var e = estimate();
			rangeEl.textContent = money(e.low) + ' to ' + money(e.high);
			drawPlan();
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
				option: state.opt,
				dims: state.dims,
				addons: Object.keys(state.addons).filter(function (i) { return state.addons[i]; }).map(Number),
				page: window.location.href
			};
			fetch(root.getAttribute('data-endpoint'), {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload)
			})
				.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
				.then(function (res) {
					if (!res.ok || !res.j.ok) throw new Error(res.j && res.j.message ? res.j.message : '');
					form.hidden = true;
					var o = opts[state.opt];
					var picked = addons.filter(function (a, i) { return state.addons[i]; }).map(function (a) { return a.name; });
					var list = h('ul', {}, [
						o ? h('li', { text: o.name + ', ' + num(qty()) + ' ' + unit }) : null,
						h('li', { text: picked.length ? 'Extras: ' + picked.join(', ') : 'No extras' }),
						res.j.low ? h('li', { text: 'Estimate: ' + money(res.j.low) + ' to ' + money(res.j.high) }) : null
					]);
					done.appendChild(h('h4', { text: 'Request sent' }));
					done.appendChild(h('p', { text: cfg.success_message }));
					done.appendChild(list);
					done.hidden = false;
					done.focus();
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
