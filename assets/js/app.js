(() => {
'use strict';
/* ========== Helper umum ========== */
const $ = (s, r = document) => r.querySelector(s);
const csrf = $('meta[name=csrf]')?.content;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const nf = new Intl.NumberFormat('id-ID');
const nf2 = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const int = v => nf.format(Math.round(v || 0));
const rp = v => 'Rp ' + nf.format(Math.round(v || 0));
const gr = v => nf2.format(v || 0) + ' g';
const pad = n => String(n).padStart(2, '0');
const ymd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parse = s => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
const idDate = s => parse(s).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
const tag = t => `<span class="tag ${t}">${t === 'toko' ? 'Toko' : 'Luar toko'}</span>`;

function rangeFor(mode, s) {
  const d = parse(s), y = d.getFullYear(), m = d.getMonth();
  if (mode === 'minggu') { const a = new Date(d); a.setDate(d.getDate() - ((d.getDay() + 6) % 7)); const b = new Date(a); b.setDate(a.getDate() + 6); return [ymd(a), ymd(b)]; }
  if (mode === 'bulan') return [ymd(new Date(y, m, 1)), ymd(new Date(y, m + 1, 0))];
  if (mode === 'triwulan') { const q = Math.floor(m / 3); return [ymd(new Date(y, q * 3, 1)), ymd(new Date(y, q * 3 + 3, 0))]; }
  return [s, s];
}

let toastTimer;
function toast(msg, err = false) {
  const t = $('#toast'); t.textContent = msg; t.className = 'show' + (err ? ' err' : '');
  clearTimeout(toastTimer); toastTimer = setTimeout(() => t.className = '', err ? 6000 : 3000);
}

async function api(action, params = {}, post = false) {
  let url = 'api.php?action=' + action; const opt = { credentials: 'same-origin' };
  if (post) {
    let body = params;
    if (!(params instanceof FormData)) { body = new FormData(); Object.entries(params).forEach(([k, v]) => body.append(k, v)); }
    Object.assign(opt, { method: 'POST', body, headers: { 'X-CSRF-Token': csrf } });
  } else url += '&' + new URLSearchParams(params);
  const r = await fetch(url, opt);
  if (r.status === 401) { location.href = 'login.php'; throw new Error('Sesi habis'); }
  const raw = await r.text();
  let j; try { j = JSON.parse(raw); } catch {
    // Server membalas selain JSON (biasanya error PHP / file belum terupload). Tampilkan petunjuk yang bisa ditindaklanjuti.
    const snippet = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 140);
    throw new Error(`Respons server tidak valid (HTTP ${r.status}${snippet ? ': ' + snippet : ', isi kosong — cek error_log di hosting atau pastikan semua file sudah terupload lengkap'}).`);
  }
  if (!j.ok) throw new Error(j.error || 'Terjadi kesalahan.');
  return j;
}

/* ========== Tabel (bisa diurutkan lewat klik header) ========== */
function table(el, cols, rows, foot, empty = 'Tidak ada data pada periode ini.') {
  el._d = { cols, rows, foot, empty };
  if (!el._bound) {
    el._bound = true;
    el.addEventListener('click', e => {
      const th = e.target.closest('th[data-k]'); if (!th) return;
      const s = el._s || (el._s = { k: null, dir: -1 });
      s.dir = s.k === th.dataset.k ? -s.dir : -1; s.k = th.dataset.k; draw(el);
    });
  }
  draw(el);
}
function draw(el) {
  const { cols, rows, foot, empty } = el._d, s = el._s || {};
  const data = [...rows];
  if (s.k) data.sort((a, b) => {
    const x = a[s.k], y = b[s.k];
    return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x ?? '').localeCompare(String(y ?? ''), 'id')) * s.dir;
  });
  // Baris bertanda _pin (mis. "Lainnya" gabungan sales bukan toko) selalu tetap di bawah, walau diurutkan ulang.
  const pinned = data.filter(r => r._pin), rest = data.filter(r => !r._pin);
  const ordered = [...rest, ...pinned];
  const cell = (c, r) => { const v = r[c.k]; return c.fmt ? c.fmt(v, r) : esc(v); };
  const th = cols.map(c => `<th class="${c.n ? 'n' : ''} ${c.cls || ''} ${c.nosort ? '' : 'sortable'}" ${c.nosort ? '' : `data-k="${c.k}"`} ${s.k === c.k ? `aria-sort="${s.dir > 0 ? 'ascending' : 'descending'}"` : ''}>${esc(c.label)}</th>`).join('');
  let body = ordered.map(r => `<tr class="${r._pin ? 'pinned' : ''} ${r._sub ? 'subtotal' : ''}">${cols.map(c => `<td class="${c.n ? 'n' : ''} ${c.cls || ''}">${cell(c, r)}</td>`).join('')}</tr>`).join('');
  if (!data.length) body = `<tr><td class="empty" colspan="${cols.length}">${esc(empty)}</td></tr>`;
  // foot: satu objek total ({...}) atau beberapa baris [{label, obj, cls?}, ...] (mis. "Total" + "Bulan Lalu").
  const footRows = !foot ? [] : Array.isArray(foot) ? foot : [{ label: 'Total', obj: foot }];
  const tf = footRows.length && data.length ? `<tfoot>${footRows.map(fr => `<tr class="${fr.cls || ''}">${cols.map((c, i) => `<td class="${c.n ? 'n' : ''} ${c.cls || ''}">${i === 0 ? esc(fr.label) : (fr.obj[c.k] !== undefined ? (c.fmt ? c.fmt(fr.obj[c.k], fr.obj) : esc(fr.obj[c.k])) : '')}</td>`).join('')}</tr>`).join('')}</tfoot>` : '';
  el.innerHTML = `<thead><tr>${th}</tr></thead><tbody>${body}</tbody>${tf}`;
}

/** Panel "Kesimpulan": ringkasan bahasa biasa. `lines` array string (boleh HTML sederhana, mis. <b>). */
function kesimpulan(el, title, lines) {
  if (!el) return;
  lines = (lines || []).filter(Boolean);   // baris kosong diabaikan
  if (!lines || !lines.length) { el.innerHTML = ''; el.hidden = true; return; }
  el.hidden = false;
  el.innerHTML = `<h2>${esc(title)}</h2><ul>${lines.map(l => `<li>${l}</li>`).join('')}</ul>`;
}
/** Persentase perubahan cur vs prev (null jika prev 0, hindari bagi nol / label "baru" yang menyesatkan). */
function pctDiff2(cur, prev) { return prev > 0 ? Math.round((cur - prev) / prev * 1000) / 10 : null; }
/* ========== Export Excel / PDF ========== */
/** Ambil isi tabel yang sedang tampil di layar (header, badan, footer) sebagai teks biasa. */
function tableToAOA(el) {
  const cellText = td => (td.innerText || td.textContent || '').replace(/\n+/g, ' - ').replace(/\s+/g, ' ').trim();
  const header = [...el.querySelectorAll('thead th')].map(cellText);
  const body = [...el.querySelectorAll('tbody tr')].map(tr => [...tr.children].map(cellText));
  const foot = [...el.querySelectorAll('tfoot tr')].map(tr => [...tr.children].map(cellText));
  return { header, body, foot };
}
function exportTableExcel(el, filename, sheetName) {
  if (!window.XLSX) { toast('Modul Excel belum termuat.', true); return; }
  const { header, body, foot } = tableToAOA(el);
  const ws = XLSX.utils.aoa_to_sheet([header, ...body, ...foot]);
  ws['!cols'] = header.map(() => ({ wch: 16 }));
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, (sheetName || 'Data').slice(0, 31));
  XLSX.writeFile(wb, filename);
}
function exportTablePDF(el, filename, title, subtitle) {
  if (!window.jspdf) { toast('Modul PDF belum termuat.', true); return; }
  const { header, body, foot } = tableToAOA(el);
  const doc = new jspdf.jsPDF({ orientation: header.length > 7 ? 'landscape' : 'portrait', unit: 'pt' });
  doc.setFontSize(13); doc.text(title, 32, 30);
  if (subtitle) { doc.setFontSize(9); doc.setTextColor(120); doc.text(subtitle, 32, 46); }
  doc.autoTable({ head: [header], body, foot, startY: subtitle ? 56 : 42, styles: { fontSize: 7, cellPadding: 4 },
    headStyles: { fillColor: [166, 124, 26] }, footStyles: { fillColor: [244, 234, 210], textColor: 20, fontStyle: 'bold' },
    margin: { left: 32, right: 32 } });
  doc.save(filename);
}

/** Format perubahan sebagai teks naik/turun dengan warna, untuk dipakai di kalimat kesimpulan. */
function trendWord(pct) {
  if (pct == null) return '';
  return pct > 0 ? `<span class="delta up">naik ${nf2.format(pct)}%</span>` : pct < 0 ? `<span class="delta down">turun ${nf2.format(Math.abs(pct))}%</span>` : 'tidak berubah';
}

function kpis(el, items) {
  el.innerHTML = items.map(i => `<div class="kpi"><div class="kl">${esc(i.label)}</div><div class="kv">${i.value}</div>${i.sub ? `<div class="ks">${i.sub}</div>` : ''}</div>`).join('');
}

/* ========== Chart ========== */
const charts = {};
const C = { ink: '#1b2a3a', brass: '#a67c1a', teal: '#2b7a78', mute: '#8a94a0', grid: '#e6e9ed' };
if (window.Chart) { Chart.defaults.font.family = getComputedStyle(document.body).fontFamily; Chart.defaults.color = '#5b6773'; }
function chart(id, cfg) {
  charts[id]?.destroy();
  cfg.options = Object.assign({ responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false } }, cfg.options);
  charts[id] = new Chart($('#' + id), cfg);
}
const yScale = (extra = {}) => Object.assign({ beginAtZero: true, grid: { color: C.grid }, border: { display: false } }, extra);

/* ========== Filter periode ========== */
function initFilters(onChange) {
  const mode = $('#f-mode'), date = $('#f-date'), from = $('#f-from'), to = $('#f-to'), info = $('#range-info');
  const today = ymd(new Date());
  if (date) date.value = today;
  if (from) { from.value = rangeFor('bulan', today)[0]; to.value = today; }
  function range() {
    if (mode.value === 'custom') return { from: from.value || today, to: to.value || today };
    const [a, b] = rangeFor(mode.value, date.value || today); return { from: a, to: b };
  }
  function sync() {
    const custom = mode.value === 'custom';
    document.querySelectorAll('.f-single').forEach(e => e.hidden = custom);
    document.querySelectorAll('.f-range').forEach(e => e.hidden = !custom);
    const r = range(); info.textContent = r.from === r.to ? idDate(r.from) : `${idDate(r.from)} – ${idDate(r.to)}`;
  }
  let t; $('#filters').addEventListener('input', e => {
    if (e.target.type === 'search') { clearTimeout(t); t = setTimeout(onChange, 300); }
  });
  $('#filters').addEventListener('change', e => { if (e.target.type === 'search') return; sync(); onChange(); });
  sync();
  return { range, val: id => $('#' + id)?.value };
}

async function guarded(box, fn) {
  box?.classList.add('loading');
  try { await fn(); } catch (e) { toast(e.message, true); } finally { box?.classList.remove('loading'); }
}

/* ========== Halaman ========== */
const pages = {};

