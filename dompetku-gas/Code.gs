/**
 * DompetKu — Pencatat Keuangan Pribadi
 * Google Apps Script Web App, database di Google Sheets (dibuat otomatis).
 *
 * File: Code.gs (server) + Index.html (tampilan).
 */

const APP_NAME = 'DompetKu';
const SCHEMA_VERSION = '8';

const SCHEMA = {
  Wallets:      ['id', 'name', 'type', 'color', 'initial', 'archived', 'order', 'createdAt', 'limit', 'sub', 'logo', 'rateBp', 'rateTax', 'rateFrom', 'taxBp', 'taxMin'],
  Categories:   ['id', 'name', 'type', 'color', 'icon', 'order', 'group'],
  Transactions: ['id', 'date', 'type', 'walletId', 'toWalletId', 'categoryId', 'amount', 'fee', 'note', 'createdAt', 'updatedAt', 'refId', 'with', 'photo'],
  Budgets:      ['id', 'categoryId', 'amount'],
  Debts:        ['id', 'kind', 'name', 'amount', 'due', 'note', 'createdAt'],
  Recurring:    ['id', 'name', 'type', 'walletId', 'toWalletId', 'categoryId', 'amount', 'freq', 'start', 'next', 'auto', 'active', 'note', 'logo'],
  Goals:        ['id', 'name', 'target', 'walletId', 'due', 'color', 'icon', 'createdAt'],
  Logos:        ['id', 'name', 'data'],
  Photos:       ['id', 'part', 'data'],
  Settings:     ['key', 'value']
};
const NUMERIC = ['initial', 'order', 'amount', 'fee', 'limit', 'target', 'rateBp', 'taxBp', 'taxMin'];
const BOOLS = ['archived', 'auto', 'active', 'rateTax'];
const DATES = ['date', 'due', 'start', 'next', 'rateFrom'];
const FREQS = ['daily', 'weekly', 'monthly', 'yearly'];
const USER_SETTINGS = ['userName'];
const TX_TYPES = ['income', 'expense', 'transfer'];
const WALLET_TYPES = ['cash', 'bank', 'ewallet', 'saving', 'credit', 'paylater', 'invest', 'other'];
const GROUPS = ['need', 'want', 'oblig', 'social', 'other'];

// [tipe, nama, ikon, warna, grup]
const DEFAULT_CATEGORIES = [
  ['expense', 'Makan & Minum', 'food', '#F97316', 'need'],
  ['expense', 'Transportasi', 'car', '#0EA5E9', 'need'],
  ['expense', 'Tagihan & Utilitas', 'zap', '#F59E0B', 'need'],
  ['expense', 'Pulsa & Internet', 'wifi', '#8B5CF6', 'need'],
  ['expense', 'Kesehatan', 'heart', '#14B8A6', 'need'],
  ['expense', 'Pendidikan', 'book', '#4F46E5', 'need'],
  ['expense', 'Rumah Tangga', 'home', '#84CC16', 'need'],
  ['expense', 'Belanja', 'bag', '#EC4899', 'want'],
  ['expense', 'Hiburan', 'film', '#E5484D', 'want'],
  ['expense', 'Cicilan & Kredit', 'card', '#DC2626', 'oblig'],
  ['expense', 'Asuransi & Pajak', 'shield', '#0F766E', 'oblig'],
  ['expense', 'Keluarga', 'users', '#64748B', 'social'],
  ['expense', 'Sedekah & Hadiah', 'gift', '#16A34A', 'social'],
  ['expense', 'Lainnya', 'dots', '#94A3B8', 'other'],
  ['income', 'Gaji', 'briefcase', '#16A34A', 'other'],
  ['income', 'Bonus', 'award', '#F59E0B', 'other'],
  ['income', 'Usaha', 'store', '#0EA5E9', 'other'],
  ['income', 'Hasil Investasi', 'trend', '#8B5CF6', 'other'],
  ['income', 'Hadiah', 'gift', '#EC4899', 'other'],
  ['income', 'Lainnya', 'coins', '#64748B', 'other'],
  ['expense', 'Fashion', 'shirt', '#06B6D4', 'want'],
  ['expense', 'Perawatan Diri', 'scissors', '#D946EF', 'want'],
  ['expense', 'Kopi & Jajan', 'coffee', '#B45309', 'want'],
  ['expense', 'Olahraga & Hobi', 'ball', '#22C55E', 'want'],
  ['expense', 'Liburan & Travel', 'plane', '#0EA5E9', 'want'],
  ['expense', 'Gadget & Elektronik', 'phone', '#6366F1', 'want'],
  ['expense', 'Hewan Peliharaan', 'pet', '#F59E0B', 'want'],
  ['expense', 'Bensin', 'fuel', '#EF4444', 'need'],
  ['expense', 'Biaya Admin & Bank', 'receipt', '#64748B', 'oblig'],
  ['income', 'Penjualan', 'tag', '#22C55E', 'other']
];
// Kategori yang ditambahkan di skema 6 (diberikan juga ke database lama saat migrasi).
const CATEGORIES_V6 = ['Fashion', 'Perawatan Diri', 'Kopi & Jajan', 'Olahraga & Hobi', 'Liburan & Travel', 'Gadget & Elektronik', 'Hewan Peliharaan', 'Bensin', 'Biaya Admin & Bank', 'Penjualan'];
const WITH = ['', 'pacar', 'teman', 'keluarga', 'kerja'];

