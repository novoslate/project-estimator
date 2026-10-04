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

	/* Booking tools that accept the customer's name and email in the link. */
	function bookingLink(url, name, email) {
		try {
			var u = new URL(url);
			if (/(^|\.)(calendly\.com|cal\.com)$/.test(u.hostname)) {
				if (name) u.searchParams.set('name', name);
				if (email) u.searchParams.set('email', email);
			}
			return u.toString();
		} catch (e) { return url; }
	}
	/* Embed versions of booking pages where the tool needs one. */
	function bookingEmbed(url) {
		try {
			var u = new URL(url);
			if (/(^|\.)calendly\.com$/.test(u.hostname)) {
				u.searchParams.set('embed_domain', window.location.hostname);
				u.searchParams.set('embed_type', 'Inline');
			} else if (u.hostname === 'calendar.google.com') {
				u.searchParams.set('gv', 'true');
			}
			return u.toString();
		} catch (e) { return url; }
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
		/* How prices show while building: the full range, "Starting at" only, or nothing until the request. */
		var priceMode = ['range', 'starting', 'gated'].indexOf(cfg.price_display) !== -1 ? cfg.price_display : 'range';

		/* Dashboard counters: one view and one start per visitor per estimator per day. */
		var trackUrl = root.getAttribute('data-track') || '';
		function trackEvent(ev) {
			if (!trackUrl) return;
			var key = 'pe_' + ev + '_' + estId + '_' + new Date().toISOString().slice(0, 10);
			try { if (window.localStorage.getItem(key)) return; window.localStorage.setItem(key, '1'); } catch (e) { /* storage blocked: still count */ }
			var body = JSON.stringify({ estimator_id: estId, event: ev });
			try {
				if (navigator.sendBeacon && navigator.sendBeacon(trackUrl, new Blob([body], { type: 'application/json' }))) return;
			} catch (e) { /* fall back to fetch */ }
			try { fetch(trackUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true }); } catch (e) { /* ignore */ }
		}
		trackEvent('view');

		var started = false;
		function markStarted() {
			if (started) return;
			started = true;
			trackEvent('start');
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
			return h('h3', { class: 'nse-step', id: id, tabindex: '-1' }, [h('span', { class: 'nse-n', 'aria-hidden': 'true' }), label]);
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
			root.appendChild(h('section', { class: 'nse-sec', 'data-step': 'project', 'aria-labelledby': uid + '-p' }, [
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
				projBody.appendChild(h('section', { class: 'nse-sec', 'data-step': 'options', 'aria-labelledby': uid + '-o' }, [
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
				projBody.appendChild(h('section', { class: 'nse-sec', 'data-step': 'colors', 'aria-labelledby': uid + '-c' }, [
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
			projBody.appendChild(h('section', { class: 'nse-sec', 'data-step': 'size', 'aria-labelledby': uid + '-m' }, [
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
				projBody.appendChild(h('section', { class: 'nse-sec', 'data-step': 'extras', 'aria-labelledby': uid + '-a' }, [
					stepTitle(proj.addons_label || 'Add extras', uid + '-a'),
					h('div', { class: 'nse-addons' }, addons.map(function (a, i) {
						return h('label', { class: 'nse-addon' }, [
							h('input', { type: 'checkbox', onchange: function (e) { state.addons[i] = e.target.checked; update(); } }),
							h('span', { class: 'nse-addon-t' }, [h('span', { text: a.name }), a.note ? h('small', { text: a.note }) : null]),
							priceMode === 'gated' ? null : h('span', { class: 'nse-addon-p', text: a.per_unit ? money(a.low) + ' per ' + unit : '+' + money(a.low) })
						]);
					}))
				]));
			}
			renumber();
			afterBuild();
		}
		var afterBuild = function () {};

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

		/* Photos: shrunk in the browser before upload (also removes hidden data such as GPS location). */
		var PHOTO_MAX = 6;
		var photos = [], photoBusy = false, photoGrid = null, photoNote = null, photoAdd = null;
		function shrinkPhoto(file) {
			return new Promise(function (resolve, reject) {
				var src = URL.createObjectURL(file);
				var img = new Image();
				img.onload = function () {
					var w = img.naturalWidth, hgt = img.naturalHeight;
					var sc = Math.min(1, 1600 / Math.max(w, hgt));
					var cv = document.createElement('canvas');
					cv.width = Math.max(1, Math.round(w * sc));
					cv.height = Math.max(1, Math.round(hgt * sc));
					var ctx = cv.getContext('2d');
					ctx.fillStyle = '#FFFFFF';
					ctx.fillRect(0, 0, cv.width, cv.height);
					ctx.drawImage(img, 0, 0, cv.width, cv.height);
					URL.revokeObjectURL(src);
					cv.toBlob(function (blob) {
						if (blob) resolve({ blob: blob, url: URL.createObjectURL(blob) });
						else reject(new Error('read'));
					}, 'image/jpeg', 0.82);
				};
				img.onerror = function () { URL.revokeObjectURL(src); reject(new Error('format')); };
				img.src = src;
			});
		}
		function renderPhotos() {
			photoGrid.innerHTML = '';
			photos.forEach(function (ph, i) {
				photoGrid.appendChild(h('div', { class: 'nse-photo' }, [
					h('img', { src: ph.url, alt: 'Photo ' + (i + 1) }),
					h('button', { type: 'button', class: 'nse-photo-x', 'aria-label': 'Remove photo ' + (i + 1), text: '\u00D7', onclick: function () {
						URL.revokeObjectURL(ph.url);
						photos.splice(i, 1);
						photoNote.textContent = '';
						renderPhotos();
					} })
				]));
			});
			photoAdd.hidden = photos.length >= PHOTO_MAX;
			photoAdd.lastChild.textContent = photos.length ? '+ Add more photos' : '+ Add photos';
		}
		function addPhotos(e) {
			var files = Array.prototype.slice.call(e.target.files || []);
			e.target.value = '';
			if (!files.length) return;
			var room = PHOTO_MAX - photos.length;
			var over = files.length > room;
			files = files.slice(0, Math.max(0, room));
			var failed = 0;
			photoBusy = true;
			photoNote.textContent = 'Adding photos...';
			files.reduce(function (chain, f) {
				return chain.then(function () {
					return shrinkPhoto(f).then(function (ph) { photos.push(ph); renderPhotos(); }, function () { failed++; });
				});
			}, Promise.resolve()).then(function () {
				photoBusy = false;
				var msg = [];
				if (failed) msg.push(failed + (failed === 1 ? ' photo' : ' photos') + ' could not be read. Try a JPG or PNG.');
				if (over) msg.push('You can add up to ' + PHOTO_MAX + ' photos.');
				photoNote.textContent = msg.join(' ');
			});
		}
		if (F.photos && F.photos !== 'off') {
			var photoInput = h('input', { type: 'file', id: uid + '-photos', class: 'nse-photo-input', accept: 'image/*', multiple: true, onchange: addPhotos });
			photoGrid = h('div', { class: 'nse-photo-grid' });
			photoNote = h('p', { class: 'nse-photo-note', role: 'status' });
			photoAdd = h('label', { for: uid + '-photos', class: 'nse-photo-add' }, [photoInput, h('span', { text: '+ Add photos' })]);
			formKids.push(h('div', { class: 'nse-field nse-full nse-photos' }, [
				h('span', { class: 'nse-photo-label', text: 'Photos of your space' + (F.photos === 'optional' ? ' (optional)' : '') }),
				h('small', { class: 'nse-photo-hint', text: 'Up to ' + PHOTO_MAX + '. Photos help us price your project accurately.' }),
				photoGrid, photoAdd, photoNote
			]));
		}

		var hp = h('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' });
		formKids.push(h('div', { class: 'nse-hp', 'aria-hidden': 'true' }, [hp]));
		var err = h('p', { class: 'nse-err', role: 'alert' });
		var btn = h('button', { type: 'submit', class: 'nse-btn', text: cfg.cta_text || 'Send my quote request' });
		/* reCAPTCHA: v2 shows a checkbox here; v3 runs invisibly at submit time. */
		var rc = cfg.recaptcha && cfg.recaptcha.site_key ? cfg.recaptcha : null;
		var rcBox = null, rcWidget = null;
		if (rc && rc.mode === 'v2') {
			rcBox = h('div', { class: 'nse-captcha nse-full' });
			formKids.push(rcBox);
		}
		formKids.push(err, h('div', { class: 'nse-full' }, [btn]));
		if (rc && rc.hide_badge) {
			root.classList.add('nse--rc-hidden');
			formKids.push(h('p', { class: 'nse-rc-note nse-full' }, [
				'This site is protected by reCAPTCHA and the Google ',
				h('a', { href: 'https://policies.google.com/privacy', target: '_blank', rel: 'noopener', text: 'Privacy Policy' }),
				' and ',
				h('a', { href: 'https://policies.google.com/terms', target: '_blank', rel: 'noopener', text: 'Terms of Service' }),
				' apply.'
			]));
		}

		var form = h('form', { class: 'nse-form', novalidate: true }, formKids);
		var done = h('div', { class: 'nse-done', tabindex: '-1', hidden: true });
		var quoteSec = h('section', { class: 'nse-sec', 'data-step': 'contact', 'aria-labelledby': uid + '-q' }, [
			stepTitle('Get your exact quote', uid + '-q'), form, done,
			cfg.disclaimer || biz.phone ? h('p', { class: 'nse-fine', text: [cfg.disclaimer, biz.phone ? 'Prefer to talk? Call ' + biz.phone + '.' : ''].filter(Boolean).join(' ') }) : null
		]);
		root.appendChild(quoteSec);
		buildProject();

		function rcRender() {
			if (!rcBox) return;
			var tries = 0;
			(function wait() {
				if (window.grecaptcha && typeof window.grecaptcha.render === 'function') {
					try { rcWidget = window.grecaptcha.render(rcBox, { sitekey: rc.site_key }); } catch (e) { /* already rendered */ }
				} else if (tries++ < 150) {
					setTimeout(wait, 200);
				}
			})();
		}
		rcRender();

		function rcReset() {
			if (rcWidget !== null && window.grecaptcha && window.grecaptcha.reset) {
				try { window.grecaptcha.reset(rcWidget); } catch (e) { /* ignore */ }
			}
		}

		/* Resolves with a token ('' when reCAPTCHA is off). Rejects when the v2 box is not checked. */
		function rcToken() {
			return new Promise(function (resolve, reject) {
				if (!rc) return resolve('');
				if (rc.mode === 'v2') {
					var t = rcWidget !== null && window.grecaptcha ? window.grecaptcha.getResponse(rcWidget) : '';
					return t ? resolve(t) : reject(new Error('Please check the "I\'m not a robot" box.'));
				}
				if (!window.grecaptcha || !window.grecaptcha.ready) return resolve('');
				var settled = false;
				var finish = function (t) { if (!settled) { settled = true; resolve(t || ''); } };
				setTimeout(function () { finish(''); }, 8000);
				window.grecaptcha.ready(function () {
					try {
						window.grecaptcha.execute(rc.site_key, { action: 'quote_request' }).then(finish, function () { finish(''); });
					} catch (e) { finish(''); }
				});
			});
		}

		/* Sticky price bar */
		var rangeEl = h('strong', {});
		var submitted = false, barBtn = null;
		var barLabel = h('small', { text: priceMode === 'starting' ? 'Starting at' : priceMode === 'gated' ? 'Your price' : 'Estimated price range' });
		var barEl = h('div', { class: 'nse-bar', role: 'status', 'aria-live': 'polite' }, [
			h('div', {}, [barLabel, rangeEl]),
			barBtn = h('button', { type: 'button', class: 'nse-bar-btn', text: priceMode === 'gated' ? 'See my price' : 'Get exact quote', onclick: function () {
				if (stepped) {
					showStep('contact', true);
				} else {
					quoteSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
				if (inputs.name && !form.hidden) setTimeout(function () { inputs.name.focus({ preventScroll: true }); }, 400);
			} })
		]);
		root.appendChild(barEl);

		/* ---------- Step by step layout ---------- */
		var layoutMode = (cfg.design && cfg.design.layout) || 'single';
		var stepped = false, stepKey = null, advTimer = null;
		var progLabel = h('div', { class: 'nse-progress-l' });
		var progFill = h('div', { class: 'nse-progress-b' });
		var progress = h('div', { class: 'nse-progress', hidden: true }, [progLabel, h('div', { class: 'nse-progress-t', 'aria-hidden': 'true' }, [progFill])]);
		var stage = h('div', { class: 'nse-stage', hidden: true });
		var backBtn = h('button', { type: 'button', class: 'nse-back', text: 'Back', onclick: function () { go(-1); } });
		var nextBtn = h('button', { type: 'button', class: 'nse-next', text: 'Next', onclick: function () { go(1); } });
		var nav = h('div', { class: 'nse-stepnav', hidden: true }, [backBtn, nextBtn]);
		var headEl = root.querySelector('.nse-head');
		root.insertBefore(progress, headEl.nextSibling);
		root.insertBefore(stage, progress.nextSibling);
		root.insertBefore(nav, barEl);

		function stepList() { return Array.prototype.slice.call(root.querySelectorAll('.nse-sec[data-step]')); }
		function stepIndex(list, key) {
			for (var i = 0; i < list.length; i++) if (list[i].getAttribute('data-step') === key) return i;
			return -1;
		}
		function titleOf(sec) {
			var hd = sec.querySelector('.nse-step');
			return hd && hd.lastChild ? hd.lastChild.textContent : '';
		}
		function wantStepped() {
			return layoutMode === 'always' || (layoutMode === 'mobile' && root.clientWidth > 0 && root.clientWidth < 640);
		}
		/* Keep the 3D preview on screen above the steps where it helps (style, color, size, extras). */
		function placeVisual() {
			var vis = root.querySelector('.nse-visual');
			var cap = root.querySelector('.nse-caption');
			var home = root.querySelector('.nse-sec[data-step="size"] .nse-box');
			if (!vis) return;
			var useStage = stepped && has3d() && stepKey !== 'project' && stepKey !== 'contact';
			if (useStage) {
				if (vis.parentNode !== stage) stage.appendChild(vis);
				if (cap && cap.parentNode !== stage) stage.appendChild(cap);
				stage.hidden = false;
			} else {
				stage.hidden = true;
				if (home && vis.parentNode !== home) home.insertBefore(vis, home.firstChild);
				if (cap && home && cap.parentNode !== home) {
					var chips = home.querySelector('.nse-chips');
					home.insertBefore(cap, chips ? chips.nextSibling : vis.nextSibling);
				}
			}
		}
		function showStep(key, userAction) {
			var list = stepList();
			if (!list.length) return;
			var idx = stepIndex(list, key);
			if (idx < 0) idx = Math.min(Math.max(0, stepIndex(list, stepKey)), list.length - 1);
			if (idx < 0) idx = 0;
			stepKey = list[idx].getAttribute('data-step');
			list.forEach(function (sec, i) { sec.classList.toggle('is-current', i === idx); });
			progLabel.textContent = 'Step ' + (idx + 1) + ' of ' + list.length;
			progress.setAttribute('aria-label', 'Step ' + (idx + 1) + ' of ' + list.length + ': ' + titleOf(list[idx]));
			root.classList.toggle('nse--past-first', idx > 0);
			root.classList.toggle('nse--at-contact', stepKey === 'contact');
			progFill.style.width = Math.round(100 * (idx + 1) / list.length) + '%';
			backBtn.hidden = idx === 0;
			nextBtn.hidden = idx === list.length - 1;
			nextBtn.textContent = idx === list.length - 2 ? 'Next: your details' : 'Next';
			placeVisual();
			if (userAction) {
				var hd = list[idx].querySelector('.nse-step');
				if (hd) hd.focus({ preventScroll: true });
				if (progress.getBoundingClientRect().top < 0) progress.scrollIntoView({ behavior: 'smooth', block: 'start' });
				push({ event: 'project_estimator_step', estimator_id: estId, estimator_name: estName, step: stepKey, step_number: idx + 1, step_total: list.length });
			}
		}
		function go(delta) {
			var list = stepList();
			var idx = stepIndex(list, stepKey);
			var n = Math.max(0, Math.min(list.length - 1, idx + delta));
			showStep(list[n].getAttribute('data-step'), true);
		}
		function layout() {
			stepped = wantStepped();
			root.classList.toggle('nse--stepped', stepped);
			progress.hidden = !stepped;
			nav.hidden = !stepped;
			if (stepped) {
				showStep(stepKey || (stepList()[0] && stepList()[0].getAttribute('data-step')), false);
			} else {
				stepList().forEach(function (sec) { sec.classList.remove('is-current'); });
				root.classList.remove('nse--past-first', 'nse--at-contact');
				placeVisual();
			}
		}
		afterBuild = function () { if (stepped) showStep(stepKey, false); else placeVisual(); };

		/* Tapping a project type, style, or color moves on to the next step. */
		root.addEventListener('click', function (e) {
			if (!stepped || !e.target.closest) return;
			var btn = e.target.closest('.nse-opt, .nse-color');
			var sec = btn ? btn.closest('.nse-sec[data-step]') : null;
			if (!sec || sec.getAttribute('data-step') !== stepKey) return;
			clearTimeout(advTimer);
			advTimer = setTimeout(function () { go(1); }, 320);
		});

		var resizeTimer = null;
		window.addEventListener('resize', function () {
			if (layoutMode !== 'mobile') return;
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(function () { if (wantStepped() !== stepped) layout(); }, 150);
		});
		layout();

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
			if (!submitted) rangeEl.textContent = priceMode === 'gated' ? 'Unlocks with your quote' : priceMode === 'starting' ? money(e.low) : money(e.low) + ' to ' + money(e.high);
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
			if (photoBusy) return fail('Your photos are still being added. One moment.');
			if (root.getAttribute('data-preview')) return fail('Preview only: quote requests are turned off in the Elementor editor. View the published page to test.');
			if (F.photos === 'required' && !photos.length) return fail('Add at least one photo of your space.');

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
			rcToken()
				.then(function (token) {
					payload.recaptcha = token;
					return snapshot();
				})
				.then(function (illustration) {
					payload.illustration = illustration;
					if (photos.length) {
						/* With photos: the same fields as JSON in "payload", plus the image files. */
						var fd = new FormData();
						fd.append('payload', JSON.stringify(payload));
						photos.forEach(function (ph, i) { fd.append('photos[]', ph.blob, 'photo-' + (i + 1) + '.jpg'); });
						btn.textContent = 'Sending photos...';
						return fetch(root.getAttribute('data-endpoint'), { method: 'POST', body: fd });
					}
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
						res.j.photos ? h('li', { text: res.j.photos + (res.j.photos === 1 ? ' photo' : ' photos') + ' sent' + (res.j.photos_skipped ? ' (' + res.j.photos_skipped + ' could not be sent)' : '') }) : null
					]);
					done.appendChild(h('h4', { text: 'Request sent' }));
					/* The bar now shows the confirmed price, and its button is no longer needed. */
					submitted = true;
					if (res.j.low) {
						barLabel.textContent = 'Your estimated price';
						rangeEl.textContent = money(res.j.low) + ' to ' + money(res.j.high);
					}
					barBtn.hidden = true;
					if (res.j.low) {
						done.appendChild(h('div', { class: 'nse-price' }, [
							h('small', { text: 'Your estimated price' }),
							h('strong', { text: money(res.j.low) + ' to ' + money(res.j.high) })
						]));
					}
					done.appendChild(h('p', { text: cfg.success_message }));
					done.appendChild(list);
					var booking = cfg.booking && cfg.booking.url ? cfg.booking : null;
					if (booking) {
						var bookUrl = bookingLink(booking.url, payload.name, payload.email);
						var recordBooking = function (how) {
							if (recordBooking.done) return;
							recordBooking.done = true;
							push({ event: 'project_estimator_booking_click', estimator_id: estId, estimator_name: estName, lead_id: String(res.j.lead_id || ''), method: how });
							var bk = root.getAttribute('data-booking');
							if (bk && res.j.lead_id && res.j.booking_key) {
								var body = JSON.stringify({ lead_id: res.j.lead_id, key: res.j.booking_key });
								try {
									if (!(navigator.sendBeacon && navigator.sendBeacon(bk, new Blob([body], { type: 'application/json' })))) {
										fetch(bk, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true });
									}
								} catch (e) { /* ignore */ }
							}
						};
						var bookKids = [
							h('p', { class: 'nse-book-l', text: 'Next step' }),
							h('a', { class: 'nse-book-btn', href: bookUrl, target: '_blank', rel: 'noopener', text: booking.label || 'Book your free on-site visit', onclick: function () { recordBooking('button'); } })
						];
						if (booking.embed) {
							bookKids.push(h('iframe', { class: 'nse-book-frame', src: bookingEmbed(bookUrl), title: booking.label || 'Book your on-site visit', loading: 'lazy' }));
							/* Calendly reports a scheduled visit from inside its embed. */
							window.addEventListener('message', function (ev) {
								if (/calendly\.com$/.test((ev.origin || '').replace(/^https?:\/\//, '')) && ev.data && ev.data.event === 'calendly.event_scheduled') {
									recordBooking('embed');
									push({ event: 'project_estimator_booking_scheduled', estimator_id: estId, lead_id: String(res.j.lead_id || '') });
								}
							});
						}
						done.appendChild(h('div', { class: 'nse-book' }, bookKids));
					}
					if (res.j.pdf_url) {
						done.appendChild(h('p', { class: 'nse-pdf' }, [
							h('a', { class: 'nse-pdf-btn' + (booking ? ' nse-pdf-btn--secondary' : ''), href: res.j.pdf_url, target: '_blank', rel: 'noopener', text: 'Download your estimate (PDF)' })
						]));
						if (payload.email) done.appendChild(h('p', { class: 'nse-pdf-note', text: 'We also emailed a copy to ' + payload.email + '.' }));
					}
					done.hidden = false;
					if (stepped) {
						nav.hidden = true;
						progLabel.textContent = 'Done: request sent';
						progFill.style.width = '100%';
					}
					done.focus();
					fireConversion(res.j, payload);
				})
				.catch(function (x) {
					rcReset(); // tokens work once, so a retry needs a fresh check
					fail(x.message || ('Your request did not go through. Please try again' + (biz.phone ? ' or call ' + biz.phone : '') + '.'));
					btn.disabled = false;
					btn.textContent = cfg.cta_text || 'Send my quote request';
				});
		});

		update();
	}

	/* Start an estimator once, even if several loaders see it. */
	function start(el) {
		if (!el || el.__peStarted || !el.getAttribute('data-config')) return;
		el.__peStarted = true;
		init(el);
	}
	function boot() {
		var els = document.querySelectorAll('.nse[data-config]');
		for (var i = 0; i < els.length; i++) start(els[i]);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();

	/* For page builders and anything else that adds an estimator after the page loads. */
	window.ProjectEstimator = { start: start, boot: boot };

	/* Elementor: widgets are added and re-rendered in the editor without a page load. */
	function hookElementor() {
		if (!window.elementorFrontend || !window.elementorFrontend.hooks) return false;
		window.elementorFrontend.hooks.addAction('frontend/element_ready/project_estimator.default', function ($scope) {
			var host = $scope && $scope[0] ? $scope[0] : null;
			if (host) start(host.querySelector('.nse[data-config]'));
		});
		return true;
	}
	if (!hookElementor() && window.jQuery) {
		window.jQuery(window).on('elementor/frontend/init', hookElementor);
	}
	/* Safety net in the editor preview, where widgets are swapped in and out as settings change. */
	var inEditor = (document.body && /\belementor-editor-(active|preview)\b/.test(document.body.className)) || !!document.querySelector('.nse[data-preview]');
	if (inEditor && window.MutationObserver) {
		new MutationObserver(function () { boot(); }).observe(document.body, { childList: true, subtree: true });
	}
})();
