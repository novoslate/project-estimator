/* Project Estimator: live illustrated preview (isometric SVG). */
(function () {
	'use strict';
	var NS = 'http://www.w3.org/2000/svg';
	var COS = Math.cos(Math.PI / 6), SIN = 0.5;
	var VW = 400, VH = 260;

	/* Face colors: [top, left (+y), right (+x)] */
	var ALU = ['#FBFBF8', '#E6E5DF', '#CFCEC6'];
	var WOOD = ['#CC9563', '#AA7442', '#8C5D33'];
	var STUCCO = ['#F2E8D6', '#E4D5BB', '#CFBEA0'];
	var CONC = ['#E3DFD7', '#D0CABF', '#BDB6A9'];
	var DARK = ['#50565A', '#3C4144', '#2D3133'];
	var BLOCK = ['#D8D3C9', '#C6BFB2', '#B1A99A'];
	var VINYL = ['#FFFFFF', '#EFEFEA', '#DDDDD6'];
	var ROOFCAP = ['#A39582', '#8E806C', '#7C6F5C'];
	var STONE = ['#C9C2B6', '#B3AB9D', '#9D9586'];

	function Scene(svg) {
		this.svg = svg;
		this.k = 1; this.ox = 0; this.oy = 0;
	}
	Scene.prototype.p = function (x, y, z) {
		return [this.ox + (x - y) * COS * this.k, this.oy + (x + y) * SIN * this.k - z * this.k];
	};
	Scene.prototype.fit = function (x0, x1, y0, y1, z1) {
		var minx = Infinity, maxx = -Infinity, miny = Infinity, maxy = -Infinity;
		[x0, x1].forEach(function (x) {
			[y0, y1].forEach(function (y) {
				[0, z1].forEach(function (z) {
					var sx = (x - y) * COS, sy = (x + y) * SIN - z;
					minx = Math.min(minx, sx); maxx = Math.max(maxx, sx);
					miny = Math.min(miny, sy); maxy = Math.max(maxy, sy);
				});
			});
		});
		this.k = Math.min((VW - 20) / (maxx - minx), (VH - 20) / (maxy - miny));
		this.ox = VW / 2 - ((minx + maxx) / 2) * this.k;
		this.oy = VH / 2 - ((miny + maxy) / 2) * this.k;
	};
	Scene.prototype.el = function (tag, attrs) {
		var e = document.createElementNS(NS, tag);
		for (var a in attrs) if (attrs[a] !== undefined) e.setAttribute(a, attrs[a]);
		this.svg.appendChild(e);
		return e;
	};
	Scene.prototype.d = function (pts) {
		var self = this;
		return pts.map(function (q, i) {
			var s = self.p(q[0], q[1], q[2]);
			return (i ? 'L' : 'M') + s[0].toFixed(1) + ' ' + s[1].toFixed(1);
		}).join('');
	};
	Scene.prototype.poly = function (pts, fill, stroke, sw) {
		return this.el('path', {
			d: this.d(pts) + 'Z', fill: fill,
			stroke: stroke === undefined ? 'rgba(40,35,25,.22)' : stroke,
			'stroke-width': sw || 0.6, 'stroke-linejoin': 'round'
		});
	};
	Scene.prototype.line = function (a, b, stroke, sw) {
		return this.el('path', { d: this.d([a, b]), stroke: stroke, 'stroke-width': sw || 1, fill: 'none', 'stroke-linecap': 'round' });
	};
	Scene.prototype.path = function (d, stroke, sw, fill) {
		return this.el('path', { d: d, stroke: stroke, 'stroke-width': sw || 1, fill: fill || 'none', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' });
	};
	Scene.prototype.box = function (x, y, z, w, d, h, c) {
		this.poly([[x, y + d, z], [x + w, y + d, z], [x + w, y + d, z + h], [x, y + d, z + h]], c[1]);
		this.poly([[x + w, y, z], [x + w, y + d, z], [x + w, y + d, z + h], [x + w, y, z + h]], c[2]);
		this.poly([[x, y, z + h], [x + w, y, z + h], [x + w, y + d, z + h], [x, y + d, z + h]], c[0]);
	};
	/* Roof slab that slopes from zb at y0 to zf at y1 */
	Scene.prototype.slope = function (x0, x1, y0, y1, zb, zf, t, c) {
		this.poly([[x0, y1, zf], [x1, y1, zf], [x1, y1, zf + t], [x0, y1, zf + t]], c[1]);
		this.poly([[x1, y0, zb], [x1, y1, zf], [x1, y1, zf + t], [x1, y0, zb + t]], c[2]);
		this.poly([[x0, y0, zb + t], [x1, y0, zb + t], [x1, y1, zf + t], [x0, y1, zf + t]], c[0]);
	};
	Scene.prototype.flat = function (x0, y0, x1, y1, z, fill, stroke) {
		return this.poly([[x0, y0, z], [x1, y0, z], [x1, y1, z], [x0, y1, z]], fill, stroke);
	};
	Scene.prototype.dot = function (x, y, z, r, fill, opacity) {
		var s = this.p(x, y, z);
		return this.el('circle', { cx: s[0].toFixed(1), cy: s[1].toFixed(1), r: r, fill: fill, opacity: opacity === undefined ? 1 : opacity });
	};
	Scene.prototype.glow = function (x, y, z) {
		this.dot(x, y, z, 6, '#FFE08A', 0.35);
		this.dot(x, y, z, 2.2, '#FFD25A');
	};
	/* Ground ellipse (circle in plan) */
	Scene.prototype.ring = function (cx, cy, r, z, n) {
		var pts = [];
		for (var i = 0; i < (n || 24); i++) {
			var a = (i / (n || 24)) * Math.PI * 2;
			pts.push([cx + Math.cos(a) * r, cy + Math.sin(a) * r, z]);
		}
		return pts;
	};
	Scene.prototype.cylinder = function (cx, cy, r, h, c) {
		var n = 24, bottom = this.ring(cx, cy, r, 0, n), top = this.ring(cx, cy, r, h, n);
		/* Visible side: angles where the outward normal faces the viewer (x + y increasing) */
		var side = [];
		for (var i = 0; i <= n / 2; i++) {
			var a = -Math.PI / 4 + (i / (n / 2)) * Math.PI;
			side.push([cx + Math.cos(a) * r, cy + Math.sin(a) * r, 0]);
		}
		var back = side.slice().reverse().map(function (q) { return [q[0], q[1], h]; });
		this.poly(side.concat(back), c[1]);
		this.poly(top, c[0]);
	};

	/* Seeded random so plants do not jump around while sliders move */
	function rand(a, b) {
		var x = Math.sin(a * 127.1 + b * 311.7) * 43758.5453;
		return x - Math.floor(x);
	}

	function has(features, key) { return features.indexOf(key) !== -1; }

	/* ---------- Shared pieces ---------- */

	function backdrop(sc) {
		sc.el('rect', { x: 0, y: 0, width: VW, height: VH, fill: '#E7E0CF' });
	}

	function house(sc, x0, x1, opts) {
		opts = opts || {};
		/* A full building so the area behind the wall reads as the house */
		sc.box(x0, -16, 0, x1 - x0, 16, 10.5, STUCCO);
		sc.box(x0 - 0.3, -16.3, 10.5, x1 - x0 + 0.6, 16.6, 0.8, ['#D3C6B0', '#8E806C', '#7C6F5C']);
		/* Windows and a slider door on the visible wall face (y = 0) */
		var wins = opts.windows || [x0 + 2.5];
		wins.forEach(function (wx) {
			sc.poly([[wx, 0, 4.2], [wx + 4, 0, 4.2], [wx + 4, 0, 8], [wx, 0, 8]], '#C3D6DE', '#FFFFFF', 1.4);
			sc.line([wx + 2, 0, 4.2], [wx + 2, 0, 8], '#FFFFFF', 1);
		});
		if (opts.door !== undefined) {
			var dx = opts.door;
			sc.poly([[dx, 0, 0.3], [dx + 6, 0, 0.3], [dx + 6, 0, 7.2], [dx, 0, 7.2]], '#B9CFD8', '#FFFFFF', 1.4);
			sc.line([dx + 3, 0, 0.3], [dx + 3, 0, 7.2], '#FFFFFF', 1.2);
		}
	}

	function shadow(sc, x0, y0, x1, y1, h) {
		var dx = h * 0.18, dy = h * 0.32;
		sc.flat(x0 + dx, y0 + dy, x1 + dx, y1 + dy, 0.02, 'rgba(60,50,30,.14)', 'none');
	}

	function slab(sc, x0, y0, x1, y1, c) {
		sc.box(x0, y0, 0, x1 - x0, y1 - y0, 0.3, c || CONC);
	}

	function postXs(w, spacing) {
		var n = Math.max(2, Math.ceil(w / spacing) + 1), xs = [];
		for (var i = 0; i < n; i++) xs.push(0.15 + (w - 0.65) * (i / (n - 1)));
		return xs;
	}

	/* ---------- Scenes ---------- */

	function patioCover(sc, W, D, variant, f, hooks) {
		var Hb = 10, H = 9.2;
		var zAt = function (y) { return Hb + (H - Hb) * (y / D); };
		slab(sc, -0.6, 0, W + 0.6, D + 0.6);
		shadow(sc, 0, 0, W, D, 9);
		hooks && hooks.underRoof && hooks.underRoof();

		var solidTo = variant === 'lattice' ? 0 : variant === 'combo' ? W / 2 : W;
		/* Ledger on the wall */
		sc.box(0, 0, Hb - 0.6, W, 0.4, 0.6, ALU);
		if (solidTo > 0) {
			sc.slope(0, solidTo, 0, D + 0.4, Hb, H, 0.45, ALU);
			for (var px = 2; px < solidTo - 0.5; px += 2) {
				sc.line([px, 0, Hb + 0.46], [px, D + 0.4, H + 0.46], 'rgba(0,0,0,.08)', 0.8);
			}
		}
		if (solidTo < W) {
			for (var rx = solidTo + 0.2; rx < W; rx += 2) {
				sc.slope(rx, rx + 0.3, 0, D + 0.6, Hb - 0.6, H - 0.6, 0.6, ALU);
			}
			for (var ly = 0.6; ly < D + 0.4; ly += 0.9) {
				var z = zAt(ly) + 0.02;
				sc.box(solidTo, ly, z, W - solidTo, 0.22, 0.22, ALU);
			}
		}
		/* Front beam and posts */
		sc.box(-0.3, D - 0.4, H - 1, W + 0.6, 0.8, 1, ALU);
		hooks && hooks.beforePosts && hooks.beforePosts(H);
		postXs(W, 12).forEach(function (x) { sc.box(x, D - 0.5, 0.3, 0.5, 0.5, H - 1.3, ALU); });
		if (has(f, 'lights')) {
			for (var lx = 2; lx < W - 1; lx += 4) sc.glow(lx, D + 0.2, H - 1.2);
		}
	}

	function pergola(sc, W, D, variant, f) {
		var y0 = 2.5, y1 = y0 + D, H = 8.6;
		var c = variant === 'wood' ? WOOD : ALU;
		slab(sc, -0.8, y0 - 0.8, W + 0.8, y1 + 0.8, STONE);
		shadow(sc, 0, y0, W, y1, 9);
		var xs = postXs(W, 14);
		/* Back posts, privacy wall, then front posts */
		xs.forEach(function (x) { sc.box(x, y0, 0.3, 0.5, 0.5, H - 0.3, c); });
		if (has(f, 'privacy_wall')) {
			for (var pz = 1; pz < H - 1; pz += 0.65) sc.box(W - 0.35, y0 + 0.5, pz, 0.2, D - 1, 0.42, variant === 'wood' ? WOOD : DARK);
		}
		xs.forEach(function (x) { sc.box(x, y1 - 0.5, 0.3, 0.5, 0.5, H - 0.3, c); });
		/* Beams */
		sc.box(-0.6, y0, H, W + 1.2, 0.5, 0.8, c);
		sc.box(-0.6, y1 - 0.5, H, W + 1.2, 0.5, 0.8, c);
		if (variant === 'louvered') {
			sc.box(-0.6, y0, H, 0.5, D, 0.8, c);
			sc.box(W + 0.1, y0, H, 0.5, D, 0.8, c);
			for (var ly = y0 + 0.6; ly < y1 - 0.6; ly += 0.75) {
				sc.poly([[-0.1, ly, H + 0.5], [W + 0.1, ly, H + 0.5], [W + 0.1, ly + 0.6, H + 0.9], [-0.1, ly + 0.6, H + 0.9]], ALU[0]);
			}
		} else {
			for (var rx = 0; rx <= W; rx += 1.6) sc.box(rx, y0 - 1, H + 0.8, 0.3, D + 2, 0.6, c);
			if (variant === 'wood') {
				for (var sy = y0 - 0.5; sy < y1 + 0.6; sy += 1.4) sc.box(-0.3, sy, H + 1.4, W + 0.6, 0.18, 0.18, WOOD);
			}
		}
		if (has(f, 'canopy')) {
			var cw = Math.min(W, Math.max(6, W * 0.6));
			sc.poly([[0, y0 + 0.3, H + 1.6], [cw, y0 + 0.3, H + 1.6], [cw, y1 - 0.3, H + 1.5], [0, y1 - 0.3, H + 1.5]], 'rgba(245,236,220,.92)', 'rgba(150,130,100,.4)', 0.8);
			for (var cx = 1.5; cx < cw; cx += 1.5) sc.line([cx, y0 + 0.3, H + 1.6], [cx, y1 - 0.3, H + 1.5], 'rgba(150,130,100,.18)', 0.8);
		}
		if (has(f, 'lights')) {
			var pts = [], step = 0.5;
			for (var x = 0.4; x <= W - 0.2; x += step) {
				var t = (x - 0.4) / Math.max(1, W - 0.6);
				pts.push([x, y1 + 0.1, H - 0.4 - Math.sin(t * Math.PI) * 1.4]);
			}
			sc.path(sc.d(pts), 'rgba(40,40,40,.55)', 0.8);
			for (var i = 0; i < pts.length; i += 3) sc.glow(pts[i][0], pts[i][1], pts[i][2] - 0.2);
		}
	}

	function panel(sc, a, b, z0, z1, fill, mullion) {
		/* Vertical panel between ground points a=[x,y] and b=[x,y] */
		sc.poly([[a[0], a[1], z0], [b[0], b[1], z0], [b[0], b[1], z1], [a[0], a[1], z1]], fill, mullion || '#FFFFFF', 1.2);
	}

	function wallPanels(sc, from, to, z0, z1, every, fill, frame) {
		var len = Math.hypot(to[0] - from[0], to[1] - from[1]);
		var n = Math.max(1, Math.round(len / every));
		for (var i = 0; i < n; i++) {
			var t0 = i / n, t1 = (i + 1) / n;
			panel(sc,
				[from[0] + (to[0] - from[0]) * t0, from[1] + (to[1] - from[1]) * t0],
				[from[0] + (to[0] - from[0]) * t1, from[1] + (to[1] - from[1]) * t1],
				z0, z1, fill, frame);
		}
	}

	function sunroom(sc, W, D, variant, f) {
		var Hb = 10, H = 8.8;
		var glass = variant === 'screen' ? 'rgba(80,90,95,.38)' : 'rgba(175,208,222,.62)';
		var knee = variant === 'four_season' ? 2.6 : variant === 'three_season' ? 1.6 : 0.9;
		var kneeC = variant === 'four_season' ? STUCCO : ALU;
		slab(sc, -0.4, 0, W + 0.4, D + 0.4);
		shadow(sc, 0, 0, W, D, 9);
		if (has(f, 'ac')) {
			sc.box(W + 1.5, D * 0.35, 0, 2.6, 1.3, 2.2, ['#D9DBD8', '#C4C7C3', '#B0B3AF']);
			sc.line([W + 1.5, D * 0.35 + 0.6, 1.8], [W + 0.2, D * 0.35 + 0.6, 4], '#9A9C98', 1.1);
		}
		sc.slope(-0.3, W + 0.3, 0, D + 0.6, Hb, H, variant === 'four_season' ? 0.8 : 0.5, ALU);
		/* Front wall (y = D) and right wall (x = W) */
		sc.box(0, D - 0.4, 0.3, W, 0.4, knee, kneeC);
		sc.box(W - 0.4, 0, 0.3, 0.4, D - 0.4, knee, kneeC);
		var top = H - 0.1;
		wallPanels(sc, [0, D], [W, D], knee + 0.3, top, 3, glass);
		wallPanels(sc, [W, 0], [W, D], knee + 0.3, top, 3, glass);
		/* Door on the front */
		var dx = Math.max(0.5, W / 2 - 1.5);
		panel(sc, [dx, D + 0.02], [dx + 3, D + 0.02], 0.3, 7, variant === 'screen' ? 'rgba(80,90,95,.45)' : 'rgba(165,200,215,.75)', '#FFFFFF');
		sc.dot(dx + 2.6, D + 0.05, 3.6, 1.2, '#7A7A72');
		if (variant !== 'screen') {
			/* Transom band */
			sc.line([0, D, top - 1.2], [W, D, top - 1.2], '#FFFFFF', 1.1);
			sc.line([W, 0, top - 1.2], [W, D, top - 1.2], '#FFFFFF', 1.1);
		}
		if (has(f, 'lights')) {
			for (var lx = 2; lx < W - 1; lx += 4) sc.glow(lx, D + 0.4, H - 0.2);
		}
	}

	function enclosure(sc, W, D, variant, f) {
		var fill = variant === 'glass' ? 'rgba(175,208,222,.6)' : variant === 'vinyl' ? 'rgba(232,240,242,.72)' : 'rgba(80,90,95,.36)';
		var knee = has(f, 'knee_wall') ? 2.6 : 0.3;
		patioCover(sc, W, D, 'solid', f.filter(function (x) { return x !== 'lights'; }), {
			beforePosts: function (H) {
				var top = H - 1;
				if (knee > 0.3) {
					sc.box(0, D - 0.5, 0.3, W, 0.5, knee - 0.3, STUCCO);
					sc.box(W - 0.5, 0.2, 0.3, 0.5, D - 0.7, knee - 0.3, STUCCO);
				}
				wallPanels(sc, [0, D - 0.25], [W, D - 0.25], knee, top, 4, fill, variant === 'screen' ? '#E9E7E0' : '#FFFFFF');
				wallPanels(sc, [W - 0.25, 0.3], [W - 0.25, D - 0.25], knee, top, 4, fill, variant === 'screen' ? '#E9E7E0' : '#FFFFFF');
				if (has(f, 'screen_door')) {
					var dx = Math.max(0.6, W / 2 - 1.5);
					panel(sc, [dx, D - 0.2], [dx + 3, D - 0.2], 0.3, 7, 'rgba(70,80,85,.5)', '#F4F2EC');
					sc.line([dx, D - 0.2, 3.5], [dx + 3, D - 0.2, 3.5], '#F4F2EC', 1.4);
				}
			}
		});
		if (has(f, 'lights')) {
			for (var lx = 2; lx < W - 1; lx += 4) sc.glow(lx, D + 0.2, 8);
		}
	}

	function plant(sc, x, y, kind, s) {
		if (kind === 0) {
			/* Round shrub */
			sc.dot(x + 0.3, y + 0.4, 0.05, 3.2 * s, 'rgba(60,50,30,.15)');
			sc.dot(x, y, 0.9, 3.4 * s, '#6E9550');
			sc.dot(x - 0.25, y - 0.25, 1.3, 2.2 * s, '#89AF67');
		} else if (kind === 1) {
			/* Agave: fan of leaves */
			var base = sc.p(x, y, 0);
			for (var i = 0; i < 7; i++) {
				var a = (-80 + i * 27) * Math.PI / 180, len = (6 + (i % 2) * 2) * s;
				sc.path('M' + base[0] + ' ' + base[1] + 'L' + (base[0] + Math.sin(a) * len).toFixed(1) + ' ' + (base[1] - Math.cos(a) * len * 0.9).toFixed(1), '#7FA08A', 2.2 * s);
			}
		} else if (kind === 2) {
			/* Barrel cactus */
			sc.dot(x, y, 0.6, 2.8 * s, '#5E8A52');
			sc.dot(x - 0.2, y - 0.2, 0.9, 1.4 * s, '#79A26A');
		} else {
			/* Small tree */
			sc.dot(x + 1.2, y + 1.6, 0.05, 6 * s, 'rgba(60,50,30,.13)');
			sc.line([x, y, 0], [x, y, 4], '#8A6A48', 1.6 * s);
			sc.dot(x, y, 5.2, 6.5 * s, '#7C9F59');
			sc.dot(x - 0.6, y - 0.6, 6.2, 4.2 * s, '#96B873');
		}
	}

	function gravelBed(sc, x0, y0, x1, y1, color, dots) {
		sc.flat(x0, y0, x1, y1, 0.05, color, 'rgba(0,0,0,.08)');
		for (var gx = x0 + 0.7; gx < x1; gx += 1.7) {
			for (var gy = y0 + 0.7; gy < y1; gy += 1.7) {
				var jx = rand(gx, gy) * 1.2, jy = rand(gy, gx) * 1.2;
				sc.dot(gx + jx, gy + jy, 0.06, 0.9, dots, 0.8);
			}
		}
	}

	function landscape(sc, W, D, variant, f) {
		var y0 = 1, y1 = y0 + D;
		gravelBed(sc, 0, y0, W, y1, variant === 'gravel' ? '#D2C4A6' : '#D8CBAF', '#B4A486');
		var items = [];
		if (variant === 'full') {
			/* Paver path curving through the yard */
			var pw = 3, px = W * 0.45;
			sc.flat(px, y0, px + pw, y1, 0.07, '#E6DFD2', 'rgba(0,0,0,.12)');
			for (var py = y0 + 1.5; py < y1; py += 1.5) sc.line([px, py, 0.08], [px + pw, py, 0.08], 'rgba(0,0,0,.1)', 0.8);
		}
		if (variant !== 'gravel') {
			var gap = variant === 'full' ? 5 : 6;
			for (var gx = 2; gx < W - 1; gx += gap) {
				for (var gy = y0 + 2; gy < y1 - 0.5; gy += gap) {
					var r = rand(gx, gy);
					var x = gx + (r - 0.5) * 2.5, y = gy + (rand(gy, gx) - 0.5) * 2.5;
					if (variant === 'full' && x > W * 0.45 - 1.2 && x < W * 0.45 + 4.2) continue;
					var kind = variant === 'full' ? (r > 0.85 ? 3 : r > 0.4 ? 0 : 1) : (r > 0.66 ? 1 : r > 0.33 ? 2 : 0);
					items.push([x, y, kind]);
				}
			}
		} else {
			/* A few accent plants in a refreshed rock yard */
			[[W * 0.2, y0 + D * 0.3], [W * 0.75, y0 + D * 0.6], [W * 0.5, y0 + D * 0.85]].forEach(function (q, i) {
				items.push([q[0], q[1], i === 1 ? 1 : 2]);
			});
		}
		if (has(f, 'boulders')) {
			[[W * 0.15, y0 + D * 0.7], [W * 0.82, y0 + D * 0.25], [W * 0.6, y0 + D * 0.5]].forEach(function (q) { items.push([q[0], q[1], 'b']); });
		}
		items.sort(function (a, b) { return (a[0] + a[1]) - (b[0] + b[1]); });
		items.forEach(function (it) {
			if (it[2] === 'b') {
				var bs = Math.min(2.6, Math.max(0.8, sc.k / 2.4));
				sc.dot(it[0] + 0.4, it[1] + 0.5, 0.05, 4 * bs, 'rgba(60,50,30,.15)');
				sc.dot(it[0], it[1], 1, 4.2 * bs, '#A79F92');
				sc.dot(it[0] - 0.3, it[1] - 0.3, 1.5, 2.4 * bs, '#BDB6AA');
			} else {
				plant(sc, it[0], it[1], it[2], Math.min(2.6, Math.max(0.8, sc.k / 2.4)));
			}
		});
		if (has(f, 'edging')) sc.box(0, y1, 0, W, 0.4, 0.4, CONC);
		if (has(f, 'lights')) {
			for (var lx = 1.5; lx < W; lx += 5) sc.glow(lx, y1 - 0.4, 0.8);
		}
	}

	function turf(sc, W, D, variant, f) {
		var y0 = 1, y1 = y0 + D;
		var a = variant === 'premium' ? '#5E9147' : '#6D9E4E', b = variant === 'premium' ? '#69A052' : '#7AAB5A';
		for (var sx = 0, i = 0; sx < W; sx += 2.5, i++) {
			sc.flat(sx, y0, Math.min(W, sx + 2.5), y1, 0.05, i % 2 ? a : b, 'none');
		}
		if (variant === 'putting') {
			var cx = W / 2, cy = y0 + D / 2, r = Math.min(W, D) * 0.34;
			var pts = sc.ring(cx, cy, r, 0.07, 32).map(function (q, j) {
				return [cx + (q[0] - cx) * 1.25, q[1], q[2]];
			});
			sc.poly(pts, '#8CC067', 'rgba(0,0,0,.1)');
			sc.poly(sc.ring(cx + r * 0.5, cy - r * 0.2, 0.35, 0.08, 12), '#2F3A2A', 'none');
			sc.line([cx + r * 0.5, cy - r * 0.2, 0.08], [cx + r * 0.5, cy - r * 0.2, 6], '#EDEDE8', 1.2);
			sc.poly([[cx + r * 0.5, cy - r * 0.2, 6], [cx + r * 0.5 + 1.8, cy - r * 0.2, 5.4], [cx + r * 0.5, cy - r * 0.2, 4.8]], '#D9473B', 'none');
		}
		var border = has(f, 'edging');
		if (border) {
			sc.box(-0.4, y1, 0, W + 0.8, 0.4, 0.45, CONC);
			sc.box(W, y0, 0, 0.4, D, 0.45, CONC);
		}
	}

	function pavers(sc, W, D, variant, f) {
		var y0 = 1, y1 = y0 + D;
		var base = variant === 'travertine' ? '#EFE6D6' : variant === 'porcelain' ? '#C9C9C4' : '#D6C2A6';
		var joint = variant === 'travertine' ? 'rgba(150,130,100,.35)' : variant === 'porcelain' ? 'rgba(80,80,80,.25)' : 'rgba(120,95,70,.35)';
		var pl = variant === 'travertine' ? 2 : variant === 'porcelain' ? 4 : 1.5;
		var pd = variant === 'travertine' ? 2 : variant === 'porcelain' ? 1.3 : 0.9;
		sc.box(0, y0, 0, W, D, 0.25, [base, '#B9AE9C', '#A89D8B']);
		var z = 0.26, d = '';
		for (var yy = y0 + pd, row = 0; yy < y1; yy += pd, row++) d += sc.d([[0, yy, z], [W, yy, z]]);
		for (var ry = y0, r2 = 0; ry < y1 - 0.01; ry += pd, r2++) {
			var off = (r2 % 2) * pl / 2;
			for (var jx = off; jx < W; jx += pl) {
				if (jx <= 0.01) continue;
				d += sc.d([[jx, ry, z], [jx, Math.min(y1, ry + pd), z]]);
			}
		}
		sc.path(d, joint, 0.6);
		if (has(f, 'seat_wall')) {
			var sw = Math.min(W - 1, 10);
			sc.box(W - sw - 0.5, y0 + 0.6, 0.25, sw, 1.2, 1.8, STONE);
		}
		if (has(f, 'fire_pit')) {
			var cx = W * 0.55, cy = y0 + D * 0.55, r = Math.min(2.4, Math.min(W, D) * 0.18);
			sc.cylinder(cx, cy, r, 1.4, STONE);
			sc.poly(sc.ring(cx, cy, r * 0.62, 1.42, 20), '#3B3632', 'none');
			sc.dot(cx, cy, 2.2, 3.2, '#F2A53A', 0.9);
			sc.dot(cx, cy, 2.6, 1.8, '#FFD86B');
		}
	}

	function fence(sc, L, variant, f) {
		/* Three sides of a square yard: left, back, right */
		var S = Math.max(8, L / 3), H = 6;
		var stucco = variant === 'block' && has(f, 'stucco');
		var walk = has(f, 'walk_gate'), drive = has(f, 'drive_gate');
		var driveW = Math.min(12, S * 0.5), driveX = (S - driveW) / 2;

		function run(x0, y0, x1, y1, gate) {
			/* Draw one straight run, skipping a gate opening if given */
			var segs = gate ? [[0, gate[0]], [gate[1], 1]] : [[0, 1]];
			segs.forEach(function (sg) {
				var ax = x0 + (x1 - x0) * sg[0], ay = y0 + (y1 - y0) * sg[0];
				var bx = x0 + (x1 - x0) * sg[1], by = y0 + (y1 - y0) * sg[1];
				section(ax, ay, bx, by);
			});
		}
		function section(ax, ay, bx, by) {
			var len = Math.hypot(bx - ax, by - ay);
			if (len < 0.2) return;
			var alongX = Math.abs(bx - ax) > Math.abs(by - ay);
			var x = Math.min(ax, bx), y = Math.min(ay, by);
			var t = variant === 'block' ? 0.7 : 0.35;
			if (variant === 'block') {
				var c = stucco ? STUCCO : BLOCK;
				if (alongX) sc.box(x, y - t / 2, 0, len, t, H, c); else sc.box(x - t / 2, y, 0, t, len, H, c);
				if (alongX) sc.box(x - 0.1, y - t / 2 - 0.1, H, len + 0.2, t + 0.2, 0.35, STONE); else sc.box(x - t / 2 - 0.1, y - 0.1, H, t + 0.2, len + 0.2, 0.35, STONE);
				if (!stucco) {
					var dd = '';
					for (var cz = 0.67; cz < H; cz += 0.67) {
						dd += alongX ? sc.d([[x, y + t / 2, cz], [x + len, y + t / 2, cz]]) : sc.d([[x + t / 2, y, cz], [x + t / 2, y + len, cz]]);
					}
					sc.path(dd, 'rgba(90,80,65,.22)', 0.6);
				}
				return;
			}
			if (variant === 'iron') {
				var d = '';
				for (var s = 0; s <= len; s += 0.45) {
					var px = alongX ? x + s : x, py = alongX ? y : y + s;
					d += sc.d([[px, py, 0.3], [px, py, H - 0.3]]);
				}
				sc.path(d, '#33383B', 0.9);
				sc.line(alongX ? [x, y, H - 0.4] : [x, y, H - 0.4], alongX ? [x + len, y, H - 0.4] : [x, y + len, H - 0.4], '#2B2F31', 1.4);
				sc.line(alongX ? [x, y, 0.6] : [x, y, 0.6], alongX ? [x + len, y, 0.6] : [x, y + len, 0.6], '#2B2F31', 1.4);
				for (var ps = 0; ps <= len + 0.01; ps += 6) {
					if (alongX) sc.box(x + Math.min(ps, len) - 0.2, y - 0.2, 0, 0.4, 0.4, H + 0.3, DARK);
					else sc.box(x - 0.2, y + Math.min(ps, len) - 0.2, 0, 0.4, 0.4, H + 0.3, DARK);
				}
				return;
			}
			var cc = variant === 'vinyl' ? VINYL : WOOD;
			if (alongX) sc.box(x, y - t / 2, 0, len, t, H, cc); else sc.box(x - t / 2, y, 0, t, len, H, cc);
			var lines = '';
			var gapB = variant === 'vinyl' ? 6 : 0.55;
			for (var b = gapB; b < len; b += gapB) {
				lines += alongX ? sc.d([[x + b, y + t / 2, 0], [x + b, y + t / 2, H]]) : sc.d([[x + t / 2, y + b, 0], [x + t / 2, y + b, H]]);
			}
			sc.path(lines, variant === 'vinyl' ? 'rgba(0,0,0,.12)' : 'rgba(70,40,15,.25)', variant === 'vinyl' ? 1.2 : 0.6);
		}

		shadow(sc, 0, 0, S, 0.6, 6);
		/* Back run with optional driveway gate */
		run(0, 0, S, 0, drive ? [driveX / S, (driveX + driveW) / S] : null);
		if (drive) {
			var gd = '';
			for (var gx = driveX + 0.3; gx < driveX + driveW; gx += 0.5) gd += sc.d([[gx, 0, 0.4], [gx, 0, H - 0.6]]);
			sc.path(gd, '#33383B', 0.9);
			sc.line([driveX, 0, H - 0.6], [driveX + driveW, 0, H - 0.6], '#2B2F31', 1.5);
			sc.line([driveX + driveW / 2, 0, 0.4], [driveX + driveW / 2, 0, H - 0.6], '#2B2F31', 1.5);
		}
		run(0, 0, 0, S, null);
		var walkFrom = 0.55, walkTo = Math.min(0.95, walkFrom + 4 / S);
		run(S, 0, S, S, walk ? [walkFrom, walkTo] : null);
		if (walk) {
			var g0 = S * walkFrom, g1 = S * walkTo;
			sc.poly([[S, g0, 0.3], [S, g1, 0.3], [S, g1, H - 0.4], [S, g0, H - 0.4]], variant === 'vinyl' ? '#FAFAF7' : variant === 'wood' ? '#B98352' : 'rgba(50,55,58,.25)', variant === 'iron' || variant === 'block' ? '#2B2F31' : 'rgba(0,0,0,.3)', 1.1);
			sc.dot(S + 0.05, g0 + 0.4, 3.2, 1.3, '#555');
		}
	}

	/* ---------- Public entry ---------- */

	/**
	 * opts: { scene, variant, dims: [w, d] or [len], max: [w, d] or [len], features: [] }
	 */
	function render(svg, opts) {
		while (svg.firstChild) svg.removeChild(svg.firstChild);
		svg.setAttribute('viewBox', '0 0 ' + VW + ' ' + VH);
		var sc = new Scene(svg);
		var f = opts.features || [];
		var W = opts.dims[0], D = opts.dims[1] || 0;
		var MW = Math.max(opts.max[0] || W, W), MD = Math.max(opts.max[1] || D, D);

		/* Frame the current size with room to grow, capped at the slider max */
		function extent(cur, max) { return Math.min(Math.max(max, cur), Math.max(cur * 1.4, cur + 8)); }

		if (opts.scene === 'fence') {
			var S = Math.max(8, W / 3);
			var ES = extent(S, Math.max(8, MW / 3));
			var off = (ES - S) / 2;
			sc.fit(-off - 3, S + off + 3, -off - 3, S + off + 3, 7);
			backdrop(sc);
			sc.flat(0.4, 0.4, S, S, 0.01, '#DED5BF', 'none');
			fence(sc, W, opts.variant, f);
			return;
		}

		var attached = ['patio_cover', 'sunroom', 'enclosure'].indexOf(opts.scene) !== -1;
		var EW = extent(W, MW), ED = extent(D, MD);
		var padX = (EW - W) / 2 + 3;
		var x0 = -padX, x1 = W + padX;
		var lead = opts.scene === 'pergola' ? 2.5 : attached ? 0 : 1;
		var front = lead + D + (ED - D) * 0.6 + 2;
		sc.fit(x0, x1, -3, front, 11.5);
		backdrop(sc);
		var wins = [x0 + 1];
		if (x1 - W > 6) wins.push(W + 1.5);
		house(sc, x0 - 20, x1 + 20, { windows: wins, door: (attached || opts.scene === 'pergola') ? Math.max(0.5, W / 2 - 3) : undefined });

		switch (opts.scene) {
			case 'patio_cover': patioCover(sc, W, D, opts.variant, f); break;
			case 'pergola': pergola(sc, W, D, opts.variant, f); break;
			case 'sunroom': sunroom(sc, W, D, opts.variant, f); break;
			case 'enclosure': enclosure(sc, W, D, opts.variant, f); break;
			case 'landscape': landscape(sc, W, D, opts.variant, f); break;
			case 'turf': turf(sc, W, D, opts.variant, f); break;
			case 'pavers': pavers(sc, W, D, opts.variant, f); break;
		}
	}

	window.PEPreview = { render: render };
})();
