/*!
 * farm_engine.js — Sawah Panen Cuan 3D engine
 *
 * Isi file (semua global, tanpa bundler — dimuat setelah three r128, OrbitControls & GSAP):
 *   FarmAudio   synthesizer Web Audio API (tanpa file mp3) + haptic
 *   FarmAPI     dispatcher fetch() ke /api/farm_action.php (header CSRF)
 *   FarmEngine  Scene, kamera, lampu, mesh prosedural, state 12 petak,
 *               raycast (tap), partikel, dan sekuens animasi "juicy".
 *
 * Anggaran performa (target 60 FPS di HP):
 *   - Semua dekorasi statis digabung jadi SATU mesh ber-vertex-color. Outline
 *     lembut (hijau tua) adalah "inverted hull" yang ikut digabung di geometri
 *     yang sama (winding dibalik), jadi outline tidak menambah draw call.
 *   - Elemen per petak (tanah, gembok, tanaman, sabit, hama, dst.) memakai
 *     InstancedMesh: satu draw call untuk 12 petak.
 *   - Timer, label & teks melayang adalah elemen HTML yang diproyeksikan dari
 *     posisi 3D: tajam di semua DPI dan 0 draw call.
 *   - Bayangan awan memakai caster khusus di layer 1 yang hanya dilihat kamera
 *     bayangan matahari (tidak dirender kamera utama).
 *   Total ± 20 draw call (cek: engine.renderer.info.render.calls).
 */