/* ============================== Web App ============================== */

function doGet() {
  return HtmlService.createHtmlOutputFromFile('Index')
    .setTitle(APP_NAME)
    .addMetaTag('viewport', 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover')
    .addMetaTag('apple-mobile-web-app-capable', 'yes')
    .addMetaTag('mobile-web-app-capable', 'yes');
}

// API JSON untuk tampilan yang di-host di luar Apps Script (GitHub Pages).
// Klien mengirim POST text/plain {fn, args, token} agar tidak memicu preflight CORS.
function doPost(e) {
  let out;
  try {
    const req = JSON.parse(e.postData.contents || '{}');
    const args = Array.isArray(req.args) ? req.args : [];
    let data;
    if (req.fn === 'boot') data = boot();
    else if (req.fn === 'unlock') data = unlock(args[0]);
    else data = call(req.token, req.fn, args);
    out = { ok: true, data: data === undefined ? null : data };
  } catch (err) {
    out = { ok: false, error: err && err.message ? err.message : String(err) };
  }
  return ContentService.createTextOutput(JSON.stringify(out)).setMimeType(ContentService.MimeType.JSON);
}

/* ============================== Database ============================== */

let _ss = null;
const _sheets = {};

function db_() {
  if (_ss) return _ss;
  const props = PropertiesService.getScriptProperties();
  const id = props.getProperty('DB_ID');
  if (id) {
    try { _ss = SpreadsheetApp.openById(id); return _ss; } catch (e) { /* dibuat ulang di bawah */ }
  }
  try { _ss = SpreadsheetApp.getActiveSpreadsheet(); } catch (e) { _ss = null; }
  if (!_ss) {
    _ss = SpreadsheetApp.create(APP_NAME + ' — Database');
    _ss.getSheets()[0].setName('Wallets');
  }
  props.setProperty('DB_ID', _ss.getId());
  return _ss;
}

function formatCols_(sh, cols, from) {
  for (let i = from; i < cols.length; i++) {
    sh.getRange(1, i + 1, sh.getMaxRows(), 1).setNumberFormat(NUMERIC.indexOf(cols[i]) >= 0 ? '#,##0' : '@');
  }
}

function sheet_(name) {
  if (_sheets[name]) return _sheets[name];
  const ss = db_();
  const cols = SCHEMA[name];
  const sh = ss.getSheetByName(name) || ss.insertSheet(name);
  if (sh.getLastRow() === 0) {
    sh.getRange(1, 1, 1, cols.length).setValues([cols]).setFontWeight('bold').setBackground('#EAF1FF');
    sh.setFrozenRows(1);
    formatCols_(sh, cols, 0);
  }
  _sheets[name] = sh;
  return sh;
}

// Menambah kolom baru ke sheet lama saat skema berubah.
function migrate_() {
  const props = PropertiesService.getScriptProperties();
  if (props.getProperty('SCHEMA_V') === SCHEMA_VERSION) return;
  locked_(() => {
    Object.keys(SCHEMA).forEach(name => {
      const sh = sheet_(name), cols = SCHEMA[name];
      const have = sh.getLastColumn();
      if (have < cols.length) {
        sh.getRange(1, 1, 1, cols.length).setValues([cols]).setFontWeight('bold').setBackground('#EAF1FF');
        formatCols_(sh, cols, have);
      }
    });
    dirty_();
    // isi grup kategori lama berdasarkan nama bawaan
    const byName = {};
    DEFAULT_CATEGORIES.forEach(c => byName[c[0] + ':' + c[1]] = c[4]);
    rows_('Categories').filter(c => !c.group).forEach(c => {
      c.group = byName[c.type + ':' + c.name] || 'other';
      upsert_('Categories', c);
    });
    // tambahkan kategori baru skema 6 yang belum ada
    const cats = rows_('Categories');
    DEFAULT_CATEGORIES.filter(c => CATEGORIES_V6.indexOf(c[1]) >= 0).forEach((c, i) => {
      if (cats.some(x => x.type === c[0] && x.name.toLowerCase() === c[1].toLowerCase())) return;
      upsert_('Categories', { id: Utilities.getUuid(), name: c[1], type: c[0], color: c[3], icon: c[2], order: cats.length + i, group: c[4] });
    });
    // skema 8: pajak bunga jadi persentase
    rows_('Wallets').filter(w => w.rateTax && !w.taxBp).forEach(w => {
      w.taxBp = 2000; w.taxMin = 7500000;
      upsert_('Wallets', w);
    });
    props.setProperty('SCHEMA_V', SCHEMA_VERSION);
  });
}

// Baca sheet sekali per permintaan (memo), salinan dikembalikan agar aman diubah pemanggil.
const _rows = {};
const dirty_ = name => { if (name) delete _rows[name]; else Object.keys(_rows).forEach(k => delete _rows[k]); };
function rows_(name) {
  if (!_rows[name]) _rows[name] = readRows_(name);
  return _rows[name].map(o => Object.assign({}, o));
}

function readRows_(name) {
  const sh = sheet_(name);
  const cols = SCHEMA[name];
  const n = sh.getLastRow() - 1;
  if (n < 1) return [];
  const tz = Session.getScriptTimeZone();
  return sh.getRange(2, 1, n, cols.length).getValues()
    .filter(r => r[0] !== '' && r[0] !== null)
    .map(r => {
      const o = {};
      cols.forEach((c, i) => {
        let v = r[i];
        if (NUMERIC.indexOf(c) >= 0) v = Number(v) || 0;
        else if (BOOLS.indexOf(c) >= 0) v = v === true || String(v).toLowerCase() === 'true';
        else if (v instanceof Date) v = Utilities.formatDate(v, tz, DATES.indexOf(c) >= 0 ? 'yyyy-MM-dd' : "yyyy-MM-dd'T'HH:mm:ss");
        else v = String(v);
        o[c] = v;
      });
      return o;
    });
}

function findRow_(sh, key) {
  const n = sh.getLastRow() - 1;
  if (n < 1) return -1;
  const ids = sh.getRange(2, 1, n, 1).getValues();
  for (let i = 0; i < ids.length; i++) if (String(ids[i][0]) === String(key)) return i + 2;
  return -1;
}

function upsert_(name, obj) {
  dirty_(name);
  const sh = sheet_(name);
  const cols = SCHEMA[name];
  const row = cols.map(c => {
    const v = obj[c];
    if (v === undefined || v === null) return '';
    // cegah teks dibaca sebagai formula
    if (typeof v === 'string' && /^[=+\-@]/.test(v)) return "'" + v;
    return v;
  });
  const r = findRow_(sh, obj[cols[0]]);
  if (r > 0) sh.getRange(r, 1, 1, cols.length).setValues([row]);
  else sh.appendRow(row);
}

function remove_(name, key) {
  dirty_(name);
  const sh = sheet_(name);
  const r = findRow_(sh, key);
  if (r > 0) sh.deleteRow(r);
}

// Hapus semua baris yang cocok, dari bawah agar nomor baris tidak bergeser.
function removeWhere_(name, pred) {
  dirty_(name);
  const sh = sheet_(name), cols = SCHEMA[name];
  const n = sh.getLastRow() - 1;
  if (n < 1) return 0;
  const vals = sh.getRange(2, 1, n, cols.length).getValues();
  let count = 0;
  for (let i = vals.length - 1; i >= 0; i--) {
    const o = {};
    cols.forEach((c, j) => o[c] = String(vals[i][j]));
    if (pred(o)) { sh.deleteRow(i + 2); count++; }
  }
  return count;
}

function locked_(fn) {
  const lock = LockService.getScriptLock();
  lock.waitLock(15000);
  try { return fn(); } finally { lock.releaseLock(); }
}

function getSetting_(k) {
  const r = rows_('Settings').find(x => x.key === k);
  return r ? r.value : '';
}

function setSetting_(k, v) {
  upsert_('Settings', { key: k, value: v });
}

function str_(v, max) { return String(v == null ? '' : v).trim().slice(0, max || 200); }
function num_(v) { const n = Math.round(Number(v)); return isFinite(n) ? n : 0; }
function now_() { return new Date().toISOString(); }
function isDate_(s) { return /^\d{4}-\d{2}-\d{2}$/.test(String(s)); }
function hash_(pin) {
  const bytes = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, 'dk:' + pin + ':' + ScriptApp.getScriptId());
  return Utilities.base64Encode(bytes);
}