pages.index = async () => {
  const j = await api('dashboard');
  $('#dash-period').textContent = `Ringkasan ${idDate(j.periode.from)} – ${idDate(j.periode.to)}. Data penjualan terakhir: ${j.lastData.jual ? idDate(j.lastData.jual) : 'belum ada'}, pembelian: ${j.lastData.beli ? idDate(j.lastData.beli) : 'belum ada'}.`;
  const k = j.kpi;
  kpis($('#kpis'), [
    { label: 'Penjualan bulan ini', value: rp(k.jual_rp), sub: `termasuk diamond ${rp(k.diamond_rp)}` },
    { label: 'Pcs terjual', value: int(k.jual_pcs), sub: gr(k.jual_gram) },
    { label: 'Pcs dibeli (buyback)', value: int(k.beli_pcs) },
    { label: 'Transaksi customer', value: int(k.trx), sub: 'jumlah nota penjualan' },
    { label: 'Customer bertransaksi', value: int(k.customer), sub: `${int(k.repeat)} customer belanja 2× atau lebih` },
  ]);
  const s = j.series, lb = j.labels;
  chart('c-jual', { data: { labels: lb, datasets: [
    { type: 'bar', label: 'Rupiah', data: s.rpJual, backgroundColor: C.brass, yAxisID: 'y', borderRadius: 3 },
    { type: 'line', label: 'Pcs', data: s.pcsJual, borderColor: C.ink, backgroundColor: C.ink, yAxisID: 'y1', tension: .25, pointRadius: 2 }] },
    options: { scales: { y: yScale({ ticks: { callback: v => nf.format(v / 1e6) + ' jt' } }), y1: yScale({ position: 'right', grid: { drawOnChartArea: false } }) } } });
  chart('c-beli', { type: 'bar', data: { labels: lb, datasets: [{ label: 'Pcs dibeli', data: s.pcsBeli, backgroundColor: C.teal, borderRadius: 3 }] },
    options: { scales: { y: yScale() }, plugins: { legend: { display: false } } } });
  chart('c-trx', { type: 'line', data: { labels: lb, datasets: [
    { label: 'Nota', data: s.trx, borderColor: C.ink, backgroundColor: C.ink, tension: .25, pointRadius: 2 },
    { label: 'Customer unik', data: s.cust, borderColor: C.brass, backgroundColor: C.brass, tension: .25, pointRadius: 2 }] },
    options: { scales: { y: yScale() } } });
  table($('#t-top'), [
    { k: 'nama', label: 'Customer', fmt: (v, r) => `${esc(v)}<div class="sub">${esc(r.jenis)}: ${esc(r.customer_key.slice(2))}</div>`, nosort: true },
    { k: 'trx', label: 'Transaksi', n: 1, fmt: int, nosort: true }, { k: 'rupiah', label: 'Rupiah', n: 1, fmt: rp, nosort: true }], j.top);
  const topCust = j.top[0];
  kesimpulan($('#kesimpulan'), 'Kesimpulan', [
    `Bulan berjalan (s.d. ${j.lastData.jual ? idDate(j.lastData.jual) : 'belum ada data'}): penjualan <b>${rp(k.jual_rp)}</b> dari <b>${int(k.jual_pcs)} pcs</b> (${gr(k.jual_gram)}), termasuk diamond ${rp(k.diamond_rp)}.`,
    `Pembelian (buyback) sejauh ini <b>${int(k.beli_pcs)} pcs</b>.`,
    `Total <b>${int(k.trx)} transaksi</b> dari <b>${int(k.customer)} customer</b>, ${int(k.repeat)} di antaranya belanja 2 kali atau lebih.`,
    topCust ? `Customer paling aktif: <b>${esc(topCust.nama)}</b> dengan ${int(topCust.trx)} transaksi (${rp(topCust.rupiah)}).` : 'Belum ada transaksi customer bulan ini.',
  ]);
};

const JENIS_LABEL = { MD: 'MD', MT: 'MT', BERLIAN: 'Diamond', OTHER: 'Other' };
const JENIS_COLOR = { MD: '#a67c1a', MT: '#2b7a78', BERLIAN: '#6a4fb0', OTHER: '#6b7684' };
function renderJenisStat(j) {
  kpis($('#jenis-kpis'), j.groups.filter(g => g.group !== 'OTHER' || g.total.rupiah > 0).map(g => ({
    label: JENIS_LABEL[g.group], value: rp(g.total.rupiah), sub: `${int(g.total.pcs)} pcs · ${gr(g.total.gram)} · ${nf2.format(g.pct_rupiah)}% dari total`,
  })));
  $('#jenis-groups').innerHTML = j.groups.filter(g => g.group !== 'OTHER' || g.rows.length).map(g => `
    <div class="stock-group">
      <h3 style="background:${JENIS_COLOR[g.group]}">${esc(JENIS_LABEL[g.group])}</h3>
      <table><thead><tr><th>Kode</th><th class="n">Pcs</th><th class="n">Gram</th><th class="n">Rupiah</th></tr></thead>
      <tbody>${g.rows.map(r => `<tr><td>${jenisChip(r.kode_jenis)}</td><td class="n">${int(r.pcs)}</td><td class="n">${gr(r.gram)}</td><td class="n">${rp(r.rupiah)}</td></tr>`).join('') || '<tr><td colspan="4" class="sub">Tidak ada data</td></tr>'}</tbody>
      <tfoot><tr><td>Total</td><td class="n">${int(g.total.pcs)}</td><td class="n">${gr(g.total.gram)}</td><td class="n">${rp(g.total.rupiah)}</td></tr></tfoot></table>
    </div>`).join('');
}

function rekapPage(kind) {
  const isBeli = kind === 'pembelian';
  const F = initFilters(load);
  const cols = [
    { k: 'nama_sales', label: 'Nama sales' },
    { k: 'tipe', label: 'Jenis', fmt: tag },
    { k: 'pcs', label: 'Pcs', n: 1, fmt: int },
  ];
  if (!isBeli) cols.push({ k: 'gram', label: 'Gramasi', n: 1, fmt: gr }, { k: 'rupiah', label: 'Rupiah', n: 1, fmt: rp });
  async function load() {
    const r = F.range();
    await guarded($('#t-rekap').closest('.panel'), async () => {
      const j = await api('rekap', { kind, from: r.from, to: r.to, tipe: F.val('f-tipe') });
      const t = j.total;
      kpis($('#kpis'), isBeli
        ? [{ label: 'Total pcs dibeli', value: int(t.pcs) }, { label: 'Sales aktif', value: int(j.rows.length) }]
        : [{ label: 'Rupiah', value: rp(t.rupiah) }, { label: 'Pcs', value: int(t.pcs) }, { label: 'Gramasi', value: gr(t.gram) }, { label: 'Sales aktif', value: int(j.rows.length) }]);
      if (!$('#t-rekap')._s) $('#t-rekap')._s = { k: isBeli ? 'pcs' : 'rupiah', dir: -1 };
      const rows = j.rows.map(x => x.kode_sales === 'OTHER' ? { ...x, _pin: true } : x);
      table($('#t-rekap'), cols, rows, t);
      if (kind === 'diamond') {
        $('#detail-info').textContent = j.detail.length >= 500 ? 'Menampilkan 500 transaksi terbaru.' : `${int(j.detail.length)} transaksi.`;
        table($('#t-detail'), [
          { k: 'tgl', label: 'Tanggal', fmt: v => idDate(v) }, { k: 'no_faktur', label: 'No faktur' }, { k: 'sales', label: 'Sales' },
          { k: 'nama_customer', label: 'Customer' }, { k: 'kode_dept', label: 'Dept' }, { k: 'nama_barang', label: 'Barang' },
          { k: 'berat', label: 'Berat', n: 1, fmt: v => gr(+v) }, { k: 'harga_total', label: 'Rupiah', n: 1, fmt: v => rp(+v) }], j.detail);
      }
      const named = j.rows.filter(x => x.kode_sales !== 'OTHER');
      const other = j.rows.find(x => x.kode_sales === 'OTHER');
      const top = named[0];
      const periode = r.from === r.to ? idDate(r.from) : `${idDate(r.from)} – ${idDate(r.to)}`;
      const lines = isBeli
        ? [`Periode ${periode}: total <b>${int(t.pcs)} pcs</b> dibeli dari <b>${int(j.rows.length)} sales</b>.`,
           top ? `Sales dengan pembelian terbanyak: <b>${esc(top.nama_sales)}</b> (${int(top.pcs)} pcs).` : 'Belum ada data pembelian pada periode ini.']
        : [`Periode ${periode}: total <b>${rp(t.rupiah)}</b> dari <b>${int(t.pcs)} pcs</b> (${gr(t.gram)}).`,
           top ? `Sales terbaik: <b>${esc(top.nama_sales)}</b> dengan ${rp(top.rupiah)} (${int(top.pcs)} pcs)${t.rupiah ? `, ${nf2.format(top.rupiah / t.rupiah * 100)}% dari total` : ''}.` : 'Belum ada transaksi pada periode ini.'];
      if (other && other.rupiah) lines.push(`Sales bukan toko (Lainnya) menyumbang ${isBeli ? int(other.pcs) + ' pcs' : rp(other.rupiah)}.`);
      if (kind === 'penjualan' && $('#jenis-groups')) {
        const js = await api('jenis_stat', { from: r.from, to: r.to, tipe: F.val('f-tipe') });
        renderJenisStat(js);
        const best = [...js.groups].filter(g => g.group !== 'OTHER').sort((a, b) => b.total.rupiah - a.total.rupiah)[0];
        const topKode = js.groups.flatMap(g => g.rows).filter(x => x.pcs > 0).sort((a, b) => b.pcs - a.pcs)[0];
        if (best && best.total.rupiah > 0) lines.push(`Kelompok dengan nilai terbesar: <b>${JENIS_LABEL[best.group]}</b> (${rp(best.total.rupiah)}, ${nf2.format(best.pct_rupiah)}% dari total).`);
        if (topKode) lines.push(`Kode jenis paling banyak terjual: <b>${esc(topKode.kode_jenis)}</b> (${int(topKode.pcs)} pcs, ${gr(topKode.gram)}).`);
      }
      kesimpulan($('#kesimpulan'), 'Kesimpulan', lines);
    });
  }
  load();
}
pages.penjualan = () => rekapPage('penjualan');
pages.diamond = () => rekapPage('diamond');
pages.pembelian = () => rekapPage('pembelian');

/* ---- Tabel dua baris header (SALES/CUSTOMER x PCS/GRAM/RP) dipakai halaman Perbandingan & Pareto ---- */
const fmtN = { pcs: v => nf.format(Math.round(v || 0)), gram: v => nf2.format(v || 0), rupiah: v => nf.format(Math.round(v || 0)) };
const zdash = (v, f) => v ? f(v) : '<span class="zero">–</span>';