(function (global) {
  'use strict';
  const THREE = global.THREE;

  /* ══════════════════════════════════════════════════════════
     KONSTANTA TATA LETAK & WARNA
     ══════════════════════════════════════════════════════════ */
  const PLOT = 2.8, GAP = 0.6, COLS = 4, ROWS = 3, PITCH = PLOT + GAP;
  const GRID_Z = -1;                 // pusat grid digeser ke belakang memberi ruang sungai
  const SOIL_TOP = 0.34;             // tinggi permukaan tanah petak
  const FENCE = { x0: -7.0, x1: 7.0, z0: -6.6, z1: 4.6, gate: 1.1 };
  const STREAM = { x0: -21, x1: 16.5, z0: 6.2, z1: 7.8, y: 0.07 };
  const POND = { x: 19.8, z: 7, r: 3.8 };
  const FALL_H = 5.5;                // tinggi air terjun di kaki gunung barat
  const HUT = new THREE.Vector3(-9.8, 0, -7.8);        // saung (gubuk sawah)
  const LUMBUNG = new THREE.Vector3(10.4, 0, -7.6);    // lumbung padi
  const WHEEL = new THREE.Vector3(-12.6, 0, 7);        // kincir air di sungai
  const WHEEL_Y = 1.15;
  const FLAG = new THREE.Vector3(2.5, 0, 5.3);         // tiang bendera merah putih
  const SCARECROW = new THREE.Vector3(-8.3, 0, -3.6);
  const PAN_LIMIT = 6;
  const SAIL_SPEED = 0.012;          // putaran kincir air, rad per frame @60fps
  const OUTLINE_SCALE = 0.6;         // ketebalan outline (1 = tebal ala kartun, 0.6 = halus)

  const C = {
    ink: '#24452f', grass: '#10b981', grassSide: '#059669', dirt: '#78350f', dirtDeep: '#4a1d06',
    soil: '#451a03', soilLocked: '#8a6a43', soilPest: '#6b6461',
    wood: '#92400e', woodLight: '#b45309', plank: '#d97706', rim: '#7c2d12',
    stone: '#a8a29e', stoneDark: '#78716c', white: '#ffffff', cream: '#fef3c7',
    leaf: '#16a34a', leafLight: '#4ade80', leafDark: '#15803d', pine: '#166534', pineLight: '#15803d',
    birch: '#84cc16', birch2: '#a3e635', gold: '#facc15', goldDeep: '#f59e0b', silver: '#e5e7eb',
    red: '#dc2626', blue: '#2563eb', straw: '#fde68a', sky: '#38bdf8', pink: '#f9a8d4',
  };

  /** Posisi pusat petak (1..12) di dunia. */
  function plotCenter(i) {
    const k = i - 1;
    return new THREE.Vector3(
      -((COLS - 1) * PITCH) / 2 + (k % COLS) * PITCH,
      0,
      GRID_Z - PITCH + Math.floor(k / COLS) * PITCH
    );
  }

  /** PRNG deterministik (mulberry32) agar dekorasi selalu sama di setiap muat. */
  function rng(seed) {
    let a = seed >>> 0;
    return function () {
      a |= 0; a = (a + 0x6D2B79F5) | 0;
      let t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const lerp = (a, b, t) => a + (b - a) * t;
  const fmtRp = (n) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');

  /* ══════════════════════════════════════════════════════════
     GEO BUILDER — gabung banyak primitive + outline jadi 1 geometri
     ══════════════════════════════════════════════════════════ */
  const _eu = new THREE.Euler(), _qt = new THREE.Quaternion(), _up = new THREE.Vector3(0, 1, 0);

  /** Matriks dari opsi {p:[x,y,z], r:[x,y,z], q:Quaternion, s:number|[x,y,z]}. */
  function xform(o) {
    const q = o.q ? o.q.clone() : _qt.setFromEuler(_eu.set(...(o.r || [0, 0, 0]))).clone();
    const s = o.s == null ? [1, 1, 1] : (typeof o.s === 'number' ? [o.s, o.s, o.s] : o.s);
    return new THREE.Matrix4().compose(new THREE.Vector3(...(o.p || [0, 0, 0])), q, new THREE.Vector3(...s));
  }

  class Geo {
    constructor(seed = 7) { this.parts = []; this.rand = rng(seed); }

    /**
     * Tambah primitive. color: hex atau fn(local, world, normal) → THREE.Color.
     * o.fruit=true → bagian ini diwarnai instanceColor (buah, tanah).
     * o.ol = tebal outline (false = tanpa outline).
     */
    add(geo, color, o = {}) {
      const g = geo.index ? geo.toNonIndexed() : geo.clone();
      geo.dispose();
      const local = g.attributes.position.array.slice();
      g.applyMatrix4(xform(o));
      this.parts.push({
        pos: g.attributes.position.array, local, color,
        fruit: o.fruit ? 1 : 0, ol: o.ol, jitter: o.jitter == null ? 0.05 : o.jitter,
      });
      g.dispose();
      return this;
    }

    /** Batang silinder dari titik base ke arah dir sepanjang len. */
    stick(base, dir, len, r0, r1, color, o = {}) {
      const d = dir.clone().normalize();
      const q = new THREE.Quaternion().setFromUnitVectors(_up, d);
      const c = base.clone().addScaledVector(d, len / 2);
      return this.add(new THREE.CylinderGeometry(r1, r0, len, o.seg || 5), color, Object.assign({}, o, { p: [c.x, c.y, c.z], q }));
    }

    build(thick = 0.05) {
      let n = 0;
      for (const p of this.parts) n += (p.pos.length / 3) * (p.ol === false ? 1 : 2);
      const pos = new Float32Array(n * 3), col = new Float32Array(n * 3), fr = new Float32Array(n);
      const tc = new THREE.Color(), ink = new THREE.Color(C.ink);
      const L = new THREE.Vector3(), W = new THREE.Vector3(), N = new THREE.Vector3();
      const a = new THREE.Vector3(), b = new THREE.Vector3(), c = new THREE.Vector3();
      let o = 0;

      for (const p of this.parts) {
        const P = p.pos, cnt = P.length / 3;
        // 1) permukaan berwarna (faceted: tiap segitiga sedikit di-jitter agar low-poly terasa)
        for (let t = 0; t < cnt; t += 3) {
          a.fromArray(P, t * 3); b.fromArray(P, t * 3 + 3); c.fromArray(P, t * 3 + 6);
          N.subVectors(c, b).cross(a.clone().sub(b)).normalize();
          const j = 1 + (this.rand() * 2 - 1) * p.jitter;
          for (let k = 0; k < 3; k++) {
            const vi = (t + k) * 3;
            if (typeof p.color === 'function') {
              L.fromArray(p.local, vi); W.fromArray(P, vi);
              tc.copy(p.color(L, W, N));
            } else tc.set(p.color);
            pos[o * 3] = P[vi]; pos[o * 3 + 1] = P[vi + 1]; pos[o * 3 + 2] = P[vi + 2];
            col[o * 3] = tc.r * j; col[o * 3 + 1] = tc.g * j; col[o * 3 + 2] = tc.b * j;
            fr[o] = p.fruit; o++;
          }
        }
        if (p.ol === false) continue;
        // 2) inverted hull: geser vertex sepanjang normal halus, balik winding, warnai hitam
        const tk = (p.ol == null ? thick : p.ol) * OUTLINE_SCALE;
        const key = (i) => Math.round(P[i] * 1e3) + '|' + Math.round(P[i + 1] * 1e3) + '|' + Math.round(P[i + 2] * 1e3);
        const acc = new Map();
        for (let t = 0; t < cnt; t += 3) {
          a.fromArray(P, t * 3); b.fromArray(P, t * 3 + 3); c.fromArray(P, t * 3 + 6);
          N.subVectors(c, b).cross(a.clone().sub(b)); // berbobot luas
          for (let k = 0; k < 3; k++) {
            const kk = key((t + k) * 3);
            const v = acc.get(kk) || acc.set(kk, new THREE.Vector3()).get(kk);
            v.add(N);
          }
        }
        acc.forEach((v) => v.normalize());
        for (let t = 0; t < cnt; t += 3) {
          for (const k of [0, 2, 1]) {
            const vi = (t + k) * 3, nn = acc.get(key(vi));
            pos[o * 3] = P[vi] + nn.x * tk; pos[o * 3 + 1] = P[vi + 1] + nn.y * tk; pos[o * 3 + 2] = P[vi + 2] + nn.z * tk;
            col[o * 3] = ink.r; col[o * 3 + 1] = ink.g; col[o * 3 + 2] = ink.b;
            fr[o] = 0; o++;
          }
        }
      }
      const g = new THREE.BufferGeometry();
      g.setAttribute('position', new THREE.BufferAttribute(pos, 3));
      g.setAttribute('color', new THREE.BufferAttribute(col, 3));
      g.setAttribute('fruit', new THREE.BufferAttribute(fr, 1));
      g.computeVertexNormals();
      g.computeBoundingSphere();
      this.parts = [];
      return g;
    }
  }

  /* ══════════════════════════════════════════════════════════
     MATERIAL
     ══════════════════════════════════════════════════════════ */
  /** Gradient 3-tingkat untuk cel-shading toon. */
  function toonGradient() {
    const d = new Uint8Array([30, 30, 30, 255, 68, 68, 68, 255, 98, 98, 98, 255]);
    const t = new THREE.DataTexture(d, 3, 1, THREE.RGBAFormat);
    t.minFilter = t.magFilter = THREE.NearestFilter;
    t.generateMipmaps = false;
    t.needsUpdate = true;
    return t;
  }

  /**
   * Material toon ber-vertex-color. instanceColor hanya mewarnai vertex dengan
   * atribut fruit=1 (buah/tanah), batang & outline tetap warna aslinya.
   */
  function tintMaterial(gradientMap, extra = {}) {
    const m = new THREE.MeshToonMaterial(Object.assign({ vertexColors: true, gradientMap }, extra));
    m.onBeforeCompile = (sh) => {
      sh.vertexShader = 'attribute float fruit;\n' + sh.vertexShader.replace(
        'vColor.xyz *= instanceColor.xyz;',
        'vColor.xyz *= mix(vec3(1.0), instanceColor.xyz, fruit);'
      );
    };
    return m;
  }

  /* ══════════════════════════════════════════════════════════
     AUDIO SYNTH (Web Audio API — nol aset eksternal)
     ══════════════════════════════════════════════════════════ */
  class FarmAudio {
    constructor() {
      this.ctx = null;
      this.master = null;
      try { this.muted = localStorage.getItem('farm_muted') === '1'; } catch (e) { this.muted = false; }
    }

    /** Wajib dipanggil dari gesture user (tap) — kebijakan autoplay browser. */
    unlock() {
      if (!this.ctx) {
        const AC = global.AudioContext || global.webkitAudioContext;
        if (!AC) return;
        this.ctx = new AC();
        const comp = this.ctx.createDynamicsCompressor();
        this.master = this.ctx.createGain();
        this.master.gain.value = 0.55;
        this.master.connect(comp).connect(this.ctx.destination);
      }
      if (this.ctx.state === 'suspended') this.ctx.resume();
    }

    setMuted(m) {
      this.muted = !!m;
      try { localStorage.setItem('farm_muted', this.muted ? '1' : '0'); } catch (e) { /* storage diblokir */ }
    }

    _ok() { return this.ctx && !this.muted && this.ctx.state === 'running'; }

    /** Satu nada dengan envelope attack-decay; opsional sweep frekuensi. */
    tone(freq, dur, type = 'sine', vol = 0.3, when = 0, freqEnd = null) {
      if (!this._ok()) return;
      const t = this.ctx.currentTime + when;
      const o = this.ctx.createOscillator(), g = this.ctx.createGain();
      o.type = type;
      o.frequency.setValueAtTime(freq, t);
      if (freqEnd) o.frequency.exponentialRampToValueAtTime(freqEnd, t + dur * 0.8);
      g.gain.setValueAtTime(0.0001, t);
      g.gain.exponentialRampToValueAtTime(vol, t + 0.008);
      g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
      o.connect(g).connect(this.master);
      o.start(t); o.stop(t + dur + 0.02);
    }

    /** Burst noise terfilter (air, swoosh). */
    noise(dur, f0, f1, vol = 0.25, when = 0, q = 1.2) {
      if (!this._ok()) return;
      const t = this.ctx.currentTime + when, len = Math.ceil(this.ctx.sampleRate * dur);
      const buf = this.ctx.createBuffer(1, len, this.ctx.sampleRate), d = buf.getChannelData(0);
      for (let i = 0; i < len; i++) d[i] = Math.random() * 2 - 1;
      const src = this.ctx.createBufferSource(), bp = this.ctx.createBiquadFilter(), g = this.ctx.createGain();
      src.buffer = buf; bp.type = 'bandpass'; bp.Q.value = q;
      bp.frequency.setValueAtTime(f0, t);
      bp.frequency.exponentialRampToValueAtTime(f1, t + dur);
      g.gain.setValueAtTime(0.0001, t);
      g.gain.exponentialRampToValueAtTime(vol, t + dur * 0.15);
      g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
      src.connect(bp).connect(g).connect(this.master);
      src.start(t); src.stop(t + dur + 0.02);
    }

    /** "pop/blip" marimba: sweep 300→600 Hz + harmonik lembut. */
    soundPlant() { this.tone(300, 0.18, 'sine', 0.45, 0, 600); this.tone(1200, 0.08, 'sine', 0.08, 0.01); }
    /** Arpeggio mayor C5-E5-G5-C6 dengan kilau. */
    soundHarvest() {
      [523.25, 659.25, 783.99, 1046.5].forEach((f, i) => { this.tone(f, 0.32, 'triangle', 0.28, i * 0.075); this.tone(f * 2, 0.18, 'sine', 0.05, i * 0.075 + 0.02); });
      this.tone(2093, 0.5, 'sine', 0.05, 0.32);
    }
    /** Ping logam ganda 987 Hz & 1318 Hz. */
    soundCoin(vol = 0.25) { this.tone(987.77, 0.12, 'triangle', vol, 0); this.tone(1318.51, 0.28, 'triangle', vol, 0.075); }
    soundWater() { this.noise(0.7, 900, 3200, 0.22, 0, 0.8); this.noise(0.5, 2500, 1500, 0.08, 0.15, 2); }
    soundError() { this.tone(150, 0.2, 'square', 0.16); this.tone(110, 0.16, 'square', 0.1, 0.09); }
    soundSwoosh() { this.noise(0.28, 350, 3200, 0.3, 0, 1.6); }
    soundUnlock() { this.soundCoin(0.22); [392, 523.25, 783.99].forEach((f, i) => this.tone(f, 0.25, 'sine', 0.2, 0.12 + i * 0.07)); }
    soundTick() { this.tone(1800, 0.03, 'square', 0.05); }

    vibrate(p) { try { if (navigator.vibrate) navigator.vibrate(p); } catch (e) { /* tidak didukung */ } }
  }

  /* ══════════════════════════════════════════════════════════
     API DISPATCHER
     ══════════════════════════════════════════════════════════ */
  class FarmAPI {
    constructor(url, csrf) { this.url = url; this.csrf = csrf; }

    async call(action, payload = {}) {
      try {
        const r = await fetch(this.url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': this.csrf },
          body: JSON.stringify(Object.assign({ action }, payload)),
        });
        const j = await r.json().catch(() => null);
        if (!j) return { ok: false, msg: 'Respon server tidak valid (' + r.status + ').', reason: 'server' };
        return j;
      } catch (e) {
        return { ok: false, msg: 'Koneksi terputus. Cek internet kamu ya.', reason: 'network' };
      }
    }
  }

  /* ══════════════════════════════════════════════════════════
     PARTIKEL — satu THREE.Points (1 draw call) dengan pool ring-buffer
     shape: 0 bulatan lembut · 1 kilau bintang · 2 cincin asap · 3 confetti kotak
     ══════════════════════════════════════════════════════════ */
  class Particles {
    constructor(max = 800) {
      this.max = max; this.cursor = 0;
      this.pos = new Float32Array(max * 3); this.col = new Float32Array(max * 3);
      this.size = new Float32Array(max); this.alpha = new Float32Array(max); this.shape = new Float32Array(max);
      this.vel = new Float32Array(max * 3); this.life = new Float32Array(max); this.age = new Float32Array(max);
      this.grav = new Float32Array(max); this.drag = new Float32Array(max);
      this.s0 = new Float32Array(max); this.s1 = new Float32Array(max); this.a0 = new Float32Array(max);
      this.alive = 0;

      const g = new THREE.BufferGeometry();
      g.setAttribute('position', new THREE.BufferAttribute(this.pos, 3).setUsage(THREE.DynamicDrawUsage));
      g.setAttribute('pcolor', new THREE.BufferAttribute(this.col, 3).setUsage(THREE.DynamicDrawUsage));
      g.setAttribute('psize', new THREE.BufferAttribute(this.size, 1).setUsage(THREE.DynamicDrawUsage));
      g.setAttribute('palpha', new THREE.BufferAttribute(this.alpha, 1).setUsage(THREE.DynamicDrawUsage));
      g.setAttribute('pshape', new THREE.BufferAttribute(this.shape, 1).setUsage(THREE.DynamicDrawUsage));
      this.mat = new THREE.ShaderMaterial({
        transparent: true, depthWrite: false,
        uniforms: { uScale: { value: 500 } },
        vertexShader: `
          attribute vec3 pcolor; attribute float psize; attribute float palpha; attribute float pshape;
          uniform float uScale;
          varying vec3 vColor; varying float vAlpha; varying float vShape;
          void main() {
            vColor = pcolor; vAlpha = palpha; vShape = pshape;
            vec4 mv = modelViewMatrix * vec4(position, 1.0);
            gl_PointSize = min(psize * uScale / max(-mv.z, 0.1), 160.0);
            gl_Position = projectionMatrix * mv;
          }`,
        fragmentShader: `
          varying vec3 vColor; varying float vAlpha; varying float vShape;
          void main() {
            vec2 p = gl_PointCoord * 2.0 - 1.0;
            float d = length(p), a = 0.0; vec3 col = vColor;
            if (vShape < 0.5) { a = smoothstep(1.0, 0.55, d); }
            else if (vShape < 1.5) {
              float cr = max(0.0, 1.0 - abs(p.x) * 7.0) * (1.0 - abs(p.y)) + max(0.0, 1.0 - abs(p.y) * 7.0) * (1.0 - abs(p.x));
              a = clamp(cr + smoothstep(0.45, 0.0, d), 0.0, 1.0);
              col = mix(col, vec3(1.0), smoothstep(0.35, 0.0, d));
            }
            else if (vShape < 2.5) { a = smoothstep(0.28, 0.05, abs(d - 0.66)); }
            else {
              float m = max(abs(p.x), abs(p.y) * 1.6);
              a = step(m, 0.85);
              a *= 1.0 - 0.25 * step(0.62, m);
            }
            if (a * vAlpha < 0.02) discard;
            gl_FragColor = vec4(col, a * vAlpha);
          }`,
      });
      this.points = new THREE.Points(g, this.mat);
      this.points.frustumCulled = false;
      this.points.renderOrder = 10;
      this._c = new THREE.Color();
    }

    /** Lepas satu partikel. o: {p:Vector3, v:[x,y,z], c:hex, s, s1, life, g, drag, shape, a} */
    emit(o) {
      const i = this.cursor; this.cursor = (this.cursor + 1) % this.max;
      const i3 = i * 3;
      this.pos[i3] = o.p.x; this.pos[i3 + 1] = o.p.y; this.pos[i3 + 2] = o.p.z;
      this.vel[i3] = o.v[0]; this.vel[i3 + 1] = o.v[1]; this.vel[i3 + 2] = o.v[2];
      this._c.set(o.c);
      this.col[i3] = this._c.r; this.col[i3 + 1] = this._c.g; this.col[i3 + 2] = this._c.b;
      this.s0[i] = o.s || 0.2; this.s1[i] = o.s1 == null ? 0 : o.s1;
      this.a0[i] = o.a == null ? 1 : o.a;
      this.life[i] = o.life || 1; this.age[i] = 0;
      this.grav[i] = o.g || 0; this.drag[i] = o.drag || 0; this.shape[i] = o.shape || 0;
      this.size[i] = this.s0[i]; this.alpha[i] = this.a0[i];
    }

    update(dt) {
      let alive = 0;
      for (let i = 0; i < this.max; i++) {
        if (this.age[i] >= this.life[i]) { if (this.size[i] !== 0) { this.size[i] = 0; this.alpha[i] = 0; } continue; }
        alive++;
        this.age[i] += dt;
        const t = clamp(this.age[i] / this.life[i], 0, 1), i3 = i * 3, k = Math.max(0, 1 - this.drag[i] * dt);
        this.vel[i3 + 1] += this.grav[i] * dt;
        this.vel[i3] *= k; this.vel[i3 + 1] *= k; this.vel[i3 + 2] *= k;
        this.pos[i3] += this.vel[i3] * dt; this.pos[i3 + 1] += this.vel[i3 + 1] * dt; this.pos[i3 + 2] += this.vel[i3 + 2] * dt;
        this.size[i] = lerp(this.s0[i], this.s1[i], t);
        this.alpha[i] = this.a0[i] * (t < 0.7 ? 1 : 1 - (t - 0.7) / 0.3);
      }
      if (alive || this.alive) {
        const a = this.points.geometry.attributes;
        a.position.needsUpdate = a.pcolor.needsUpdate = a.psize.needsUpdate = a.palpha.needsUpdate = a.pshape.needsUpdate = true;
      }
      this.alive = alive;
    }
  }

  /* ══════════════════════════════════════════════════════════
     MODEL PROSEDURAL
     ══════════════════════════════════════════════════════════ */
  const V = (x, y, z) => new THREE.Vector3(x, y, z);
  const PLANT_OFFSETS = [[-0.6, -0.6], [0.6, -0.6], [-0.6, 0.6], [0.6, 0.6]];

  /* ══════════════════════════════════════════════════════════
     TATA LETAK PEDESAAN (sawah Indonesia)
     Arah "latar" = arah pandang kamera awal (−x, −z): di sana berdiri dua gunung
     berapi dengan matahari di tengahnya — pemandangan khas gambar anak Indonesia.
     ══════════════════════════════════════════════════════════ */
  const BACK = V(-1, 0, -1).normalize(), SIDE = V(1, 0, -1).normalize();
  const VOLCANOES = [
    { p: BACK.clone().multiplyScalar(74).addScaledVector(SIDE, 24), r: 30, h: 31, top: 3.2 },
    { p: BACK.clone().multiplyScalar(80).addScaledVector(SIDE, -26), r: 27, h: 26, top: 2.6 },
  ];
  const CRATER = V(VOLCANOES[0].p.x, VOLCANOES[0].h - 0.5, VOLCANOES[0].p.z);
  const FIXED_TREES = [
    { type: 'palm', x: -10.4, z: 2.4 }, { type: 'palm', x: 10.6, z: -3.6 }, { type: 'palm', x: -10.8, z: -1.6 },
    { type: 'palm', x: 6.6, z: -9.6 }, { type: 'palm', x: -6.4, z: -10.2 }, { type: 'banana', x: -4.4, z: 9.7 },
    { type: 'banana', x: -8.0, z: 9.6 },
    { type: 'banana', x: 9.9, z: 3.9 }, { type: 'banana', x: -3.6, z: -9.2 }, { type: 'banana', x: -11.2, z: 5.0 },
    { type: 'banana', x: 2.6, z: -9.6 }, { type: 'banana', x: 12.0, z: -0.9 },
  ];
  const BAMBOO = [{ x: -12.6, z: -4.4 }, { x: 12.8, z: 2.2 }, { x: -6.6, z: 11.6 }, { x: 13.4, z: -6.0 }];

  /** Petak sawah tergenang di lembah (di luar pagar kebun). stage: <.3 baru tanam · <.75 hijau · selebihnya menguning. */
  const PADDIES = (() => {
    const out = [], R = rng(91), W = 3.3, D = 2.7;
    const avoid = [HUT, LUMBUNG, SCARECROW, WHEEL, FLAG];
    for (let gx = -8; gx <= 8; gx++) {
      for (let gz = -10; gz <= 9; gz++) {
        const x = gx * W + 0.35, z = gz * D + 0.2, d = Math.hypot(x, z);
        if (d < 9.5 || d > 26) continue;
        if (x > FENCE.x0 - 2.7 && x < FENCE.x1 + 2.7 && z > FENCE.z0 - 2.6 && z < STREAM.z1 + 2.2) continue; // kebun, jalan, sungai depan
        if (z > STREAM.z0 - 2.0 && z < STREAM.z1 + 2.0) continue;                                            // sungai
        if (Math.hypot(x - POND.x, z - POND.z) < POND.r + 2.8) continue;                                       // kolam
        if (Math.hypot(x - (STREAM.x0 - 2), z - 7) < 8) continue;                                              // tebing air terjun
        if (avoid.some((p) => Math.hypot(p.x - x, p.z - z) < 3.6)) continue;
        if (FIXED_TREES.some((t) => Math.abs(t.x - x) < W / 2 + 0.6 && Math.abs(t.z - z) < D / 2 + 0.6)) continue;
        if (BAMBOO.some((b) => Math.hypot(b.x - x, b.z - z) < 3.2)) continue;
        if (R() < 0.12) continue; // celah alami untuk pohon
        out.push({ x, z, w: W - 0.24, d: D - 0.24, stage: R() });
      }
    }
    return out;
  })();
  const inPaddy = (x, z, m = 0.3) => PADDIES.some((p) => Math.abs(p.x - x) < p.w / 2 + m && Math.abs(p.z - z) < p.d / 2 + m);

  /** Warna dua-nada: atas (normal ke atas) vs sisi — untuk pematang & terasering. */
  const topSide = (top, side) => { const a = new THREE.Color(top), b = new THREE.Color(side); return (l, w, n) => (n.y > 0.55 ? a : b); };
  const BAMBOO_C = ['#c9b45a', '#b9a24a', '#d4c06a'];

  /** Lembah sawah + gunung berapi + terasering + saung + lumbung + pagar bambu + kincir air (rangka) → satu geometri. */
  function buildStaticWorld() {
    const g = new Geo(11), R = g.rand;
    const tmp = new THREE.Color();

    // Hamparan rumput lembah tropis (luas, menghilang di kabut)
    const g1 = new THREE.Color('#4c9532'), g2 = new THREE.Color('#5ea33a'), g3 = new THREE.Color('#3a7a2e');
    g.add(new THREE.CircleGeometry(95, 64, 0, Math.PI * 2), (l, w) => {
      const n = Math.sin(w.x * 0.21) * Math.cos(w.z * 0.17) + Math.sin(w.x * 0.07 + w.z * 0.09);
      const far = clamp((Math.hypot(w.x, w.z) - 22) / 30, 0, 1);
      return tmp.copy(g1).lerp(g2, clamp(n * 0.25 + 0.25, 0, 0.5)).lerp(g3, far * 0.45);
    }, { r: [-Math.PI / 2, 0, 0], ol: false, jitter: 0.03 });

    // Dua gunung berapi (latar) — lereng hutan, alur lahar, kawah gelap
    const vLow = new THREE.Color('#3f8f3a'), vMid = new THREE.Color('#2d6a3e'), vHigh = new THREE.Color('#7b7564'), vTop = new THREE.Color('#5d554c'), vGully = new THREE.Color('#25573a');
    VOLCANOES.forEach((v, i) => {
      g.add(new THREE.CylinderGeometry(v.top, v.r, v.h, 26, 7), (l, w, n) => {
        if (n.y > 0.9 && w.y > v.h * 0.95) return tmp.set('#463d36');
        const t = clamp(w.y / v.h, 0, 1), ang = Math.atan2(l.x, l.z);
        tmp.copy(vLow).lerp(vMid, clamp(t / 0.45, 0, 1));
        if (t > 0.45) tmp.lerp(vHigh, clamp((t - 0.45) / 0.35, 0, 1));
        if (t > 0.82) tmp.lerp(vTop, clamp((t - 0.82) / 0.18, 0, 1));
        if (Math.sin(ang * 13 + i) > 0.55 && t < 0.8) tmp.lerp(vGully, 0.5);
        return tmp;
      }, { p: [v.p.x, v.h / 2 - 0.4, v.p.z], ol: 0.22, jitter: 0.05 });
      // Kaki gunung bergelombang
      for (let k = 0; k < 5; k++) {
        const a = R() * Math.PI * 2, rr = v.r * (0.85 + R() * 0.2);
        g.add(new THREE.DodecahedronGeometry(6 + R() * 4, 1), (l, w) => tmp.copy(vLow).lerp(vMid, clamp(w.y / 6, 0, 1)),
          { p: [v.p.x + Math.cos(a) * rr, -1, v.p.z + Math.sin(a) * rr], s: [1.5, 0.55, 1.3], ol: 0.12 });
      }
    });

    // Barisan pegunungan hijau di sekeliling (tanpa salju, berkabut biru di kejauhan)
    const mBase = new THREE.Color('#3d7d3a'), mTop = new THREE.Color('#5b8f63'), mFar = new THREE.Color('#7fa99a');
    for (let i = 0; i < 30; i++) {
      const a = (i / 30) * Math.PI * 2 + R() * 0.15;
      let da = Math.abs(((a - Math.atan2(BACK.z, BACK.x)) % (Math.PI * 2) + Math.PI * 3) % (Math.PI * 2) - Math.PI);
      if (da < 0.62) continue; // beri ruang gunung berapi
      const r = 48 + R() * 12, h = 9 + R() * 10, br = 9 + R() * 6;
      g.add(new THREE.ConeGeometry(br, h, 7, 2), (l, w) => tmp.copy(mBase).lerp(mTop, clamp(w.y / h, 0, 1)).lerp(mFar, 0.25),
        { p: [Math.cos(a) * r, h / 2 - 0.5, Math.sin(a) * r], r: [0, R() * 3, 0], ol: 0.16, jitter: 0.06 });
    }

    // Bukit terasering (subak) mengelilingi lembah
    const tierTops = ['#86d052', '#a2df6a', '#9ad3cf', '#e0c556', '#79c447'];
    for (let i = 0; i < 20; i++) {
      const a = (i / 20) * Math.PI * 2 + R() * 0.2, r = 31 + R() * 6;
      const hx = Math.cos(a) * r, hz = Math.sin(a) * r;
      if (Math.abs(hz - 7) < 5 && hx > 13) continue; // kolam timur
      const br = 6 + R() * 3, tiers = 4 + Math.floor(R() * 3), sx = 1.25 + R() * 0.3, rot = R() * 3;
      for (let k = 0; k < tiers; k++) {
        const rk = br * (1 - k * (0.8 / tiers));
        g.add(new THREE.CylinderGeometry(rk, rk + 0.35, 0.72, 16), topSide(tierTops[Math.floor(R() * tierTops.length)], k % 2 ? '#6b7a34' : '#5f6d2e'),
          { p: [hx, k * 0.72 - 0.2, hz], s: [sx, 1, 1], r: [0, rot, 0], ol: 0.06, jitter: 0.03 });
      }
      if (R() < 0.6) palmTrunk(g, hx + (R() - 0.5) * 3, hz + (R() - 0.5) * 3, tiers * 0.72 - 0.2, R, 0.8); // pohon kelapa di puncak
    }

    // Tebing batu & air terjun sumber sungai (barat), rimbun paku-pakuan
    const fx = STREAM.x0;
    g.add(new THREE.DodecahedronGeometry(4.2, 0), C.stoneDark, { p: [fx - 3.2, FALL_H * 0.42, 7], s: [1, 1.35, 1.3], r: [0.2, 0.5, 0], ol: 0.12 });
    g.add(new THREE.DodecahedronGeometry(2.4, 0), C.stone, { p: [fx - 1.6, FALL_H - 0.6, 4.8], r: [0.4, 0.2, 0.3], ol: 0.08 });
    g.add(new THREE.DodecahedronGeometry(2.2, 0), C.stone, { p: [fx - 1.4, FALL_H - 0.8, 9.3], r: [0.1, 0.9, 0.2], ol: 0.08 });
    for (let i = 0; i < 9; i++) g.add(new THREE.IcosahedronGeometry(0.7 + R() * 0.5, 0), R() < 0.5 ? '#3f9a3a' : '#2f7d33', { p: [fx - 2 - R() * 3, FALL_H * (0.4 + R() * 0.7), 4.6 + R() * 5], s: [1.3, 0.7, 1.1], ol: 0.05 });
    for (let i = 0; i < 7; i++) {
      const a = Math.PI * 0.5 + (i / 6) * Math.PI;
      g.add(new THREE.DodecahedronGeometry(0.35 + R() * 0.2, 0), C.stoneDark, { p: [fx + 0.6 + Math.cos(a) * -0.5, 0.15, 7 + Math.sin(a) * 1.1], r: [R(), R(), R()], s: [1, 0.7, 1], ol: 0.03 });
    }
    // Kolam (timur) dengan batu tepian & teratai
    for (let i = 0; i < 22; i++) {
      const a = (i / 22) * Math.PI * 2;
      if (Math.cos(a) < -0.85 && Math.abs(Math.sin(a)) < 0.3) continue; // celah masuk sungai
      g.add(new THREE.DodecahedronGeometry(0.32 + R() * 0.22, 0), R() < 0.5 ? C.stone : C.stoneDark,
        { p: [POND.x + Math.cos(a) * (POND.r + 0.15), 0.1, POND.z + Math.sin(a) * (POND.r + 0.15)], r: [R(), R(), R()], s: [1, 0.6, 1], ol: 0.03 });
    }
    for (let i = 0; i < 6; i++) {
      const a = R() * Math.PI * 2, rr = 1.6 + R() * 1.6, x = POND.x + Math.cos(a) * rr, z = POND.z + Math.sin(a) * rr;
      g.add(new THREE.CylinderGeometry(0.42, 0.42, 0.03, 9, 1, false, 0.3, Math.PI * 1.8), '#3fae4a', { p: [x, STREAM.y + 0.04, z], ol: 0.02 });
      if (i % 2) g.add(new THREE.ConeGeometry(0.13, 0.22, 6), '#f9a8d4', { p: [x + 0.1, STREAM.y + 0.16, z], ol: 0.015 });
    }

    // Sawah tergenang: air + pematang (padi di mesh terpisah yang bergoyang angin)
    const water = [new THREE.Color('#4fa9b3'), new THREE.Color('#6cc2cf'), new THREE.Color('#3f8f86'), new THREE.Color('#8a7346'), new THREE.Color('#a8905c')];
    PADDIES.forEach((p) => {
      const mud = p.stage < 0.12, wc = mud ? water[3] : p.stage < 0.3 ? water[1] : water[0], wc2 = mud ? water[4] : water[2];
      g.add(new THREE.BoxGeometry(p.w, 0.05, p.d), (l, w) => tmp.copy(wc).lerp(wc2, clamp(Math.sin(w.x * 1.3 + w.z * 0.7) * 0.35 + 0.25, 0, 0.6)), { p: [p.x, 0.025, p.z], ol: false, jitter: 0.06 });
      const bund = topSide('#6fb23f', '#7a5a32');
      g.add(new THREE.BoxGeometry(p.w + 0.24, 0.16, 0.22), bund, { p: [p.x, 0.08, p.z - p.d / 2], ol: false });
      g.add(new THREE.BoxGeometry(p.w + 0.24, 0.16, 0.22), bund, { p: [p.x, 0.08, p.z + p.d / 2], ol: false });
      g.add(new THREE.BoxGeometry(0.22, 0.16, p.d), bund, { p: [p.x - p.w / 2, 0.08, p.z], ol: false });
      g.add(new THREE.BoxGeometry(0.22, 0.16, p.d), bund, { p: [p.x + p.w / 2, 0.08, p.z], ol: false });
    });
    // Kerbau membajak + petani bercaping di sawah yang baru diolah
    const muddy = PADDIES.filter((p) => p.stage < 0.12).sort((a, b) => Math.hypot(a.x + 8, a.z + 12) - Math.hypot(b.x + 8, b.z + 12));
    if (muddy[0]) buildKerbau(g, muddy[0].x - 0.4, muddy[0].z, 0.6);
    if (muddy[0]) buildPetani(g, muddy[0].x + 1.1, muddy[0].z + 0.2, -0.9);
    if (muddy[2]) buildPetani(g, muddy[2].x, muddy[2].z - 0.3, 2.2, true);
    // Kuntul putih mencari makan di sawah
    PADDIES.filter((p, i) => i % 7 === 3).forEach((p, i) => buildEgret(g, p.x + (R() - 0.5) * 1.6, p.z + (R() - 0.5) * 1.2, R() * 6, i % 2 === 0));

    // Pematang di sekeliling petak kebun (tanah berumput)
    const rim = topSide('#6db33f', '#7a5230');
    for (let i = 1; i <= 12; i++) {
      const c = plotCenter(i), h = PLOT / 2 + 0.08;
      g.add(new THREE.BoxGeometry(PLOT + 0.32, 0.46, 0.18), rim, { p: [c.x, 0.23, c.z - h], ol: 0.035 });
      g.add(new THREE.BoxGeometry(PLOT + 0.32, 0.46, 0.18), rim, { p: [c.x, 0.23, c.z + h], ol: 0.035 });
      g.add(new THREE.BoxGeometry(0.18, 0.46, PLOT), rim, { p: [c.x - h, 0.23, c.z], ol: 0.035 });
      g.add(new THREE.BoxGeometry(0.18, 0.46, PLOT), rim, { p: [c.x + h, 0.23, c.z], ol: 0.035 });
    }

    // Pagar bambu (gapura di depan)
    const fenceSide = (ax, az, bx, az2, gate) => {
      const len = Math.hypot(bx - ax, az2 - az), n = Math.ceil(len / 1.45);
      const pts = [];
      for (let i = 0; i <= n; i++) pts.push([lerp(ax, bx, i / n), lerp(az, az2, i / n)]);
      pts.forEach(([x, z], i) => {
        g.add(new THREE.CylinderGeometry(0.075, 0.085, 1.0, 6), BAMBOO_C[i % 3], { p: [x, 0.5, z], ol: 0.03 });
        g.add(new THREE.CylinderGeometry(0.09, 0.09, 0.05, 6), '#8f7a2e', { p: [x, 0.62, z], ol: false });
      });
      for (let i = 0; i < n; i++) {
        const [x0, z0] = pts[i], [x1, z1] = pts[i + 1], mx = (x0 + x1) / 2;
        if (gate && Math.abs(mx) < FENCE.gate) continue;
        [0.36, 0.72].forEach((y) => g.stick(V(x0, y, z0), V(x1 - x0, 0, z1 - z0), Math.hypot(x1 - x0, z1 - z0), 0.045, 0.045, BAMBOO_C[(i + 1) % 3], { ol: 0.02, seg: 5 }));
        g.stick(V(x0, 0.2, z0), V(x1 - x0, 0.62, z1 - z0), Math.hypot(Math.hypot(x1 - x0, z1 - z0), 0.62), 0.03, 0.03, '#a8913f', { ol: false, seg: 4 });
      }
    };
    fenceSide(FENCE.x0, FENCE.z0, FENCE.x1, FENCE.z0);
    fenceSide(FENCE.x1, FENCE.z0, FENCE.x1, FENCE.z1);
    fenceSide(FENCE.x0, FENCE.z1, FENCE.x0, FENCE.z0);
    fenceSide(FENCE.x0, FENCE.z1, FENCE.x1, FENCE.z1, true);
    // Gapura bambu beratap
    [-FENCE.gate, FENCE.gate].forEach((x) => g.add(new THREE.CylinderGeometry(0.13, 0.15, 2.5, 7), '#b9a24a', { p: [x, 1.25, FENCE.z1], ol: 0.035 }));
    g.stick(V(-FENCE.gate - 0.35, 2.35, FENCE.z1), V(1, 0, 0), FENCE.gate * 2 + 0.7, 0.1, 0.1, '#c9b45a', { ol: 0.03, seg: 6 });
    g.add(new THREE.ConeGeometry(1.0, 0.55, 4), '#8a6a3a', { p: [0, 2.72, FENCE.z1], r: [0, Math.PI / 4, 0], s: [1.9, 1, 0.75], ol: 0.04 });
    g.add(new THREE.BoxGeometry(1.3, 0.34, 0.06), '#fef3c7', { p: [0, 2.0, FENCE.z1 + 0.12], ol: 0.025 });
    g.add(new THREE.BoxGeometry(1.1, 0.06, 0.03), '#b91c1c', { p: [0, 2.08, FENCE.z1 + 0.16], ol: false });
    g.add(new THREE.BoxGeometry(0.8, 0.05, 0.03), '#15803d', { p: [0, 1.95, FENCE.z1 + 0.16], ol: false });
    // Umbul-umbul segitiga merah putih sepanjang pagar depan
    [[FENCE.x0 + 0.1, -FENCE.gate - 0.15], [FENCE.gate + 0.15, FENCE.x1 - 0.1]].forEach(([xa, xb]) => {
      g.add(new THREE.BoxGeometry(xb - xa, 0.02, 0.02), '#5b4a2a', { p: [(xa + xb) / 2, 1.0, FENCE.z1 + 0.06], ol: false });
      const n = Math.floor((xb - xa) / 0.34);
      for (let k = 0; k < n; k++) {
        const x = xa + 0.17 + k * 0.34, sag = Math.sin((k / Math.max(1, n - 1)) * Math.PI) * 0.06;
        g.add(new THREE.CylinderGeometry(0.15, 0.15, 0.02, 3), k % 2 ? '#ffffff' : '#dc2626', { p: [x, 0.9 - sag, FENCE.z1 + 0.06], r: [Math.PI / 2, 0, 0], ol: 0.012 });
      }
    });
    // Tiang bendera bambu
    g.add(new THREE.CylinderGeometry(0.07, 0.1, 4.6, 7), '#c9b45a', { p: [FLAG.x, 2.3, FLAG.z], ol: 0.03 });
    g.add(new THREE.SphereGeometry(0.12, 8, 6), C.gold, { p: [FLAG.x, 4.65, FLAG.z], ol: 0.02 });
    g.add(new THREE.CylinderGeometry(0.35, 0.45, 0.25, 8), C.stone, { p: [FLAG.x, 0.12, FLAG.z], ol: 0.03 });

    // Jalan tanah gerbang → jembatan, tepian sungai batu kali & jembatan bambu
    g.add(new THREE.BoxGeometry(1.7, 0.06, STREAM.z0 - FENCE.z1 + 0.2), '#d9b98a', { p: [0, 0.03, (FENCE.z1 + STREAM.z0) / 2], ol: false, jitter: 0.08 });
    const bankLen = STREAM.x1 - STREAM.x0 + 0.4;
    [STREAM.z0 - 0.08, STREAM.z1 + 0.08].forEach((z) => g.add(new THREE.BoxGeometry(bankLen, 0.18, 0.2), C.stone, { p: [(STREAM.x0 + STREAM.x1) / 2 - 0.05, 0.09, z], ol: 0.03 }));
    for (let i = 0; i < 26; i++) {
      const x = lerp(STREAM.x0, STREAM.x1 - 0.5, R()), z = R() < 0.5 ? STREAM.z0 - 0.3 - R() * 0.25 : STREAM.z1 + 0.3 + R() * 0.25;
      g.add(new THREE.DodecahedronGeometry(0.12 + R() * 0.12, 0), R() < 0.5 ? C.stone : C.stoneDark, { p: [x, 0.08, z], r: [R() * 3, R() * 3, 0], s: [1, 0.6, 1], ol: 0.025 });
    }
    for (let i = 0; i < 9; i++) {
      const z = STREAM.z0 - 0.3 + i * 0.27;
      g.stick(V(-1.1, 0.32 + Math.sin((i / 8) * Math.PI) * 0.14, z), V(1, 0, 0), 2.2, 0.11, 0.11, BAMBOO_C[i % 3], { ol: 0.022, seg: 6 });
    }
    [-1, 1].forEach((sx) => {
      [STREAM.z0 - 0.3, STREAM.z1 + 0.3].forEach((z) => g.add(new THREE.CylinderGeometry(0.07, 0.08, 0.9, 6), '#b9a24a', { p: [sx * 1.0, 0.5, z], ol: 0.025 }));
      g.stick(V(sx * 1.0, 0.82, STREAM.z0 - 0.3), V(0, 0, 1), STREAM.z1 - STREAM.z0 + 0.6, 0.045, 0.045, '#c9b45a', { ol: 0.02, seg: 5 });
    });

    // Kincir air bambu: tiang & poros (roda berputar dibuat terpisah)
    [WHEEL.z - 0.95, WHEEL.z + 0.95].forEach((z) => {
      g.stick(V(WHEEL.x - 0.7, 0, z), V(0.7, WHEEL_Y, 0), Math.hypot(0.7, WHEEL_Y), 0.08, 0.07, '#9c8440', { ol: 0.025 });
      g.stick(V(WHEEL.x + 0.7, 0, z), V(-0.7, WHEEL_Y, 0), Math.hypot(0.7, WHEEL_Y), 0.08, 0.07, '#9c8440', { ol: 0.025 });
    });
    g.stick(V(WHEEL.x, WHEEL_Y, WHEEL.z - 1.05), V(0, 0, 1), 2.1, 0.08, 0.08, '#6b4f1d', { ol: 0.02 });
    // Talang bambu dari kincir ke sawah
    g.stick(V(WHEEL.x + 0.2, WHEEL_Y + 1.2, WHEEL.z - 0.5), V(0.1, -0.35, -1), 4.4, 0.1, 0.1, '#a8913f', { ol: 0.025 });
    [[-0.5, 1.35], [-2.0, 0.85], [-3.4, 0.4]].forEach(([dz, y]) => g.add(new THREE.CylinderGeometry(0.05, 0.05, y * 2, 5), '#8f7a2e', { p: [WHEEL.x + 0.25 - dz * 0.05, y, WHEEL.z - 0.5 + dz], ol: 0.015 }));

    // Saung (gubuk sawah) di atas panggung
    const hx = HUT.x, hz = HUT.z;
    [[-0.9, -0.8], [0.9, -0.8], [-0.9, 0.8], [0.9, 0.8]].forEach(([dx, dz]) => g.add(new THREE.CylinderGeometry(0.09, 0.1, 2.5, 6), '#b9a24a', { p: [hx + dx, 1.25, hz + dz], ol: 0.03 }));
    g.add(new THREE.BoxGeometry(2.2, 0.14, 1.95), '#c8a86a', { p: [hx, 1.0, hz], ol: 0.035 });
    for (let k = 0; k < 6; k++) g.add(new THREE.BoxGeometry(2.24, 0.02, 0.05), '#9c7f45', { p: [hx, 1.08, hz - 0.8 + k * 0.32], ol: false });
    g.add(new THREE.ConeGeometry(1.95, 1.3, 4), '#a8844a', { p: [hx, 3.05, hz], r: [0, Math.PI / 4, 0], s: [1.15, 1, 1.05], ol: 0.05 });
    g.add(new THREE.ConeGeometry(1.98, 0.25, 4), '#8a6a3a', { p: [hx, 2.48, hz], r: [0, Math.PI / 4, 0], s: [1.15, 1, 1.05], ol: 0.03 });
    [-0.18, 0.18].forEach((dx) => g.stick(V(hx + 0.9 + dx, 0, hz + 1.5), V(0, 1, -0.5), 1.15, 0.04, 0.04, '#9c8440', { ol: 0.015 }));
    for (let k = 0; k < 4; k++) g.add(new THREE.BoxGeometry(0.44, 0.04, 0.06), '#b9a24a', { p: [hx + 0.9, 0.18 + k * 0.24, hz + 1.42 - k * 0.12], ol: false });
    g.add(new THREE.ConeGeometry(0.42, 0.2, 12), '#e3c27a', { p: [hx - 0.5, 1.2, hz + 0.3], ol: 0.02 }); // caping tergeletak
    g.add(new THREE.CylinderGeometry(0.16, 0.12, 0.28, 8), '#7c5a2e', { p: [hx + 0.4, 1.21, hz - 0.3], ol: 0.02 });   // bakul

    // Lumbung padi (panggung tinggi, atap curam)
    const lx = LUMBUNG.x, lz = LUMBUNG.z;
    [[-0.7, -0.6], [0.7, -0.6], [-0.7, 0.6], [0.7, 0.6]].forEach(([dx, dz]) => {
      g.add(new THREE.CylinderGeometry(0.1, 0.12, 1.6, 6), '#7a4a1e', { p: [lx + dx, 0.8, lz + dz], ol: 0.03 });
      g.add(new THREE.CylinderGeometry(0.28, 0.28, 0.07, 10), '#5b3612', { p: [lx + dx, 1.62, lz + dz], ol: 0.02 }); // penahan tikus
    });
    g.add(new THREE.BoxGeometry(1.85, 0.12, 1.6), '#8a5a2b', { p: [lx, 1.72, lz], ol: 0.03 });
    g.add(new THREE.BoxGeometry(1.3, 1.0, 1.1), '#b07a3e', { p: [lx, 2.3, lz], ol: 0.035 });
    g.add(new THREE.BoxGeometry(0.4, 0.5, 0.04), '#6b4220', { p: [lx, 2.3, lz + 0.57], ol: 0.015 });
    g.add(new THREE.ConeGeometry(1.45, 2.4, 4), '#7a5a32', { p: [lx, 3.95, lz], r: [0, Math.PI / 4, 0], s: [1, 1, 0.82], ol: 0.05 });
    g.add(new THREE.ConeGeometry(0.2, 0.5, 4), '#5b3f22', { p: [lx, 5.25, lz], r: [0, Math.PI / 4, 0], ol: 0.02 });
    for (let k = 0; k < 5; k++) g.add(new THREE.ConeGeometry(0.1, 0.42, 5), '#e8c95a', { p: [lx - 0.6 + k * 0.3, 1.45, lz + 0.82], r: [Math.PI, 0, 0], ol: 0.012 }); // ikat padi digantung

    // Orang-orangan sawah bercaping & berbaju lurik (menghadap kamera)
    const sc = new THREE.Group();
    sc.position.copy(SCARECROW); sc.rotation.y = Math.PI / 4; sc.updateMatrixWorld();
    const scAdd = (geo, color, p, o = {}) => {
      const v = V(...p).applyMatrix4(sc.matrixWorld);
      const r = o.r || [0, 0, 0];
      g.add(geo, color, Object.assign({}, o, { p: [v.x, v.y, v.z], r: [r[0], r[1] + Math.PI / 4, r[2]] }));
    };
    const lurik = (l) => tmp.set(Math.sin(l.x * 40) > 0.2 ? '#7c2d12' : '#c2410c');
    scAdd(new THREE.CylinderGeometry(0.06, 0.07, 2.0, 6), '#b9a24a', [0, 1.0, -0.05], { ol: 0.03 });
    scAdd(new THREE.CylinderGeometry(0.05, 0.05, 1.5, 6), '#b9a24a', [0, 1.58, -0.05], { r: [0, 0, Math.PI / 2], ol: 0.025 });
    scAdd(new THREE.BoxGeometry(0.64, 0.72, 0.38), lurik, [0, 1.42, 0], { ol: 0.035 });
    [-1, 1].forEach((sx) => {
      scAdd(new THREE.BoxGeometry(0.46, 0.24, 0.26), lurik, [sx * 0.52, 1.58, 0], { ol: 0.03 });
      scAdd(new THREE.ConeGeometry(0.09, 0.26, 5), C.gold, [sx * 0.86, 1.58, 0], { r: [0, 0, -sx * Math.PI / 2], ol: 0.02 });
    });
    scAdd(new THREE.BoxGeometry(0.7, 0.3, 0.42), (l) => tmp.set((Math.floor(l.x * 12) + Math.floor(l.y * 12)) % 2 ? '#1e3a8a' : '#2563eb'), [0, 0.98, 0], { ol: 0.025 }); // sarung kotak
    scAdd(new THREE.SphereGeometry(0.34, 9, 7), C.straw, [0, 2.06, 0], { ol: 0.035 });
    [-1, 1].forEach((sx) => {
      scAdd(new THREE.BoxGeometry(0.07, 0.11, 0.05), C.ink, [sx * 0.13, 2.1, 0.31], { ol: false });
      scAdd(new THREE.SphereGeometry(0.06, 6, 4), C.pink, [sx * 0.22, 1.98, 0.27], { s: [1, 0.6, 0.5], ol: false });
    });
    scAdd(new THREE.BoxGeometry(0.2, 0.035, 0.05), C.ink, [0, 1.93, 0.32], { ol: false });
    scAdd(new THREE.ConeGeometry(0.78, 0.42, 16), '#e3c27a', [0, 2.5, 0], { ol: 0.035 });
    scAdd(new THREE.TorusGeometry(0.3, 0.03, 4, 14), '#8a6a3a', [0, 2.36, 0], { r: [Math.PI / 2, 0, 0], ol: false });
    // Tali kaleng pengusir burung
    scAdd(new THREE.BoxGeometry(0.02, 0.5, 0.02), '#5b4a2a', [0.86, 1.32, 0], { ol: false });
    scAdd(new THREE.CylinderGeometry(0.06, 0.06, 0.12, 7), C.silver, [0.86, 1.05, 0], { ol: 0.015 });

    // Batang pohon kelapa & pisang (tajuk = InstancedMesh terpisah agar bisa bergoyang)
    TREES.forEach((t) => {
      if (t.type === 'palm') palmTrunk(g, t.x, t.z, 0, rng(Math.round(t.x * 97 + t.z * 31)), 1, t);
      else g.add(new THREE.CylinderGeometry(0.14, 0.2, 1.3, 7), '#7d9a46', { p: [t.x, 0.65, t.z], ol: 0.035 });
    });
    // Rumpun bambu
    BAMBOO.forEach((b, bi) => {
      const r2 = rng(300 + bi);
      for (let k = 0; k < 8; k++) {
        const a = r2() * Math.PI * 2, d = r2() * 0.6, h = 4.2 + r2() * 1.8;
        const base = V(b.x + Math.cos(a) * d, 0, b.z + Math.sin(a) * d), dir = V(Math.cos(a) * 0.18, 1, Math.sin(a) * 0.18);
        g.stick(base, dir, h, 0.08, 0.06, k % 2 ? '#7fb04a' : '#9cbc3c', { ol: 0.02, seg: 5 });
        const top = base.clone().addScaledVector(dir.clone().normalize(), h);
        for (let j = 0; j < 4; j++) {
          const f = 0.5 + j * 0.15, n0 = V(lerp(base.x, top.x, f), lerp(0, top.y, f), lerp(base.z, top.z, f));
          for (let q = 0; q < 4; q++) {
            const aa = r2() * Math.PI * 2;
            g.stick(n0, V(Math.cos(aa), -0.25 - r2() * 0.3, Math.sin(aa)), 0.7 + r2() * 0.3, 0.07, 0.01, q % 2 ? '#5fae3a' : '#4a9a32', { ol: false, seg: 3 });
          }
        }
      }
    });

    // Rumput, kembang sepatu & bunga liar (hindari kebun, sungai, sawah)
    const blocked = (x, z) => (x > FENCE.x0 - 0.6 && x < FENCE.x1 + 0.6 && z > FENCE.z0 - 0.6 && z < FENCE.z1 + 0.5)
      || (z > STREAM.z0 - 0.7 && z < STREAM.z1 + 0.7) || V(x, 0, z).distanceTo(HUT) < 2.4 || V(x, 0, z).distanceTo(SCARECROW) < 1
      || V(x, 0, z).distanceTo(LUMBUNG) < 2 || V(x, 0, z).distanceTo(WHEEL) < 2.2 || inPaddy(x, z, 0.2)
      || TREES.some((t) => Math.hypot(t.x - x, t.z - z) < 1.2) || Math.hypot(x, z) > 27 || Math.hypot(x - POND.x, z - POND.z) < POND.r + 0.8;
    let placed = 0;
    for (let k = 0; k < 1500 && placed < 150; k++) {
      const x = (R() * 2 - 1) * 27, z = (R() * 2 - 1) * 27;
      if (blocked(x, z)) continue;
      placed++;
      if (R() < 0.3) {
        const col = ['#e11d48', '#fde047', '#ffffff', '#f97316'][Math.floor(R() * 4)];
        g.add(new THREE.CylinderGeometry(0.02, 0.02, 0.3, 3), C.leafDark, { p: [x, 0.15, z], ol: false });
        g.add(new THREE.IcosahedronGeometry(0.09, 0), col, { p: [x, 0.33, z], ol: 0.02 });
      } else {
        for (let b = 0; b < 3; b++) g.add(new THREE.ConeGeometry(0.06, 0.3 + R() * 0.15, 3), b % 2 ? C.leafDark : '#22c55e', { p: [x + (b - 1) * 0.08, 0.15, z + R() * 0.06], r: [0, 0, (b - 1) * 0.35], ol: false });
      }
    }
    return g.build(0.05);
  }

  /** Batang kelapa melengkung (4 ruas); mengisi t.top bila t diberikan. */
  function palmTrunk(g, x, z, y0, R, s = 1, t = null) {
    const lean = R() * Math.PI * 2, bend = 0.12 + R() * 0.12;
    let p = V(x, y0, z);
    for (let k = 0; k < 5; k++) {
      const dir = V(Math.cos(lean) * bend * k, 1, Math.sin(lean) * bend * k).normalize();
      g.stick(p, dir, 0.95 * s, (0.17 - k * 0.012) * s, (0.16 - k * 0.012) * s, k % 2 ? '#8b7355' : '#9c8466', { ol: 0.03, seg: 6 });
      p = p.clone().addScaledVector(dir, 0.95 * s);
    }
    if (t) t.top = p;
    else { // tajuk statis untuk kelapa jauh (terasering)
      for (let k = 0; k < 7; k++) {
        const a = (k / 7) * Math.PI * 2;
        g.add(new THREE.BoxGeometry(1.5 * s, 0.04, 0.34 * s), k % 2 ? '#2f9e44' : '#3fb950', { p: [p.x + Math.cos(a) * 0.7 * s, p.y - 0.1, p.z + Math.sin(a) * 0.7 * s], r: [0, -a, -0.35], ol: 0.03 });
      }
    }
    return p;
  }

  /** Kerbau berkubang (statis). */
  function buildKerbau(g, x, z, ry) {
    const grp = new THREE.Group(); grp.position.set(x, 0, z); grp.rotation.y = ry; grp.updateMatrixWorld();
    const at = (geo, color, p, o = {}) => { const v = V(...p).applyMatrix4(grp.matrixWorld); const r = o.r || [0, 0, 0]; g.add(geo, color, Object.assign({}, o, { p: [v.x, v.y, v.z], r: [r[0], r[1] + ry, r[2]] })); };
    const hide = '#4b4b55';
    at(new THREE.SphereGeometry(0.55, 10, 7), hide, [0, 0.62, 0], { s: [1.6, 0.85, 0.9], ol: 0.04 });
    [[-0.55, -0.28], [0.55, -0.28], [-0.55, 0.28], [0.55, 0.28]].forEach(([dx, dz]) => at(new THREE.CylinderGeometry(0.11, 0.1, 0.5, 6), hide, [dx, 0.22, dz], { ol: 0.025 }));
    at(new THREE.SphereGeometry(0.3, 8, 6), hide, [0.95, 0.72, 0], { s: [1.2, 0.9, 0.9], ol: 0.035 });
    at(new THREE.SphereGeometry(0.16, 7, 5), '#6b6b75', [1.22, 0.62, 0], { ol: 0.02 });
    [-1, 1].forEach((sd) => at(new THREE.TorusGeometry(0.28, 0.05, 5, 10, Math.PI * 0.8), '#e7e5d8', [0.88, 0.95, sd * 0.2], { r: [0, sd * 0.4, Math.PI * 0.1], ol: 0.02 }));
    at(new THREE.CylinderGeometry(0.03, 0.02, 0.6, 4), '#3f3f46', [-0.95, 0.55, 0], { r: [0, 0, 0.5], ol: false });
    // Bajak kayu di belakang
    at(new THREE.BoxGeometry(1.3, 0.08, 0.08), '#7a4a1e', [-1.3, 0.5, 0], { r: [0, 0, 0.25], ol: 0.02 });
    at(new THREE.BoxGeometry(0.08, 0.6, 0.08), '#5b3612', [-1.85, 0.3, 0], { ol: 0.02 });
  }

  /** Petani bercaping (statis). */
  function buildPetani(g, x, z, ry, bending = false) {
    const grp = new THREE.Group(); grp.position.set(x, 0, z); grp.rotation.y = ry; grp.updateMatrixWorld();
    const at = (geo, color, p, o = {}) => { const v = V(...p).applyMatrix4(grp.matrixWorld); const r = o.r || [0, 0, 0]; g.add(geo, color, Object.assign({}, o, { p: [v.x, v.y, v.z], r: [r[0], r[1] + ry, r[2]] })); };
    const lean = bending ? 0.7 : 0;
    [-0.09, 0.09].forEach((dz) => at(new THREE.CylinderGeometry(0.06, 0.06, 0.55, 6), '#1e293b', [0, 0.28, dz], { ol: 0.02 }));
    at(new THREE.CylinderGeometry(0.17, 0.2, 0.62, 8), bending ? '#b45309' : '#0f766e', [lean * 0.25, 0.85, 0], { r: [0, 0, -lean], ol: 0.03 });
    at(new THREE.SphereGeometry(0.13, 8, 6), '#c68a5c', [lean * 0.55, 1.25 - lean * 0.12, 0], { ol: 0.02 });
    at(new THREE.ConeGeometry(0.36, 0.2, 14), '#e3c27a', [lean * 0.6, 1.4 - lean * 0.12, 0], { r: [0, 0, -lean * 0.6], ol: 0.025 });
    [-1, 1].forEach((sd) => at(new THREE.CylinderGeometry(0.045, 0.045, 0.5, 5), '#c68a5c', [lean * 0.45 + 0.08, 0.78 - lean * 0.1, sd * 0.2], { r: [sd * 0.25, 0, bending ? -1.3 : 0.2], ol: 0.015 }));
  }

  /** Burung kuntul berdiri (statis). */
  function buildEgret(g, x, z, ry, pecking) {
    const grp = new THREE.Group(); grp.position.set(x, 0.05, z); grp.rotation.y = ry; grp.updateMatrixWorld();
    const at = (geo, color, p, o = {}) => { const v = V(...p).applyMatrix4(grp.matrixWorld); const r = o.r || [0, 0, 0]; g.add(geo, color, Object.assign({}, o, { p: [v.x, v.y, v.z], r: [r[0], r[1] + ry, r[2]] })); };
    [-0.05, 0.05].forEach((dz) => at(new THREE.CylinderGeometry(0.015, 0.015, 0.4, 3), '#1f2937', [0, 0.2, dz], { ol: false }));
    at(new THREE.SphereGeometry(0.14, 8, 6), '#ffffff', [0, 0.48, 0], { s: [1.6, 0.9, 0.9], ol: 0.02 });
    at(new THREE.CylinderGeometry(0.03, 0.035, 0.32, 5), '#ffffff', [0.18, 0.62, 0], { r: [0, 0, pecking ? -1.0 : -0.3], ol: 0.012 });
    const hx = pecking ? 0.34 : 0.24, hy = pecking ? 0.62 : 0.8;
    at(new THREE.SphereGeometry(0.06, 6, 5), '#ffffff', [hx, hy, 0], { ol: 0.012 });
    at(new THREE.ConeGeometry(0.02, 0.16, 4), '#facc15', [hx + 0.1, hy - (pecking ? 0.06 : 0), 0], { r: [0, 0, -Math.PI / 2 - (pecking ? 0.6 : 0)], ol: false });
  }

  const TREES = (() => {
    const list = FIXED_TREES.map((t) => Object.assign({}, t));
    const R = rng(77);
    for (let k = 0; k < 600 && list.length < 44; k++) {
      const a = R() * Math.PI * 2, r = 13 + R() * 15, x = Math.cos(a) * r, z = Math.sin(a) * r;
      if (Math.abs(z - 7) < 2.2 && x > STREAM.x0 - 2 && x < STREAM.x1) continue;       // sungai
      if (Math.hypot(x - POND.x, z - POND.z) < POND.r + 1.5) continue;                   // kolam
      if (Math.hypot(x - STREAM.x0 + 2, z - 7) < 6) continue;                            // tebing air terjun
      if (Math.abs(x) < 2 && z > 4) continue;                                             // jalan & jembatan
      if (inPaddy(x, z, 0.5)) continue;                                                   // sawah
      if ([HUT, LUMBUNG, WHEEL, SCARECROW].some((p) => Math.hypot(p.x - x, p.z - z) < 2.6)) continue;
      if (BAMBOO.some((b) => Math.hypot(b.x - x, b.z - z) < 2.4)) continue;
      if (list.some((t) => Math.hypot(t.x - x, t.z - z) < 2.2)) continue;
      const front = (x + z) * Math.SQRT1_2 > 4;   // sisi kamera: hanya tanaman pendek agar kebun tidak tertutup
      if (front && R() < 0.6) continue;
      list.push({ type: !front && R() < 0.75 ? 'palm' : 'banana', x, z });
    }
    return list;
  })();

  /** Tajuk kelapa: pelepah melengkung + buah kelapa (titik asal = pucuk batang). */
  function buildPalmCanopy() {
    const g = new Geo(3);
    for (let k = 0; k < 9; k++) {
      const a = (k / 9) * Math.PI * 2 + (k % 2) * 0.2, up = k % 3 === 0 ? 0.35 : 0.1;
      const c = k % 2 ? '#2f9e44' : '#3fb950';
      g.add(new THREE.BoxGeometry(1.3, 0.04, 0.4), c, { p: [Math.cos(a) * 0.62, 0.12 + up * 0.5, Math.sin(a) * 0.62], r: [0, -a, up], ol: 0.03 });
      g.add(new THREE.BoxGeometry(1.25, 0.04, 0.32), k % 2 ? '#3fb950' : '#58c45f', { p: [Math.cos(a) * 1.75, -0.12 + up * 0.6, Math.sin(a) * 1.75], r: [0, -a, -0.45 + up * 0.5], ol: 0.03 });
      g.add(new THREE.BoxGeometry(0.8, 0.035, 0.22), c, { p: [Math.cos(a) * 2.6, -0.6 + up * 0.5, Math.sin(a) * 2.6], r: [0, -a, -0.9], ol: 0.025 });
    }
    [[0.18, 0], [-0.1, 0.16], [-0.06, -0.17], [0.05, 0.05]].forEach(([dx, dz], i) => g.add(new THREE.SphereGeometry(0.17, 7, 5), i % 2 ? '#6b8e23' : '#8a6a2e', { p: [dx, -0.22, dz], ol: 0.02 }));
    return g.build(0.05);
  }
  /** Tajuk pisang: daun lebar melengkung + tandan & jantung pisang. */
  function buildBananaCanopy() {
    const g = new Geo(4);
    for (let k = 0; k < 6; k++) {
      const a = (k / 6) * Math.PI * 2 + (k % 2) * 0.3, c = k % 2 ? '#5cb83a' : '#4aa02c';
      g.stick(V(0, 0.1, 0), V(Math.cos(a) * 0.45, 1, Math.sin(a) * 0.45), 0.9, 0.035, 0.03, '#7d9a46', { ol: 0.012, seg: 4 });
      g.add(new THREE.BoxGeometry(0.85, 0.025, 0.34), c, { p: [Math.cos(a) * 0.62, 1.02, Math.sin(a) * 0.62], r: [0, -a, 0.35], ol: 0.02 });
      g.add(new THREE.BoxGeometry(0.75, 0.025, 0.3), k % 2 ? '#4aa02c' : '#6cc444', { p: [Math.cos(a) * 1.25, 0.95, Math.sin(a) * 1.25], r: [0, -a, -0.5], ol: 0.02 });
      g.add(new THREE.BoxGeometry(0.45, 0.02, 0.2), c, { p: [Math.cos(a) * 1.62, 0.66, Math.sin(a) * 1.62], r: [0, -a, -1.05], ol: 0.015 });
    }
    g.add(new THREE.CylinderGeometry(0.06, 0.06, 0.9, 5), '#7d9a46', { p: [0.28, 0.5, 0], r: [0, 0, 0.5], ol: 0.015 });
    for (let k = 0; k < 6; k++) g.add(new THREE.CylinderGeometry(0.06, 0.05, 0.28, 5), '#b5c93a', { p: [0.42 + (k % 2) * 0.1, 0.25 - Math.floor(k / 2) * 0.13, (k % 3 - 1) * 0.1], r: [0, 0, -0.4], ol: 0.012 });
    g.add(new THREE.ConeGeometry(0.11, 0.3, 6), '#7c2d4f', { p: [0.55, -0.2, 0], r: [Math.PI, 0, 0], ol: 0.015 });
    return g.build(0.05);
  }
  function buildCloud(seed) {
    const g = new Geo(seed), R = g.rand;
    for (let i = 0; i < 5; i++) g.add(new THREE.IcosahedronGeometry(0.9 + R() * 0.6, 1), C.white, { p: [(i - 2) * 0.95, R() * 0.4, (R() - 0.5) * 0.9], s: [1, 0.72, 0.9], jitter: 0.02 });
    return g.build(0.07);
  }
  /** Kincir air bambu (roda di bidang XY, berputar pada sumbu Z). */
  function buildSails() {
    const g = new Geo(5), R_W = 1.25;
    [-0.3, 0.3].forEach((z) => g.add(new THREE.TorusGeometry(R_W, 0.055, 5, 22), '#b9a24a', { p: [0, 0, z], ol: 0.025 }));
    g.add(new THREE.CylinderGeometry(0.2, 0.2, 0.75, 8), '#6b4f1d', { r: [Math.PI / 2, 0, 0], ol: 0.025 });
    for (let k = 0; k < 8; k++) {
      const a = (k / 8) * Math.PI * 2;
      [-0.3, 0.3].forEach((z) => g.add(new THREE.BoxGeometry(0.06, R_W * 2, 0.05), '#9c8440', { p: [0, 0, z], r: [0, 0, a], ol: 0.015 }));
    }
    for (let k = 0; k < 12; k++) {
      const a = (k / 12) * Math.PI * 2, x = Math.cos(a) * (R_W + 0.1), y = Math.sin(a) * (R_W + 0.1);
      g.add(new THREE.BoxGeometry(0.36, 0.06, 0.72), k % 2 ? '#c9b45a' : '#a8913f', { p: [x, y, 0], r: [0, 0, a], ol: 0.02 });
      if (k % 3 === 0) g.add(new THREE.CylinderGeometry(0.07, 0.07, 0.3, 6), '#7fb04a', { p: [x * 0.92, y * 0.92, 0.42], r: [0, 0, a], ol: 0.012 }); // tabung bambu penimba
    }
    return g.build(0.04);
  }

  /** Padi di sawah tergenang: rumpun bilah (fruit=1 → ikut angin di shader). */
  function buildPaddyRice() {
    const g = new Geo(23), R = g.rand;
    const young = ['#7cc84a', '#93d65a'], green = ['#3f9e3a', '#58b545'], gold = ['#d9b84a', '#c9a43a'];
    PADDIES.forEach((p) => {
      const st = p.stage;
      if (st < 0.12) return; // sawah baru dibajak: masih lumpur
      const h = st < 0.3 ? 0.3 : 0.58, cols = st < 0.3 ? young : st < 0.75 ? green : gold;
      const nx = 6, nz = 4;
      for (let i = 0; i < nx; i++) for (let j = 0; j < nz; j++) {
        const x = p.x - p.w / 2 + (i + 0.5) * (p.w / nx) + (R() - 0.5) * 0.08, z = p.z - p.d / 2 + (j + 0.5) * (p.d / nz) + (R() - 0.5) * 0.08;
        for (let b = 0; b < 4; b++) {
          const a = (b / 4) * Math.PI * 2 + R(), tilt = 0.25 + R() * 0.2;
          g.add(new THREE.ConeGeometry(0.075, h * (0.8 + R() * 0.4), 3), cols[b % 2], { p: [x + Math.cos(a) * 0.05, h * 0.5, z + Math.sin(a) * 0.05], r: [Math.sin(a) * tilt, 0, -Math.cos(a) * tilt], fruit: true, ol: false, jitter: 0.08 });
        }
        if (st >= 0.75 && (i + j) % 2 === 0) g.add(new THREE.IcosahedronGeometry(0.05, 0), '#f2d16b', { p: [x + 0.06, h * 0.95, z], s: [1, 2.2, 1], r: [0, 0, 0.8], fruit: true, ol: false });
      }
    });
    return g.build();
  }
  /** Layang-layang (sayap diwarnai instanceColor) + ekor + tali menjulur ke bawah. */
  function buildKite() {
    const g = new Geo(41);
    g.add(new THREE.CylinderGeometry(0.75, 0.75, 0.03, 4), C.white, { r: [Math.PI / 2, 0, 0], s: [0.8, 1, 1.1], fruit: true, ol: 0.03 });
    g.add(new THREE.BoxGeometry(0.03, 1.65, 0.04), '#6b4f1d', { p: [0, 0, 0.03], ol: false });
    g.add(new THREE.BoxGeometry(1.2, 0.03, 0.04), '#6b4f1d', { p: [0, 0.15, 0.03], ol: false });
    for (let k = 0; k < 4; k++) g.add(new THREE.CylinderGeometry(0.1, 0.1, 0.02, 3), k % 2 ? '#ffffff' : '#fde047', { p: [Math.sin(k) * 0.12, -1.0 - k * 0.32, 0], r: [Math.PI / 2, 0, k * 0.6], ol: 0.01 });
    g.stick(V(0, -0.3, 0.02), V(0.25, -1, 0.35), 16, 0.012, 0.012, '#f8fafc', { ol: false, seg: 3 });
    return g.build();
  }
  /** Burung kuntul terbang (sayap fruit=1 → dikepakkan di shader). */
  function buildFlyingEgret() {
    const g = new Geo(42);
    g.add(new THREE.SphereGeometry(0.18, 8, 6), '#ffffff', { s: [0.9, 0.8, 2.2], ol: 0.02 });
    g.add(new THREE.SphereGeometry(0.09, 6, 5), '#ffffff', { p: [0, 0.06, 0.45], ol: 0.015 });
    g.add(new THREE.ConeGeometry(0.03, 0.22, 4), '#facc15', { p: [0, 0.05, 0.6], r: [Math.PI / 2, 0, 0], ol: false });
    g.add(new THREE.CylinderGeometry(0.012, 0.012, 0.5, 3), '#1f2937', { p: [0, -0.03, -0.55], r: [Math.PI / 2, 0, 0], ol: false });
    [-1, 1].forEach((sd) => g.add(new THREE.BoxGeometry(0.95, 0.03, 0.36), '#ffffff', { p: [sd * 0.55, 0.04, 0], r: [0, 0, sd * 0.08], fruit: true, ol: 0.015 }));
    return g.build();
  }
  /** Bebek di kolam. */
  function buildDuck() {
    const g = new Geo(43);
    g.add(new THREE.SphereGeometry(0.22, 8, 6), '#fafaf9', { p: [0, 0.12, 0], s: [1.5, 0.85, 1], ol: 0.02 });
    g.add(new THREE.ConeGeometry(0.08, 0.2, 5), '#fafaf9', { p: [-0.34, 0.2, 0], r: [0, 0, 1.1], ol: 0.015 });
    g.add(new THREE.SphereGeometry(0.12, 7, 5), '#fafaf9', { p: [0.26, 0.36, 0], ol: 0.015 });
    g.add(new THREE.BoxGeometry(0.14, 0.04, 0.08), '#f59e0b', { p: [0.4, 0.34, 0], ol: 0.01 });
    [-1, 1].forEach((sd) => g.add(new THREE.SphereGeometry(0.02, 4, 3), C.ink, { p: [0.33, 0.39, sd * 0.07], ol: false }));
    return g.build();
  }
  /** Gumpalan asap kawah. */
  function buildPuff() {
    const g = new Geo(44);
    g.add(new THREE.IcosahedronGeometry(1, 1), '#e7e5e4', { ol: 0.06, jitter: 0.04 });
    g.add(new THREE.IcosahedronGeometry(0.7, 1), '#f5f5f4', { p: [0.6, 0.3, 0.2], ol: 0.05 });
    return g.build();
  }

  /**
   * Material toon dengan animasi vertex (atribut fruit=1):
   *  mode 'wind' → bilah padi bergoyang (posisi dunia, mesh statis)
   *  mode 'flap' → sayap burung mengepak (per instance, fase dari posisi)
   */
  function animMaterial(gradientMap, mode) {
    const m = new THREE.MeshToonMaterial({ vertexColors: true, gradientMap });
    m.userData.uTime = { value: 0 };
    m.onBeforeCompile = (sh) => {
      sh.uniforms.uTime = m.userData.uTime;
      sh.vertexShader = 'attribute float fruit;\nuniform float uTime;\n' + sh.vertexShader.replace('#include <begin_vertex>',
        mode === 'wind'
          ? '#include <begin_vertex>\n float sw = sin(uTime * 1.7 + position.x * 0.45 + position.z * 0.3) + 0.4 * sin(uTime * 3.1 + position.x * 1.3);\n transformed.x += sw * 0.06 * fruit * clamp(position.y / 0.5, 0.0, 1.0);\n transformed.z += sw * 0.03 * fruit * clamp(position.y / 0.5, 0.0, 1.0);'
          : '#include <begin_vertex>\n float ph = 0.0;\n #ifdef USE_INSTANCING\n ph = instanceMatrix[3].x * 0.9 + instanceMatrix[3].z * 0.4;\n #endif\n transformed.y += sin(uTime * 8.0 + ph) * abs(transformed.x) * 0.75 * fruit;');
    };
    return m;
  }

  /* ── Petak ── */
  function buildSoil() {
    const g = new Geo(6);
    g.add(new THREE.BoxGeometry(PLOT - 0.04, SOIL_TOP, PLOT - 0.04), C.white, { p: [0, SOIL_TOP / 2, 0], fruit: true, ol: false, jitter: 0.03 });
    return g.build();
  }
  function buildFurrows() {
    const g = new Geo(8);
    [-1.0, -0.5, 0, 0.5, 1.0].forEach((z) => g.add(new THREE.BoxGeometry(2.45, 0.14, 0.24), '#c7c7c7', { p: [0, SOIL_TOP + 0.03, z], fruit: true, ol: false, jitter: 0.06 }));
    return g.build();
  }
  function buildWeeds() {
    const g = new Geo(9), R = g.rand;
    for (let i = 0; i < 9; i++) {
      const x = (R() * 2 - 1) * 1.05, z = (R() * 2 - 1) * 1.05;
      for (let b = 0; b < 3; b++) g.add(new THREE.ConeGeometry(0.07, 0.3 + R() * 0.25, 3), b % 2 ? '#4d7c0f' : '#65a30d', { p: [x + (b - 1) * 0.09, SOIL_TOP + 0.15, z], r: [0, R() * 3, (b - 1) * 0.4], ol: 0.015 });
    }
    for (let i = 0; i < 7; i++) g.add(new THREE.DodecahedronGeometry(0.1 + R() * 0.08, 0), R() < 0.5 ? C.stone : C.stoneDark, { p: [(R() * 2 - 1) * 1.1, SOIL_TOP + 0.03, (R() * 2 - 1) * 1.1], s: [1, 0.65, 1], ol: 0.02 });
    return g.build();
  }
  function buildPadlock() {
    const g = new Geo(10);
    g.add(new THREE.BoxGeometry(0.66, 0.52, 0.28), C.gold, { p: [0, 0, 0], ol: 0.04 });
    g.add(new THREE.TorusGeometry(0.22, 0.065, 6, 12, Math.PI), C.silver, { p: [0, 0.26, 0], ol: 0.03 });
    g.add(new THREE.CylinderGeometry(0.07, 0.07, 0.05, 8), C.ink, { p: [0, 0.05, 0.15], r: [Math.PI / 2, 0, 0], ol: false });
    g.add(new THREE.BoxGeometry(0.05, 0.14, 0.04), C.ink, { p: [0, -0.06, 0.15], ol: false });
    return g.build();
  }
  function buildSickle() {
    const g = new Geo(12);
    g.stick(V(0, -0.6, 0), V(0, 1, 0), 0.5, 0.055, 0.05, C.wood, { ol: 0.03 });
    g.add(new THREE.TorusGeometry(0.32, 0.06, 4, 14, Math.PI * 1.25), C.gold, { p: [0.28, -0.08, 0], r: [0, 0, -0.2], s: [1, 1, 0.45], ol: 0.03 });
    return g.build();
  }
  function buildSprout() {
    // Dibuat besar; ditampilkan dengan skala 0.4 (tahap 0–30%)
    const g = new Geo(13);
    PLANT_OFFSETS.forEach(([x, z]) => {
      const X = x * 2.5, Z = z * 2.5;
      g.stick(V(X, SOIL_TOP / 0.4, Z), V(0, 1, 0), 0.6, 0.07, 0.05, C.leaf, { ol: 0.05 });
      [-1, 1].forEach((s) => g.add(new THREE.SphereGeometry(0.34, 6, 4), C.leafLight, { p: [X + s * 0.32, SOIL_TOP / 0.4 + 0.62, Z], r: [0, 0, s * 0.45], s: [1, 0.24, 0.6], ol: 0.05 }));
    });
    return g.build();
  }
  function buildRice() {
    const g = new Geo(14), R = g.rand;
    PLANT_OFFSETS.forEach(([x, z]) => {
      g.add(new THREE.ConeGeometry(0.2, 0.32, 5), C.leafDark, { p: [x, SOIL_TOP + 0.16, z], ol: 0.025 });
      for (let i = 0; i < 6; i++) {
        const a = (i / 6) * Math.PI * 2 + R() * 0.4, tilt = 0.14 + R() * 0.12, len = 1.0 + R() * 0.3;
        const dir = V(Math.cos(a) * Math.sin(tilt), Math.cos(tilt), Math.sin(a) * Math.sin(tilt));
        const base = V(x, SOIL_TOP, z);
        g.stick(base, dir, len, 0.035, 0.022, i % 2 ? '#65a30d' : '#84cc16', { ol: 0.018, seg: 4 });
        const tip = base.clone().addScaledVector(dir, len);
        const droop = V(Math.cos(a) * 0.9, -0.5, Math.sin(a) * 0.9).normalize();
        const q = new THREE.Quaternion().setFromUnitVectors(_up, droop);
        const c = tip.clone().addScaledVector(droop, 0.16);
        g.add(new THREE.IcosahedronGeometry(0.09, 0), C.white, { p: [c.x, c.y, c.z], q, s: [1, 2.4, 1], fruit: true, ol: 0.02 });
      }
    });
    return g.build();
  }
  function buildChili() {
    const g = new Geo(15), R = g.rand;
    PLANT_OFFSETS.forEach(([x, z]) => {
      g.stick(V(x, SOIL_TOP, z), V(0, 1, 0), 0.45, 0.06, 0.05, C.leafDark, { ol: 0.02 });
      [[0, 0.75, 0, 0.34], [0.22, 0.6, 0.12, 0.26], [-0.2, 0.62, -0.1, 0.27]].forEach(([dx, y, dz, r]) => g.add(new THREE.IcosahedronGeometry(r, 0), R() < 0.5 ? C.leaf : '#22c55e', { p: [x + dx, SOIL_TOP + y, z + dz], r: [R(), R(), 0], ol: 0.03 }));
      for (let i = 0; i < 5; i++) {
        const a = (i / 5) * Math.PI * 2 + 0.3;
        g.add(new THREE.ConeGeometry(0.065, 0.36, 6), C.white, { p: [x + Math.cos(a) * 0.32, SOIL_TOP + 0.42, z + Math.sin(a) * 0.32], r: [Math.PI + Math.sin(a) * 0.3, 0, Math.cos(a) * 0.3], fruit: true, ol: 0.02 });
      }
    });
    return g.build();
  }
  function buildCorn() {
    const g = new Geo(16);
    PLANT_OFFSETS.forEach(([x, z], pi) => {
      g.stick(V(x, SOIL_TOP, z), V(0, 1, 0), 1.65, 0.06, 0.04, '#65a30d', { ol: 0.02 });
      [0.45, 0.8, 1.1, 1.35].forEach((y, i) => {
        const a = i * 1.9 + pi;
        g.add(new THREE.BoxGeometry(0.62, 0.03, 0.12), i % 2 ? C.leaf : '#22c55e', { p: [x + Math.cos(a) * 0.3, SOIL_TOP + y, z + Math.sin(a) * 0.3], r: [0, -a, -0.45], ol: 0.015 });
      });
      const ca = pi * 1.3 + 0.6, cx = x + Math.cos(ca) * 0.12, cz = z + Math.sin(ca) * 0.12;
      g.add(new THREE.CylinderGeometry(0.085, 0.07, 0.4, 7), C.white, { p: [cx, SOIL_TOP + 0.95, cz], r: [Math.sin(ca) * 0.4, 0, -Math.cos(ca) * 0.4], fruit: true, ol: 0.022 });
      g.add(new THREE.ConeGeometry(0.1, 0.22, 5), '#84cc16', { p: [cx, SOIL_TOP + 0.74, cz], r: [Math.PI, 0, 0], ol: 0.015 });
      for (let t = 0; t < 3; t++) g.stick(V(x, SOIL_TOP + 1.62, z), V(Math.cos(t * 2.1) * 0.5, 1, Math.sin(t * 2.1) * 0.5), 0.26, 0.018, 0.012, '#ca8a04', { ol: false, seg: 3 });
    });
    return g.build();
  }
  function buildWatermelon() {
    const g = new Geo(17), R = g.rand, stripe = new THREE.Color(), light = new THREE.Color(1, 1, 1), dark = new THREE.Color(0.55, 0.55, 0.55);
    PLANT_OFFSETS.forEach(([x, z]) => {
      for (let i = 0; i < 5; i++) {
        const a = (i / 5) * Math.PI * 2 + R();
        g.add(new THREE.IcosahedronGeometry(0.28, 0), i % 2 ? C.leaf : C.leafDark, { p: [x + Math.cos(a) * 0.42, SOIL_TOP + 0.08, z + Math.sin(a) * 0.42], s: [1, 0.35, 1], r: [0, a, 0], ol: 0.02 });
      }
      g.add(new THREE.SphereGeometry(0.36, 10, 7), (l) => stripe.copy(Math.sin(Math.atan2(l.z, l.y) * 6) > 0.25 ? dark : light),
        { p: [x, SOIL_TOP + 0.3, z], s: [1.3, 0.95, 1], r: [0, R() * 3, 0], fruit: true, ol: 0.03, jitter: 0.02 });
      g.add(new THREE.CylinderGeometry(0.025, 0.03, 0.12, 4), C.leafDark, { p: [x, SOIL_TOP + 0.66, z], ol: false });
    });
    return g.build();
  }
  function buildBug() {
    const g = new Geo(18);
    for (let i = 0; i < 5; i++) g.add(new THREE.SphereGeometry(0.085, 7, 5), i % 2 ? C.gold : '#84cc16', { p: [-0.3 + i * 0.12, 0.085 + (i % 2) * 0.03, 0], ol: 0.022 });
    g.add(new THREE.SphereGeometry(0.11, 8, 6), '#65a30d', { p: [0.33, 0.13, 0], ol: 0.025 });
    [-1, 1].forEach((s) => {
      g.add(new THREE.SphereGeometry(0.035, 6, 4), C.white, { p: [0.42, 0.17, s * 0.05], ol: false });
      g.add(new THREE.SphereGeometry(0.018, 5, 3), C.ink, { p: [0.45, 0.17, s * 0.05], ol: false });
      g.stick(V(0.36, 0.22, s * 0.04), V(0.4, 1, s * 0.4), 0.16, 0.012, 0.012, C.ink, { ol: false, seg: 3 });
    });
    return g.build();
  }
  function buildCan() {
    const g = new Geo(19);
    g.add(new THREE.CylinderGeometry(0.32, 0.36, 0.5, 10), C.sky, { ol: 0.035 });
    g.add(new THREE.CylinderGeometry(0.34, 0.34, 0.06, 10), '#0284c7', { p: [0, 0.25, 0], ol: 0.02 });
    g.stick(V(0.28, -0.1, 0), V(1, 0.7, 0), 0.6, 0.06, 0.04, '#0284c7', { ol: 0.025 });
    g.add(new THREE.ConeGeometry(0.1, 0.12, 8), '#0369a1', { p: [0.78, 0.29, 0], r: [0, 0, -Math.PI / 2 - 0.6], ol: 0.02 });
    g.add(new THREE.TorusGeometry(0.2, 0.04, 5, 10, Math.PI), '#0284c7', { p: [-0.12, 0.28, 0], r: [0, 0, 0.3], ol: 0.02 });
    return g.build();
  }

  /* ══════════════════════════════════════════════════════════
     IKON 3D UNTUK UI
     Model low-poly dirender sekali ke gambar (data URL) lalu dipakai
     <img> di kartu, dock & ikon terbang. Satu konteks WebGL kecil
     terpisah yang langsung dibuang setelah semua ikon jadi.
     ══════════════════════════════════════════════════════════ */
  function buildSeedBag() {
    const g = new Geo(31);
    g.add(new THREE.SphereGeometry(0.55, 10, 8), '#d6a35c', { p: [0, 0.5, 0], s: [1, 1.05, 0.85], ol: 0.04 });
    g.add(new THREE.CylinderGeometry(0.16, 0.3, 0.28, 9), '#c48a45', { p: [0, 1.1, 0], ol: 0.03 });
    g.add(new THREE.TorusGeometry(0.19, 0.05, 6, 12), '#7c2d12', { p: [0, 1.0, 0], r: [Math.PI / 2, 0, 0], ol: false });
    g.add(new THREE.SphereGeometry(0.2, 8, 6), '#e8c07d', { p: [0, 1.28, 0], s: [1.2, 0.6, 1.2], ol: 0.03 });
    g.add(new THREE.CircleGeometry(0.22, 12), '#16a34a', { p: [0, 0.55, 0.47], ol: false });
    [[-0.07, 0], [0.07, 0]].forEach(([x], i) => g.add(new THREE.SphereGeometry(0.1, 6, 4), '#4ade80', { p: [x * 1.6, 0.62, 0.49], s: [0.6, 1, 0.3], r: [0, 0, i ? -0.6 : 0.6], ol: false }));
    for (let i = 0; i < 4; i++) g.add(new THREE.SphereGeometry(0.07, 6, 4), C.gold, { p: [-0.45 + i * 0.3, 0.06, 0.45], s: [1, 0.7, 1], ol: 0.015 });
    return g.build();
  }
  function buildFertilizer() {
    const g = new Geo(32);
    g.add(new THREE.BoxGeometry(0.9, 1.1, 0.5), '#38bdf8', { p: [0, 0.6, 0], ol: 0.04 });
    g.add(new THREE.BoxGeometry(0.92, 0.12, 0.52), '#0284c7', { p: [0, 1.18, 0], ol: 0.025 });
    g.add(new THREE.BoxGeometry(0.62, 0.5, 0.02), '#ffffff', { p: [0, 0.62, 0.26], ol: false });
    // petir
    const bolt = new THREE.Shape();
    bolt.moveTo(0.05, 0.22); bolt.lineTo(-0.12, -0.02); bolt.lineTo(0.0, -0.02); bolt.lineTo(-0.06, -0.22); bolt.lineTo(0.13, 0.04); bolt.lineTo(0.01, 0.04); bolt.closePath();
    g.add(new THREE.ShapeGeometry(bolt), C.gold, { p: [0, 0.62, 0.28], ol: false });
    for (let i = 0; i < 5; i++) g.add(new THREE.OctahedronGeometry(0.06, 0), i % 2 ? '#fde047' : '#ffffff', { p: [-0.5 + i * 0.25, 1.4 + (i % 2) * 0.12, 0.1], ol: false });
    return g.build();
  }
  function buildSpray() {
    const g = new Geo(33);
    g.add(new THREE.CylinderGeometry(0.3, 0.34, 0.9, 10), '#22c55e', { p: [0, 0.45, 0], ol: 0.035 });
    g.add(new THREE.CylinderGeometry(0.22, 0.24, 0.4, 10), '#ffffff', { p: [0, 0.48, 0.02], ol: false, s: [1.05, 1, 1.05] });
    g.add(new THREE.CylinderGeometry(0.14, 0.18, 0.22, 8), '#15803d', { p: [0, 1.0, 0], ol: 0.025 });
    g.add(new THREE.BoxGeometry(0.42, 0.14, 0.16), '#f43f5e', { p: [0.12, 1.15, 0], ol: 0.025 });
    g.add(new THREE.BoxGeometry(0.08, 0.26, 0.1), '#be123c', { p: [0.05, 0.98, 0.1], r: [0, 0, 0.3], ol: 0.015 });
    for (let i = 0; i < 6; i++) g.add(new THREE.SphereGeometry(0.06 + (i % 3) * 0.02, 6, 4), '#bbf7d0', { p: [0.45 + i * 0.09, 1.15 + Math.sin(i) * 0.12, (i % 2 ? 0.06 : -0.06)], ol: false });
    return g.build();
  }
  function buildBarn() {
    const g = new Geo(34);
    g.add(new THREE.BoxGeometry(1.2, 0.85, 0.9), '#dc2626', { p: [0, 0.43, 0], ol: 0.04 });
    const roof = new THREE.CylinderGeometry(0.75, 0.75, 1.0, 3, 1);
    g.add(roof, '#7f1d1d', { p: [0, 1.02, 0], r: [Math.PI / 2, 0, Math.PI / 2], s: [1, 1, 0.55], ol: 0.04 });
    g.add(new THREE.BoxGeometry(0.46, 0.56, 0.04), '#fef3c7', { p: [0, 0.3, 0.46], ol: 0.02 });
    g.add(new THREE.BoxGeometry(0.06, 0.62, 0.05), '#dc2626', { p: [0, 0.3, 0.48], r: [0, 0, 0.7], ol: false });
    g.add(new THREE.BoxGeometry(0.06, 0.62, 0.05), '#dc2626', { p: [0, 0.3, 0.48], r: [0, 0, -0.7], ol: false });
    g.add(new THREE.BoxGeometry(0.26, 0.2, 0.04), '#fef3c7', { p: [0, 0.98, 0.42], ol: 0.015 });
    g.add(new THREE.CylinderGeometry(0.2, 0.2, 0.9, 8), '#e5e7eb', { p: [0.82, 0.45, -0.1], ol: 0.03 });
    g.add(new THREE.SphereGeometry(0.2, 8, 5, 0, Math.PI * 2, 0, Math.PI / 2), '#94a3b8', { p: [0.82, 0.9, -0.1], ol: 0.025 });
    return g.build();
  }
  function buildStall() {
    const g = new Geo(35);
    g.add(new THREE.BoxGeometry(1.3, 0.5, 0.7), '#b45309', { p: [0, 0.25, 0], ol: 0.035 });
    [[-0.6, -0.3], [0.6, -0.3], [-0.6, 0.3], [0.6, 0.3]].forEach(([x, z]) => g.add(new THREE.BoxGeometry(0.07, 0.75, 0.07), '#78350f', { p: [x, 0.85, z], ol: 0.015 }));
    for (let i = 0; i < 6; i++) g.add(new THREE.BoxGeometry(0.24, 0.06, 0.95), i % 2 ? '#ffffff' : '#f43f5e', { p: [-0.6 + i * 0.24, 1.3 - Math.abs(i - 2.5) * 0.02, 0], r: [0.22, 0, 0], ol: 0.015 });
    g.add(new THREE.SphereGeometry(0.15, 8, 6), '#ef4444', { p: [-0.35, 0.62, 0.15], ol: 0.02 });
    g.add(new THREE.SphereGeometry(0.17, 8, 6), '#22c55e', { p: [0, 0.64, 0.1], s: [1.2, 1, 1], ol: 0.02 });
    g.add(new THREE.SphereGeometry(0.13, 8, 6), C.gold, { p: [0.35, 0.6, 0.18], ol: 0.02 });
    return g.build();
  }
  function buildCoins() {
    const g = new Geo(36);
    for (let i = 0; i < 5; i++) g.add(new THREE.CylinderGeometry(0.34, 0.34, 0.1, 14), i % 2 ? '#fbbf24' : '#facc15', { p: [-0.25 + (i % 2) * 0.02, 0.05 + i * 0.11, 0.05], ol: 0.025 });
    for (let i = 0; i < 3; i++) g.add(new THREE.CylinderGeometry(0.34, 0.34, 0.1, 14), '#facc15', { p: [0.45, 0.05 + i * 0.11, -0.1], ol: 0.025 });
    g.add(new THREE.CylinderGeometry(0.34, 0.34, 0.1, 14), '#fde047', { p: [0.25, 0.35, 0.45], r: [1.2, 0, 0.3], ol: 0.025 });
    g.add(new THREE.TorusGeometry(0.2, 0.03, 4, 12), '#d97706', { p: [0.25, 0.35, 0.51], r: [1.2 - Math.PI / 2, 0, 0.3], ol: false });
    return g.build();
  }
  function buildBasket() {
    const g = new Geo(37);
    g.add(new THREE.CylinderGeometry(0.62, 0.45, 0.5, 12, 1, true), '#c2843d', { p: [0, 0.25, 0], ol: 0.035 });
    g.add(new THREE.CylinderGeometry(0.45, 0.45, 0.04, 12), '#a16207', { p: [0, 0.02, 0], ol: false });
    g.add(new THREE.TorusGeometry(0.62, 0.05, 6, 16), '#92400e', { p: [0, 0.5, 0], r: [Math.PI / 2, 0, 0], ol: 0.02 });
    g.add(new THREE.TorusGeometry(0.55, 0.04, 5, 14, Math.PI), '#92400e', { p: [0, 0.5, 0], ol: 0.02 });
    g.add(new THREE.SphereGeometry(0.22, 8, 6), '#ef4444', { p: [-0.2, 0.55, 0.1], ol: 0.02 });
    g.add(new THREE.SphereGeometry(0.25, 8, 6), '#22c55e', { p: [0.18, 0.58, -0.05], s: [1.2, 1, 1], ol: 0.02 });
    g.add(new THREE.SphereGeometry(0.16, 8, 6), C.gold, { p: [0.05, 0.62, 0.28], s: [0.8, 1.4, 0.8], ol: 0.02 });
    return g.build();
  }
  function buildStar() {
    const g = new Geo(38), sh = new THREE.Shape();
    for (let i = 0; i < 10; i++) { const a = Math.PI / 2 + (i / 10) * Math.PI * 2, r = i % 2 ? 0.26 : 0.6; i ? sh.lineTo(Math.cos(a) * r, Math.sin(a) * r) : sh.moveTo(Math.cos(a) * r, Math.sin(a) * r); }
    g.add(new THREE.ExtrudeGeometry(sh, { depth: 0.18, bevelEnabled: true, bevelSize: 0.05, bevelThickness: 0.05, bevelSegments: 1 }), C.gold, { p: [0, 0.6, -0.09], ol: 0.03 });
    return g.build();
  }

  /** Model hasil panen untuk ikon (bukan tanaman di petak): warna = warna khas bibit. */
  function buildProduce(model, hex) {
    const g = new Geo(40), col = new THREE.Color(hex), dark = col.clone().multiplyScalar(0.72);
    if (model === 'chili') {
      [[-0.32, 0.35, 0.5], [0.05, 0.45, 0.05], [0.38, 0.33, -0.45]].forEach(([x, y, rz], i) => {
        g.add(new THREE.ConeGeometry(0.17, 1.05, 9), i === 1 ? col : dark, { p: [x, y, i * 0.08], r: [0, 0, Math.PI + rz], s: [1, 1, 0.9], ol: 0.03 });
        g.add(new THREE.CylinderGeometry(0.06, 0.09, 0.22, 6), '#15803d', { p: [x - Math.sin(rz) * 0.55, y + 0.55, i * 0.08], r: [0, 0, rz], ol: 0.02 });
      });
    } else if (model === 'corn') {
      g.add(new THREE.CylinderGeometry(0.26, 0.2, 1.2, 10), col, { p: [0, 0.65, 0], r: [0, 0, -0.35], ol: 0.03 });
      for (let i = 0; i < 6; i++) g.add(new THREE.SphereGeometry(0.075, 5, 4), dark, { p: [-0.12 + i * 0.07 + 0.18, 0.35 + i * 0.16, 0.22], ol: false });
      [-1, 1].forEach((sd) => g.add(new THREE.ConeGeometry(0.22, 1.0, 5), '#65a30d', { p: [sd * 0.18 - 0.15, 0.45, 0.05], r: [0, 0, -0.35 + sd * 0.4], s: [1, 1, 0.35], ol: 0.025 }));
      g.add(new THREE.ConeGeometry(0.08, 0.3, 5), '#ca8a04', { p: [0.24, 1.32, 0], r: [0, 0, -0.35], ol: false });
    } else if (model === 'watermelon') {
      const stripe = new THREE.Color(), lite = new THREE.Color('#22c55e'), deep = new THREE.Color('#15803d');
      g.add(new THREE.SphereGeometry(0.62, 14, 10), (l) => stripe.copy(Math.sin(Math.atan2(l.z, l.x) * 7) > 0.2 ? deep : lite).lerp(col, 0.25),
        { p: [-0.15, 0.55, -0.1], s: [1.25, 0.95, 1], ol: 0.035, jitter: 0.02 });
      g.add(new THREE.CylinderGeometry(0.5, 0.5, 0.16, 14, 1, false, 0, Math.PI), '#ef4444', { p: [0.55, 0.3, 0.45], r: [Math.PI / 2, 0, 0.2], ol: 0.025 });
      g.add(new THREE.TorusGeometry(0.5, 0.05, 4, 14, Math.PI), '#16a34a', { p: [0.55, 0.3, 0.45], r: [0, 0, 0.2], ol: false });
      for (let i = 0; i < 4; i++) g.add(new THREE.SphereGeometry(0.035, 4, 3), '#1f2937', { p: [0.4 + i * 0.1, 0.45 - (i % 2) * 0.1, 0.55], ol: false });
    } else { // rice: ikatan padi emas
      for (let i = 0; i < 9; i++) {
        const a = (i / 9) * Math.PI * 2, tilt = 0.18 + (i % 3) * 0.06;
        const dir = V(Math.cos(a) * Math.sin(tilt), Math.cos(tilt), Math.sin(a) * Math.sin(tilt));
        g.stick(V(Math.cos(a) * 0.06, 0, Math.sin(a) * 0.06), dir, 1.1, 0.04, 0.03, '#a3a635', { ol: 0.015, seg: 4 });
        const tip = V(Math.cos(a) * 0.06, 0, Math.sin(a) * 0.06).addScaledVector(dir, 1.1);
        const droop = V(Math.cos(a), -0.4, Math.sin(a)).normalize();
        const q = new THREE.Quaternion().setFromUnitVectors(_up, droop);
        const c = tip.addScaledVector(droop, 0.16);
        g.add(new THREE.IcosahedronGeometry(0.1, 0), col, { p: [c.x, c.y, c.z], q, s: [1, 2.6, 1], ol: 0.02 });
      }
      g.add(new THREE.TorusGeometry(0.14, 0.05, 5, 12), '#b45309', { p: [0, 0.45, 0], r: [Math.PI / 2, 0, 0], ol: 0.015 });
    }
    return g.build();
  }

  /** Render semua ikon 3D ke data URL (dipanggil sekali). */
  function renderIcons(crops, models, plainMat) {
    const size = 160, out = {};
    let r;
    try {
      r = new THREE.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: true });
    } catch (e) { return out; }
    r.setPixelRatio(1);
    r.setSize(size, size, false);
    r.setClearColor(0x000000, 0);
    const scene = new THREE.Scene();
    scene.add(new THREE.HemisphereLight('#ffffff', '#86efac', 0.75));
    const sun = new THREE.DirectionalLight('#ffffff', 1.25);
    sun.position.set(3, 6, 5);
    scene.add(sun);
    const cam = new THREE.PerspectiveCamera(28, 1, 0.1, 100);
    const shoot = (key, mesh, tilt = 0.55) => {
      scene.add(mesh);
      const box = new THREE.Box3().setFromObject(mesh), c = box.getCenter(new THREE.Vector3()), sz = box.getSize(new THREE.Vector3());
      const rad = Math.max(sz.x, sz.y, sz.z) * 0.62;
      const dist = rad / Math.sin(THREE.MathUtils.degToRad(cam.fov / 2));
      cam.position.copy(c).add(new THREE.Vector3(0.62, tilt, 1).normalize().multiplyScalar(dist));
      cam.lookAt(c);
      r.render(scene, cam);
      out[key] = r.domElement.toDataURL('image/png');
      scene.remove(mesh);
    };
    // Hasil panen tiap bibit (warna khas bibit)
    for (const c of crops) {
      const geo = buildProduce(c.model, c.color);
      shoot('crop:' + c.id, new THREE.Mesh(geo, plainMat || models.mat), 0.6);
      geo.dispose();
    }
    const props = { seed: buildSeedBag(), speed: buildFertilizer(), cure: buildSpray(), barn: buildBarn(), shop: buildStall(),
      coins: buildCoins(), basket: buildBasket(), star: buildStar(), lock: models.padlock, can: models.can };
    if (plainMat) for (const [k, geo] of Object.entries(props)) if (geo) shoot(k, new THREE.Mesh(geo, plainMat));
    r.dispose();
    try { r.forceContextLoss(); } catch (e) { /* abaikan */ }
    return out;
  }

  /* ══════════════════════════════════════════════════════════
     ENGINE
     ══════════════════════════════════════════════════════════ */
  const MODELS = ['rice', 'chili', 'corn', 'watermelon'];

  class FarmEngine {
    /**
     * @param {HTMLElement} container  wadah canvas
     * @param {object} opts { labelLayer: HTMLElement, barnEl: HTMLElement, audio: FarmAudio, onPlotTap(plot) }
     */
    constructor(container, opts = {}) {
      this.container = container;
      this.labelLayer = opts.labelLayer;
      this.barnEl = opts.barnEl;
      this.audio = opts.audio || new FarmAudio();
      this.onPlotTap = opts.onPlotTap || (() => {});
      this.onReadyChange = opts.onReadyChange || (() => {});
      this.crops = new Map();
      this.serverOffset = 0;
      this.frozen = false;
      this.hover = -1;
      this.busy = new Set();
      this.nextPlotCost = 0;
      this.clock = new THREE.Clock();
      this.time = 0;
      this._v = new THREE.Vector3(); this._m = new THREE.Matrix4(); this._q = new THREE.Quaternion();
      this._e = new THREE.Euler(); this._s = new THREE.Vector3(); this._p = new THREE.Vector3(); this._c = new THREE.Color();
      this._zero = new THREE.Matrix4().makeScale(0, 0, 0);

      // Metadata 12 petak (sesuai spesifikasi) + state animasi (fx) per petak
      this.plots = [];
      this.fx = [];
      for (let i = 1; i <= ROWS * COLS; i++) {
        const anchor = new THREE.Object3D();
        const center = plotCenter(i);
        anchor.position.set(center.x, 2.35, center.z);
        this.plots.push({ plotIndex: i, state: 'LOCKED', cropData: null, plantedAt: 0, maturesAt: 0, pestAt: 0, pest: false, timerMesh: anchor, center });
        this.fx.push({ pop: 1, till: 1, lastStage: null, override: null, ghost: null, lockGhost: null, bugGhost: null, sw: null, label: null, labelKey: '' });
      }

      this._initRenderer();
      this._initScene();
      this._initControls();
      this._initLabels();
      this.icons = {};
      this._iconModels = { rice: this.cropMesh.rice.geometry, chili: this.cropMesh.chili.geometry, corn: this.cropMesh.corn.geometry,
        watermelon: this.cropMesh.watermelon.geometry, padlock: this.m.padlock.geometry, can: this.can.geometry, mat: this.mat };
      this._bindEvents();
      this._fps = { acc: 0, n: 0 };
      this._loop = this._loop.bind(this);
      requestAnimationFrame(this._loop);
    }

    /* ── Setup ─────────────────────────────────────────────── */
    _initRenderer() {
      const r = this.renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' });
      this.dpr = Math.min(global.devicePixelRatio || 1, 2);
      r.setPixelRatio(this.dpr);
      r.shadowMap.enabled = true;
      r.shadowMap.type = THREE.PCFSoftShadowMap;
      r.setClearColor(0x000000, 0);
      this.container.appendChild(r.domElement);
      r.domElement.style.touchAction = 'none';

      this.camera = new THREE.PerspectiveCamera(45, 1, 0.1, 1000);
      // Arah isometrik (18,22,18), jarak di dalam batas zoom agar tidak "melompat" saat clamp
      this.camera.position.set(18, 22, 18).setLength(28);
      this._resize();
      // Layar potret: kamera lebih menukik (polar 38°) agar sawah mengisi tinggi layar
      if (this.camera.aspect < 1) this.camera.position.setFromSphericalCoords(28, THREE.MathUtils.degToRad(38), Math.PI / 4);
      this.camera.lookAt(0, 0, 0);
    }

    _initScene() {
      const s = this.scene = new THREE.Scene();
      s.fog = new THREE.Fog('#e6f1df', 50, 125);
      const grad = this.gradient = toonGradient();
      // Material terpisah untuk mesh ber-instanceColor: three r128 tidak mengompilasi
      // ulang program bila satu material dipakai mesh instanced dengan & tanpa instanceColor.
      this.mat = tintMaterial(grad);
      this.tintMat = tintMaterial(grad);
      this.glowMat = tintMaterial(grad, { emissive: new THREE.Color('#3d2b00') });

      // Lampu
      s.add(new THREE.HemisphereLight('#fffaf0', '#9bd27a', 0.72));
      const sun = this.sun = new THREE.DirectionalLight('#fff3da', 1.4);
      sun.position.set(20, 35, 15);
      sun.castShadow = true;
      sun.shadow.mapSize.width = 2048; sun.shadow.mapSize.height = 2048;
      sun.shadow.bias = -0.0005;
      sun.shadow.normalBias = 0.02;
      const sc = sun.shadow.camera;
      sc.left = -17; sc.right = 17; sc.top = 17; sc.bottom = -17; sc.near = 5; sc.far = 80;
      sc.layers.enable(1); // layer 1 = caster bayangan awan (tidak terlihat kamera utama)
      s.add(sun, sun.target);

      const world = this.world = new THREE.Group();
      s.add(world);

      // 1) Dunia statis (1 draw call)
      const stat = new THREE.Mesh(buildStaticWorld(), this.mat);
      stat.castShadow = stat.receiveShadow = true;
      world.add(stat);

      // 2) Sungai irigasi + air terjun (shader UV bergerak)
      world.add(this._buildWater());

      // 3) Roda kincir air (berputar mengikuti arus sungai)
      this.sailPivot = new THREE.Group();
      this.sailPivot.position.set(WHEEL.x, WHEEL_Y, WHEEL.z);
      this.sails = new THREE.Mesh(buildSails(), this.mat);
      this.sails.castShadow = true;
      this.sailPivot.add(this.sails);
      world.add(this.sailPivot);

      // 4) Tajuk kelapa & pisang (instanced, bergoyang)
      const palms = TREES.filter((t) => t.type === 'palm'), bananas = TREES.filter((t) => t.type === 'banana');
      this.palmMesh = this._inst(buildPalmCanopy(), this.mat, palms.length);
      this.bananaMesh = this._inst(buildBananaCanopy(), this.mat, bananas.length);
      this.palmTrees = palms; this.bananaTrees = bananas;

      // 4b) Padi di sawah tergenang (1 mesh, bergoyang angin di vertex shader)
      this.windMat = animMaterial(grad, 'wind');
      const paddy = new THREE.Mesh(buildPaddyRice(), this.windMat);
      paddy.receiveShadow = true;
      world.add(paddy);

      // 4c) Kehidupan desa: layang-layang, kawanan kuntul, bebek, asap kawah, bendera, matahari
      this.kites = [
        { p: BACK.clone().multiplyScalar(17).addScaledVector(SIDE, -9).setY(12.5), c: '#ef4444' },
        { p: BACK.clone().multiplyScalar(23).addScaledVector(SIDE, 11).setY(15), c: '#3b82f6' },
      ];
      this.kiteMesh = this._inst(buildKite(), this.tintMat, this.kites.length, { tint: true, shadow: false });
      this.kites.forEach((k, i) => this.kiteMesh.setColorAt(i, new THREE.Color(k.c)));
      this.birdMat = animMaterial(grad, 'flap');
      this.flock = this._inst(buildFlyingEgret(), this.birdMat, 7, { shadow: false });
      this.duckMesh = this._inst(buildDuck(), this.mat, 5);
      this.puffMesh = this._inst(buildPuff(), this.mat, 7, { shadow: false });

      // Bendera merah putih (vertex digerakkan di CPU; atas merah, bawah putih)
      const fg = this.flagGeo = new THREE.PlaneGeometry(1.5, 1.0, 14, 2).toNonIndexed();
      fg.translate(0.75, 0, 0);
      const fpos = fg.attributes.position, fc = new Float32Array(fpos.count * 3), red = new THREE.Color('#dc2626'), wht = new THREE.Color('#ffffff');
      for (let t3 = 0; t3 < fpos.count; t3 += 3) {
        const cy = (fpos.getY(t3) + fpos.getY(t3 + 1) + fpos.getY(t3 + 2)) / 3;
        for (let k = 0; k < 3; k++) (cy > 0 ? red : wht).toArray(fc, (t3 + k) * 3);
      }
      fg.setAttribute('color', new THREE.BufferAttribute(fc, 3));
      this.flagBase = fpos.array.slice();
      this.flag = new THREE.Mesh(fg, new THREE.MeshBasicMaterial({ vertexColors: true, side: THREE.DoubleSide }));
      this.flag.position.set(FLAG.x, 4.05, FLAG.z);
      this.flag.rotation.y = Math.PI / 4;
      world.add(this.flag);

      // Matahari di antara dua gunung
      this.sunDisc = new THREE.Group();
      this.sunDisc.position.copy(BACK.clone().multiplyScalar(112).setY(27));
      const halo = new THREE.Mesh(new THREE.CircleGeometry(13, 40), new THREE.MeshBasicMaterial({ color: '#fff6cf', transparent: true, opacity: 0.35, fog: false, depthWrite: false }));
      const disc = new THREE.Mesh(new THREE.CircleGeometry(7.5, 40), new THREE.MeshBasicMaterial({ color: '#ffe680', fog: false }));
      disc.position.z = 0.1;
      this.sunDisc.add(halo, disc);
      world.add(this.sunDisc);

      // 5) Awan dekoratif + caster bayangan awan
      const cloudGeo = buildCloud(21);
      this.clouds = [
        { x: -15, y: 14, z: -28, s: 2.2, v: 0.35 }, { x: 4, y: 17, z: -36, s: 2.6, v: 0.25 }, { x: 22, y: 13, z: -24, s: 1.9, v: 0.4 },
        { x: -32, y: 12, z: 3, s: 2.0, v: 0.3 }, { x: 30, y: 15, z: 10, s: 1.8, v: 0.32 }, { x: -6, y: 18, z: -44, s: 3.0, v: 0.2 },
      ];
      this.cloudMesh = this._inst(cloudGeo, this.mat, this.clouds.length, { shadow: false });
      this.cloudShadows = [{ x: -4, z: -2, v: 0.5, s: 1.5 }, { x: 10, z: 4, v: 0.45, s: 1.2 }, { x: -16, z: 6, v: 0.55, s: 1.3 }];
      this.shadowCloudMesh = new THREE.InstancedMesh(cloudGeo, new THREE.MeshBasicMaterial(), this.cloudShadows.length);
      this.shadowCloudMesh.castShadow = true;
      this.shadowCloudMesh.layers.set(1);
      this.shadowCloudMesh.frustumCulled = false;
      world.add(this.shadowCloudMesh);

      // 6) Elemen petak (instanced, 12 instance tiap mesh)
      const N = 12;
      this.m = {
        soil: this._inst(buildSoil(), this.tintMat, N, { tint: true }),
        furrows: this._inst(buildFurrows(), this.tintMat, N, { tint: true }),
        weeds: this._inst(buildWeeds(), this.mat, N),
        padlock: this._inst(buildPadlock(), this.glowMat, N),
        sickle: this._inst(buildSickle(), this.glowMat, N),
        sprout: this._inst(buildSprout(), this.mat, N),
        bug: this._inst(buildBug(), this.mat, N),
      };
      this.m.soil.castShadow = false;
      this.m.furrows.castShadow = false;
      this.cropMesh = {
        rice: this._inst(buildRice(), this.tintMat, N, { tint: true }),
        chili: this._inst(buildChili(), this.tintMat, N, { tint: true }),
        corn: this._inst(buildCorn(), this.tintMat, N, { tint: true }),
        watermelon: this._inst(buildWatermelon(), this.tintMat, N, { tint: true }),
      };
      const ring = new THREE.RingGeometry(1.02, 1.26, 40);
      ring.rotateX(-Math.PI / 2);
      this.m.decal = new THREE.InstancedMesh(ring, new THREE.MeshBasicMaterial({ color: '#ffffff', transparent: true, opacity: 0.9, depthWrite: false }), N);
      this.m.decal.instanceColor = new THREE.InstancedBufferAttribute(new Float32Array(N * 3).fill(1), 3);
      this.m.decal.frustumCulled = false;
      this.m.decal.renderOrder = 2;
      world.add(this.m.decal);
      // Mesh per petak dipadatkan tiap frame: hanya instance aktif yang digambar (count dinamis)
      this.plotMeshes = [...Object.values(this.m), ...Object.values(this.cropMesh)];

      // 7) Properti sekuens (penyiram & jejak tebasan) — satu mesh, disembunyikan bila tidak dipakai
      this.can = new THREE.Mesh(buildCan(), this.mat);
      this.can.visible = false;
      this.canTip = new THREE.Vector3(0.82, 0.3, 0);
      world.add(this.can);
      const slash = new THREE.RingGeometry(0.75, 1.3, 32, 1, 0, Math.PI * 0.9);
      slash.rotateX(-Math.PI / 2);
      this.slash = new THREE.Mesh(slash, new THREE.MeshBasicMaterial({ color: '#fde047', transparent: true, opacity: 0, side: THREE.DoubleSide, depthWrite: false }));
      this.slash.visible = false;
      this.slash.renderOrder = 5;
      world.add(this.slash);

      // 8) Partikel
      this.particles = new Particles(800);
      world.add(this.particles.points);
      this._updateParticleScale();

      this.introDone = true;
    }

    /** Buat InstancedMesh dengan semua instance tersembunyi. */
    _inst(geo, mat, count, o = {}) {
      const m = new THREE.InstancedMesh(geo, mat, count);
      m.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
      if (o.tint) m.instanceColor = new THREE.InstancedBufferAttribute(new Float32Array(count * 3).fill(1), 3);
      m.castShadow = o.shadow !== false;
      m.receiveShadow = true;
      m.frustumCulled = false;
      for (let i = 0; i < count; i++) m.setMatrixAt(i, this._zero);
      this.world.add(m);
      return m;
    }

    _buildWater() {
      const len = STREAM.x1 - STREAM.x0, w = STREAM.z1 - STREAM.z0, zc = (STREAM.z0 + STREAM.z1) / 2;
      const parts = [];
      // Air terjun di tebing barat: mengalir ke bawah (flow naik), lalu lanjut ke sungai
      const fall = new THREE.PlaneGeometry(w, FALL_H, 1, 4).toNonIndexed();
      fall.rotateY(Math.PI / 2);
      fall.translate(STREAM.x0 - 0.05, STREAM.y + FALL_H / 2, zc);
      parts.push([fall, (x, y, z) => [FALL_H - y, (z - STREAM.z0) / w, 1]]);
      const strip = new THREE.PlaneGeometry(len + 0.6, w, 1, 1).toNonIndexed();
      strip.rotateX(-Math.PI / 2);
      strip.translate((STREAM.x0 + STREAM.x1 + 0.6) / 2, STREAM.y, zc);
      parts.push([strip, (x, y, z) => [FALL_H + x - STREAM.x0, (z - STREAM.z0) / w, 0]]);
      const pond = new THREE.CircleGeometry(POND.r, 32).toNonIndexed();
      pond.rotateX(-Math.PI / 2);
      pond.translate(POND.x, STREAM.y + 0.005, POND.z);
      parts.push([pond, (x, y, z) => [FALL_H + len + Math.hypot(x - POND.x, z - POND.z) * 0.6, 0.5 + 0.5 * Math.hypot(x - POND.x, z - POND.z) / POND.r, 0]]);
      const n = parts.reduce((a, [geo]) => a + geo.attributes.position.count, 0);
      const pos = new Float32Array(n * 3), flow = new Float32Array(n), across = new Float32Array(n), isFall = new Float32Array(n);
      let o = 0;
      for (const [geo, attr] of parts) {
        const P = geo.attributes.position.array;
        for (let i = 0; i < P.length; i += 3, o++) {
          pos[o * 3] = P[i]; pos[o * 3 + 1] = P[i + 1]; pos[o * 3 + 2] = P[i + 2];
          [flow[o], across[o], isFall[o]] = attr(P[i], P[i + 1], P[i + 2]);
        }
      }
      const g = new THREE.BufferGeometry();
      g.setAttribute('position', new THREE.BufferAttribute(pos, 3));
      g.setAttribute('flow', new THREE.BufferAttribute(flow, 1));
      g.setAttribute('across', new THREE.BufferAttribute(across, 1));
      g.setAttribute('fall', new THREE.BufferAttribute(isFall, 1));
      this.waterMat = new THREE.ShaderMaterial({
        uniforms: { uTime: { value: 0 } },
        vertexShader: `
          attribute float flow; attribute float across; attribute float fall;
          varying float vFlow; varying float vAcross; varying float vFall;
          void main() { vFlow = flow; vAcross = across; vFall = fall; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }`,
        fragmentShader: `
          uniform float uTime;
          varying float vFlow; varying float vAcross; varying float vFall;
          void main() {
            float fall = vFall;
            float f = vFlow - uTime * mix(0.9, 2.8, fall);
            float wav = sin(f * 2.2 + sin(vAcross * 9.0 + uTime * 1.3) * 1.2) * 0.5 + 0.5;
            vec3 col = mix(vec3(0.008, 0.518, 0.78), vec3(0.22, 0.741, 0.973), 0.3 + 0.5 * wav);
            float streak = smoothstep(0.86, 0.95, fract(f * 0.55 + sin(vAcross * 14.0) * 0.08)) * smoothstep(0.0, 0.2, vAcross) * smoothstep(1.0, 0.8, vAcross);
            float edge = smoothstep(0.14, 0.0, vAcross) + smoothstep(0.86, 1.0, vAcross);
            col = mix(col, vec3(1.0), clamp(streak * 0.7 + edge * 0.55 + fall * 0.1, 0.0, 1.0));
            gl_FragColor = vec4(col, 1.0);
          }`,
      });
      const mesh = new THREE.Mesh(g, this.waterMat);
      mesh.receiveShadow = false;
      return mesh;
    }

    _initControls() {
      const c = this.controls = new THREE.OrbitControls(this.camera, this.renderer.domElement);
      c.target.set(0, 0, 0);
      c.enableDamping = true;
      c.dampingFactor = 0.05;
      c.minDistance = 14;
      c.maxDistance = 30;
      c.minPolarAngle = Math.PI / 6;
      c.maxPolarAngle = Math.PI / 2.5;
      c.screenSpacePanning = false;
      c.rotateSpeed = 0.6;
      // Batasi pan: target tidak boleh keluar kotak [-6, 6]
      c.addEventListener('change', () => {
        const t = c.target;
        const nx = clamp(t.x, -PAN_LIMIT, PAN_LIMIT), nz = clamp(t.z, -PAN_LIMIT, PAN_LIMIT);
        if (nx !== t.x || nz !== t.z || t.y !== 0) {
          const dx = nx - t.x, dy = -t.y, dz = nz - t.z;
          t.set(nx, 0, nz);
          this.camera.position.x += dx; this.camera.position.y += dy; this.camera.position.z += dz;
        }
      });
      c.update();
    }

    _initLabels() {
      if (!this.labelLayer) return;
      this.fx.forEach((fx) => {
        const el = document.createElement('div');
        el.className = 'plot-tag';
        el.innerHTML = '<div class="pt-ring"><i class="ph-fill ph-plant"></i></div><div class="pt-text"></div>';
        el.style.display = 'none';
        this.labelLayer.appendChild(el);
        fx.label = el;
        fx.labelIcon = el.querySelector('i');
        fx.labelText = el.querySelector('.pt-text');
      });
    }

    _bindEvents() {
      const el = this.renderer.domElement;
      let down = null;
      el.addEventListener('pointerdown', (e) => {
        this.audio.unlock();
        down = { x: e.clientX, y: e.clientY, t: performance.now() };
      });
      global.addEventListener('pointerup', (e) => {
        if (!down) return;
        const moved = Math.hypot(e.clientX - down.x, e.clientY - down.y), dt = performance.now() - down.t;
        down = null;
        if (this.frozen || moved > 10 || dt > 600 || e.target !== el) return;
        const idx = this.pick(e.clientX, e.clientY);
        if (idx > 0) this.onPlotTap(this.plots[idx - 1]);
      });
      el.addEventListener('pointermove', (e) => {
        if (e.pointerType !== 'mouse' || this.frozen) return;
        const idx = this.pick(e.clientX, e.clientY);
        this.hover = idx;
        el.style.cursor = idx > 0 ? 'pointer' : '';
      });
      el.addEventListener('pointerleave', () => { this.hover = -1; });
      this._ro = new ResizeObserver(() => this._resize());
      this._ro.observe(this.container);
    }

    _resize() {
      const w = this.container.clientWidth || 1, h = this.container.clientHeight || 1, aspect = w / h;
      this.width = w; this.height = h;
      this.camera.aspect = aspect;
      // Layar potret (HP): perlebar FOV vertikal agar seluruh sawah tetap terlihat
      this.camera.fov = aspect < 1 ? clamp((2 * Math.atan(Math.tan(THREE.MathUtils.degToRad(20)) / aspect)) * THREE.MathUtils.RAD2DEG, 45, 75) : 45;
      this.camera.updateProjectionMatrix();
      this.renderer.setSize(w, h, false);
      this.renderer.domElement.style.width = w + 'px';
      this.renderer.domElement.style.height = h + 'px';
      this._updateParticleScale();
    }

    _updateParticleScale() {
      if (!this.particles) return;
      const hpx = this.height * this.renderer.getPixelRatio();
      this.particles.mat.uniforms.uScale.value = hpx / (2 * Math.tan(THREE.MathUtils.degToRad(this.camera.fov) / 2));
    }

    /* ── Raycast tap → petak (uji kotak per petak, termasuk tanaman tinggi) ── */
    pick(cx, cy) {
      const rect = this.renderer.domElement.getBoundingClientRect();
      const ndc = new THREE.Vector2(((cx - rect.left) / rect.width) * 2 - 1, -((cy - rect.top) / rect.height) * 2 + 1);
      const ray = this._ray || (this._ray = new THREE.Raycaster());
      ray.setFromCamera(ndc, this.camera);
      const box = new THREE.Box3(), hit = new THREE.Vector3();
      let best = -1, bestD = Infinity;
      for (const p of this.plots) {
        box.min.set(p.center.x - PLOT / 2, 0, p.center.z - PLOT / 2);
        box.max.set(p.center.x + PLOT / 2, p.state === 'LOCKED' ? 1.6 : 2.2, p.center.z + PLOT / 2);
        if (ray.ray.intersectBox(box, hit)) {
          const d = hit.distanceToSquared(ray.ray.origin);
          if (d < bestD) { bestD = d; best = p.plotIndex; }
        }
      }
      return best;
    }

    /* ── State ─────────────────────────────────────────────── */
    serverNow() { return Date.now() / 1000 + this.serverOffset; }

    setCatalog(crops) {
      this.crops.clear();
      (crops || []).forEach((c) => this.crops.set(c.id, Object.assign({}, c, { model: MODELS.includes(c.model) ? c.model : 'rice' })));
      // Ikon 3D: semua sekali di awal, lalu hanya bibit baru yang belum punya ikon
      const first = !this.icons.seed;
      const todo = [...this.crops.values()].filter((c) => !this.icons['crop:' + c.id]);
      if (first || todo.length) Object.assign(this.icons, renderIcons(todo, this._iconModels, first ? this.mat : null));
    }

    /** URL gambar ikon 3D (mis. 'crop:1', 'seed', 'barn'); kosong bila WebGL tidak tersedia. */
    icon(key) { return this.icons[key] || ''; }

    /** Terapkan state dari server (get_state / respon aksi). */
    applyState(state) {
      if (!state) return;
      if (state.server_time) this.serverOffset = state.server_time - Date.now() / 1000;
      if (state.crops) this.setCatalog(state.crops);
      this.nextPlotCost = state.next_plot_cost || 0;
      (state.plots || []).forEach((sp) => {
        const p = this.plots[sp.plot_index - 1];
        if (!p) return;
        const status = String(sp.status || 'locked').toUpperCase();
        p.state = status === 'GROWING' || status === 'READY' ? 'GROWING' : status; // READY diturunkan dari waktu
        p.cropData = sp.crop_id ? this.crops.get(sp.crop_id) || null : null;
        p.plantedAt = sp.planted_at || 0;
        p.maturesAt = sp.matures_at || 0;
        p.pestAt = sp.pest_at || 0;
      });
      this.recalibrate();
    }

    /** Hitung ulang status dari jam server (dipanggil tiap tick & saat tab kembali aktif). */
    recalibrate() {
      const now = this.serverNow();
      let ready = 0;
      this.plots.forEach((p) => {
        if (p.state === 'GROWING' || p.state === 'READY' || p.state === 'WITHERED') {
          const isReady = now >= p.maturesAt;
          p.pest = p.pestAt > 0 && now >= p.pestAt && p.pestAt <= p.maturesAt;
          p.state = isReady ? 'READY' : (p.pest ? 'WITHERED' : 'GROWING');
          if (isReady) ready++;
        } else p.pest = false;
      });
      if (ready !== this._readyCount) { this._readyCount = ready; this.onReadyChange(ready); }
    }

    /** Tampilan petak saat ini (memperhitungkan override animasi). */
    _view(p, fx) {
      if (p.state === 'LOCKED' || p.state === 'EMPTY') return { k: p.state };
      let planted = p.plantedAt, matures = p.maturesAt, now = this.serverNow();
      if (fx.override) { matures = fx.override.maturesAt; now = fx.override.now; }
      const total = Math.max(1, matures - planted), pct = clamp((now - planted) / total, 0, 1);
      const ready = now >= matures;
      const pest = p.pestAt > 0 && now >= p.pestAt && p.pestAt <= matures;
      const stage = ready ? 4 : pct <= 0.30 ? 1 : pct <= 0.75 ? 2 : 3;
      const within = stage === 1 ? pct / 0.3 : stage === 2 ? (pct - 0.3) / 0.45 : stage === 3 ? (pct - 0.75) / 0.25 : 1;
      return { k: ready ? 'READY' : 'GROWING', pct, stage, within, pest, remain: Math.max(0, matures - now) };
    }

    setFrozen(f) { this.frozen = !!f; this.controls.enabled = !f; if (f) this.hover = -1; }

    /* ── Loop ──────────────────────────────────────────────── */
    _loop() {
      requestAnimationFrame(this._loop);
      const dt = Math.min(this.clock.getDelta(), 0.05);
      this.time += dt;
      const t = this.time;

      this._adaptQuality(dt);
      this.controls.update();
      this.waterMat.uniforms.uTime.value = t;
      this.sails.rotation.z += SAIL_SPEED * dt * 60;
      this.windMat.userData.uTime.value = t;
      this.birdMat.userData.uTime.value = t;

      // Pohon kelapa & pisang bergoyang
      this.palmTrees.forEach((tr, i) => this._setInst(this.palmMesh, i, tr.top.x, tr.top.y, tr.top.z, Math.sin(t * 1.1 + i) * 0.05, i * 0.7 + Math.sin(t * 0.6 + i) * 0.06, Math.cos(t * 1.3 + i) * 0.05, 1));
      this.bananaTrees.forEach((tr, i) => this._setInst(this.bananaMesh, i, tr.x, 1.2, tr.z, Math.sin(t * 1.5 + i * 2) * 0.04, i * 1.3, Math.sin(t * 1.2 + i) * 0.05, 0.85));
      this.palmMesh.instanceMatrix.needsUpdate = this.bananaMesh.instanceMatrix.needsUpdate = true;
      this._updateLife(t);

      // Awan
      this.clouds.forEach((c, i) => {
        c.x += c.v * dt; if (c.x > 45) c.x = -45;
        this._setInst(this.cloudMesh, i, c.x, c.y + Math.sin(t * 0.5 + i) * 0.2, c.z, 0, i, 0, c.s);
      });
      this.cloudShadows.forEach((c, i) => {
        c.x += c.v * dt; if (c.x > 24) c.x = -24;
        this._setInst(this.shadowCloudMesh, i, c.x, 14, c.z, 0, i, 0, c.s);
      });
      this.cloudMesh.instanceMatrix.needsUpdate = this.shadowCloudMesh.instanceMatrix.needsUpdate = true;

      // Petak
      this._tick = (this._tick || 0) + dt;
      if (this._tick > 0.25) { this._tick = 0; this.recalibrate(); }
      this._updatePlots(t, dt);

      this.particles.update(dt);
      this.renderer.render(this.scene, this.camera);
      this._updateLabels();
    }

    /** Animasi suasana desa: layang-layang, kuntul terbang, bebek, asap kawah, bendera, matahari. */
    _updateLife(t) {
      this.kites.forEach((k, i) => this._setInst(this.kiteMesh, i, k.p.x + Math.sin(t * 0.7 + i * 2) * 0.8, k.p.y + Math.sin(t * 1.1 + i) * 0.6, k.p.z, Math.sin(t * 1.3 + i) * 0.08, Math.PI / 4, Math.sin(t * 0.9 + i * 3) * 0.25, 1.15));
      this.kiteMesh.instanceMatrix.needsUpdate = true;

      // Kawanan kuntul formasi V melintas di depan gunung tiap 46 detik
      const u = (t % 46) / 46, head = SIDE, perp = this._v.set(head.z, 0, -head.x);
      const lead = BACK.clone().multiplyScalar(26).addScaledVector(SIDE, lerp(-48, 48, u)).setY(13 + Math.sin(u * Math.PI * 2) * 1.5);
      const ry = Math.atan2(head.x, head.z);
      for (let i = 0; i < 7; i++) {
        const row = Math.ceil(i / 2), sd = i === 0 ? 0 : (i % 2 ? 1 : -1);
        this._setInst(this.flock, i, lead.x - head.x * row * 1.3 + perp.x * sd * row * 1.0, lead.y - row * 0.15 + Math.sin(t * 2 + i) * 0.08,
          lead.z - head.z * row * 1.3 + perp.z * sd * row * 1.0, 0, ry, 0, 0.9);
      }
      this.flock.instanceMatrix.needsUpdate = true;

      for (let i = 0; i < 5; i++) {
        const a = t * (0.22 + i * 0.02) + i * 1.25, r = 1.1 + (i % 3) * 0.75;
        this._setInst(this.duckMesh, i, POND.x + Math.cos(a) * r, STREAM.y + Math.sin(t * 2.2 + i) * 0.02, POND.z + Math.sin(a) * r, 0, -(a + Math.PI / 2), Math.sin(t * 2 + i) * 0.05, 0.9);
      }
      this.duckMesh.instanceMatrix.needsUpdate = true;

      for (let i = 0; i < 7; i++) {
        const p = (t * 0.045 + i / 7) % 1, s = (1.2 + p * 4.2) * (p > 0.85 ? 1 - (p - 0.85) / 0.15 : Math.min(1, p * 8));
        this._setInst(this.puffMesh, i, CRATER.x + p * 7 * SIDE.x, CRATER.y + p * 15, CRATER.z + p * 7 * SIDE.z, 0, i + p, 0, s);
      }
      this.puffMesh.instanceMatrix.needsUpdate = true;

      const P = this.flagGeo.attributes.position, B = this.flagBase;
      for (let i = 0; i < P.count; i++) {
        const x = B[i * 3], k = x / 1.5;
        P.array[i * 3 + 2] = Math.sin(x * 3.4 - t * 6) * 0.13 * k;
        P.array[i * 3 + 1] = B[i * 3 + 1] - k * k * 0.08 + Math.sin(x * 2 - t * 4) * 0.03 * k;
      }
      P.needsUpdate = true;

      this.sunDisc.quaternion.copy(this.camera.quaternion);
    }

    /** Turunkan pixel ratio bila FPS rata-rata < 45 (HP lemah). */
    _adaptQuality(dt) {
      const f = this._fps;
      f.acc += dt; f.n++;
      if (f.acc < 2) return;
      const fps = f.n / f.acc;
      f.acc = 0; f.n = 0;
      if (fps < 45 && this.dpr > 1 && !document.hidden) {
        this.dpr = Math.max(1, this.dpr - 0.25);
        this.renderer.setPixelRatio(this.dpr);
        this._resize();
      }
    }

    _setInst(mesh, i, x, y, z, rx, ry, rz, s) {
      if (!s) { mesh.setMatrixAt(i, this._zero); return; }
      this._q.setFromEuler(this._e.set(rx, ry, rz));
      this._m.compose(this._p.set(x, y, z), this._q, this._s.set(s, s, s));
      mesh.setMatrixAt(i, this._m);
    }

    /** Tambah satu instance aktif ke mesh per-petak (dipadatkan; tanpa slot tersembunyi). */
    _put(mesh, x, y, z, rx, ry, rz, s, color) {
      if (!s) return;
      const i = mesh._n++;
      this._q.setFromEuler(this._e.set(rx, ry, rz));
      this._m.compose(this._p.set(x, y, z), this._q, this._s.set(s, s, s));
      mesh.setMatrixAt(i, this._m);
      if (color) mesh.setColorAt(i, color);
    }

    _updatePlots(t, dt) {
      const m = this.m, col = this._c;
      this.plotMeshes.forEach((mesh) => { mesh._n = 0; });
      for (const p of this.plots) {
        const i = p.plotIndex - 1, fx = this.fx[i], c = p.center, v = this._view(p, fx);
        fx.view = v;
        const locked = v.k === 'LOCKED', empty = v.k === 'EMPTY', growing = v.k === 'GROWING', ready = v.k === 'READY';

        // Tanah: warna sesuai status (terkunci = tanah kering, hama = keabuan)
        col.set(locked ? C.soilLocked : v.pest ? C.soilPest : C.soil);
        if (!locked) col.multiplyScalar(1.6);
        this._put(m.soil, c.x, 0, c.z, 0, 0, 0, 1, col);
        if (!locked) this._put(m.furrows, c.x, 0, c.z, 0, 0, 0, fx.till, col);
        else this._put(m.weeds, c.x, 0, c.z, 0, 0, 0, 1);

        // Gembok melayang
        const lg = fx.lockGhost;
        if (locked || lg) {
          this._put(m.padlock, c.x, 1.2 + Math.sin(t * 2 + i) * 0.12 + (lg ? lg.y : 0), c.z, 0, t * 0.8 + i, Math.sin(t * 1.5 + i) * 0.08, lg ? lg.s : 1);
        }

        // Lingkaran "siap tanam" berdenyut (lebih terang saat di-hover)
        if (empty) {
          const hov = this.hover === p.plotIndex;
          col.set(hov ? '#fff7ae' : '#fde047');
          if (!hov) col.multiplyScalar(0.85 + Math.sin(t * 4 + i) * 0.15);
          this._put(m.decal, c.x, SOIL_TOP + 0.17, c.z, 0, t * 0.4, 0, (hov ? 1.1 : 1) + Math.sin(t * 4 + i) * 0.05, col);
        }

        // Tanaman: kecambah (0–30%) lalu model tanaman (31–75% skala 0.75, 76%+ skala 1.0)
        const sway = Math.sin(t * 3 + p.plotIndex) * 0.05, swayX = Math.cos(t * 2.3 + p.plotIndex) * 0.03;
        const droop = v.pest ? 0.16 : 0;
        if ((growing || ready) && v.stage === 1) {
          this._put(m.sprout, c.x, 0, c.z, swayX, 0, sway + droop, 0.4 * (0.9 + 0.12 * v.within) * fx.pop);
        }
        const crop = p.cropData, gh = fx.ghost;
        if (gh) {
          this._put(this.cropMesh[gh.model], c.x, gh.lift, c.z, 0, gh.spin, 0, gh.s, this._readyColor(gh.color, t, i));
        } else if (crop && (growing || ready) && v.stage >= 2) {
          const base = v.stage === 2 ? 0.75 : 1.0;
          const s = base * (v.stage === 4 ? 1 : 0.92 + 0.08 * v.within) * fx.pop;
          if (v.stage === 2) col.set('#4ade80');
          else if (v.stage === 3) col.set('#4ade80').lerp(this._c2 || (this._c2 = new THREE.Color()).set(crop.color), v.within);
          else col.copy(this._readyColor(crop.color, t, i));
          if (v.pest) col.lerp(this._c3 || (this._c3 = new THREE.Color('#78716c')), 0.5);
          this._put(this.cropMesh[crop.model], c.x, 0, c.z, swayX, 0, sway + droop, s, col);
        }

        // Hama (ulat merayap mengitari tanaman)
        const bg = fx.bugGhost;
        if ((v.pest && (growing || ready)) || bg) {
          const a = t * 0.9 + i, r = 0.55, h = SOIL_TOP + (v.stage >= 2 ? 0.55 : 0.05);
          this._put(m.bug, c.x + Math.cos(a) * r, h + (bg ? bg.y : Math.abs(Math.sin(t * 8)) * 0.03), c.z + Math.sin(a) * r, 0, -a - Math.PI / 2, 0, bg ? bg.s : 1.15);
        }

        // Sabit emas melayang (READY) / ayunan 360° saat panen
        const sw = fx.sw;
        if (sw) {
          this._put(m.sickle, c.x + Math.cos(sw.a) * 1.05, 1.0 + sw.y, c.z + Math.sin(sw.a) * 1.05, Math.PI / 2, -sw.a, 0, sw.s);
        } else if (ready && !this.busy.has(p.plotIndex)) {
          this._put(m.sickle, c.x, 2.0 + Math.sin(t * 3 + i) * 0.15, c.z, 0, t * 1.5, Math.sin(t * 2) * 0.15, 1);
        }

        // Partikel emas naik dari tanaman siap panen
        if (ready && !gh && Math.random() < dt * 7) {
          this.particles.emit({ p: this._p.set(c.x + (Math.random() - 0.5) * 2.2, SOIL_TOP + 0.6 + Math.random() * 0.6, c.z + (Math.random() - 0.5) * 2.2),
            v: [0, 0.7 + Math.random() * 0.6, 0], c: Math.random() < 0.7 ? '#fde047' : '#ffffff', s: 0.22, s1: 0, life: 1.6, g: 0.15, shape: 1 });
        }

        // Pop kecil saat naik tahap
        const stage = v.stage || 0;
        if (fx.lastStage !== null && stage > fx.lastStage && stage >= 2 && !fx.override && !gh) {
          global.gsap.fromTo(fx, { pop: 0.7 }, { pop: 1, duration: 0.6, ease: 'elastic.out(1.1, 0.45)' });
          this._burst(c, stage === 4 ? 14 : 6, ['#fde047', '#ffffff'], 1.0, 1);
          if (stage === 4 && document.visibilityState === 'visible') this.audio.soundCoin(0.12);
        }
        fx.lastStage = stage;
      }
      this.plotMeshes.forEach((mesh) => {
        mesh.count = mesh._n;
        mesh.instanceMatrix.needsUpdate = true;
        if (mesh.instanceColor) mesh.instanceColor.needsUpdate = true;
      });
    }

    _readyColor(hex, t, i) {
      const c = this._rc || (this._rc = new THREE.Color());
      c.set(hex).lerp(this._gold || (this._gold = new THREE.Color('#fde047')), 0.3).multiplyScalar(1.25 + Math.sin(t * 4 + i) * 0.1);
      return c;
    }

    /* ── Label HTML (timer radial, gembok, hama) ─────────────── */
    toScreen(v3) {
      const v = this._v.copy(v3).add(this.world.position).project(this.camera);
      return { x: (v.x + 1) / 2 * this.width, y: (1 - v.y) / 2 * this.height, visible: v.z < 1 };
    }

    _updateLabels() {
      if (!this.labelLayer) return;
      const nextLocked = this.plots.find((p) => p.state === 'LOCKED'); // harga hanya di petak terkunci berikutnya
      for (const p of this.plots) {
        const fx = this.fx[p.plotIndex - 1], v = fx.view, el = fx.label;
        if (!el || !v) continue;
        let kind = '', text = '', icon = '', prog = 0, y = 2.35;
        if (v.k === 'LOCKED' && !fx.lockGhost && p === nextLocked) { kind = 'locked'; icon = 'ph-lock-key'; text = fmtShort(this.nextPlotCost); y = 1.75; }
        else if (v.k === 'GROWING') {
          kind = v.pest ? 'pest' : 'grow'; icon = v.pest ? 'ph-bug' : 'ph-plant';
          text = v.pest ? 'HAMA!' : fmtTime(v.remain); prog = v.pct;
          if (fx.override) kind += ' spin';
        } else if (v.k === 'READY' && v.pest && !fx.ghost) { kind = 'pest'; icon = 'ph-bug'; text = 'HAMA!'; prog = 1; }
        if (!kind) { if (el.style.display !== 'none') el.style.display = 'none'; continue; }
        const key = kind + '|' + icon + '|' + text;
        if (key !== fx.labelKey) {
          fx.labelKey = key;
          el.className = 'plot-tag is-' + kind.split(' ').join(' is-');
          fx.labelIcon.className = 'ph-fill ' + icon;
          fx.labelText.textContent = text;
        }
        el.style.setProperty('--p', prog.toFixed(3));
        p.timerMesh.position.y = y;
        const s = this.toScreen(p.timerMesh.position);
        el.style.display = s.visible ? '' : 'none';
        el.style.transform = `translate3d(${s.x.toFixed(1)}px, ${s.y.toFixed(1)}px, 0) translate(-50%, -50%)`;
      }
    }

    /* ══════════════════════════════════════════════════════════
       SEKUENS ANIMASI
       ══════════════════════════════════════════════════════════ */
    _burst(center, n, colors, speed = 3, shape = 1, y = 1.0) {
      for (let k = 0; k < n; k++) {
        const a = Math.random() * Math.PI * 2, sp = speed * (0.5 + Math.random() * 0.8);
        this.particles.emit({ p: this._p.set(center.x, y, center.z), v: [Math.cos(a) * sp, 1 + Math.random() * speed, Math.sin(a) * sp],
          c: colors[k % colors.length], s: 0.26, s1: 0.04, life: 0.9 + Math.random() * 0.4, g: -4, drag: 1.5, shape });
      }
    }

    /** Tanam: 3 benih emas jatuh (gravitasi) → kepulan tanah → kecambah pop (0 → 1.3 → 1). */
    playPlant(idx) {
      const p = this.plots[idx - 1], fx = this.fx[idx - 1], c = p.center;
      fx.pop = 0; fx.lastStage = 1;
      const fallT = 0.62;
      for (let k = 0; k < 3; k++) {
        const ox = (k - 1) * 0.35, oz = (Math.random() - 0.5) * 0.4;
        this.particles.emit({ p: this._p.set(c.x + ox, 4.3, c.z + oz), v: [0, 0, 0], c: '#facc15', s: 0.24, s1: 0.22, life: fallT, g: -20, shape: 0 });
      }
      global.gsap.delayedCall(fallT, () => {
        for (let k = 0; k < 12; k++) {
          const a = (k / 12) * Math.PI * 2;
          this.particles.emit({ p: this._p.set(c.x, SOIL_TOP + 0.15, c.z), v: [Math.cos(a) * 2.2, 0.6 + Math.random() * 0.5, Math.sin(a) * 2.2],
            c: k % 2 ? '#92400e' : '#a16207', s: 0.35, s1: 1.0, life: 0.6, drag: 4, shape: 2, a: 0.9 });
        }
        this.audio.soundPlant();
        this.audio.vibrate(40);
        global.gsap.timeline()
          .to(fx, { pop: 1.3, duration: 0.18, ease: 'back.out(3)' })
          .to(fx, { pop: 1.0, duration: 0.12, ease: 'elastic.out(1, 0.4)' });
      });
    }

    /** Pupuk Kilat: penyiram miring 45°, tetesan biru/emas, timer berputar cepat → READY + pusaran. */
    playSpeedUp(idx, prev) {
      const p = this.plots[idx - 1], fx = this.fx[idx - 1], c = p.center;
      const ov = fx.override = { maturesAt: prev.maturesAt, now: this.serverNow() };
      const can = this.can;
      can.visible = true;
      can.position.set(c.x - 0.7, 2.6, c.z);
      can.rotation.set(0, 0, 0);
      can.scale.setScalar(0.01);
      this.audio.soundWater();
      const tip = new THREE.Vector3();
      let pouring = false;
      const pour = () => {
        if (!pouring) return;
        tip.copy(this.canTip); can.localToWorld(tip); tip.sub(this.world.position);
        for (let k = 0; k < 3; k++) {
          this.particles.emit({ p: tip, v: [0.6 + Math.random() * 0.8, -1 - Math.random(), (Math.random() - 0.5) * 0.9],
            c: Math.random() < 0.65 ? '#38bdf8' : '#fde047', s: 0.15, s1: 0.08, life: 0.55, g: -14, shape: Math.random() < 0.3 ? 1 : 0 });
        }
      };
      global.gsap.ticker.add(pour);
      global.gsap.timeline({ onComplete: () => { global.gsap.ticker.remove(pour); can.visible = false; } })
        .to(can.scale, { x: 1, y: 1, z: 1, duration: 0.25, ease: 'back.out(2)' })
        .to(can.rotation, { z: -Math.PI / 4, duration: 0.3, ease: 'power2.out', onComplete: () => { pouring = true; } })
        .to(ov, { now: prev.maturesAt, duration: 0.9, ease: 'power2.in', onUpdate: () => { if (Math.random() < 0.3) this.audio.soundTick(); } }, '+=0.1')
        .add(() => {
          pouring = false;
          fx.override = null;
          // Pusaran perayaan
          for (let k = 0; k < 28; k++) {
            const a = (k / 28) * Math.PI * 4, r = 0.4 + k * 0.03;
            this.particles.emit({ p: this._p.set(c.x + Math.cos(a) * r, SOIL_TOP + 0.3 + k * 0.05, c.z + Math.sin(a) * r),
              v: [-Math.sin(a) * 2.2, 1.6, Math.cos(a) * 2.2], c: k % 3 ? '#fde047' : '#ffffff', s: 0.28, s1: 0, life: 1.1, drag: 1.2, shape: 1 });
          }
          this.audio.soundCoin(0.3);
          this.audio.vibrate(30);
        })
        .to(can.rotation, { z: 0, duration: 0.2 })
        .to(can.scale, { x: 0.01, y: 0.01, z: 0.01, duration: 0.2, ease: 'back.in(2)' });
    }

    /** Pestisida: kabut hijau, ulat melompat lalu hilang. */
    playCure(idx) {
      const c = this.plots[idx - 1].center, fx = this.fx[idx - 1];
      const bg = fx.bugGhost = { y: 0, s: 1.15 };
      this.audio.soundWater();
      for (let k = 0; k < 30; k++) {
        this.particles.emit({ p: this._p.set(c.x + (Math.random() - 0.5) * 2.4, 2.2, c.z + (Math.random() - 0.5) * 2.4),
          v: [(Math.random() - 0.5) * 0.6, -1.2, (Math.random() - 0.5) * 0.6], c: k % 2 ? '#86efac' : '#ffffff', s: 0.35, s1: 0.7, life: 1.0, drag: 1, shape: 0, a: 0.6 });
      }
      global.gsap.timeline({ delay: 0.35, onComplete: () => { fx.bugGhost = null; } })
        .to(bg, { y: 0.9, duration: 0.3, ease: 'power2.out' })
        .to(bg, { s: 0, duration: 0.2, ease: 'back.in(2)' })
        .add(() => { this._burst(c, 10, ['#fde047', '#ffffff'], 1.5, 1, 1.4); this.audio.soundCoin(0.2); });
    }

    /** Buka petak: gembok membesar & pecah jadi kilau, tanah tergarap muncul. */
    playUnlock(idx) {
      const c = this.plots[idx - 1].center, fx = this.fx[idx - 1];
      const lg = fx.lockGhost = { s: 1, y: 0 };
      fx.till = 0;
      this.audio.soundUnlock();
      global.gsap.timeline({ onComplete: () => { fx.lockGhost = null; } })
        .to(lg, { s: 1.5, y: 0.3, duration: 0.18, ease: 'back.out(3)' })
        .to(lg, { s: 0, duration: 0.2, ease: 'power2.in' })
        .add(() => this._burst(c, 22, ['#fde047', '#e5e7eb', '#ffffff'], 3, 1, 1.4))
        .to(fx, { till: 1, duration: 0.6, ease: 'elastic.out(1, 0.45)' });
    }

    /**
     * Panen: sabit ayun 360° + swoosh (langsung saat tap), tunggu server,
     * lalu tanaman melompat & berputar, confetti, teks emas, ikon terbang ke Lumbung.
     * @param {Promise} req promise hasil FarmAPI.call('harvest')
     * @param {function} onDone(res) dipanggil setelah respon (sukses/gagal)
     */
    async playHarvest(idx, req, onDone) {
      const p = this.plots[idx - 1], fx = this.fx[idx - 1], c = p.center;
      const crop = p.cropData;
      this.busy.add(idx);
      const sw = fx.sw = { a: -Math.PI / 2, y: 1.0, s: 1 };
      this.slash.visible = true;
      this.slash.position.set(c.x, 1.0, c.z);
      this.audio.soundSwoosh();
      const swing = global.gsap.timeline()
        .to(sw, { y: 0.1, duration: 0.12, ease: 'power2.in' })
        .to(sw, { a: Math.PI * 1.5, duration: 0.42, ease: 'power2.inOut', onUpdate: () => {
          this.slash.rotation.y = -sw.a;
          this.slash.material.opacity = 0.85 * Math.sin(clamp((sw.a + Math.PI / 2) / (Math.PI * 2), 0, 1) * Math.PI);
        } });
      const [res] = await Promise.all([req, new Promise((r) => setTimeout(r, 560))]);
      swing.kill();
      this.slash.visible = false;
      fx.sw = null;
      this.busy.delete(idx);

      if (!res || !res.ok) { this.audio.soundError(); onDone && onDone(res); return; }

      // Tanaman "hantu" tetap tampil & melompat sementara tanah langsung kembali EMPTY
      const gh = fx.ghost = { model: crop ? crop.model : 'rice', color: crop ? crop.color : '#facc15', lift: 0, spin: 0, s: 1 };
      onDone && onDone(res);
      this.audio.soundHarvest();
      this.audio.vibrate([30, 50, 60]);
      for (let k = 0; k < 44; k++) {
        const a = Math.random() * Math.PI * 2, el = Math.random() * 0.9 + 0.3, sp = 4 + Math.random() * 3;
        this.particles.emit({ p: this._p.set(c.x, 1.3, c.z), v: [Math.cos(a) * Math.cos(el) * sp, Math.sin(el) * sp + 1.5, Math.sin(a) * Math.cos(el) * sp],
          c: ['#f43f5e', '#fde047', '#38bdf8', '#10b981', '#a78bfa'][k % 5], s: 0.26, s1: 0.18, life: 1.3, g: -9, drag: 1.3, shape: k % 3 === 0 ? 1 : 3 });
      }
      global.gsap.timeline({ onComplete: () => { fx.ghost = null; } })
        .to(gh, { lift: 2.5, spin: Math.PI * 3, duration: 0.6, ease: 'back.out(1.6)' })
        .to(gh, { s: 0, duration: 0.25, ease: 'back.in(2)' });

      const name = res.crop_name || (crop && crop.name) || 'Panen';
      this.floatText(V(c.x, 2.8, c.z), '+' + res.yield + ' ' + name + (res.pest ? ' (hama)' : ''));
      global.gsap.delayedCall(0.45, () => {
        const from = this.toScreen(V(c.x, 2.4, c.z));
        this.flyIcons(from, this.barnEl, iconHtml(this.icon('crop:' + (res.crop_id || (crop && crop.id))), cropIcon(gh.model)), Math.min(res.yield || 1, 6), (k, last) => {
          this.audio.tone(880 + k * 90, 0.08, 'triangle', 0.15);
          if (last) this.audio.soundCoin(0.2);
        });
      });
    }

    /** Teks emas melayang naik 1.5 unit lalu memudar. */
    floatText(pos, text, cls = '') {
      if (!this.labelLayer) return;
      const el = document.createElement('div');
      el.className = 'float-text ' + cls;
      el.textContent = text;
      this.labelLayer.appendChild(el);
      const st = { y: 0, a: 1, s: 0.6 }, base = pos.clone();
      const draw = () => {
        const s = this.toScreen(this._p.copy(base).setY(base.y + st.y));
        el.style.opacity = st.a;
        el.style.transform = `translate3d(${s.x}px, ${s.y}px, 0) translate(-50%, -50%) scale(${st.s})`;
      };
      draw();
      global.gsap.timeline({ onUpdate: draw, onComplete: () => el.remove() })
        .to(st, { s: 1, duration: 0.3, ease: 'back.out(3)' }, 0)
        .to(st, { y: 1.5, duration: 1.4, ease: 'power1.out' }, 0)
        .to(st, { a: 0, duration: 0.6 }, 0.8);
    }

    /**
     * Ikon terbang sepanjang kurva Bezier kuadratik dari titik layar ke elemen HUD.
     * onLand(k, isLast) dipanggil saat tiap ikon tiba; elemen target berjoget.
     */
    flyIcons(from, targetEl, html, count, onLand) {
      if (!targetEl || !this.labelLayer) return;
      const lr = this.labelLayer.getBoundingClientRect(), tr = targetEl.getBoundingClientRect();
      const to = { x: tr.left + tr.width / 2 - lr.left, y: tr.top + tr.height / 2 - lr.top };
      for (let k = 0; k < count; k++) {
        const el = document.createElement('div');
        el.className = 'fly-icon';
        el.innerHTML = html;
        this.labelLayer.appendChild(el);
        const sx = from.x + (Math.random() - 0.5) * 50, sy = from.y + (Math.random() - 0.5) * 30;
        const cx = (sx + to.x) / 2 - 60 + Math.random() * 40, cy = Math.min(sy, to.y) - 140 - Math.random() * 60;
        const st = { t: 0 };
        const draw = () => {
          const t = st.t, u = 1 - t;
          const x = u * u * sx + 2 * u * t * cx + t * t * to.x, y = u * u * sy + 2 * u * t * cy + t * t * to.y;
          el.style.transform = `translate3d(${x}px, ${y}px, 0) translate(-50%, -50%) scale(${1.15 - t * 0.45}) rotate(${t * 360}deg)`;
        };
        draw();
        global.gsap.to(st, {
          t: 1, duration: 0.8, delay: k * 0.09, ease: 'power1.in', onUpdate: draw,
          onComplete: () => {
            el.remove();
            global.gsap.fromTo(targetEl, { scale: 1 }, { scale: 1.25, duration: 0.1, yoyo: true, repeat: 1, ease: 'power2.out', overwrite: true });
            onLand && onLand(k, k === count - 1);
          },
        });
      }
    }

    /** Hujan confetti besar (naik level / jual). */
    celebrate() {
      for (let k = 0; k < 70; k++) {
        this.particles.emit({ p: this._p.set((Math.random() - 0.5) * 16, 8 + Math.random() * 3, (Math.random() - 0.5) * 12 - 1),
          v: [(Math.random() - 0.5) * 2, -1 - Math.random(), (Math.random() - 0.5) * 2], c: ['#f43f5e', '#fde047', '#38bdf8', '#10b981', '#a78bfa'][k % 5],
          s: 0.3, s1: 0.25, life: 2.6, g: -2.5, drag: 0.6, shape: k % 4 === 0 ? 1 : 3 });
      }
    }
  }

  /* ── Util tampilan ── */
  function fmtTime(sec) {
    sec = Math.ceil(sec);
    const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60, pad = (n) => String(n).padStart(2, '0');
    return h > 0 ? h + ':' + pad(m) + ':' + pad(s) : pad(m) + ':' + pad(s);
  }
  function fmtShort(n) {
    if (!n) return 'Gratis';
    if (n >= 1e6) return 'Rp ' + (n / 1e6).toFixed(n % 1e6 ? 1 : 0).replace('.', ',') + 'jt';
    if (n >= 1e3) return 'Rp ' + Math.round(n / 1e3) + 'rb';
    return 'Rp ' + Math.round(n);
  }
  /** <img> ikon 3D, atau ikon Phosphor sebagai cadangan bila WebGL ikon gagal. */
  function iconHtml(url, fallback) {
    return url ? '<img src="' + url + '" alt="" draggable="false">' : '<i class="ph-fill ' + fallback + '"></i>';
  }
  function cropIcon(model) {
    return { rice: 'ph-grains', chili: 'ph-pepper', corn: 'ph-plant', watermelon: 'ph-orange-slice' }[model] || 'ph-plant';
  }

  global.FarmEngine = FarmEngine;
  global.FarmAudio = FarmAudio;
  global.FarmAPI = FarmAPI;
  global.FarmUtil = { fmtRp, fmtShort, fmtTime, cropIcon, iconHtml };
  // Builder geometri & material untuk alat pembuat ikon 3D UI (assets/img/icons3d/)
  global.FarmGeo = { Geo, V, toonGradient, tintMaterial, colors: C };
})(window);