function seed_() {
  if (getSetting_('seeded')) return;
  locked_(() => {
    if (!rows_('Categories').length) {
      const rows = DEFAULT_CATEGORIES.map((c, i) => [Utilities.getUuid(), c[1], c[0], c[3], c[2], i, c[4]]);
      sheet_('Categories').getRange(2, 1, rows.length, rows[0].length).setValues(rows);
      dirty_('Categories');
    }
    if (!rows_('Wallets').length) {
      upsert_('Wallets', { id: Utilities.getUuid(), name: 'Tunai', type: 'cash', color: '#16A34A', initial: 0, archived: false, order: 0, createdAt: now_(), limit: 0, sub: '' });
    }
    ['Transactions', 'Budgets', 'Debts', 'Recurring', 'Goals', 'Logos'].forEach(rows_);
    setSetting_('seeded', '1');
  });
  PropertiesService.getScriptProperties().setProperty('SCHEMA_V', SCHEMA_VERSION);
}

function getAll_() {
  return {
    wallets: rows_('Wallets'),
    categories: rows_('Categories'),
    transactions: rows_('Transactions'),
    budgets: rows_('Budgets'),
    debts: rows_('Debts'),
    recurring: rows_('Recurring'),
    goals: rows_('Goals'),
    logos: rows_('Logos'),
    settings: USER_SETTINGS.reduce((o, k) => (o[k] = getSetting_(k), o), {}),
    hasPin: !!getSetting_('pin'),
    dbUrl: db_().getUrl()
  };
}