pages.pareto = () => {
  const F = initFilters(load);
  const kelas = v => `<span class="kelas k${v}">${v}</span>`;
  const GL = ['MD', 'MT', 'LM', 'LS', 'BERLIAN', 'OTHERS'];
  const M3 = ['pcs', 'gram', 'rupiah'];
  function renderTable(j) {
    const el = $('#t-pareto');
    const head1 = `<tr><th rowspan="2">NAMA CUST</th><th rowspan="2">NO HP</th>${GL.map(g => `<th colspan="3" class="sep">${g}</th>`).join('')}<th colspan="3" class="sep grp-total">TOTAL</th><th rowspan="2" class="sep">KELAS</th></tr>`;
    const head2 = `<tr>${[...GL, 'TOTAL'].map(() => ['PCS', 'GRAM', 'RP'].map((t, i) => `<th class="sub-h ${i === 0 ? 'sep' : ''}">${t}</th>`).join('')).join('')}</tr>`;
    const cells = (gv, tot) => GL.map(g => M3.map((m, i) => `<td class="n ${i === 0 ? 'sep' : ''}">${tot ? fmtN[m](gv[g][m]) : zdash(gv[g][m], fmtN[m])}</td>`).join('')).join('');
    const totCells = (o, bold) => M3.map((m, i) => `<td class="n tot ${i === 0 ? 'sep' : ''}">${fmtN[m](o[m])}</td>`).join('');
    const body = j.rows.map(r => {
      const hp = r.hp && r.hp !== '-' ? esc(r.hp) : '<span class="zero">–</span>';
      return `<tr><td class="name">${r.no}. ${esc(r.nama) || '<i>(tanpa nama)</i>'}<div class="sub">${esc(r.jenis)}: ${esc(r.customer_key.slice(2))} · ${int(r.trx)} transaksi</div></td>
        <td>${hp}</td>${cells(r.g, false)}${totCells(r)}<td class="sep" style="text-align:center">${kelas(r.kelas)}</td></tr>`;
    }).join('') || `<tr><td class="empty" colspan="${2 + 3 * (GL.length + 1) + 1}">Tidak ada customer pada periode ini.</td></tr>`;
    let foot = '';
    if (j.rows.length) {
      const fr = (label, o) => `<tr><td>${label}</td><td></td>${cells(o.g, true)}${totCells(o)}<td class="sep"></td></tr>`;
      foot = `<tfoot>${fr(`TOTAL SEMUA CUSTOMER (${int(j.summary.customer)})`, j.total)}`;
      if (j.rows.length !== j.summary.customer) foot += fr(`Total ${int(j.rows.length)} customer yang tampil`, j.shown);
      foot += '</tfoot>';
    }
    el.innerHTML = `<thead>${head1}${head2}</thead><tbody>${body}</tbody>${foot}`;
  }
  async function load() {
    const r = F.range();
    await guarded($('#t-pareto').closest('.panel'), async () => {
      const j = await api('pareto', { from: r.from, to: r.to, sumber: F.val('f-sumber'), urut: F.val('f-urut'), limit: F.val('f-limit'), q: F.val('f-q') });
      const s = j.summary;
      kpis($('#kpis'), [
        { label: 'Customer', value: int(s.customer), sub: s.tanpa_identitas ? `${int(s.tanpa_identitas)} transaksi tanpa identitas tidak dihitung` : '' },
        { label: 'Total transaksi', value: int(s.trx), sub: s.customer ? `rata-rata ${nf2.format(s.trx / s.customer)} per customer` : '' },
        { label: 'Belanja berulang', value: int(s.repeat), sub: 'customer dengan 2 transaksi atau lebih' },
        { label: 'Customer kelas A', value: int(s.kelasA), sub: s.customer ? `${nf2.format(s.kelasA / s.customer * 100)}% customer = 80% total` : '' },
      ]);
      const ex = j.others_detail || [], exRp = ex.reduce((a, e) => a + e.rupiah, 0), exN = ex.reduce((a, e) => a + e.baris, 0);
      const exBox = $('#excl-note');
      exBox.hidden = !ex.length;
      if (ex.length) exBox.textContent = `Isi kolom OTHERS (kode dept di luar MD/MT/LM/LS/BERLIAN): ${int(exN)} baris, ${rp(exRp)} — ${ex.slice(0, 8).map(e => `${e.dept} ${rp(e.rupiah)}`).join(', ')}${ex.length > 8 ? ', dst.' : ''}.`;
      const mLabel = { trx: 'Transaksi', rupiah: 'Rupiah', gram: 'Gramasi', pcs: 'Pcs' }[j.metric];
      $('#chart-sub').textContent = `20 teratas berdasarkan ${mLabel.toLowerCase()}; garis = persentase kumulatif.`;
      const top = j.rows.slice(0, 20), nm = x => (x.nama || '(tanpa nama)');
      chart('c-pareto', { data: { labels: top.map(x => nm(x).length > 16 ? nm(x).slice(0, 15) + '…' : nm(x)), datasets: [
        { type: 'bar', label: mLabel, data: top.map(x => x[j.metric]), backgroundColor: C.brass, yAxisID: 'y', borderRadius: 3 },
        { type: 'line', label: 'Kumulatif %', data: top.map(x => x.cum), borderColor: C.ink, backgroundColor: C.ink, yAxisID: 'y1', pointRadius: 2, tension: .2 }] },
        options: { scales: { y: yScale(), y1: yScale({ position: 'right', max: 100, grid: { drawOnChartArea: false }, ticks: { callback: v => v + '%' } }), x: { ticks: { maxRotation: 60, minRotation: 45 } } } } });
      renderTable(j);
      const topC = j.rows[0];
      const gSorted = GL.map(g => ({ g, rp: j.total.g[g].rupiah })).sort((a, b) => b.rp - a.rp);
      const gTop = gSorted[0];
      kesimpulan($('#kesimpulan'), 'Kesimpulan', [
        `Total <b>${int(s.customer)} customer</b> dengan <b>${int(s.trx)} transaksi</b> (rata-rata ${s.customer ? nf2.format(s.trx / s.customer) : 0} transaksi/customer).`,
        `<b>${int(s.kelasA)} customer</b> (kelas A) menyumbang 80% dari total ${mLabel.toLowerCase()}, dan ${int(s.repeat)} customer belanja berulang.`,
        j.total.rupiah > 0 ? `Per kelompok: ${GL.map(g => `${g} ${rp(j.total.g[g].rupiah)} (${nf2.format(j.total.g[g].rupiah / j.total.rupiah * 100)}%)`).join(' · ')}. Terbesar: <b>${gTop.g}</b>.` : '',
        topC ? `Customer teratas: <b>${esc(nm(topC))}</b> dengan ${int(topC.trx)} transaksi, ${rp(topC.rupiah)}.` : 'Belum ada customer pada periode ini.',
      ].filter(Boolean));
    });
  }
  load();
};

/* ---- daftar cabang & bulan bersama untuk halaman harian/mingguan ---- */
async function initMonthCabang() {
  const bln = $('#f-bulan'), cab = $('#f-cabang'), hb = $('#f-hb');
  const now = new Date(); bln.value = `${now.getFullYear()}-${pad(now.getMonth() + 1)}`;
  try { const j = await api('cabang_list'); j.rows.forEach(c => cab.insertAdjacentHTML('beforeend', `<option value="${esc(c)}">${esc(c)}</option>`)); } catch {}
  function fillHb() {
    const [y, m] = bln.value.split('-').map(Number); const days = new Date(y, m, 0).getDate();
    const keep = hb.value; hb.innerHTML = '<option value="0">Otomatis (data terakhir)</option>';
    for (let i = 1; i <= days; i++) hb.insertAdjacentHTML('beforeend', `<option value="${i}">Tanggal ${i}</option>`);
    hb.value = keep && +keep <= days ? keep : '0';
  }
  fillHb();
  bln.addEventListener('change', fillHb);
  return { bulan: () => bln.value, cabang: () => cab.value, hb: () => hb.value };
}

/** Isi ulang dropdown "dari/sampai" (1..n), pertahankan pilihan lama kalau masih valid. */
function fillSpanOptions(n, labelFn = String) {
  ['#f-dari', '#f-sampai'].forEach((sel, idx) => {
    const el = $(sel); if (!el) return;
    const keep = +el.value || 0;
    el.innerHTML = '';
    for (let i = 1; i <= n; i++) el.insertAdjacentHTML('beforeend', `<option value="${i}">${labelFn(i)}</option>`);
    el.value = (keep >= 1 && keep <= n) ? keep : (idx === 0 ? 1 : n);
  });
}
/** Daftar indeks (1..n) yang harus ditampilkan, sesuai mode #f-tampil (semua/rentang/ringkas). */
function spanFor(n) {
  const mode = $('#f-tampil')?.value || 'semua';
  if (mode === 'ringkas') return [];
  if (mode === 'rentang') {
    let a = +$('#f-dari').value || 1, b = +$('#f-sampai').value || n;
    if (a > b) [a, b] = [b, a];
    const arr = []; for (let i = a; i <= b; i++) arr.push(i); return arr;
  }
  const arr = []; for (let i = 1; i <= n; i++) arr.push(i); return arr;
}
function bindTampilControls(onChange) {
  const t = $('#f-tampil'); if (!t) return;
  t.addEventListener('change', () => { document.querySelectorAll('.f-rentang').forEach(e => e.hidden = t.value !== 'rentang'); onChange(); });
  $('#f-dari')?.addEventListener('change', onChange);
  $('#f-sampai')?.addEventListener('change', onChange);
}

pages.sales_harian = async () => {
  const F = await initMonthCabang();
  const gramOn = () => $('#f-gram').checked;
  let last = null;

  function dayCols(hari) {
    const cols = [{ k: 'nama', label: 'Nama', fmt: (v, r) => `${esc(v)}<div class="sub">${esc(r.grade || '')}</div>` }];
    spanFor(hari).forEach(i => cols.push({ k: 'd' + i, label: String(i), n: 1, nosort: true, fmt: v => v ? (gramOn() ? `${int(v[0])}<div class="sub">${gr(v[1])}</div>` : int(v[0])) : '' }));
    cols.push({ k: 'tp', label: 'Total Pcs', n: 1, fmt: int }, { k: 'tg', label: 'Total Gram', n: 1, fmt: gr },
      { k: 'target', label: 'Target Pcs', n: 1, fmt: v => v || '–' },
      { k: 'fp', label: 'Forecast Pcs', n: 1, cls: 'col-forecast', fmt: v => v == null ? '–' : nf2.format(v) },
      { k: 'pct', label: '% Capaian', n: 1, cls: 'col-forecast', fmt: v => v == null ? '–' : `<span class="delta ${v >= 100 ? 'up' : v < 70 ? 'down' : ''}">${nf2.format(v)}%</span>` });
    return cols;
  }
  function diamondCols(hari) {
    const cols = [{ k: 'nama', label: 'Nama' }];
    spanFor(hari).forEach(i => cols.push({ k: 'd' + i, label: String(i), n: 1, nosort: true, fmt: v => v && v[0] ? `${int(v[0])}<div class="sub">${rp(v[1])}</div>` : '' }));
    cols.push({ k: 'tp', label: 'Total Pcs', n: 1, fmt: int }, { k: 'tn', label: 'Total Nominal', n: 1, fmt: rp });
    return cols;
  }
  function render() {
    if (!last) return;
    const j = last;
    const rows = j.rows.map(r => { const o = { nama: r.nama, grade: r.grade, tp: r.tp, tg: r.tg, target: r.target, fp: r.fp, pct: r.pct, _pin: r.nama === 'Lainnya' }; r.d.forEach((v, i) => o['d' + (i + 1)] = v); return o; });
    const foot = { nama: 'Total', tp: j.total.tp, tg: j.total.tg, target: j.total.target, fp: j.total.fp, pct: j.total.pct };
    j.total.d.forEach((v, i) => foot['d' + (i + 1)] = v);
    table($('#t-harian'), dayCols(j.meta.hari), rows, foot);
    const drows = j.diamond.rows.map(r => { const o = { nama: r.nama, tp: r.tp, tn: r.tn, _pin: r.nama === 'Lainnya' }; r.d.forEach((v, i) => o['d' + (i + 1)] = v); return o; });
    const dfoot = { nama: 'Total', tp: j.diamond.total.tp, tn: j.diamond.total.tn };
    j.diamond.total.d.forEach((v, i) => dfoot['d' + (i + 1)] = v);
    table($('#t-diamond'), diamondCols(j.meta.hari), drows, dfoot, 'Tidak ada penjualan diamond bulan ini.');
  }
  async function load() {
    await guarded($('#t-harian').closest('.panel'), async () => {
      const j = await api('sales_harian', { bulan: F.bulan(), cabang: F.cabang(), tipe: 'toko', hb: F.hb() });
      last = j;
      fillSpanOptions(j.meta.hari);
      $('#range-info').textContent = `Hari berjalan: ${j.meta.hb} dari ${j.meta.hari} hari` + (j.meta.hb_manual ? ' (manual)' : ` (otomatis, data terakhir ${j.meta.last ? idDate(j.meta.last) : '-'})`);
      kpis($('#kpis'), [
        { label: 'Total pcs', value: int(j.total.tp), sub: gr(j.total.tg) },
        { label: 'Target pcs', value: int(j.total.target) },
        { label: 'Forecast pcs sebulan', value: j.total.fp == null ? '–' : nf2.format(j.total.fp), sub: j.total.pct != null ? `${nf2.format(j.total.pct)}% dari target` : '' },
        { label: 'Gramasi (emas)', value: gr(j.total.tg), sub: `target ${gr(j.emas.target)}${j.emas.inherited ? ' (bulan lalu)' : ''}` },
        { label: 'Forecast gram', value: j.emas.forecast == null ? '–' : gr(j.emas.forecast), sub: j.emas.capaian != null ? `${nf2.format(j.emas.capaian)}% dari target emas` : '' },
      ]);
      const rk = j.ringkas, mLbl = ['Pcs', 'Gram', 'Rupiah'], fmts = [int, gr, rp];
      kpis($('#kpis-prev'), [0, 1, 2].map(k => ({
        label: `${mLbl[k]} vs ${idDate(rk.prev_bulan + '-01').split(' ').slice(1).join(' ')}`,
        value: rk.forecast[k] == null ? '–' : fmts[k](rk.forecast[k]),
        sub: `${trendWord(rk.selisih_pct[k])} dari bulan lalu (${fmts[k](rk.prev[k])})`,
      })).concat([{ label: 'Emas vs bulan lalu', value: j.emas.selisih_prev == null ? '–' : gr(j.emas.selisih_prev), sub: `${trendWord(j.emas.selisih_prev_pct)}, bulan lalu ${gr(j.emas.prev_gram)}` }]));
      render();
      kesimpulan($('#kesimpulan'), 'Kesimpulan', [
        `Forecast bulan ${j.meta.bulan}: <b>${int(rk.forecast[0] ?? j.total.tp)} pcs</b>, <b>${gr(rk.forecast[1] ?? j.total.tg)}</b>, <b>${rp(rk.forecast[2] ?? j.total.tr)}</b> — dibanding bulan lalu ${trendWord(rk.selisih_pct[2])}.`,
        `Target emas bulan ini ${gr(j.emas.target)}${j.emas.inherited ? ' (dari bulan lalu, belum diisi target baru)' : ''}, forecast pencapaian ${j.emas.capaian != null ? nf2.format(j.emas.capaian) + '%' : 'belum bisa dihitung'}.`,
        j.total.pct != null ? `Forecast pcs terhadap target: <b>${nf2.format(j.total.pct)}%</b>.` : 'Target pcs belum diisi untuk sebagian/semua sales — isi lewat Master Sales agar forecast bisa dibandingkan.',
      ]);
    });
  }
  $('#filters').addEventListener('change', load);
  $('#f-gram').addEventListener('change', render);
  bindTampilControls(render);
  load();
};