/* ============================== Sesi ============================== */
// Web app bisa dibuka siapa saja yang tahu URL-nya, jadi PIN dicek di server:
// setelah PIN benar, klien memegang token sesi yang wajib dikirim di setiap panggilan.

const SESSION_TTL = 21600; // detik (batas maksimal CacheService = 6 jam)

function issueToken_() {
  const t = Utilities.getUuid();
  CacheService.getScriptCache().put('tok:' + t, '1', SESSION_TTL);
  return t;
}

function guard_(token) {
  if (!getSetting_('pin')) return;
  if (!token || !CacheService.getScriptCache().get('tok:' + token)) throw new Error('SESI_HABIS: Sesi berakhir, masukkan PIN lagi.');
}

/* ============================== API (dipanggil dari Index.html) ============================== */

// Satu-satunya pintu ke data; fungsi berakhiran _ tidak bisa dipanggil langsung dari klien.
const API_ = {
  getAll: getAll_, setPin: setPin_,
  saveWallet: saveWallet_, deleteWallet: deleteWallet_,
  saveCategory: saveCategory_, deleteCategory: deleteCategory_,
  saveTx: saveTx_, deleteTx: deleteTx_,
  saveBudget: saveBudget_, deleteBudget: deleteBudget_,
  saveDebt: saveDebt_, deleteDebt: deleteDebt_,
  saveRecurring: saveRecurring_, deleteRecurring: deleteRecurring_,
  saveGoal: saveGoal_, deleteGoal: deleteGoal_,
  saveSetting: saveSetting_,
  saveLogo: saveLogo_, deleteLogo: deleteLogo_,
  savePhoto: savePhoto_, getPhoto: getPhoto_, deletePhoto: deletePhoto_
};

function call(token, fn, args) {
  guard_(token);
  if (!Object.prototype.hasOwnProperty.call(API_, fn)) throw new Error('Fungsi tidak dikenal');
  return API_[fn].apply(null, Array.isArray(args) ? args : []);
}

function boot() {
  seed_();
  migrate_();
  runRecurring_();
  runInterest_();
  const hasPin = !!getSetting_('pin');
  return { hasPin: hasPin, data: hasPin ? null : getAll_() };
}

function unlock(pin) {
  const cache = CacheService.getScriptCache();
  const fails = Number(cache.get('pinFails') || 0);
  if (fails >= 5) throw new Error('Terlalu banyak percobaan. Coba lagi 5 menit lagi.');
  if (hash_(String(pin)) !== getSetting_('pin')) {
    cache.put('pinFails', String(fails + 1), 300);
    throw new Error('PIN salah');
  }
  cache.remove('pinFails');
  return Object.assign(getAll_(), { token: issueToken_() });
}