pages.sales_mingguan = async () => {
  const F = await initMonthCabang();
  let last = null;

  function weekCols(weeks) {
    const cols = [{ k: 'nama', label: 'Nama', fmt: (v, r) => `${esc(v)}<div class="sub">${esc(r.grade || '')}</div>` }];
    spanFor(weeks.length).forEach(i => { const w = weeks[i - 1]; cols.push({ k: 'w' + i, label: `Minggu ${w.no}`, n: 1, nosort: true,
      fmt: v => v ? `${int(v[0])} pcs<div class="sub">${gr(v[1])} · ${rp(v[2])}</div>` : '' }); });
    cols.push({ k: 'tp', label: 'Total Pcs', n: 1, fmt: int }, { k: 'tg', label: 'Total Gram', n: 1, fmt: gr }, { k: 'tr', label: 'Total Rupiah', n: 1, fmt: rp },
      { k: 'sel', label: 'vs Bulan Lalu', n: 1, cls: 'col-selisih', nosort: true, fmt: (v, row) => {
        const [p, g, ru] = row.sel_pct || [null, null, null];
        return `<div class="mini">Pcs ${trendWord(p) || '–'}</div><div class="mini">Gram ${trendWord(g) || '–'}</div><div class="mini">Rp ${trendWord(ru) || '–'}</div>`;
      } });
    return cols;
  }
  function render() {
    if (!last) return;
    const j = last;
    const rows = j.rows.map(row => { const o = { nama: row.nama, grade: row.grade, tp: row.tp, tg: row.tg, tr: row.tr, sel_pct: row.sel_pct, _pin: row.nama === 'Lainnya' }; row.w.forEach((v, i) => o['w' + (i + 1)] = v); return o; });
    const totFoot = { nama: 'Total', tp: j.total.tp, tg: j.total.tg, tr: j.total.tr };
    j.total.w.forEach((v, i) => totFoot['w' + (i + 1)] = v);
    const prevFoot = { tp: j.ringkas.prev[0], tg: j.ringkas.prev[1], tr: j.ringkas.prev[2] };
    table($('#t-mingguan'), weekCols(j.weeks), rows, [{ label: 'Total', obj: totFoot }, { label: `Bulan Lalu (${j.meta.prev_bulan})`, obj: prevFoot, cls: 'col-prev' }]);
  }
  async function load() {
    await guarded($('#t-mingguan').closest('.panel'), async () => {
      const j = await api('sales_mingguan', { bulan: F.bulan(), cabang: F.cabang(), tipe: 'toko', hb: F.hb() });
      last = j;
      fillSpanOptions(j.weeks.length, i => `Minggu ${j.weeks[i - 1].no}`);
      $('#range-info').textContent = `Hari berjalan: ${j.meta.hb} dari ${j.meta.hari} hari, dibandingkan ${idDate(j.meta.prev_bulan + '-01')}`;
      const r = j.ringkas, mLbl = ['Pcs', 'Gram', 'Rupiah'], fmts = [int, gr, rp];
      kpis($('#kpis'), [0, 1, 2].map(k => ({
        label: `Total ${mLbl[k]}`, value: fmts[k](k === 0 ? j.total.tp : k === 1 ? j.total.tg : j.total.tr),
        sub: `${trendWord(k === 0 ? pctDiff2(j.total.tp, r.prev[0]) : k === 1 ? pctDiff2(j.total.tg, r.prev[1]) : pctDiff2(j.total.tr, r.prev[2]))} dari bulan lalu (${fmts[k](r.prev[k])})`,
      })).concat([0, 1, 2].map(k => ({
        label: `Forecast ${mLbl[k]} (%)`, value: r.forecast[k] == null ? '–' : fmts[k](r.forecast[k]),
        sub: r.selisih_pct[k] != null ? `${trendWord(r.selisih_pct[k])} vs bulan lalu` : 'Data hari berjalan belum cukup',
      }))));
      render();
      const lbl = j.weeks.map(w => `Minggu ${w.no}`);
      chart('c-forecast', { data: { labels: lbl, datasets: [
        { type: 'bar', label: 'Rupiah', data: j.total.w.map(w => w[2]), backgroundColor: C.brass, borderRadius: 3, yAxisID: 'y' },
        { type: 'line', label: 'Pcs', data: j.total.w.map(w => w[0]), borderColor: C.ink, backgroundColor: C.ink, yAxisID: 'y1', tension: .25, pointRadius: 3 },
        { type: 'line', label: 'Gram', data: j.total.w.map(w => w[1]), borderColor: C.teal, backgroundColor: C.teal, yAxisID: 'y2', tension: .25, pointRadius: 3, borderDash: [4, 3] }] },
        options: { scales: {
          y: yScale({ ticks: { callback: v => nf.format(v / 1e6) + ' jt' } }),
          y1: yScale({ position: 'right', grid: { drawOnChartArea: false } }),
          y2: yScale({ position: 'right', grid: { drawOnChartArea: false }, display: false }) } } });
      const bySales = [...j.rows].filter(x => x.tr > 0).sort((a, b) => b.tr - a.tr).slice(0, 15);
      chart('c-sales', { type: 'bar', data: { labels: bySales.map(x => x.nama), datasets: [{ label: 'Rupiah', data: bySales.map(x => x.tr), backgroundColor: bySales.map(x => x.nama === 'Lainnya' ? C.mute : C.brass), borderRadius: 3 }] },
        options: { indexAxis: 'y', scales: { x: yScale({ ticks: { callback: v => nf.format(v / 1e6) + ' jt' } }) }, plugins: { legend: { display: false } } } });
      const topSales = [...j.rows].filter(x => x.nama !== 'Lainnya').sort((a, b) => b.tr - a.tr)[0];
      kesimpulan($('#kesimpulan'), 'Kesimpulan', [
        `Bulan ${j.meta.bulan} sejauh ini: <b>${int(j.total.tp)} pcs</b>, <b>${gr(j.total.tg)}</b>, <b>${rp(j.total.tr)}</b> — dibanding periode sama bulan lalu ${trendWord(pctDiff2(j.total.tr, r.prev[2]))}.`,
        `Forecast akhir bulan: ${r.forecast[0] == null ? 'belum bisa dihitung' : `<b>${int(r.forecast[0])} pcs</b>, <b>${gr(r.forecast[1])}</b>, <b>${rp(r.forecast[2])}</b>`}${r.selisih_pct[2] != null ? `, rupiah ${trendWord(r.selisih_pct[2])} dari bulan lalu` : ''}.`,
        topSales ? `Sales toko terbaik bulan ini: <b>${esc(topSales.nama)}</b> dengan ${rp(topSales.tr)} (${int(topSales.tp)} pcs).` : 'Belum ada sales toko dengan transaksi bulan ini.',
      ]);
    });
  }
  $('#filters').addEventListener('change', load);
  bindTampilControls(render);
  load();
};

pages.grading = async () => {
  const bln = $('#f-bulan'), mode = $('#f-mode'), cab = $('#f-cabang');
  const now = new Date(); bln.value = `${now.getFullYear()}-${pad(now.getMonth() + 1)}`;
  try { const j = await api('cabang_list'); j.rows.forEach(c => cab.insertAdjacentHTML('beforeend', `<option value="${esc(c)}">${esc(c)}</option>`)); } catch {}
  const statusTag = s => s == null ? '<span class="sub">–</span>' : s === 'tercapai' ? '<span class="tag toko">Tercapai</span>' : '<span class="tag luar">Belum tercapai</span>';
  let last = null;
  function cols(j) {
    const c = [{ k: 'nama', label: 'Nama', fmt: (v, r) => `${esc(v)}<div class="sub">${esc(r.grade || (r.nama === 'Lainnya' ? '' : 'Belum ada grade'))}</div>` }];
    if (j.buckets.length > 1) j.buckets.forEach((bk, i) => c.push({ k: 'b' + i, label: bk.label, n: 1, nosort: true, cls: 'col-prev',
      fmt: v => !v || !v.point ? '0' : `${int(v.point)}<div class="sub">${v.point_pcs} pcs + ${v.point_lm1}/${v.point_lm2}/${v.point_lm3} lm + ${v.point_trimas} trimas</div>` }));
    c.push(
      { k: 'point_pcs', label: 'Poin Pcs', n: 1, fmt: int },
      { k: 'point_lm1', label: 'LM <50gr', n: 1, fmt: int },
      { k: 'point_lm2', label: 'LM 50gr', n: 1, fmt: int },
      { k: 'point_lm3', label: 'LM 100gr', n: 1, fmt: int },
      { k: 'point_trimas', label: 'Poin Trimas', n: 1, fmt: int },
      { k: 'point', label: 'Total Poin', n: 1, fmt: int },
      { k: 'target', label: 'Target', n: 1, cls: 'col-prev', fmt: v => v == null ? '–' : int(v) },
      { k: 'pct', label: '% Capaian', n: 1, cls: 'col-forecast', fmt: v => v == null ? '–' : `<span class="delta ${v >= 100 ? 'up' : v < 70 ? 'down' : ''}">${nf2.format(v)}%</span>` },
      { k: 'status', label: 'Status', cls: 'col-selisih', nosort: true, fmt: statusTag },
      // Tangga level berdasarkan poin: "Tercapai" = level tertinggi yang sudah dilewati (persen terhadap targetnya),
      // "Belum tercapai" = level berikutnya yang harus dikejar untuk upgrade.
      { k: 'lv_tercapai', label: 'Tercapai', cls: 'col-forecast', nosort: true, fmt: (v, r) => {
        const l = r.level; if (!l) return '<span class="sub">–</span>';
        return l.tercapai ? `<span class="delta up">${nf2.format(l.tercapai.pct)}%</span> <b>${esc(l.tercapai.grade)}</b>` : '<span class="sub">belum capai level apa pun</span>';
      } },
      { k: 'lv_belum', label: 'Belum tercapai', cls: 'col-prev', nosort: true, fmt: (v, r) => {
        const l = r.level; if (!l) return '<span class="sub">–</span>';
        if (l.puncak) return '<span class="tag toko">Level tertinggi</span>';
        if (!l.belum) return '<span class="sub">–</span>';
        return `${l.tercapai ? 'Upgrade ke' : 'Menuju'} <b>${esc(l.belum.grade)}</b><div class="sub">${nf2.format(l.belum.pct)}% · kurang ${int(l.belum.kurang)} poin</div>`;
      } });
    return c;
  }
  async function load() {
    await guarded($('#t-grading').closest('.panel'), async () => {
      const j = await api('grading', { mode: mode.value, bulan: bln.value, cabang: cab.value });
      last = j;
      const periodLbl = j.mode === 'triwulan' ? 'Triwulan' : 'Bulanan';
      $('#range-info').textContent = `${periodLbl}: ${idDate(j.from)} – ${idDate(j.to)}`;
      const t = j.total;
      kpis($('#kpis'), [
        { label: 'Total poin', value: int(t.point), sub: `${int(t.point_pcs)} pcs, ${int(t.point_lm1 + t.point_lm2 + t.point_lm3)} LM, ${int(t.point_trimas)} Trimas` },
        { label: 'Target total', value: int(t.target) },
        { label: 'Sales tercapai target', value: int(t.tercapai), sub: `${int(t.belum)} belum tercapai` },
        { label: 'Total rupiah', value: rp(t.rupiah), sub: `${int(t.pcs)} pcs` },
      ]);
      const rows = j.rows.map(r => { const o = { ...r, _pin: r.nama === 'Lainnya' }; r.bulan.forEach((b, i) => o['b' + i] = b); return o; });
      const foot = { ...t }; t.bulan.forEach((b, i) => foot['b' + i] = b);
      table($('#t-grading'), cols(j), rows, foot);
      const chartRows = [...j.rows].filter(r => r.point > 0).sort((a, b) => b.point - a.point).slice(0, 15);
      chart('c-grading', { type: 'bar', data: { labels: chartRows.map(r => r.nama), datasets: [
        { label: 'Poin', data: chartRows.map(r => r.point), backgroundColor: chartRows.map(r => r.nama === 'Lainnya' ? C.mute : r.status === 'belum' ? '#c77' : C.brass), borderRadius: 3 }] },
        options: { indexAxis: 'y', scales: { x: yScale() }, plugins: { legend: { display: false } } } });
      const top = j.rows.filter(r => r.nama !== 'Lainnya')[0];
      const under = j.rows.filter(r => r.status === 'belum');
      kesimpulan($('#kesimpulan'), 'Kesimpulan', [
        `Periode ${periodLbl.toLowerCase()} ${idDate(j.from)} – ${idDate(j.to)}: total <b>${int(t.point)} poin</b> dari ${int(j.rows.filter(r => r.nama !== 'Lainnya').length)} sales toko.`,
        top ? `Poin tertinggi: <b>${esc(top.nama)}</b> (${int(top.point)} poin${top.target ? `, ${nf2.format(top.pct)}% dari target` : ''}).` : 'Belum ada data pada periode ini.',
        (() => {
          const lc = t.level_count || {}; const urut = [...(j.grades || [])];
          const bagian = urut.filter(g => lc[g]).map(g => `${esc(g)} ${int(lc[g])}`);
          if (lc._none) bagian.push(`belum capai level terendah ${int(lc._none)}`);
          return bagian.length ? `Sebaran level (berdasarkan poin): ${bagian.join(', ')} sales.` : '';
        })(),
        t.tercapai || t.belum ? `<b>${int(t.tercapai)} sales</b> mencapai target, <b>${int(t.belum)} sales</b> belum — ${under.length ? 'termasuk ' + under.slice(0, 3).map(r => esc(r.nama)).join(', ') + (under.length > 3 ? ', dll' : '') : ''}.` : 'Belum ada sales dengan grade & target yang bisa dibandingkan — isi grade lewat Master Sales dan target lewat Master Target.',
      ]);
    });
  }
  $('#filters').addEventListener('change', load);
  $('#btn-xlsx').addEventListener('click', () => {
    if (!last) return;
    exportTableExcel($('#t-grading'), `grading-sales_${last.from}_${last.to}.xlsx`, 'Grading Sales');
  });
  $('#btn-pdf').addEventListener('click', () => {
    if (!last) return;
    const periodLbl = last.mode === 'triwulan' ? 'Triwulan' : 'Bulanan';
    exportTablePDF($('#t-grading'), `grading-sales_${last.from}_${last.to}.pdf`, 'Grading Sales', `${periodLbl}: ${idDate(last.from)} – ${idDate(last.to)}`);
  });
  load();
};