function setPin_(oldPin, newPin) {
  const cur = getSetting_('pin');
  if (cur && hash_(String(oldPin || '')) !== cur) throw new Error('PIN lama salah');
  if (newPin) {
    if (!/^\d{4}$/.test(String(newPin))) throw new Error('PIN harus 4 angka');
    locked_(() => setSetting_('pin', hash_(String(newPin))));
    return { hasPin: true, token: issueToken_() };
  }
  locked_(() => remove_('Settings', 'pin'));
  return { hasPin: false };
}

function saveWallet_(w) {
  const o = {
    id: str_(w.id, 64) || Utilities.getUuid(),
    name: str_(w.name, 60),
    type: WALLET_TYPES.indexOf(w.type) >= 0 ? w.type : 'other',
    color: /^#[0-9a-f]{6}$/i.test(w.color) ? w.color : '#1E5EF0',
    initial: num_(w.initial),
    archived: !!w.archived,
    order: num_(w.order),
    createdAt: str_(w.createdAt, 40) || now_(),
    limit: Math.abs(num_(w.limit)),
    sub: str_(w.sub, 30),
    logo: logoKey_(w.logo),
    rateBp: 0, rateTax: false, rateFrom: '', taxBp: 0, taxMin: 0
  };
  // bunga harian hanya untuk rekening bank & tabungan.
  // rateBp = bunga kotor % p.a. x 100 (3,75% = 375); taxBp = pajak % dari bunga kotor x 100 (20% = 2000);
  // taxMin = pajak hanya dipotong bila saldo di atas angka ini (0 = selalu).
  if (INTEREST_TYPES.indexOf(o.type) >= 0 && num_(w.rateBp) > 0) {
    o.rateBp = Math.min(num_(w.rateBp), 5000);
    o.taxBp = Math.max(0, Math.min(num_(w.taxBp), 10000));
    o.taxMin = Math.max(0, num_(w.taxMin));
    o.rateTax = o.taxBp > 0;
    // tanggal terakhir bunga dikreditkan; baru diaktifkan = kemarin, jadi bunga hari ini langsung masuk
    o.rateFrom = isDate_(w.rateFrom) ? w.rateFrom : dayAdd_(today_(), -1);
  }
  if (!o.name) throw new Error('Nama akun wajib diisi');
  locked_(() => upsert_('Wallets', o));
  return o;
}

function deleteWallet_(id) {
  return locked_(() => {
    const used = rows_('Transactions').some(t => t.walletId === id || t.toWalletId === id);
    if (used) throw new Error('Akun masih punya transaksi. Arsipkan saja agar riwayat tetap utuh.');
    remove_('Wallets', id);
    return true;
  });
}

function saveCategory_(c) {
  const o = {
    id: str_(c.id, 64) || Utilities.getUuid(),
    name: str_(c.name, 40),
    type: c.type === 'income' ? 'income' : 'expense',
    color: /^#[0-9a-f]{6}$/i.test(c.color) ? c.color : '#64748B',
    icon: str_(c.icon, 20) || 'tag',
    order: num_(c.order),
    group: GROUPS.indexOf(c.group) >= 0 ? c.group : 'other'
  };
  if (!o.name) throw new Error('Nama kategori wajib diisi');
  locked_(() => upsert_('Categories', o));
  return o;
}

function deleteCategory_(id) {
  return locked_(() => {
    remove_('Categories', id);
    removeWhere_('Budgets', b => b.categoryId === id);
    return true;
  });
}

function saveTx_(t) {
  const type = TX_TYPES.indexOf(t.type) >= 0 ? t.type : 'expense';
  const tz = Session.getScriptTimeZone();
  const o = {
    id: str_(t.id, 64) || Utilities.getUuid(),
    date: isDate_(t.date) ? t.date : Utilities.formatDate(new Date(), tz, 'yyyy-MM-dd'),
    type: type,
    walletId: str_(t.walletId, 64),
    toWalletId: type === 'transfer' ? str_(t.toWalletId, 64) : '',
    categoryId: type === 'transfer' ? '' : str_(t.categoryId, 64),
    amount: Math.abs(num_(t.amount)),
    fee: type === 'transfer' ? Math.abs(num_(t.fee)) : 0,
    note: str_(t.note, 200),
    createdAt: str_(t.createdAt, 40) || now_(),
    updatedAt: now_(),
    refId: str_(t.refId, 64),
    with: WITH.indexOf(t.with) >= 0 ? t.with : '',
    photo: /^[a-z0-9-]{0,64}$/i.test(String(t.photo || '')) ? String(t.photo || '') : ''
  };
  if (!o.walletId) throw new Error('Pilih akun');
  if (o.amount <= 0) throw new Error('Nominal harus lebih dari 0');
  if (type === 'transfer' && (!o.toWalletId || o.toWalletId === o.walletId)) throw new Error('Pilih akun tujuan yang berbeda');
  locked_(() => upsert_('Transactions', o));
  return o;
}

function deleteTx_(id) {
  locked_(() => {
    const t = rows_('Transactions').find(x => x.id === id);
    remove_('Transactions', id);
    if (t && t.photo) removeWhere_('Photos', r => r.id === t.photo);
  });
  return true;
}

function saveBudget_(b) {
  const o = { id: str_(b.id, 64) || Utilities.getUuid(), categoryId: str_(b.categoryId, 64), amount: Math.abs(num_(b.amount)) };
  if (!o.categoryId) throw new Error('Pilih kategori');
  if (o.amount <= 0) throw new Error('Nominal anggaran harus lebih dari 0');
  locked_(() => {
    removeWhere_('Budgets', x => x.categoryId === o.categoryId && x.id !== o.id);
    upsert_('Budgets', o);
  });
  return o;
}

function deleteBudget_(id) {
  locked_(() => remove_('Budgets', id));
  return true;
}

function saveDebt_(d) {
  const o = {
    id: str_(d.id, 64) || Utilities.getUuid(),
    kind: d.kind === 'piutang' ? 'piutang' : 'hutang',
    name: str_(d.name, 60),
    amount: Math.abs(num_(d.amount)),
    due: isDate_(d.due) ? d.due : '',
    note: str_(d.note, 200),
    createdAt: str_(d.createdAt, 40) || now_()
  };
  if (!o.name) throw new Error('Isi nama orang / pihak');
  if (o.amount <= 0) throw new Error('Nominal harus lebih dari 0');
  locked_(() => upsert_('Debts', o));
  return o;
}

// Menghapus catatan hutang beserta transaksi yang terkait.
function deleteDebt_(id) {
  locked_(() => {
    remove_('Debts', id);
    removeWhere_('Transactions', t => t.refId === id);
  });
  return true;
}

/* ============================== Berulang & tagihan ============================== */

function addDate_(s, freq, anchorDay) {
  const p = s.split('-').map(Number);
  let y = p[0], m = p[1], d = p[2];
  if (freq === 'daily' || freq === 'weekly') {
    const dt = new Date(Date.UTC(y, m - 1, d + (freq === 'daily' ? 1 : 7)));
    return Utilities.formatDate(dt, 'UTC', 'yyyy-MM-dd');
  }
  if (freq === 'monthly') { m++; if (m > 12) { m = 1; y++; } } else y++;
  const last = new Date(Date.UTC(y, m, 0)).getUTCDate();
  d = Math.min(anchorDay || d, last);
  return y + '-' + ('0' + m).slice(-2) + '-' + ('0' + d).slice(-2);
}

// Mencatat transaksi berulang otomatis yang sudah jatuh tempo (maks. 60 per item).
function runRecurring_() {
  const today = Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'yyyy-MM-dd');
  const due = rows_('Recurring').filter(r => r.auto && r.active && isDate_(r.next) && r.next <= today);
  if (!due.length) return;
  locked_(() => {
    rows_('Recurring').filter(r => r.auto && r.active && isDate_(r.next) && r.next <= today).forEach(r => {
      const anchor = Number(String(r.start || r.next).slice(8, 10));
      for (let i = 0; i < 60 && r.next <= today; i++) {
        upsert_('Transactions', {
          id: Utilities.getUuid(), date: r.next, type: r.type, walletId: r.walletId,
          toWalletId: r.type === 'transfer' ? r.toWalletId : '', categoryId: r.type === 'transfer' ? '' : r.categoryId,
          amount: r.amount, fee: 0, note: r.note || r.name, createdAt: now_(), updatedAt: now_(), refId: r.id
        });
        r.next = addDate_(r.next, r.freq, anchor);
      }
      upsert_('Recurring', r);
    });
  });
}