pages.master_target = () => {
  const form = $('#target-grade-form');
  async function load() {
    const j = await api('grade_target_list');
    form.innerHTML = j.grades.map(g => `<label>${esc(g)} <input type="number" min="0" step="1" name="${esc(g)}" value="${j.targets[g] ?? 0}"></label>`).join('')
      + '<button class="btn primary" type="submit">Simpan target</button>';
  }
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData();
    new FormData(form).forEach((v, k) => fd.append(`target[${k}]`, v));
    try { await api('grade_target_save', fd, true); toast('Target grade disimpan.'); } catch (err) { toast(err.message, true); }
  });
  load().catch(e => toast(e.message, true));
};

pages.trimas = () => {
  const F = initFilters(load);
  const kondisiLabel = { 'LUAR JUAL SAJA': 'Luar Jual Saja', 'DOLMAS': 'Dolmas' };
  async function load() {
    const r = F.range();
    await guarded($('#t-sales').closest('.panel'), async () => {
      const j = await api('trimas', { from: r.from, to: r.to });
      kpis($('#kpis'), [
        { label: 'Total pcs', value: int(j.total.pcs) },
        { label: 'Total gramasi', value: gr(j.total.gram) },
        { label: 'Total nilai', value: rp(j.total.rupiah) },
        ...j.kondisi.map(k => ({ label: kondisiLabel[k.kondisi] || k.kondisi, value: int(k.pcs) + ' pcs', sub: `${gr(k.gram)} · ${rp(k.rupiah)}` })),
      ]);
      table($('#t-kondisi'), [
        { k: 'kondisi', label: 'Kondisi', fmt: v => kondisiLabel[v] || v },
        { k: 'pcs', label: 'Pcs', n: 1, fmt: int }, { k: 'gram', label: 'Gramasi', n: 1, fmt: gr }, { k: 'rupiah', label: 'Nilai', n: 1, fmt: rp },
      ], j.kondisi, j.total, 'Tidak ada data pada periode ini.');
      chart('c-kondisi', { type: 'doughnut', data: { labels: j.kondisi.map(k => kondisiLabel[k.kondisi] || k.kondisi),
        datasets: [{ data: j.kondisi.map(k => k.rupiah), backgroundColor: [C.brass, C.teal] }] },
        options: { plugins: { legend: { position: 'bottom' } } } });
      const sales = j.sales.map(s => ({ ...s, _pin: s.nama === 'Lainnya' }));
      table($('#t-sales'), [
        { k: 'nama', label: 'Sales' }, { k: 'tipe', label: 'Jenis', fmt: tag },
        { k: 'pcs', label: 'Pcs', n: 1, fmt: int }, { k: 'gram', label: 'Gramasi', n: 1, fmt: gr }, { k: 'rupiah', label: 'Nilai', n: 1, fmt: rp },
      ], sales, null, 'Tidak ada sales pada periode ini.');
      chart('c-harian', { data: { labels: j.harian.map(d => idDate(d.tgl).split(' ').slice(0, 2).join(' ')), datasets: [
        { type: 'bar', label: 'Nilai', data: j.harian.map(d => d.rupiah), backgroundColor: C.brass, borderRadius: 3, yAxisID: 'y' },
        { type: 'line', label: 'Pcs', data: j.harian.map(d => d.pcs), borderColor: C.ink, backgroundColor: C.ink, yAxisID: 'y1', tension: .25, pointRadius: 2 }] },
        options: { scales: { y: yScale({ ticks: { callback: v => nf.format(v / 1e6) + ' jt' } }), y1: yScale({ position: 'right', grid: { drawOnChartArea: false } }) } } });
      $('#detail-info').textContent = j.detail.length >= 500 ? 'Menampilkan 500 transaksi terbaru.' : `${int(j.detail.length)} transaksi.`;
      table($('#t-detail'), [
        { k: 'tgl', label: 'Tanggal', fmt: v => idDate(v) }, { k: 'no_faktur', label: 'No faktur' }, { k: 'sales', label: 'Sales' },
        { k: 'nama_customer', label: 'Customer' }, { k: 'kondisi', label: 'Kondisi', fmt: v => kondisiLabel[v] || v }, { k: 'nama_barang', label: 'Barang' },
        { k: 'berat', label: 'Berat', n: 1, fmt: v => gr(+v) }, { k: 'harga_total', label: 'Nilai', n: 1, fmt: v => rp(+v) },
      ], j.detail);
      const topSales = j.sales.filter(s => s.nama !== 'Lainnya')[0];
      const periode = r.from === r.to ? idDate(r.from) : `${idDate(r.from)} – ${idDate(r.to)}`;
      kesimpulan($('#kesimpulan'), 'Kesimpulan', [
        `Periode ${periode}: total Trimas <b>${int(j.total.pcs)} pcs</b> senilai <b>${rp(j.total.rupiah)}</b> (${gr(j.total.gram)}).`,
        j.kondisi.map(k => `${kondisiLabel[k.kondisi]}: ${int(k.pcs)} pcs (${rp(k.rupiah)})`).join(' · '),
        topSales ? `Sales toko dengan Trimas terbanyak: <b>${esc(topSales.nama)}</b> (${rp(topSales.rupiah)}).` : 'Belum ada transaksi Trimas dari sales toko pada periode ini.',
      ]);
    });
  }
  load();
};