function saveRecurring_(r) {
  const type = TX_TYPES.indexOf(r.type) >= 0 ? r.type : 'expense';
  const o = {
    id: str_(r.id, 64) || Utilities.getUuid(),
    name: str_(r.name, 60),
    type: type,
    walletId: str_(r.walletId, 64),
    toWalletId: type === 'transfer' ? str_(r.toWalletId, 64) : '',
    categoryId: type === 'transfer' ? '' : str_(r.categoryId, 64),
    amount: Math.abs(num_(r.amount)),
    freq: FREQS.indexOf(r.freq) >= 0 ? r.freq : 'monthly',
    start: isDate_(r.start) ? r.start : (isDate_(r.next) ? r.next : ''),
    next: isDate_(r.next) ? r.next : '',
    auto: !!r.auto,
    active: r.active !== false,
    note: str_(r.note, 200),
    logo: logoKey_(r.logo)
  };
  if (!o.name) throw new Error('Isi nama');
  if (!o.walletId) throw new Error('Pilih akun');
  if (o.amount <= 0) throw new Error('Nominal harus lebih dari 0');
  if (!o.next) throw new Error('Isi tanggal berikutnya');
  if (type === 'transfer' && (!o.toWalletId || o.toWalletId === o.walletId)) throw new Error('Pilih akun tujuan yang berbeda');
  locked_(() => upsert_('Recurring', o));
  return o;
}

function deleteRecurring_(id) {
  locked_(() => remove_('Recurring', id));
  return true;
}

/* ============================== Goals ============================== */

function saveGoal_(g) {
  const o = {
    id: str_(g.id, 64) || Utilities.getUuid(),
    name: str_(g.name, 60),
    target: Math.abs(num_(g.target)),
    walletId: str_(g.walletId, 64),
    due: isDate_(g.due) ? g.due : '',
    color: /^#[0-9a-f]{6}$/i.test(g.color) ? g.color : '#22C55E',
    icon: str_(g.icon, 20) || 'flag',
    createdAt: str_(g.createdAt, 40) || now_()
  };
  if (!o.name) throw new Error('Isi nama target');
  if (o.target <= 0) throw new Error('Target harus lebih dari 0');
  if (!o.walletId) throw new Error('Pilih kantong tabungan');
  locked_(() => upsert_('Goals', o));
  return o;
}

function deleteGoal_(id) {
  locked_(() => remove_('Goals', id));
  return true;
}

function saveSetting_(key, value) {
  if (USER_SETTINGS.indexOf(key) < 0) throw new Error('Pengaturan tidak dikenal');
  locked_(() => setSetting_(key, str_(value, 40)));
  return true;
}

/* ============================== Logo unggahan ============================== */

const LOGO_MAX = 45000; // batas aman di bawah 50.000 karakter per sel Sheets

function logoKey_(v) {
  v = String(v || '');
  return /^[a-z0-9_-]{0,40}$/i.test(v) ? v : '';
}

function saveLogo_(l) {
  const data = String(l && l.data || '');
  if (!/^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/=]+$/.test(data)) throw new Error('Format gambar tidak didukung');
  if (data.length > LOGO_MAX) throw new Error('Gambar terlalu besar');
  const o = { id: str_(l.id, 64) || Utilities.getUuid(), name: str_(l.name, 40), data: data };
  locked_(() => upsert_('Logos', o));
  return { id: o.id };
}

function deleteLogo_(id) {
  locked_(() => remove_('Logos', id));
  return true;
}

/* ============================== Foto transaksi ============================== */
// Foto disimpan sebagai data URL yang dipecah per 45.000 karakter (batas sel Sheets 50.000), satu baris per potongan.

const PHOTO_CHUNK = 45000;
const PHOTO_MAX = 1500000;

function savePhoto_(ph) {
  const id = String(ph && ph.id || '');
  const data = String(ph && ph.data || '');
  if (!/^[a-z0-9-]{1,64}$/i.test(id)) throw new Error('ID foto tidak valid');
  if (!/^data:image\/(jpeg|png|webp);base64,[A-Za-z0-9+\/=]+$/.test(data)) throw new Error('Format foto tidak didukung');
  if (data.length > PHOTO_MAX) throw new Error('Foto terlalu besar');
  const rows = [];
  for (let i = 0, n = 0; i < data.length; i += PHOTO_CHUNK, n++) rows.push([id, String(n), 'd' + data.slice(i, i + PHOTO_CHUNK)]); // awalan 'd' agar Sheets tak membaca '+'/'=' sebagai rumus
  locked_(() => {
    removeWhere_('Photos', r => r.id === id);
    const sh = sheet_('Photos'), last = sh.getLastRow();
    if (sh.getMaxRows() < last + rows.length) sh.insertRowsAfter(sh.getMaxRows(), rows.length + 50);
    sh.getRange(last + 1, 1, rows.length, 3).setValues(rows);
    dirty_('Photos');
  });
  return { id: id };
}

function getPhoto_(id) {
  const parts = rows_('Photos').filter(r => r.id === String(id)).sort((a, b) => Number(a.part) - Number(b.part));
  return parts.map(r => r.data.slice(1)).join('');
}

function deletePhoto_(id) {
  locked_(() => removeWhere_('Photos', r => r.id === String(id)));
  return true;
}

/* ============================== Bunga harian ============================== */
// Tidak perlu trigger/cron: setiap aplikasi dibuka (boot), bunga semua hari yang belum dikreditkan
// sampai hari ini ditambahkan. Bunga hari D dihitung dari saldo awal hari D (= saldo akhir hari sebelumnya).
// Satu transaksi per hari (id tetap: int-<akun>-<tanggal>), amount = bunga bersih, fee = pajak yang dipotong.
// rateFrom = hari terakhir yang bunganya sudah dikreditkan.

const INTEREST_TYPES = ['bank', 'saving'];
const INTEREST_CAT = '__int';

function today_() { return Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'yyyy-MM-dd'); }
function dayAdd_(s, n) {
  const p = s.split('-').map(Number);
  return Utilities.formatDate(new Date(Date.UTC(p[0], p[1] - 1, p[2] + n)), 'UTC', 'yyyy-MM-dd');
}

// Bunga satu hari: { gross, tax, net } dalam rupiah bulat.
function dailyInterest_(bal, w) {
  if (bal <= 0 || !(w.rateBp > 0)) return { gross: 0, tax: 0, net: 0 };
  const gross = Math.round(bal * w.rateBp / 10000 / 365);
  const tax = w.taxBp > 0 && bal > (w.taxMin || 0) ? Math.round(gross * w.taxBp / 10000) : 0;
  return { gross: gross, tax: tax, net: gross - tax };
}

// Transaksi bunga yang belum dikreditkan untuk satu akun: { txs, last }.
function interestPlan_(w, txs, today) {
  if (!(w.rateBp > 0) || w.archived || !isDate_(w.rateFrom) || INTEREST_TYPES.indexOf(w.type) < 0) return null;
  if (w.rateFrom >= today) return null;
  let start = dayAdd_(w.rateFrom, 1);
  const floor = dayAdd_(today, -400);
  if (start < floor) start = floor;
  // saldo awal hari = saldo awal akun + mutasi sebelum hari itu (bunga lama selalu bertanggal <= rateFrom)
  let base = w.initial, interestBefore = 0;
  const deltas = {};
  txs.forEach(t => {
    let v = 0;
    if (t.type === 'income' && t.walletId === w.id) v = t.amount;
    else if (t.type === 'expense' && t.walletId === w.id) v = -t.amount;
    else if (t.type === 'transfer') v = (t.walletId === w.id ? -t.amount - t.fee : 0) + (t.toWalletId === w.id ? t.amount : 0);
    if (!v) return;
    if (t.categoryId === INTEREST_CAT && t.walletId === w.id) { interestBefore += v; return; }
    if (t.date < start) base += v; else deltas[t.date] = (deltas[t.date] || 0) + v;
  });
  const out = [];
  let accrued = 0;
  for (let d = start; d <= today; d = dayAdd_(d, 1)) {
    const it = dailyInterest_(base + interestBefore + accrued, w);
    base += deltas[d] || 0;
    if (!it.net) continue;
    accrued += it.net;
    out.push({
      id: 'int-' + w.id + '-' + d, date: d, type: 'income', walletId: w.id, toWalletId: '', categoryId: INTEREST_CAT,
      amount: it.net, fee: it.tax, note: 'Bunga harian', createdAt: now_(), updatedAt: now_(), refId: '', with: '', photo: ''
    });
  }
  return { txs: out, last: today };
}

function runInterest_() {
  const today = today_();
  const due = rows_('Wallets').filter(w => w.rateBp > 0 && !w.archived && isDate_(w.rateFrom) && w.rateFrom < today);
  if (!due.length) return;
  locked_(() => {
    const txs = rows_('Transactions');
    rows_('Wallets').forEach(w => {
      const plan = interestPlan_(w, txs, today);
      if (!plan) return;
      if (plan.txs.length) {
        const sh = sheet_('Transactions'), cols = SCHEMA.Transactions;
        sh.getRange(sh.getLastRow() + 1, 1, plan.txs.length, cols.length).setValues(plan.txs.map(t => cols.map(c => t[c] == null ? '' : t[c])));
        dirty_('Transactions');
      }
      w.rateFrom = plan.last;
      upsert_('Wallets', w);
    });
  });
}