pages.perbandingan = () => {
  const now = new Date(), curYm = `${now.getFullYear()}-${pad(now.getMonth() + 1)}`;
  const addM = (ym, n) => { const [y, m] = ym.split('-').map(Number), d = new Date(y, m - 1 + n, 1); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}`; };
  const MAXP = 8;
  // pilihan periode (bebas): bulan = daftar YYYY-MM; rentang = daftar [dari, sampai]
  const st = {
    bulan: [addM(curYm, -12), addM(curYm, -1), curYm],
    rentang: [[ymd(new Date(now.getFullYear(), now.getMonth() - 1, 1)), ymd(new Date(now.getFullYear(), now.getMonth(), 0))], [ymd(new Date(now.getFullYear(), now.getMonth(), 1)), ymd(now)]],
  };
  const sel = { base: null, target: null };
  let last = null;
  const modeEl = $('#c-mode'), caraEl = $('#c-cara'), hariEl = $('#c-hari');
  hariEl.value = now.getDate();

  const rmBtn = i => `<button type="button" class="btn sm danger" data-rm="${i}" aria-label="Hapus periode">✕</button>`;
  function renderPeriods() {
    const m = modeEl.value, list = st[m];
    document.querySelectorAll('.c-bulan').forEach(e => { e.hidden = m !== 'bulan'; });
    document.querySelector('.c-hari').hidden = m !== 'bulan' || caraEl.value !== 'h2h';
    $('#periods').innerHTML = list.map((v, i) => m === 'bulan'
      ? `<div class="prow"><span class="pidx">${i + 1}</span><input type="month" data-i="${i}" value="${esc(v)}" aria-label="Bulan ${i + 1}">${list.length > 1 ? rmBtn(i) : ''}</div>`
      : `<div class="prow"><span class="pidx">${i + 1}</span><input type="date" data-i="${i}" data-f="0" value="${esc(v[0])}" aria-label="Dari"><span>s/d</span><input type="date" data-i="${i}" data-f="1" value="${esc(v[1])}" aria-label="Sampai">${list.length > 1 ? rmBtn(i) : ''}</div>`).join('');
    $('#p-add').disabled = list.length >= MAXP;
  }

  const idm = { pcs: 'pcs', gram: 'gram', rupiah: 'rupiah' };
  const sgn = (v, f) => `${v > 0 ? '+' : '−'}${f(Math.abs(v))}`;
  function dcell(a, b, m) {
    let d = a - b; if (m === 'gram') d = Math.round(d * 100) / 100;
    if (!d) return '<span class="zero">–</span>';
    const cls = d > 0 ? 'up' : 'down', pct = pctDiff2(a, b);
    return `<span class="delta ${cls}">${sgn(d, fmtN[m])}</span><small class="delta ${cls}">${pct == null ? 'baru' : (pct > 0 ? '+' : '') + nf2.format(pct) + '%'}</small>`;
  }

  function render(j) {
    const P = j.periods, n = P.length;
    if (sel.base == null || sel.target == null || sel.base >= n || sel.target >= n || sel.base === sel.target) { sel.target = n - 1; sel.base = Math.max(0, n - 2); }
    const showKes = n >= 2;
    // pilihan pembanding untuk kolom KESIMPULAN
    const opts = k => P.map((p, i) => `<option value="${i}" ${sel[k] === i ? 'selected' : ''}>${esc(p.label)}${p.truncated ? ' (' + esc(p.note) + ')' : ''}</option>`).join('');
    $('#kes-pick').innerHTML = showKes ? `Kolom KESIMPULAN = selisih <select id="k-target">${opts('target')}</select> dibanding <select id="k-base">${opts('base')}</select>` : '<span>Pilih minimal 2 periode untuk melihat kolom kesimpulan.</span>';
    // ----- tabel -----
    const head1 = `<tr><th rowspan="2">SALES</th>${P.map(p => `<th colspan="3" class="sep">${esc(p.label)}<small>${esc(p.note)}</small></th>`).join('')}${showKes ? '<th colspan="3" class="sep kes-h">KESIMPULAN</th>' : ''}</tr>`;
    const head2 = `<tr>${P.map(() => ['PCS', 'GRAM', 'RP'].map((t, i) => `<th class="sub-h ${i === 0 ? 'sep' : ''}">${t}</th>`).join('')).join('')}${showKes ? ['PCS', 'GRAM', 'RP'].map((t, i) => `<th class="sub-h kes-h ${i === 0 ? 'sep' : ''}">${t}</th>`).join('') : ''}</tr>`;
    const M3 = ['pcs', 'gram', 'rupiah'];
    const rowHtml = (label, vals, cls, zero) => `<tr class="${cls || ''}"><td>${label}</td>${vals.map(v => M3.map((m, i) => `<td class="n ${i === 0 ? 'sep' : ''}">${zero ? zdash(v[m], fmtN[m]) : fmtN[m](v[m])}</td>`).join('')).join('')}${showKes ? M3.map((m, i) => `<td class="n kes-c ${i === 0 ? 'sep' : ''}">${dcell(vals[sel.target][m], vals[sel.base][m], m)}</td>`).join('') : ''}</tr>`;
    const body = j.sales.map(s => rowHtml(esc(s.nama), s.v, s.kode === 'OTHER' ? 'pinned' : '', true)).join('')
      || `<tr><td class="empty" colspan="${1 + 3 * (n + (showKes ? 1 : 0))}">Tidak ada penjualan pada periode yang dipilih.</td></tr>`;
    const foot = j.sales.length ? `<tfoot>${rowHtml('TOTAL', P.map(p => p.total), '', false)}</tfoot>` : '';
    $('#t-cmp').innerHTML = `<thead>${head1}${head2}</thead><tbody>${body}</tbody>${foot}`;
    // ----- diagram -----
    const metric = $('#c-metric').value;
    const mName = { rupiah: 'Rupiah', gram: 'Gram', pcs: 'Pcs' }[metric];
    const top = j.sales.slice(0, 10);
    const pal = ['#9fb3c8', C.teal, C.brass, C.ink, '#c0504d', '#7a6ba8', '#5aa469', '#d98c3f'];
    $('#chart-sub').textContent = `${mName} per sales, ${top.length < j.sales.length ? '10 sales teratas, ' : ''}setiap batang = satu periode.`;
    chart('c-cmp', { type: 'bar', data: { labels: top.map(s => s.nama), datasets: P.map((p, i) => ({
      label: p.label + (p.truncated ? ` (${p.note})` : ''), data: top.map(s => s.v[i][metric]), backgroundColor: pal[i % pal.length], borderRadius: 3 })) },
      options: { scales: { y: yScale({ ticks: { callback: v => metric === 'rupiah' ? nf.format(v / 1e6) + ' jt' : nf.format(v) } }), x: { ticks: { maxRotation: 50, minRotation: 30 } } },
        plugins: { tooltip: { callbacks: { label: c => `${c.dataset.label}: ${metric === 'rupiah' ? rp(c.parsed.y) : metric === 'gram' ? gr(c.parsed.y) : int(c.parsed.y) + ' pcs'}` } } } } });
    // ----- info periode & kesimpulan teks -----
    const fr = p => p.from === p.to ? idDate(p.from) : `${idDate(p.from)} – ${idDate(p.to)}`;
    const trunc = P.find(p => p.truncated);
    let cara = '';
    if (j.mode === 'bulan') {
      if (caraEl.value === 'auto') cara = trunc ? `Bulan berjalan ikut dibandingkan, jadi semua bulan dihitung H2H (${trunc.note}).` : 'Semua bulan yang dipilih sudah selesai, jadi dibandingkan full bulan.';
      else if (caraEl.value === 'h2h') cara = `H2H: ${trunc ? trunc.note : 'tanggal 1 sampai akhir bulan'} pada setiap bulan.`;
      else cara = 'Full bulan.';
    }
    $('#range-info').textContent = (j.mode === 'bulan' ? P.map(p => `${p.label}: ${fr(p)}`) : P.map(p => `${p.label} (${p.note})`)).join('  ·  ') + (cara ? `  —  ${cara}` : '');
    const lines = [];
    const cap = p => p.label + (p.truncated ? ` (${p.note})` : '');
    if (showKes) {
      const t = P[sel.target], b = P[sel.base];
      const pr = pctDiff2(t.total.rupiah, b.total.rupiah);
      lines.push(`Penjualan <b>${esc(cap(t))}</b> sebesar <b>${rp(t.total.rupiah)}</b>${pr == null ? '' : ', ' + trendWord(pr)} dibanding <b>${esc(cap(b))}</b> (${rp(b.total.rupiah)}).`);
      lines.push(`Pcs ${int(t.total.pcs)} (pembanding ${int(b.total.pcs)}, selisih ${t.total.pcs - b.total.pcs >= 0 ? '+' : '−'}${int(Math.abs(t.total.pcs - b.total.pcs))}); gramasi ${gr(t.total.gram)} (pembanding ${gr(b.total.gram)}).`);
      const diffs = j.sales.filter(s => s.kode !== 'OTHER').map(s => ({ s, d: s.v[sel.target].rupiah - s.v[sel.base].rupiah })).filter(x => x.d !== 0);
      const up = [...diffs].sort((a, b) => b.d - a.d)[0], down = [...diffs].sort((a, b) => a.d - b.d)[0];
      if (up && up.d > 0) lines.push(`Kenaikan rupiah terbesar: <b>${esc(up.s.nama)}</b> (+${rp(up.d)}).`);
      if (down && down.d < 0) lines.push(`Penurunan rupiah terbesar: <b>${esc(down.s.nama)}</b> (−${rp(Math.abs(down.d))}).`);
    } else {
      lines.push(`Penjualan <b>${esc(cap(P[0]))}</b>: ${rp(P[0].total.rupiah)}, ${int(P[0].total.pcs)} pcs, ${gr(P[0].total.gram)}.`);
    }
    if (n > 2) {
      const best = P.reduce((a, p) => p.total.rupiah > a.total.rupiah ? p : a, P[0]);
      lines.push(`Dari ${n} periode, penjualan tertinggi pada <b>${esc(cap(best))}</b> (${rp(best.total.rupiah)}).`);
    }
    kesimpulan($('#kesimpulan'), 'Kesimpulan', lines);
  }

  async function load() {
    const m = modeEl.value, params = { mode: m, tipe: $('#c-tipe').value, kat: $('#c-kat').value };
    if (m === 'bulan') {
      const ms = [...new Set(st.bulan.filter(v => /^\d{4}-(0[1-9]|1[0-2])$/.test(v)))];
      if (!ms.length) { toast('Pilih minimal satu bulan.', true); return; }
      Object.assign(params, { months: ms.join(','), cara: caraEl.value, hari: hariEl.value || 1 });
    } else {
      const rs = st.rentang.filter(r => r[0] && r[1]).map(r => `${r[0]}..${r[1]}`);
      if (!rs.length) { toast('Isi tanggal dari dan sampai pada minimal satu periode.', true); return; }
      params.ranges = rs.join('|');
    }
    await guarded($('#t-cmp').closest('.panel'), async () => { last = await api('compare2', params); render(last); });
  }

  $('#filters').addEventListener('change', e => {
    if (e.target.id === 'c-metric') { if (last) render(last); return; }
    if (e.target.id === 'c-mode' || e.target.id === 'c-cara') renderPeriods();
    load();
  });
  $('#periods').addEventListener('change', e => {
    const i = +e.target.dataset.i; if (Number.isNaN(i)) return;
    if (modeEl.value === 'bulan') st.bulan[i] = e.target.value; else st.rentang[i][+e.target.dataset.f] = e.target.value;
    load();
  });
  $('#periods').addEventListener('click', e => {
    const b = e.target.closest('[data-rm]'); if (!b) return;
    st[modeEl.value].splice(+b.dataset.rm, 1); sel.base = sel.target = null; renderPeriods(); load();
  });
  $('#p-add').addEventListener('click', () => {
    if (modeEl.value === 'bulan') {
      const used = new Set(st.bulan); let c = addM(st.bulan.length ? [...st.bulan].filter(Boolean).sort()[0] : curYm, -1);
      while (used.has(c)) c = addM(c, -1);
      st.bulan.unshift(c);
    } else st.rentang.push([ymd(now), ymd(now)]);
    sel.base = sel.target = null; renderPeriods(); load();
  });
  $('#kes-pick').addEventListener('change', e => {
    if (e.target.id === 'k-target') sel.target = +e.target.value;
    else if (e.target.id === 'k-base') sel.base = +e.target.value;
    else return;
    if (sel.base === sel.target) { const o = e.target.id === 'k-target' ? 'base' : 'target'; sel[o] = (sel[o] + 1) % last.periods.length; }
    if (last) render(last);
  });
  renderPeriods();
  load();
};

pages.master_sales = () => {
  let master = [], q = '';
  const form = $('#sales-form');
  const save = async (data, msg) => { await api('sales_save', data, true); toast(msg); await load(); };
  function render() {
    const rows = master.filter(r => !q || (r.kode_sales + ' ' + r.nama_sales).toLowerCase().includes(q));
    table($('#t-master'), [
      { k: 'kode_sales', label: 'Kode' }, { k: 'nama_sales', label: 'Nama sales' },
      { k: 'grade', label: 'Grade', fmt: v => v || '<span class="sub">–</span>' }, { k: 'cabang', label: 'Cabang', fmt: v => v || '<span class="sub">–</span>' },
      { k: 'target_pcs', label: 'Target', n: 1, fmt: v => v ? int(v) : '–' }, { k: 'fee', label: 'Fee', n: 1, fmt: v => nf.format(+v) },
      { k: 'tipe', label: 'Jenis', fmt: (v, r) => `<select data-act="tipe" data-kode="${esc(r.kode_sales)}" aria-label="Jenis ${esc(r.nama_sales)}"><option value="toko" ${v === 'toko' ? 'selected' : ''}>Sales toko</option><option value="luar" ${v === 'luar' ? 'selected' : ''}>Sales luar toko</option></select>` },
      { k: 'aksi', label: '', nosort: true, fmt: (v, r) => `<button class="btn sm" data-act="edit" data-kode="${esc(r.kode_sales)}">Ubah</button> <button class="btn sm danger" data-act="del" data-kode="${esc(r.kode_sales)}">Hapus</button>` }],
      rows, null, 'Belum ada sales. Unggah CSV master sales atau tambah manual.');
  }
  async function load() {
    const j = await api('sales_list'); master = j.rows; render();
    $('#unreg-box').hidden = !j.unregistered.length;
    $('#unreg').innerHTML = j.unregistered.map(u => `<button type="button" class="chip" data-kode="${esc(u.kode_sales)}">${esc(u.kode_sales)} <small>${int(u.baris)} baris</small></button>`).join('');
  }
  form.addEventListener('submit', async e => {
    e.preventDefault();
    try { await save(Object.fromEntries(new FormData(form)), 'Sales disimpan.'); form.reset(); } catch (err) { toast(err.message, true); }
  });
  $('#unreg').addEventListener('click', e => { const b = e.target.closest('[data-kode]'); if (b) { form.kode.value = b.dataset.kode; form.nama.focus(); } });
  $('#ms-q').addEventListener('input', e => { q = e.target.value.toLowerCase().trim(); render(); });
  $('#t-master').addEventListener('change', async e => {
    const s = e.target.closest('[data-act=tipe]'); if (!s) return;
    const r = master.find(x => x.kode_sales === s.dataset.kode);
    try { await save({ kode: r.kode_sales, nama: r.nama_sales, fee: r.fee, tipe: s.value }, 'Jenis sales diperbarui.'); } catch (err) { toast(err.message, true); }
  });
  $('#t-master').addEventListener('click', async e => {
    const b = e.target.closest('button[data-act]'); if (!b) return;
    const r = master.find(x => x.kode_sales === b.dataset.kode);
    if (b.dataset.act === 'edit') {
      form.kode.value = r.kode_sales; form.nama.value = r.nama_sales; form.fee.value = r.fee; form.tipe.value = r.tipe;
      form.nama_laporan.value = r.nama_laporan || ''; form.cabang.value = r.cabang || ''; form.grade.value = r.grade || ''; form.target_pcs.value = r.target_pcs || 0;
      form.nama.focus();
    }
    if (b.dataset.act === 'del' && confirm(`Hapus ${r.nama_sales} dari master? Transaksinya tetap ada dan dihitung sebagai sales luar toko.`)) {
      try { await api('sales_delete', { kode: r.kode_sales }, true); toast('Sales dihapus.'); load(); } catch (err) { toast(err.message, true); }
    }
  });
  const tf = $('#target-form');
  if (tf) { tf.querySelector('[name=bulan]').value = `${new Date().getFullYear()}-${pad(new Date().getMonth() + 1)}`;
    tf.addEventListener('submit', async e => { e.preventDefault(); try { await api('target_save', Object.fromEntries(new FormData(tf)), true); toast('Target emas disimpan.'); } catch (err) { toast(err.message, true); } });
  }
  load().catch(e => toast(e.message, true));
};

pages.upload = () => {
  const input = $('#files'), list = $('#filelist'), btn = $('#btn-upload'), prog = $('#prog'), drop = $('#drop');
  let files = [];
  const setFiles = fl => {
    files = [...fl].filter(f => /\.csv$/i.test(f.name));
    list.innerHTML = files.map(f => `<li>${esc(f.name)} <small>${nf.format(Math.round(f.size / 1024))} KB</small></li>`).join('');
    btn.disabled = !files.length;
    if (fl.length && !files.length) toast('Hanya file .csv yang bisa diunggah.', true);
  };
  input.addEventListener('change', () => setFiles(input.files));
  ['dragover', 'dragenter'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => setFiles(e.dataTransfer.files));
  const jenisLbl = { penjualan: 'Penjualan', pembelian: 'Pembelian', master: 'Master sales' };
  async function log() {
    const j = await api('sales_list');
    table($('#t-log'), [{ k: 'created_at', label: 'Waktu' }, { k: 'jenis', label: 'Jenis', fmt: v => jenisLbl[v] || v }, { k: 'filename', label: 'File' },
      { k: 'total_baris', label: 'Baris', n: 1, fmt: int }, { k: 'baru', label: 'Baru', n: 1, fmt: int }, { k: 'duplikat', label: 'Duplikat', n: 1, fmt: int }], j.log, null, 'Belum ada unggahan.');
  }
  btn.addEventListener('click', () => {
    const fd = new FormData(); files.forEach(f => fd.append('files[]', f));
    btn.disabled = true; prog.hidden = false; prog.value = 0;
    const x = new XMLHttpRequest(); x.open('POST', 'api.php?action=upload'); x.setRequestHeader('X-CSRF-Token', csrf);
    x.upload.onprogress = e => { if (e.lengthComputable) prog.value = e.loaded / e.total * 90; };
    x.onload = () => {
      prog.value = 100; setTimeout(() => prog.hidden = true, 600);
      let j; try { j = JSON.parse(x.responseText); } catch { toast('Respons server tidak valid.', true); return; }
      if (x.status === 401) { location.href = 'login.php'; return; }
      if (!j.ok) { toast(j.error, true); btn.disabled = false; return; }
      $('#result-box').hidden = false;
      table($('#t-result'), [{ k: 'file', label: 'File' }, { k: 'jenis', label: 'Jenis', fmt: v => jenisLbl[v] || '–' },
        { k: 'total', label: 'Baris', n: 1, fmt: v => v == null ? '–' : int(v) }, { k: 'baru', label: 'Baru', n: 1, fmt: v => v == null ? '–' : int(v) },
        { k: 'duplikat', label: 'Duplikat / diperbarui', n: 1, fmt: v => v == null ? '–' : int(v) }, { k: 'invalid', label: 'Tidak valid', n: 1, fmt: v => v == null ? '–' : int(v) },
        { k: 'ok', label: 'Status', fmt: (v, r) => v ? '<span class="tag toko">Berhasil</span>' : `<span class="tag err">Gagal</span> <small>${esc(r.error)}</small>` }], j.results);
      const bad = j.results.filter(r => !r.ok).length;
      const bs = j.barang_sync;
      toast(bad ? `${bad} file gagal diproses.` : 'Semua file berhasil diproses.' + (bs && bs.terjual ? ` ${int(bs.terjual)} barang di stok ditandai terjual (barcode cocok dengan penjualan).` : ''), !!bad);
      input.value = ''; setFiles([]); log();
    };
    x.onerror = () => { toast('Koneksi terputus saat mengunggah.', true); btn.disabled = false; prog.hidden = true; };
    x.send(fd);
  });
  log().catch(e => toast(e.message, true));
};

/* ========== Barang: Tambah (upload CSV/Excel) ========== */
pages.barang_tambah = () => {
  const input = $('#files'), list = $('#filelist'), btn = $('#btn-upload'), prog = $('#prog'), drop = $('#drop');
  let files = [];
  const setFiles = fl => {
    files = [...fl].filter(f => /\.(csv|xlsx|xls)$/i.test(f.name));
    list.innerHTML = files.map(f => `<li>${esc(f.name)} <small>${nf.format(Math.round(f.size / 1024))} KB</small></li>`).join('');
    btn.disabled = !files.length;
    if (fl.length && !files.length) toast('Hanya file .csv, .xlsx, atau .xls yang bisa diunggah.', true);
  };
  input.addEventListener('change', () => setFiles(input.files));
  ['dragover', 'dragenter'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => setFiles(e.dataTransfer.files));

  /** File Excel dikonversi ke CSV langsung di browser (pakai sheet pertama) sebelum dikirim ke server. */
  async function toCsvFile(file) {
    if (/\.csv$/i.test(file.name)) return file;
    if (!window.XLSX) throw new Error('Modul pembaca Excel belum termuat, muat ulang halaman.');
    const buf = await file.arrayBuffer();
    // cellDates + dateNF: sel tanggal Excel dikeluarkan sebagai yyyy-mm-dd (bukan m/d/yy yang ambigu & sering gagal terbaca)
    const wb = XLSX.read(buf, { type: 'array', cellDates: true });
    const csv = XLSX.utils.sheet_to_csv(wb.Sheets[wb.SheetNames[0]], { dateNF: 'yyyy-mm-dd' });
    return new File(['\ufeff' + csv], file.name.replace(/\.(xlsx|xls)$/i, '.csv'), { type: 'text/csv' });
  }

  async function log() {
    const j = await api('barang_log');
    table($('#t-log'), [{ k: 'created_at', label: 'Waktu' }, { k: 'filename', label: 'File' },
      { k: 'total_baris', label: 'Baris', n: 1, fmt: int }, { k: 'baru', label: 'Baru', n: 1, fmt: int }, { k: 'duplikat', label: 'Duplikat', n: 1, fmt: int }],
      j.log, null, 'Belum ada unggahan.');
  }

  btn.addEventListener('click', async () => {
    btn.disabled = true; prog.hidden = false; prog.value = 10;
    let converted;
    try { converted = await Promise.all(files.map(toCsvFile)); }
    catch (e) { toast(e.message, true); btn.disabled = false; prog.hidden = true; return; }
    prog.value = 40;
    const fd = new FormData(); converted.forEach(f => fd.append('files[]', f));
    try {
      const r = await fetch('api.php?action=barang_upload', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' });
      prog.value = 100;
      if (r.status === 401) { location.href = 'login.php'; return; }
      const j = await r.json();
      if (!j.ok) { toast(j.error, true); btn.disabled = false; prog.hidden = true; return; }
      $('#result-box').hidden = false;
      const dash = v => v == null ? '–' : int(v);
      table($('#t-result'), [{ k: 'file', label: 'File' },
        { k: 'total', label: 'Baris', n: 1, fmt: dash }, { k: 'baru', label: 'Barang baru', n: 1, fmt: dash },
        { k: 'diperbarui', label: 'Data lama dilengkapi', n: 1, fmt: dash },
        { k: 'duplikat', label: 'Sudah ada', n: 1, fmt: dash }, { k: 'invalid', label: 'Tidak valid', n: 1, fmt: dash },
        { k: 'tgl_gagal', label: 'Tanggal tak terbaca', n: 1, fmt: dash },
        { k: 'ok', label: 'Status', fmt: (v, r) => v ? '<span class="tag toko">Berhasil</span>' : `<span class="tag err">Gagal</span> <small>${esc(r.error)}</small>` }], j.results);
      const notes = [];
      j.results.filter(r => r.ok && r.tgl_gagal > 0).forEach(r => notes.push(`<b>${esc(r.file)}</b>: ${int(r.tgl_gagal)} tanggal input tidak terbaca (contoh isi: ${(r.contoh_tgl || []).map(x => '<code>' + esc(x) + '</code>').join(', ')}). Barangnya tetap masuk, tanggalnya kosong — kabari format tanggalnya bila perlu ditambahkan.`));
      if (j.sync && j.sync.terjual > 0) notes.push(`<b>${int(j.sync.terjual)} barang</b> langsung ditandai <b>terjual</b> karena kode barcode-nya sudah ada di data penjualan.`);
      if (j.sync && j.sync.penjualan_total > 0 && !j.sync.penjualan_berbarcode) notes.push('Data penjualan belum menyimpan kode barcode, jadi belum ada yang bisa dicocokkan. Upload ulang CSV penjualan sekali (data tidak akan dobel) lalu klik Sinkronkan di halaman Detail Barang.');
      $('#result-note').innerHTML = notes.map(n => `<p class="note">${n}</p>`).join('');
      const bad = j.results.filter(r => !r.ok).length;
      toast(bad ? `${bad} file gagal diproses.` : 'Semua file berhasil diproses.', !!bad);
      input.value = ''; setFiles([]); log();
    } catch {
      toast('Koneksi terputus saat mengunggah.', true);
    }
    btn.disabled = false; setTimeout(() => prog.hidden = true, 600);
  });
  log().catch(e => toast(e.message, true));
};

/* ========== Barang: Detail (daftar + rekap stok per kode jenis) ========== */
function jenisColor(kj) {
  let h = 0; for (const c of String(kj || '')) h = (h * 31 + c.charCodeAt(0)) % 360;
  return `hsl(${h},55%,42%)`;
}
function jenisChip(kj) {
  if (!kj) return '<span class="sub">–</span>';
  return `<span class="jenis-chip" style="background:${jenisColor(kj)}"></span>${esc(kj)}`;
}
pages.barang_detail = () => {
  const st = { from: '', to: '', kode_jenis: '', kode_baki: '', status: 'stok', tgl_kosong: '', q: '', sort: 'created_at', dir: 'desc', page: 1 };
  const GROUP_COLOR = { MD: '#a67c1a', MT: '#2b7a78', BERLIAN: '#6a4fb0', OTHER: '#6b7684' };

  function renderStock(j) {
    kpis($('#kpis'), [
      { label: 'Total stok', value: int(j.total.pcs) + ' pcs', sub: gr(j.total.gram) },
      ...j.groups.filter(g => g.total.pcs > 0 || g.group !== 'OTHER').map(g => ({ label: g.label, value: int(g.total.pcs) + ' pcs', sub: gr(g.total.gram) })),
      { label: 'Sudah terjual', value: int(j.by_status.terjual) + ' pcs', sub: 'barcode ada di data penjualan' },
      { label: 'Sudah retur', value: int(j.by_status.retur) + ' pcs' },
      ...(j.tgl_kosong ? [{ label: 'Tanggal input kosong', value: int(j.tgl_kosong) + ' pcs', sub: 'format tanggal tidak terbaca — centang filter "Tanggal input kosong"' }] : []),
    ]);
    $('#stock-groups').innerHTML = j.groups.map(g => `
      <div class="stock-group">
        <h3 style="background:${GROUP_COLOR[g.group] || '#555'}">${esc(g.label)}</h3>
        <table><thead><tr><th>Kode</th><th class="n">Pcs</th><th class="n">Gram</th></tr></thead>
        <tbody>${g.rows.length ? g.rows.map(r => `<tr><td>${jenisChip(r.kode_jenis)}</td><td class="n">${int(r.pcs)}</td><td class="n">${gr(r.gram)}</td></tr>`).join('')
          : '<tr><td colspan="3" class="sub">Tidak ada data</td></tr>'}</tbody>
        <tfoot><tr><td>Total</td><td class="n">${int(g.total.pcs)}</td><td class="n">${gr(g.total.gram)}</td></tr></tfoot></table>
      </div>`).join('');
  }

  const cols = [
    { key: 'tanggal_input', label: 'Tgl Input', fmt: v => v ? idDate(v) : '–' },
    { key: 'kode_barcode', label: 'Barcode' },
    { key: 'kode_jenis', label: 'Jenis', fmt: jenisChip },
    { key: 'kode_baki', label: 'Baki' },
    { key: 'warna', label: 'Warna' },
    { key: 'berat', label: 'Berat', n: 1, fmt: v => gr(+v) },
    { key: 'status', label: 'Status', fmt: (v, r) => v === 'stok' ? '<span class="tag toko">Stok</span>' : v === 'terjual' ? `<span class="tag err">Terjual</span><div class="sub">${r.terjual_tgl ? idDate(r.terjual_tgl) : ''} ${esc(r.terjual_faktur || '')}</div>` : '<span class="tag luar">Retur</span>' },
  ];
  function renderTable(j) {
    const thead = cols.map(c => `<th class="${c.n ? 'n' : ''} sortable" data-k="${c.key}" ${st.sort === c.key ? `aria-sort="${st.dir === 'asc' ? 'ascending' : 'descending'}"` : ''}>${esc(c.label)}</th>`).join('');
    const body = j.rows.length ? j.rows.map(r => `<tr>${cols.map(c => `<td class="${c.n ? 'n' : ''}">${c.fmt ? c.fmt(r[c.key], r) : esc(r[c.key] ?? '')}</td>`).join('')}</tr>`).join('')
      : `<tr><td class="empty" colspan="${cols.length}">Tidak ada barang yang cocok dengan filter.</td></tr>`;
    $('#t-barang').innerHTML = `<thead><tr>${thead}</tr></thead><tbody>${body}</tbody>`;
    $('#page-info').textContent = j.total ? `${int(j.total)} barang — halaman ${j.page} dari ${j.pages}` : '';
    const pgr = $('#pager'); pgr.innerHTML = '';
    if (j.pages > 1) {
      const mk = (label, page, disabled, on) => `<button class="btn sm ${on ? 'on' : ''}" ${disabled ? 'disabled' : ''} data-page="${page}">${label}</button>`;
      pgr.insertAdjacentHTML('beforeend', mk('‹ Sebelumnya', j.page - 1, j.page <= 1));
      const span = 2; let start = Math.max(1, j.page - span), end = Math.min(j.pages, j.page + span);
      for (let p = start; p <= end; p++) pgr.insertAdjacentHTML('beforeend', mk(String(p), p, false, p === j.page));
      pgr.insertAdjacentHTML('beforeend', mk('Berikutnya ›', j.page + 1, j.page >= j.pages));
    }
  }

  async function loadStock() { renderStock(await api('barang_stock', { from: st.from, to: st.to })); }
  async function loadList() {
    await guarded($('#t-barang').closest('.panel'), async () => {
      const j = await api('barang_list', st);
      renderTable(j);
      const topJenis = j.rows[0];
      kesimpulan($('#kesimpulan'), 'Kesimpulan', [
        `Daftar sesuai filter: <b>${int(j.total)} barang</b> ditemukan.`,
        topJenis ? `Barang terakhir ditambahkan: <b>${esc(topJenis.kode_barcode)}</b> (${esc(topJenis.kode_jenis) || '–'}), ${topJenis.tanggal_input ? idDate(topJenis.tanggal_input) : 'tanggal tidak ada'}.` : 'Belum ada barang yang cocok dengan filter saat ini.',
      ]);
    });
  }
  function reload() { st.page = 1; loadList(); }

  async function loadOptions() {
    try {
      const j = await api('barang_filter_options');
      j.kode_jenis.forEach(k => $('#f-jenis').insertAdjacentHTML('beforeend', `<option value="${esc(k)}">${esc(k)}</option>`));
      j.kode_baki.forEach(k => $('#f-baki').insertAdjacentHTML('beforeend', `<option value="${esc(k)}">${esc(k)}</option>`));
    } catch {}
  }

  $('#f-from').addEventListener('change', e => { st.from = e.target.value; reload(); loadStock(); });
  $('#f-to').addEventListener('change', e => { st.to = e.target.value; reload(); loadStock(); });
  $('#f-jenis').addEventListener('change', e => { st.kode_jenis = e.target.value; reload(); });
  $('#f-baki').addEventListener('change', e => { st.kode_baki = e.target.value; reload(); });
  $('#f-status').addEventListener('change', e => { st.status = e.target.value; reload(); });
  $('#f-tglkosong').addEventListener('change', e => { st.tgl_kosong = e.target.checked ? '1' : ''; reload(); });
  $('#btn-sync').addEventListener('click', async () => {
    const b = $('#btn-sync'); b.disabled = true;
    try {
      const r = await api('barang_sync', {}, true);
      if (r.penjualan_total && !r.penjualan_berbarcode) toast('Data penjualan belum punya kode barcode — upload ulang CSV penjualan sekali (data tidak akan dobel).', true);
      else toast(r.terjual ? `${int(r.terjual)} barang ditandai terjual.` : 'Tidak ada barang baru yang terjual.');
      loadStock(); loadList();
    } catch (e) { toast(e.message, true); }
    b.disabled = false;
  });
  let qT; $('#f-q').addEventListener('input', e => { clearTimeout(qT); qT = setTimeout(() => { st.q = e.target.value.trim(); reload(); }, 300); });
  $('#t-barang').addEventListener('click', e => {
    const th = e.target.closest('th[data-k]'); if (!th) return;
    const k = th.dataset.k;
    st.dir = st.sort === k ? (st.dir === 'asc' ? 'desc' : 'asc') : 'desc';
    st.sort = k; reload();
  });
  $('#pager').addEventListener('click', e => {
    const b = e.target.closest('button[data-page]'); if (!b || b.disabled) return;
    st.page = +b.dataset.page; loadList();
  });

  loadOptions();
  loadStock().catch(e => toast(e.message, true));
  loadList().catch(e => toast(e.message, true));
};

/* ========== Barang: Retur (scan + form cetak) ========== */
pages.barang_retur = () => {
  let items = [];   // { kode_barcode, kode_baki, kode_jenis, berat, bulan, keterangan }
  const scanInput = $('#scan-input'), scanInfo = $('#scan-info');

  function renderForm() {
    $('#rf-tanggal-txt').textContent = $('#rf-set-tanggal').value ? idDate($('#rf-set-tanggal').value) : '–';
    const cab = $('#rf-set-cabang').value || 'UJUNGBERUNG BANDUNG';
    $('#rf-cabang-txt').textContent = cab; $('#rf-cabang-title').textContent = cab;
    const tb = $('#rf-tbody');
    tb.innerHTML = items.length ? items.map((it, i) => `<tr>
        <td>${esc(it.kode_baki) || '–'}</td><td>${jenisChip(it.kode_jenis)}</td><td class="n">1</td><td class="n">${nf2.format(it.berat)}</td>
        <td contenteditable="true" data-i="${i}" class="rf-ket">${esc(it.keterangan)}</td><td>${esc(it.bulan) || '–'}</td>
        <td class="no-print"><button class="rf-row-del" type="button" data-del="${i}" title="Hapus baris">✕</button></td>
      </tr>`).join('') : '<tr><td colspan="7" class="empty">Belum ada barang yang discan.</td></tr>';
    const totalPcs = items.length, totalGram = items.reduce((s, it) => s + it.berat, 0);
    $('#rf-total-pcs').textContent = int(totalPcs); $('#rf-total-gram').textContent = nf2.format(totalGram);
    $('#rf-total-ket').textContent = [...new Set(items.map(it => it.keterangan).filter(Boolean))].join(', ');
  }

  async function addBarcode(code) {
    code = code.trim(); if (!code) return;
    if (items.some(it => it.kode_barcode === code)) { toast('Barcode ini sudah discan.', true); scanInput.value = ''; scanInput.focus(); return; }
    try {
      const j = await api('barcode_lookup', { barcode: code });
      items.push({ kode_barcode: j.item.kode_barcode, kode_baki: j.item.kode_baki, kode_jenis: j.item.kode_jenis, berat: j.item.berat, bulan: j.item.bulan, keterangan: 'RETUR' });
      scanInfo.textContent = `Ditambahkan: ${j.item.kode_barcode} (${j.item.kode_jenis || '–'})`;
      renderForm();
    } catch (e) {
      toast(e.message, true);
      scanInfo.textContent = '';
    }
    scanInput.value = ''; scanInput.focus();
  }
  scanInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addBarcode(scanInput.value); } });
  $('#rf-tbody').addEventListener('click', e => {
    const b = e.target.closest('button[data-del]'); if (!b) return;
    items.splice(+b.dataset.del, 1); renderForm();
  });
  $('#rf-tbody').addEventListener('input', e => {
    const td = e.target.closest('.rf-ket'); if (!td) return;
    items[+td.dataset.i].keterangan = td.textContent.trim();
  });
  $('#rf-set-tanggal').addEventListener('change', renderForm);
  $('#rf-set-cabang').addEventListener('input', renderForm);
  $('#rf-cabang-title').addEventListener('input', () => { $('#rf-set-cabang').value = $('#rf-cabang-title').textContent.trim(); renderForm(); });

  const TTD_ROLES = ['MENGAJUKAN', 'MENGETAHUI AM', 'MENYETUJUI', 'MENGETAHUI'];
  $('#rf-ttd-row').innerHTML = TTD_ROLES.map((role, i) => `
    <div class="rf-ttd-col">
      <div class="rf-ttd-role">${esc(role)}</div>
      <div class="rf-ttd-nama" contenteditable="true" data-ttd="${i + 1}-nama">NAMA</div>
      <div class="rf-ttd-jabatan" contenteditable="true" data-ttd="${i + 1}-jabatan">JABATAN</div>
    </div>`).join('');
  $('#rf-set-tanggal').value = new Date().toISOString().slice(0, 10);

  async function loadLog() {
    const j = await api('retur_list');
    table($('#t-retur-log'), [
      { k: 'created_at', label: 'Dibuat' }, { k: 'tanggal', label: 'Tanggal', fmt: v => idDate(v) }, { k: 'cabang', label: 'Cabang' },
      { k: 'pcs', label: 'Pcs', n: 1, fmt: int }, { k: 'gram', label: 'Gram', n: 1, fmt: gr },
    ], j.rows, null, 'Belum ada riwayat retur.');
  }

  /** Tangkap form retur selebar kertas (900px) walau layar HP sempit, lalu kembalikan tampilan semula. */
  async function captureForm() {
    const f = $('#print-form'); f.classList.add('exporting');
    try { return await html2canvas(f, { scale: 2, windowWidth: 1100, ignoreElements: el => el.classList?.contains('no-print') }); }
    finally { f.classList.remove('exporting'); }
  }
  $('#btn-print').addEventListener('click', () => window.print());
  $('#btn-img').addEventListener('click', async () => {
    if (!items.length) { toast('Belum ada barang yang discan.', true); return; }
    if (!window.html2canvas) { toast('Modul export belum termuat.', true); return; }
    const canvas = await captureForm();
    const a = document.createElement('a'); a.href = canvas.toDataURL('image/png'); a.download = `retur-barang_${$('#rf-set-tanggal').value}.png`; a.click();
  });
  $('#btn-pdf').addEventListener('click', async () => {
    if (!items.length) { toast('Belum ada barang yang discan.', true); return; }
    if (!window.html2canvas || !window.jspdf) { toast('Modul export belum termuat.', true); return; }
    const canvas = await captureForm();
    const img = canvas.toDataURL('image/png');
    const w = canvas.width, h = canvas.height;
    const doc = new jspdf.jsPDF({ orientation: w > h ? 'landscape' : 'portrait', unit: 'px', format: [w, h] });
    doc.addImage(img, 'PNG', 0, 0, w, h);
    doc.save(`retur-barang_${$('#rf-set-tanggal').value}.pdf`);
  });
  $('#btn-save').addEventListener('click', async () => {
    if (!items.length) { toast('Belum ada barang yang discan.', true); return; }
    const fd = new FormData();
    fd.append('tanggal', $('#rf-set-tanggal').value || new Date().toISOString().slice(0, 10));
    fd.append('cabang', $('#rf-set-cabang').value);
    TTD_ROLES.forEach((_, i) => {
      fd.append(`ttd${i + 1}_nama`, $(`[data-ttd="${i + 1}-nama"]`).textContent.trim());
      fd.append(`ttd${i + 1}_jabatan`, $(`[data-ttd="${i + 1}-jabatan"]`).textContent.trim());
    });
    fd.append('items', JSON.stringify(items.map(it => ({ kode_barcode: it.kode_barcode, keterangan: it.keterangan }))));
    try {
      await api('retur_save', fd, true);
      toast('Retur tersimpan. Stok sudah dikurangi.');
      items = []; renderForm(); loadLog();
    } catch (e) { toast(e.message, true); }
  });

  renderForm();
  loadLog().catch(e => toast(e.message, true));
  scanInput.focus();
};

const run = pages[document.body.dataset.page];
if (run) Promise.resolve(run()).catch(e => toast(e.message, true));
})();