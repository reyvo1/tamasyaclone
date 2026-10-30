window.TamasyaCurrencyDisplay=window.TamasyaCurrencyDisplay||{formatNumber:function(v,p){if(v==null||v===''||!Number.isFinite(Number(v)))return '\u2014';var f=new Intl.NumberFormat('id-ID',p?{minimumFractionDigits:2,maximumFractionDigits:2}:{maximumFractionDigits:0});var t=f.format(Number(v));if(p)t=t.replace(/,00$/,'');return t==='-0'?'0':t;},formatRupiah:function(v,p){var t=this.formatNumber(v,p);return t==='\u2014'?t:'Rp '+t;},formatNumberExact:function(v){return this.formatNumber(v,true);},formatRupiahExact:function(v){return this.formatRupiah(v,true);}};
(() => {
  'use strict';

  const state = {
    products: [], categories: [], bankAccounts: [], activeBookings: [], recentSales: [],
    summary: {}, permissions: {}, businessDate: '', businessMonthStart: '', salesFilterMode: 'auto', salesFilterBusinessDate: '', cart: new Map(), category: 'all', search: '', currentView: 'cashier', deliveries: [], receiptData: null
  };

  const $ = (id) => document.getElementById(id);
  const money = (n) => window.TamasyaCurrencyDisplay.formatRupiah(Number(n || 0));
  const number = (n, digits = 3) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: digits }).format(Number(n || 0));
  const esc = (v) => String(v ?? '').replace(/[&<>'"]/g, (ch) => ({ '&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;' }[ch]));
  const role = sessionStorage.getItem('hotel_role') || '';
  const staffName = sessionStorage.getItem('hotel_real_name') || sessionStorage.getItem('hotel_username') || 'Staf';
  const token = () => sessionStorage.getItem('hotel_session_token') || '';

  function deviceId() {
    let id = localStorage.getItem('hotel_device_id');
    if (!id) {
      id = `device_${crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}_${Math.random().toString(36).slice(2)}`}`;
      localStorage.setItem('hotel_device_id', id);
    }
    return id;
  }

  const endpointState = { cache: null, pending: null };
  const ENDPOINT_CACHE_MS = 15000;

  function normalizeApiUrl(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, window.location.href);
      url.hash = ''; url.search = '';
      if (!/\/api\.php$/i.test(url.pathname)) url.pathname = `${url.pathname.replace(/\/+$/, '')}/api.php`;
      return url.toString();
    } catch { return ''; }
  }

  function currentApiUrl() {
    return normalizeApiUrl(new URL('./api.php', window.location.href).toString());
  }

  function endpointCandidates() {
    const runtime = window.TAMASYA_RUNTIME_CONFIG || {};
    const values = [
      ['active', sessionStorage.getItem('tamasya_active_api_url') || ''],
      ['online_config', runtime.VITE_TAMASYA_ONLINE_API_URL || ''],
      ['online_saved', localStorage.getItem('tamasya_online_api_url') || ''],
      ['local_config', runtime.VITE_TAMASYA_LOCAL_API_URL || ''],
      ['local_saved', localStorage.getItem('tamasya_local_api_url') || ''],
      ['current', currentApiUrl()]
    ];
    const seen = new Set();
    return values.map(([label, value]) => ({ label, apiUrl: normalizeApiUrl(value) }))
      .filter(item => item.apiUrl && !seen.has(item.apiUrl) && seen.add(item.apiUrl));
  }

  async function probeEndpoint(candidate, timeoutMs = 1500) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const url = new URL(candidate.apiUrl);
      url.searchParams.set('action', 'ping');
      url.searchParams.set('_ts', String(Date.now()));
      const response = await fetch(url.toString(), {
        method: 'GET',
        cache: 'no-store',
        credentials: 'same-origin',
        signal: controller.signal
      });
      const data = await response.json().catch(() => ({}));
      const cluster = data.cluster || {};
      return {
        apiUrl: candidate.apiUrl,
        label: candidate.label,
        reachable: response.ok && data.success !== false && !!data.databaseConnected,
        hotelScopeId: String(data.hotelScopeId || '').trim().toLowerCase(),
        nodeId: String(data.nodeId || cluster.nodeId || ''),
        clusterEnabled: cluster.enabled !== false,
        isPrimaryWriter: !!cluster.isPrimaryWriter,
        leadershipEpoch: Number(cluster.leadershipEpoch || 0),
        primaryApiUrl: normalizeApiUrl(cluster.primaryApiUrl || ''),
        transferState: String(cluster.transferState || 'active'),
        splitBrainRisk: !!cluster.splitBrainRisk,
        leaseValid: cluster.enabled === false ? true : !!cluster.leaseValid,
        nodeStatusCode: String(cluster.nodeStatusCode || ''),
        error: response.ok ? '' : `HTTP ${response.status}`
      };
    } catch (error) {
      return {
        apiUrl: candidate.apiUrl,
        label: candidate.label,
        reachable: false,
        hotelScopeId: '',
        nodeId: '',
        clusterEnabled: true,
        isPrimaryWriter: false,
        leadershipEpoch: 0,
        primaryApiUrl: '',
        transferState: 'unknown',
        splitBrainRisk: false,
        leaseValid: false,
        nodeStatusCode: 'isolated',
        error: error instanceof Error ? error.message : String(error)
      };
    } finally {
      clearTimeout(timer);
    }
  }

  function finalizeEndpointSelection(result) {
    endpointState.cache = result;
    if (result.activeApiUrl) sessionStorage.setItem('tamasya_active_api_url', result.activeApiUrl);
    else if (result.conflict) sessionStorage.removeItem('tamasya_active_api_url');
    window.dispatchEvent(new CustomEvent('tamasya-pos-endpoint-status', { detail: result }));
    return result;
  }

  async function resolveEndpoint(force = false) {
    if (!force && endpointState.cache && Date.now() - endpointState.cache.checkedAt < ENDPOINT_CACHE_MS) {
      return endpointState.cache;
    }
    if (endpointState.pending) return endpointState.pending;

    endpointState.pending = (async () => {
      const boundScope = String(sessionStorage.getItem('hotel_offline_hotel_scope') || '').trim().toLowerCase();
      const currentUrl = currentApiUrl();

      // Root-cause architecture fix:
      // Halaman POS yang sudah berhasil dibuka harus terlebih dahulu memeriksa
      // API same-origin. Bila backend menyatakan cluster OFF, tidak ada alasan
      // menjalankan algoritma failover/lease/peer. Server ini adalah satu-satunya
      // authoritative endpoint untuk sesi tersebut.
      const currentProbe = await probeEndpoint({ label: 'current', apiUrl: currentUrl }, 12000);

      if (currentProbe.reachable && currentProbe.clusterEnabled === false) {
        const scopeMatches = !boundScope || currentProbe.hotelScopeId === boundScope;
        const writerHealthy = currentProbe.isPrimaryWriter && !currentProbe.splitBrainRisk;
        let active = null;
        let reason = '';

        if (!scopeMatches) reason = 'session_scope_unavailable';
        else if (!writerHealthy) reason = 'single_server_not_writer';
        else {
          active = currentProbe;
          reason = 'single_server_same_origin';
        }

        return finalizeEndpointSelection({
          activeApiUrl: active?.apiUrl || '',
          active,
          probes: [currentProbe],
          conflict: false,
          reason,
          boundHotelScopeId: boundScope,
          checkedAt: Date.now()
        });
      }

      // Hanya bila cluster memang aktif (atau current server tidak dapat
      // dijangkau), gunakan selector multi-server/failover.
      const candidates = endpointCandidates();
      const probes = await Promise.all(candidates.map((candidate) => {
        if (candidate.apiUrl === currentUrl && currentProbe.reachable) return Promise.resolve(currentProbe);
        return probeEndpoint(candidate, 2500);
      }));
      const reachable = probes.filter((probe) => probe.reachable);
      let scoped = boundScope ? reachable.filter((probe) => probe.hotelScopeId === boundScope) : reachable;
      if (!boundScope && scoped.length && scoped[0].hotelScopeId) {
        scoped = scoped.filter((probe) => probe.hotelScopeId === scoped[0].hotelScopeId);
      }

      const writers = scoped.filter((probe) =>
        probe.isPrimaryWriter &&
        probe.leaseValid &&
        !probe.splitBrainRisk &&
        ['active', 'emergency'].includes(probe.transferState)
      );
      const uniqueWriters = new Map(writers.map((probe) => [probe.nodeId || probe.apiUrl, probe]));
      const conflict = uniqueWriters.size > 1;
      let active = null;
      let reason = '';

      if (reachable.length === 0) reason = 'no_reachable_endpoint';
      else if (boundScope && scoped.length === 0) reason = 'session_scope_unavailable';
      else if (!conflict && writers.length) {
        active = [...writers].sort((a, b) => b.leadershipEpoch - a.leadershipEpoch)[0];
        reason = 'primary_writer_detected';
      } else if (!conflict && scoped.length) {
        const advertised = scoped.map((probe) => probe.primaryApiUrl).find(Boolean);
        active = advertised ? scoped.find((probe) => probe.apiUrl === advertised) || null : null;
        if (!active) active = scoped[0];
        reason = advertised ? 'primary_advertised_via_standby' : 'reachable_gateway_only';
      } else {
        reason = conflict ? 'multiple_primary_writers' : 'no_reachable_endpoint';
      }

      return finalizeEndpointSelection({
        activeApiUrl: conflict ? '' : (active?.apiUrl || ''),
        active,
        probes,
        conflict,
        reason,
        boundHotelScopeId: boundScope,
        checkedAt: Date.now()
      });
    })();

    try {
      return await endpointState.pending;
    } finally {
      endpointState.pending = null;
    }
  }

  function operationId(prefix) {
    const uuid = crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    return `${prefix}-${uuid}`.slice(0, 100);
  }

  async function api(action, { method = 'GET', body = null, query = null, operation = null } = {}) {
    const methodUpper = String(method || 'GET').toUpperCase();
    const mutating = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(methodUpper);
    const opId = mutating ? (operation || operationId(`pos-${action}`)) : '';
    const selection = await resolveEndpoint(false);
    if (mutating && selection.conflict) throw new Error('Mutasi POS diblokir: lebih dari satu Primary Writer terdeteksi. Isolasi salah satu server lalu rekonsiliasi leadership.');
    if (!selection.activeApiUrl) {
      const msg = selection.reason === 'session_scope_unavailable'
        ? 'Server terhubung, tetapi scope hotel berbeda dari sesi login. Silakan login ulang.'
        : selection.reason === 'single_server_not_writer'
          ? 'Server single terhubung tetapi backend tidak mengizinkannya sebagai writer. Periksa mode/role server.'
          : 'Tidak ada server TAMASYA yang dapat dijangkau.';
      throw new Error(msg);
    }

    const perform = async (apiUrl) => {
      const url = new URL(apiUrl);
      url.searchParams.set('action', action);
      if (query) Object.entries(query).forEach(([k, v]) => v !== undefined && v !== null && url.searchParams.set(k, String(v)));
      const headers = {
        'Accept': 'application/json',
        'X-Device-ID': deviceId(),
        'X-App-Version': 'V137',
        'X-Tamasya-Request-ID': operationId('req')
      };
      const scope = String(sessionStorage.getItem('hotel_offline_hotel_scope') || '').trim().toLowerCase();
      const sessionScope = String(sessionStorage.getItem('hotel_offline_session_scope') || '').trim().toLowerCase();
      if (scope) headers['X-Tamasya-Hotel-Scope'] = scope;
      if (sessionScope) headers['X-Tamasya-Offline-Session-Scope'] = sessionScope;
      if (token()) headers.Authorization = `Bearer ${token()}`;
      if (body !== null) headers['Content-Type'] = 'application/json';
      if (mutating) headers['X-Tamasya-Operation-ID'] = opId;
      if (methodUpper === 'GET') url.searchParams.set('_ts', String(Date.now()));
      const response = await fetch(url.toString(), { method: methodUpper, headers, body: body === null ? undefined : JSON.stringify(body), credentials: 'same-origin', cache: 'no-store' });
      const text = await response.text();
      let data;
      try { data = text ? JSON.parse(text) : {}; } catch { throw new Error(`Respons server bukan JSON (${response.status}).`); }
      if (response.status === 401) {
        toast('Sesi berakhir. Silakan login kembali.', true);
        setTimeout(() => { window.location.href = './login'; }, 700);
        throw new Error(data.error || 'Sesi berakhir.');
      }
      if (!response.ok || data.success === false) {
        const error = new Error(data.error || data.message || `HTTP ${response.status}`);
        error.httpStatus = response.status; error.responseData = data;
        throw error;
      }
      return data;
    };

    try { return await perform(selection.activeApiUrl); }
    catch (firstError) {
      if (firstError?.httpStatus) throw firstError; // Server menjawab: bukan kegagalan jaringan/failover.
      const retrySelection = await resolveEndpoint(true);
      if (mutating && retrySelection.conflict) throw new Error('Retry POS diblokir: dua Primary Writer terdeteksi setelah gangguan jaringan.');
      if (!retrySelection.activeApiUrl || retrySelection.activeApiUrl === selection.activeApiUrl) throw firstError;
      // Mutasi memakai operation_id yang sama pada retry agar receipt/idempotency backend mencegah transaksi ganda.
      return await perform(retrySelection.activeApiUrl);
    }
  }

  function loading(active) { $('loading').classList.toggle('hidden', !active); }
  let toastTimer;
  function toast(message, error = false) {
    const el = $('toast');
    el.textContent = message; el.classList.toggle('error', error); el.classList.add('show');
    clearTimeout(toastTimer); toastTimer = setTimeout(() => el.classList.remove('show'), 3600);
  }

  function inclusive(gross, rate) {
    gross = Number(gross || 0); rate = Number(rate || 0);
    if (rate <= 0) return { base: gross, tax: 0 };
    const base = Math.round((gross / (1 + rate / 100)) * 100) / 100;
    return { base, tax: Math.round((gross - base) * 100) / 100 };
  }

  function syncSalesDateFilter(force = false) {
    const policy = window.TamasyaPosBusinessDatePolicy;
    if (!policy || typeof policy.reconcile !== 'function') throw new Error('Kebijakan business date POS tidak tersedia.');
    const result = policy.reconcile({
      businessDate: state.businessDate,
      businessMonthStart: state.businessMonthStart,
      currentFrom: $('sales-from').value,
      currentTo: $('sales-to').value,
      mode: state.salesFilterMode,
      force
    });
    $('sales-from').value = result.from;
    $('sales-to').value = result.to;
    state.salesFilterMode = result.mode;
    state.salesFilterBusinessDate = result.businessDate;
    return result;
  }

  function markSalesDateFilterCustom() { state.salesFilterMode = 'custom'; }

  async function loadBootstrap(showLoader = true) {
    if (showLoader) loading(true);
    try {
      const data = await api('pos-bootstrap');
      Object.assign(state, {
        products: data.products || [], categories: data.categories || [], bankAccounts: data.bankAccounts || [],
        activeBookings: data.activeBookings || [], recentSales: data.recentSales || [], summary: data.summary || {}, permissions: data.permissions || {},
        businessDate: String(data.businessDate || ''), businessMonthStart: String(data.businessMonthStart || '')
      });
      // Keep the default sales period tied to the server-authoritative hotel
      // business date across midnight/month rollover. Custom history stays custom.
      syncSalesDateFilter(false);
      renderAll();
    } catch (e) { toast(e.message, true); }
    finally { if (showLoader) loading(false); }
  }

  function renderSummary() {
    const s = state.summary || {};
    $('summary-cards').innerHTML = [
      ['Penjualan hari ini', money(s.gross), `${s.saleCount || 0} transaksi`],
      ['Diskon hari ini', money(s.discount), 'Otorisasi Manager/Admin'],
      ['Pajak tercatat', money(s.tax), 'Sesuai rule produk'],
      ['Harga pokok', money(s.cost), 'Persediaan terjual'],
      ['Laba kotor', money(s.grossProfit), 'Setelah pajak & HPP'],
      ['Stok rendah', number(s.lowStockCount, 0), 'Perlu ditinjau']
    ].map(([label, value, note]) => `<div class="summary-card"><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(note)}</small></div>`).join('');
  }

  function renderCategories() {
    const chips = [{ id: 'all', name: 'Semua' }, ...state.categories.filter(c => Number(c.isActive) !== 0)];
    $('category-chips').innerHTML = chips.map(c => `<button class="chip ${state.category === c.id ? 'active' : ''}" data-category="${esc(c.id)}" type="button">${esc(c.name)}</button>`).join('');
    $('category-chips').querySelectorAll('[data-category]').forEach(btn => btn.addEventListener('click', () => { state.category = btn.dataset.category; renderCategories(); renderProductGrid(); }));
  }

  function filteredProducts() {
    const q = state.search.trim().toLowerCase();
    return state.products.filter(p => p.isActive && (state.category === 'all' || p.categoryId === state.category) && (!q || `${p.name} ${p.sku} ${p.barcode || ''}`.toLowerCase().includes(q)));
  }

  function renderProductGrid() {
    const products = filteredProducts();
    $('product-grid').innerHTML = products.length ? products.map(p => `
      <button class="product-card ${p.lowStock ? 'low' : ''}" data-product="${esc(p.id)}" ${p.stockQuantity <= 0 ? 'disabled' : ''} type="button">
        <span class="sku">${esc(p.sku)} · ${esc(p.categoryName || 'Tanpa kategori')}</span>
        <h3>${esc(p.name)}</h3>
        <span class="price">${money(p.salePrice)}</span>
        <span class="stock">Stok ${number(p.stockQuantity)} ${esc(p.unit)}${p.lowStock ? ' · rendah' : ''}</span>
      </button>`).join('') : '<div class="empty-state">Tidak ada produk yang cocok.</div>';
    $('product-grid').querySelectorAll('[data-product]').forEach(btn => btn.addEventListener('click', () => addToCart(btn.dataset.product)));
  }

  function addToCart(productId) {
    const p = state.products.find(x => x.id === productId); if (!p) return;
    if (p.taxResolved === false || p.taxRatePreview === null || p.taxRatePreview === undefined) {
      return toast(`Aturan Pajak POS untuk ${p.name} (${p.taxKind}) belum dikonfigurasi. Buka Aturan Pajak dan buat rule sumber POS terlebih dahulu.`, true);
    }
    const current = state.cart.get(productId) || 0;
    if (current + 1 > Number(p.stockQuantity)) return toast(`Stok ${p.name} tidak cukup.`, true);
    state.cart.set(productId, current + 1); renderCart();
  }
  function changeQty(productId, delta) {
    const p = state.products.find(x => x.id === productId); if (!p) return;
    const next = (state.cart.get(productId) || 0) + delta;
    if (next <= 0) state.cart.delete(productId);
    else if (next <= Number(p.stockQuantity)) state.cart.set(productId, next);
    else return toast(`Stok ${p.name} hanya ${number(p.stockQuantity)} ${p.unit}.`, true);
    renderCart();
  }

  function selectedDiscount(subtotal) {
    const type = $('discount-type')?.value || 'none';
    const value = Math.max(0, Number($('discount-value')?.value || 0));
    if (type === 'percentage') return { type, value, amount: Math.min(subtotal, Math.round(subtotal * Math.min(value, 99.99) / 100 * 100) / 100) };
    if (type === 'fixed') return { type, value, amount: Math.min(subtotal, Math.round(value * 100) / 100) };
    return { type: 'none', value: 0, amount: 0 };
  }

  function cartTotals() {
    const lines = [];
    let subtotal = 0, base = 0, tax = 0, gross = 0;
    for (const [id, qty] of state.cart) {
      const p = state.products.find(x => x.id === id); if (!p) continue;
      const originalGross = Math.round(Number(p.salePrice) * qty * 100) / 100;
      subtotal += originalGross; lines.push({ p, qty, originalGross });
    }
    subtotal = Math.round(subtotal * 100) / 100;
    const discount = selectedDiscount(subtotal); let remaining = discount.amount;
    lines.forEach((line, index) => {
      const allocated = index === lines.length - 1 ? remaining : Math.round(discount.amount * (line.originalGross / Math.max(subtotal, 1)) * 100) / 100;
      remaining = Math.round((remaining - allocated) * 100) / 100;
      const net = Math.round((line.originalGross - allocated) * 100) / 100;
      const split = inclusive(net, Number(line.p.taxRatePreview));
      gross += net; base += split.base; tax += split.tax;
    });
    return { subtotal, discountAmount: discount.amount, discountType: discount.type, discountValue: discount.value, gross: Math.round(gross * 100) / 100, base: Math.round(base * 100) / 100, tax: Math.round(tax * 100) / 100 };
  }

  function renderCart() {
    const rows = [...state.cart.entries()];
    $('cart-list').innerHTML = rows.length ? rows.map(([id, qty]) => {
      const p = state.products.find(x => x.id === id); if (!p) return '';
      return `<div class="cart-item"><div><h4>${esc(p.name)}</h4><small>${money(p.salePrice)} × ${number(qty)}</small></div><div><strong>${money(p.salePrice * qty)}</strong><div class="qty-control"><button data-minus="${esc(id)}" type="button">−</button><span>${number(qty)}</span><button data-plus="${esc(id)}" type="button">+</button></div></div></div>`;
    }).join('') : '<div class="empty-state">Keranjang masih kosong.</div>';
    $('cart-list').querySelectorAll('[data-minus]').forEach(b => b.addEventListener('click', () => changeQty(b.dataset.minus, -1)));
    $('cart-list').querySelectorAll('[data-plus]').forEach(b => b.addEventListener('click', () => changeQty(b.dataset.plus, 1)));
    const t = cartTotals(); $('cart-subtotal').textContent = money(t.subtotal); $('cart-discount').textContent = `-${money(t.discountAmount)}`;
    $('cart-discount-row').classList.toggle('hidden', t.discountAmount <= 0); $('cart-base').textContent = money(t.base); $('cart-tax').textContent = money(t.tax); $('cart-total').textContent = money(t.gross);
    $('checkout-btn').disabled = rows.length === 0;
  }

  function renderPaymentAccounts() {
    const method = $('payment-method').value;
    const expectedType = method === 'qris' ? 'edc_qris' : method === 'card' ? 'edc_card' : method === 'transfer' ? 'bank' : null;
    const select = $('bank-account');
    const previous = select.value;
    const accounts = expectedType ? state.bankAccounts.filter(b => b.type === expectedType) : [];
    select.innerHTML = '<option value="">Pilih akun</option>' + accounts.map(b => `<option value="${esc(b.id)}">${esc(b.bankName)} · ${esc(b.accountNumber || '')}</option>`).join('');
    select.value = accounts.some(b => b.id === previous) ? previous : '';
  }

  function renderSelects() {
    renderPaymentAccounts();
    $('booking-select').innerHTML = '<option value="">Pilih kamar / tamu</option>' + state.activeBookings.map(b => {
      const warnings = [b.isOverdue ? 'CHECKOUT LEWAT' : '', b.roomStatusMismatch ? `cache kamar ${b.storedRoomStatus || 'tidak ditemukan'}→${b.roomStatus || 'unknown'}` : ''].filter(Boolean);
      return `<option value="${esc(b.id)}">Kamar ${esc(b.roomNumber)} · ${esc(b.guestName)} (${esc(b.bookingSource)}) · Sisa ${money(b.balanceDue)}${warnings.length ? ` · ⚠ ${esc(warnings.join(', '))}` : ''}</option>`;
    }).join('');
    const warningCount = state.activeBookings.filter(b => b.isOverdue || b.roomStatusMismatch).length;
    $('booking-help').textContent = state.activeBookings.length ? `${state.activeBookings.length} booking aktif terhubung ke folio kamar${warningCount ? `; ${warningCount} perlu verifikasi status/tanggal` : ''}.` : 'Tidak ada booking aktif. Charge kamar hanya tersedia setelah tamu check-in.';
    $('discount-wrap').classList.toggle('hidden', !state.permissions.canDiscount);
    $('product-category').innerHTML = '<option value="">Tanpa kategori</option>' + state.categories.filter(c => Number(c.isActive) !== 0).map(c => `<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('');
    $('stock-product').innerHTML = '<option value="">Pilih produk</option>' + state.products.filter(p => p.isActive).map(p => `<option value="${esc(p.id)}">${esc(p.sku)} · ${esc(p.name)} · stok ${number(p.stockQuantity)}</option>`).join('');
  }

  function renderProductsTable() {
    $('add-product-btn').classList.toggle('hidden', !state.permissions.manageProducts);
    $('manage-categories-btn').classList.toggle('hidden', !state.permissions.manageCategories);
    $('products-table').innerHTML = state.products.map(p => `<tr>
      <td><strong>${esc(p.sku)}</strong></td><td>${esc(p.name)}</td><td>${esc(p.categoryName || '—')}</td><td>${money(p.salePrice)}<br><small>HPP ${money(p.costPrice)}</small></td>
      <td><span class="${p.lowStock ? 'stock-value' : ''}">${number(p.stockQuantity)} ${esc(p.unit)}</span><br><small>Min ${number(p.minStock)}</small></td>
      <td>${esc(p.taxKind)}<br><small>${p.taxResolved === false || p.taxRatePreview === null || p.taxRatePreview === undefined ? '<span class="stock-value">Rule belum ada</span>' : `${number(p.taxRatePreview)}% · ${esc(p.taxRuleName || 'rule aktif')}`}</small></td><td><span class="badge ${p.isActive ? 'ok' : 'off'}">${p.isActive ? 'Aktif' : 'Nonaktif'}</span></td>
      <td>${state.permissions.manageProducts ? `<button class="btn btn-secondary edit-product" data-id="${esc(p.id)}" type="button">Edit</button>` : ''}</td></tr>`).join('');
    document.querySelectorAll('.edit-product').forEach(btn => btn.addEventListener('click', () => openProductDialog(btn.dataset.id)));
  }

  function renderLowStock() {
    const rows = state.products.filter(p => p.isActive && p.lowStock);
    $('low-stock-list').innerHTML = rows.length ? rows.map(p => `<div class="stock-row"><div><strong>${esc(p.name)}</strong><small>${esc(p.sku)} · minimum ${number(p.minStock)} ${esc(p.unit)}</small></div><span class="stock-value">${number(p.stockQuantity)} ${esc(p.unit)}</span></div>`).join('') : '<div class="empty-state">Tidak ada stok rendah.</div>';
    $('stock-form').classList.toggle('hidden', !state.permissions.adjustStock);
  }

  const deliveryLabels = { created:'Baru', preparing:'Disiapkan', out_for_delivery:'Dibawa', delivered:'Diterima', cancelled:'Dibatalkan', not_required:'—' };
  function deliveryBadge(status) {
    const cls = status === 'delivered' ? 'ok' : status === 'cancelled' ? 'off' : status === 'out_for_delivery' ? 'warn' : '';
    return `<span class="badge ${cls}">${esc(deliveryLabels[status] || status || '—')}</span>`;
  }

  function renderSalesTable(sales = state.recentSales) {
    $('sales-table').innerHTML = sales.length ? sales.map(s => `<tr>
      <td><strong>${esc(s.receiptNumber)}</strong>${s.printCount ? `<br><small>${number(s.printCount,0)}× cetak</small>` : ''}</td><td>${esc(new Date(s.createdAt).toLocaleString('id-ID'))}</td><td>${esc(s.paymentMethod.replace('_',' '))}</td><td>${esc(s.roomNumber || '—')}${s.paymentMethod === 'room_charge' ? `<br>${deliveryBadge(s.deliveryStatus)}` : ''}</td>
      <td>${money(s.grossAmount)}${s.discountAmount ? `<br><small>Diskon ${money(s.discountAmount)}</small>` : ''}</td><td>${money(s.taxAmount)}</td><td>${esc(s.createdByName)}</td><td><span class="badge ${s.status === 'posted' ? 'ok' : 'off'}">${esc(s.status)}</span></td>
      <td class="action-cell"><button class="btn btn-secondary receipt-btn" data-id="${esc(s.id)}" type="button">Cetak</button>${s.paymentMethod === 'room_charge' ? ` <button class="btn btn-secondary delivery-open-btn" data-id="${esc(s.id)}" type="button">Antar</button>` : ''}${state.permissions.voidSales && s.status === 'posted' ? ` <button class="btn btn-danger void-btn" data-id="${esc(s.id)}" type="button">Void</button>` : ''}</td></tr>`).join('') : '<tr><td colspan="9"><div class="empty-state">Belum ada penjualan pada periode ini.</div></td></tr>';
    document.querySelectorAll('.receipt-btn').forEach(btn => btn.addEventListener('click', () => showReceipt(btn.dataset.id)));
    document.querySelectorAll('.delivery-open-btn').forEach(btn => btn.addEventListener('click', async () => { await showReceipt(btn.dataset.id, 'room_delivery'); switchView('deliveries'); await loadDeliveries(false); }));
    document.querySelectorAll('.void-btn').forEach(btn => btn.addEventListener('click', () => voidSale(btn.dataset.id)));
  }

  function categoryProductCount(categoryId, activeOnly = false) {
    return state.products.filter(p => p.categoryId === categoryId && (!activeOnly || p.isActive)).length;
  }

  function renderCategoryManager() {
    const target = $('categories-table');
    if (!target) return;
    target.innerHTML = state.categories.length ? state.categories.map(c => {
      const count = categoryProductCount(c.id, false);
      const activeCount = categoryProductCount(c.id, true);
      const isActive = Number(c.isActive) !== 0;
      return `<tr><td class="category-name-cell"><strong>${esc(c.name)}</strong><small>ID: ${esc(c.id)}</small></td><td>${number(c.sortOrder,0)}</td><td>${number(count,0)} total${activeCount !== count ? ` · ${number(activeCount,0)} aktif` : ''}</td><td><span class="badge ${isActive ? 'ok' : 'off'}">${isActive ? 'Aktif' : 'Nonaktif'}</span></td><td class="action-cell"><button class="btn btn-secondary edit-category" data-id="${esc(c.id)}" type="button">Edit</button> <button class="btn ${isActive ? 'btn-danger' : 'btn-secondary'} toggle-category" data-id="${esc(c.id)}" data-active="${isActive ? '0' : '1'}" type="button">${isActive ? 'Nonaktifkan' : 'Aktifkan'}</button></td></tr>`;
    }).join('') : '<tr><td colspan="5"><div class="empty-state">Belum ada kategori POS.</div></td></tr>';
    target.querySelectorAll('.edit-category').forEach(btn => btn.addEventListener('click', () => editCategory(btn.dataset.id)));
    target.querySelectorAll('.toggle-category').forEach(btn => btn.addEventListener('click', () => toggleCategory(btn.dataset.id, btn.dataset.active === '1')));
  }

  function resetCategoryForm() {
    $('category-id').value = '';
    $('category-version').value = '';
    $('category-name').value = '';
    const nextSort = state.categories.reduce((max, c) => Math.max(max, Number(c.sortOrder || 0)), 0) + 10;
    $('category-sort-order').value = String(Math.min(nextSort || 10, 9999));
    $('category-active').checked = true;
    $('category-editor-title').textContent = 'Kategori baru';
    setTimeout(() => $('category-name').focus(), 0);
  }

  function openCategoryDialog() {
    if (!state.permissions.manageCategories) return toast('Hanya Manager atau Admin yang dapat mengelola kategori POS.', true);
    resetCategoryForm();
    renderCategoryManager();
    $('category-dialog').showModal();
  }

  function closeCategoryDialog() {
    const dialog = $('category-dialog');
    if (dialog && dialog.open) dialog.close();
  }

  function editCategory(id) {
    const c = state.categories.find(x => x.id === id);
    if (!c) return;
    $('category-id').value = c.id;
    $('category-version').value = c.version || 0;
    $('category-name').value = c.name || '';
    $('category-sort-order').value = String(Number(c.sortOrder || 0));
    $('category-active').checked = Number(c.isActive) !== 0;
    $('category-editor-title').textContent = 'Edit kategori';
    $('category-name').focus();
  }

  async function saveCategory(event) {
    event.preventDefault();
    if (event.submitter && event.submitter.id !== 'save-category') return;
    const name = $('category-name').value.trim();
    if (name.length < 2) return toast('Nama kategori minimal 2 karakter.', true);
    const op = operationId('pos-category');
    const body = {
      operationId: op,
      id: $('category-id').value || undefined,
      version: Number($('category-version').value || 0),
      name,
      sortOrder: Number($('category-sort-order').value || 0),
      isActive: $('category-active').checked
    };
    if (body.id && !body.isActive && categoryProductCount(body.id, true) > 0) return toast(`Kategori masih dipakai ${categoryProductCount(body.id, true)} produk aktif. Pindahkan atau nonaktifkan produknya terlebih dahulu.`, true);
    const saveButton = $('save-category');
    saveButton.disabled = true; loading(true);
    try {
      await api('pos-category-save', { method: 'POST', body, operation: op });
      toast(body.id ? 'Kategori berhasil diperbarui.' : 'Kategori baru berhasil ditambahkan.');
      await loadBootstrap(false); resetCategoryForm(); renderCategoryManager();
    } catch (e) { toast(e.message, true); }
    finally { saveButton.disabled = false; loading(false); }
  }

  async function toggleCategory(id, nextActive) {
    const c = state.categories.find(x => x.id === id); if (!c) return;
    const activeProducts = categoryProductCount(id, true);
    if (!nextActive && activeProducts > 0) return toast(`Kategori ${c.name} masih dipakai ${activeProducts} produk aktif. Pindahkan atau nonaktifkan produknya terlebih dahulu.`, true);
    if (!confirm(`${nextActive ? 'Aktifkan' : 'Nonaktifkan'} kategori ${c.name}?`)) return;
    const op = operationId('pos-category-status'); loading(true);
    try {
      await api('pos-category-save', { method: 'POST', body: { operationId: op, id: c.id, version: Number(c.version || 0), name: c.name, sortOrder: Number(c.sortOrder || 0), isActive: nextActive }, operation: op });
      toast(`Kategori ${nextActive ? 'diaktifkan' : 'dinonaktifkan'}.`); await loadBootstrap(false); renderCategoryManager();
    } catch (e) { toast(e.message, true); } finally { loading(false); }
  }

  function renderAll() { renderSummary(); renderCategories(); renderProductGrid(); renderCart(); renderSelects(); renderProductsTable(); renderCategoryManager(); renderLowStock(); renderSalesTable(); updateDeliveryCount(); }

  function closeProductDialog() {
    const dialog = $('product-dialog');
    if (dialog && dialog.open) dialog.close();
  }

  function openProductDialog(id = '') {
    const p = state.products.find(x => x.id === id);
    $('product-dialog-title').textContent = p ? 'Edit produk' : 'Produk baru';
    $('product-id').value = p?.id || ''; $('product-version').value = p?.version || '';
    $('product-sku').value = p?.sku || ''; $('product-name').value = p?.name || ''; $('product-category').value = p?.categoryId || '';
    $('product-unit').value = p?.unit || 'pcs'; $('product-cost').value = p?.costPrice || 0; $('product-price').value = p?.salePrice || 0;
    $('product-initial-stock').value = 0; $('product-min-stock').value = p?.minStock || 0; $('product-tax-kind').value = p?.taxKind || 'extra';
    $('product-barcode').value = p?.barcode || ''; $('product-location').value = p?.location || 'Front Office / Minibar'; $('product-notes').value = p?.notes || '';
    $('product-active').checked = p ? p.isActive : true; $('initial-stock-wrap').classList.toggle('hidden', Boolean(p));
    $('product-dialog').showModal();
  }

  async function saveProduct(event) {
    event.preventDefault();
    if (event.submitter && event.submitter.id !== 'save-product') { closeProductDialog(); return; }
    const op = operationId('pos-product');
    const body = {
      operationId: op, id: $('product-id').value || undefined, version: Number($('product-version').value || 0), sku: $('product-sku').value,
      name: $('product-name').value, categoryId: $('product-category').value || null, unit: $('product-unit').value,
      costPrice: Number($('product-cost').value || 0), salePrice: Number($('product-price').value || 0), initialStock: Number($('product-initial-stock').value || 0),
      minStock: Number($('product-min-stock').value || 0), taxKind: $('product-tax-kind').value, barcode: $('product-barcode').value || null,
      location: $('product-location').value, notes: $('product-notes').value, isActive: $('product-active').checked
    };
    const saveButton = $('save-product');
    if (saveButton) saveButton.disabled = true;
    loading(true);
    try { await api('pos-product-save', { method: 'POST', body, operation: op }); closeProductDialog(); toast('Produk berhasil disimpan.'); await loadBootstrap(false); }
    catch (e) { toast(e.message, true); } finally { if (saveButton) saveButton.disabled = false; loading(false); }
  }

  async function submitStock(event) {
    event.preventDefault(); const op = operationId('pos-stock');
    const body = { operationId: op, productId: $('stock-product').value, quantityDelta: Number($('stock-delta').value), reason: $('stock-reason').value };
    loading(true);
    try { await api('pos-stock-adjust', { method: 'POST', body, operation: op }); toast('Stok berhasil disesuaikan.'); event.target.reset(); await loadBootstrap(false); }
    catch (e) { toast(e.message, true); } finally { loading(false); }
  }

  function updateDiscountFields() {
    const type = $('discount-type').value;
    const active = type !== 'none';
    $('discount-value-wrap').classList.toggle('hidden', !active);
    $('discount-reason-wrap').classList.toggle('hidden', !active);
    $('discount-value').max = type === 'percentage' ? '99.99' : '';
    $('discount-value').placeholder = type === 'percentage' ? 'Contoh: 10' : 'Contoh: 25000';
    renderCart();
  }

  async function checkout() {
    if (!state.cart.size) return;
    const method = $('payment-method').value; const op = operationId('pos-sale');
    const body = {
      operationId: op, paymentMethod: method, bankAccountId: $('bank-account').value || null, bookingId: $('booking-select').value || null,
      discountType: $('discount-type').value, discountValue: Number($('discount-value').value || 0), discountReason: $('discount-reason').value, promoCode: $('promo-code')?.value?.trim() || null,
      notes: $('sale-notes').value, deliveryNote: method === 'room_charge' ? $('sale-notes').value : null, items: [...state.cart.entries()].map(([productId, quantity]) => ({ productId, quantity }))
    };
    if (method === 'room_charge' && !body.bookingId) return toast('Pilih kamar/tamu aktif.', true);
    if (['qris','transfer','card'].includes(method) && !body.bankAccountId) return toast('Pilih akun bank/QRIS.', true);
    const t = cartTotals();
    if (t.discountAmount > 0 && $('discount-reason').value.trim().length < 5) return toast('Alasan diskon minimal 5 karakter.', true);
    if (t.discountAmount >= t.subtotal) return toast('Diskon harus lebih kecil dari subtotal.', true);
    if (!confirm(`Simpan penjualan ${money(t.gross)}${t.discountAmount ? ` setelah diskon ${money(t.discountAmount)}` : ''}?`)) return;
    loading(true);
    try {
      const data = await api('pos-sale-create', { method: 'POST', body, operation: op });
      const cartSnapshot = [...state.cart.entries()].map(([id, qty]) => ({ product: state.products.find(p => p.id === id), qty }));
      state.cart.clear(); $('sale-notes').value = ''; renderCart(); toast('Penjualan berhasil disimpan.'); await loadBootstrap(false);
      renderReceipt(data.sale, cartSnapshot.map(x => ({ product_name: x.product?.name, quantity: x.qty, unit_price: x.product?.salePrice, gross_amount: Number(x.product?.salePrice || 0) * x.qty, unit: x.product?.unit || 'pcs', location: x.product?.location || '' })), method === 'room_charge' ? 'room_delivery' : 'customer_receipt');
      if (method === 'room_charge') await loadDeliveries(false);
    } catch (e) { toast(e.message, true); } finally { loading(false); }
  }

  async function loadSales() {
    loading(true);
    try { const data = await api('pos-sales', { query: { from: $('sales-from').value, to: $('sales-to').value } }); renderSalesTable(data.sales || []); }
    catch (e) { toast(e.message, true); } finally { loading(false); }
  }

  async function showReceipt(id, preferredType = '') {
    loading(true);
    try { const data = await api('pos-sale-detail', { query: { id } }); renderReceipt(data.sale, data.items || [], preferredType); }
    catch (e) { toast(e.message, true); } finally { loading(false); }
  }

  function itemValue(item, snake, camel, fallback = '') { return item[snake] ?? item[camel] ?? fallback; }
  function compactMoney(value) { return window.TamasyaCurrencyDisplay.formatNumberExact(Number(value || 0)); }
  function receiptDocument(sale, items, type, copyLabel, copyIndex = 1, copyTotal = 1) {
    const dateText = new Date(sale.createdAt).toLocaleString('id-ID');
    const roomHeader = sale.roomNumber ? `<div class="room-box"><strong>KAMAR ${esc(sale.roomNumber)}</strong><span>${esc(sale.guestName || 'Tamu')}</span><small>Booking: ${esc(sale.bookingId || '—')}</small></div>` : '';
    const copy = `<div class="copy-label">${esc(copyLabel)}${copyTotal > 1 ? ` · ${copyIndex}/${copyTotal}` : ''}</div>`;
    const itemRows = items.map(i => {
      const name = itemValue(i,'product_name','productName','Produk'); const qty = Number(itemValue(i,'quantity','quantity',0));
      const unit = itemValue(i,'unit','unit','pcs'); const gross = Number(itemValue(i,'gross_amount','grossAmount',Number(itemValue(i,'unit_price','unitPrice',0))*qty));
      if (type === 'picking_slip' || type === 'room_delivery') { const location = itemValue(i,'location','location',''); return `<div class="thermal-item no-price"><span>${esc(name)}${type === 'picking_slip' && location ? `<small>Lokasi: ${esc(location)}</small>` : ''}</span><strong>${number(qty)} ${esc(unit)}</strong></div>`; }
      return `<div class="thermal-item"><span>${esc(name)}<small>${number(qty)} × ${compactMoney(itemValue(i,'unit_price','unitPrice',gross/Math.max(qty,1)))}</small></span><strong>${compactMoney(gross)}</strong></div>`;
    }).join('');
    if (type === 'picking_slip') return `<section class="thermal-doc picking">${copy}<div class="hotel">${esc((window.TamasyaPropertyBranding&&window.TamasyaPropertyBranding.hotelName)||'HOTEL')}</div><h1>SLIP PENGAMBILAN</h1><div class="doc-no">${esc(sale.receiptNumber)}</div>${roomHeader}<div class="meta"><span>Dibuat</span><strong>${esc(dateText)}</strong><span>Kasir</span><strong>${esc(sale.createdByName)}</strong></div><div class="thermal-items">${itemRows}</div><div class="note-box"><b>Catatan kamar</b><br>${esc(sale.deliveryNote || sale.notes || '—')}</div><div class="signature-grid"><div>Disiapkan oleh<br><br><br>______________</div><div>Diperiksa oleh<br><br><br>______________</div></div></section>`;
    if (type === 'room_delivery') return `<section class="thermal-doc delivery">${copy}<div class="hotel">${esc((window.TamasyaPropertyBranding&&window.TamasyaPropertyBranding.hotelName)||'HOTEL')}</div><h1>SLIP PENGANTARAN KAMAR</h1><div class="doc-no">${esc(sale.receiptNumber)}</div>${roomHeader}<div class="unpaid-banner">CHARGE KE KAMAR · BELUM DIBAYAR</div><div class="thermal-items">${itemRows}</div><div class="note-box"><b>Catatan</b><br>${esc(sale.deliveryNote || sale.notes || '—')}</div><div class="delivery-audit"><span>Status</span><strong>${esc(deliveryLabels[sale.deliveryStatus] || sale.deliveryStatus)}</strong><span>Disiapkan</span><strong>${esc(sale.preparedByName || '____________')}</strong><span>Diantar</span><strong>${esc(sale.dispatchedByName || sale.deliveredByName || '____________')}</strong></div><div class="signature-block"><p>Diterima oleh: ____________________</p><p>Jam diterima: ____________________</p><p>Tanda tangan tamu:</p><div class="signature-space"></div></div><p class="footer-note">Barang telah diperiksa dan diterima. Tagihan masuk ke folio kamar.</p></section>`;
    const title = type === 'archive_copy' ? 'ARSIP NOTA POS / MINIBAR' : 'NOTA POS / MINIBAR';
    const discountRows = Number(sale.discountAmount || 0) > 0 ? `<span>Subtotal</span><strong>${compactMoney(sale.subtotalAmount)}</strong><span>Diskon${sale.discountType === 'percentage' ? ` (${number(sale.discountValue,2)}%)` : ''}</span><strong>-${compactMoney(sale.discountAmount)}</strong>` : '';
    const discountAudit = Number(sale.discountAmount || 0) > 0 ? `<div class="discount-audit">Diskon: ${esc(sale.discountReason || '—')} · Otorisasi ${esc(sale.discountAuthorizedByName || 'Manager/Admin')}</div>` : '';
    return `<section class="thermal-doc receipt">${copy}<div class="hotel">${esc((window.TamasyaPropertyBranding&&window.TamasyaPropertyBranding.hotelName)||'HOTEL')}</div><h1>${title}</h1><div class="doc-no">${esc(sale.receiptNumber)}</div>${roomHeader}<div class="thermal-items">${itemRows}</div><div class="totals">${discountRows}<span>DPP/Pendapatan</span><strong>${compactMoney(sale.baseAmount)}</strong><span>Pajak</span><strong>${compactMoney(sale.taxAmount)}</strong><span class="grand">TOTAL</span><strong class="grand">${compactMoney(sale.grossAmount)}</strong></div>${discountAudit}<div class="meta"><span>Metode</span><strong>${esc(sale.paymentMethod.replace('_',' '))}</strong><span>Kasir</span><strong>${esc(sale.createdByName)}</strong><span>Waktu</span><strong>${esc(dateText)}</strong></div>${sale.status === 'voided' ? `<div class="void-banner">VOID · ${esc(sale.voidReason || '')}</div>` : ''}<p class="footer-note">Terima kasih</p></section>`;
  }

  function renderReceipt(sale, items, preferredType = '') {
    state.receiptData = { sale, items };
    const roomOnly = sale.paymentMethod === 'room_charge';
    [...$('print-type').options].forEach(o => { if (['picking_slip','room_delivery'].includes(o.value)) o.disabled = !roomOnly; });
    $('print-type').value = preferredType || (roomOnly ? 'room_delivery' : 'customer_receipt');
    $('paper-width').value = localStorage.getItem('tamasya_pos_paper_width') || '80';
    $('print-copies').value = '1';
    refreshReceiptPreview();
    $('receipt-dialog').showModal();
  }

  function refreshReceiptPreview() {
    if (!state.receiptData) return;
    const { sale, items } = state.receiptData; const type = $('print-type').value;
    $('receipt-content').innerHTML = `<div class="receipt-preview">${receiptDocument(sale, items, type, sale.printCount > 0 ? 'COPY' : 'ORIGINAL')}</div>`;
  }

  function thermalPrintHtml(documents, width) {
    return `<!doctype html><html><head><meta charset="utf-8"><title>Cetak POS TAMASYA</title><style>
      @page{size:${width}mm auto;margin:2mm}*{box-sizing:border-box}html,body{margin:0;padding:0;background:#fff;color:#000;font-family:Arial,sans-serif}.print-root{width:${width-4}mm;margin:0 auto}.thermal-doc{position:relative;width:100%;padding:1mm 0;font-size:${width===58?'9.5px':'11px'};line-height:1.3;break-after:page}.thermal-doc:last-child{break-after:auto}.hotel,.doc-no{text-align:center}.hotel{font-weight:900;letter-spacing:.08em}.thermal-doc h1{text-align:center;font-size:${width===58?'13px':'15px'};margin:2mm 0 1mm}.doc-no{font-weight:700;margin-bottom:2mm}.copy-label{position:absolute;right:0;top:0;border:1px solid #000;padding:.5mm 1mm;font-size:8px;font-weight:900}.room-box{border:1.5px solid #000;padding:2mm;text-align:center;margin:2mm 0}.room-box strong{display:block;font-size:${width===58?'19px':'23px'}}.room-box span,.room-box small{display:block}.thermal-items{border-top:1px dashed #000;border-bottom:1px dashed #000;padding:1.5mm 0;margin:2mm 0}.thermal-item{display:flex;justify-content:space-between;gap:2mm;margin:1.3mm 0}.thermal-item>span{flex:1}.thermal-item small{display:block}.thermal-item.no-price strong{font-size:1.1em}.totals,.meta,.delivery-audit{display:grid;grid-template-columns:1fr auto;gap:1mm 2mm}.totals .grand{font-size:1.25em;font-weight:900;border-top:1px solid #000;padding-top:1.5mm}.meta{margin-top:2mm;font-size:.92em}.discount-audit{border:1px dashed #000;padding:1.5mm;margin:2mm 0;font-size:.88em}.unpaid-banner,.void-banner{border:2px solid #000;text-align:center;font-weight:900;padding:2mm;margin:2mm 0}.note-box{border:1px solid #000;padding:2mm;min-height:12mm;margin:2mm 0}.signature-grid{display:grid;grid-template-columns:1fr 1fr;gap:3mm;text-align:center;margin-top:4mm}.signature-block{margin-top:4mm}.signature-space{height:22mm;border-bottom:1px solid #000}.footer-note{text-align:center;margin:3mm 0 0;font-size:.9em}.page-separator{break-after:page}.page-separator:last-child{break-after:auto}@media print{.thermal-doc{break-inside:avoid}}
    </style></head><body><main class="print-root">${documents.join('')}</main><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),150));<\/script></body></html>`;
  }

  async function printReceipt() {
    if (!state.receiptData) return;
    const popup = window.open('', '_blank', 'width=520,height=760');
    if (popup) try { popup.opener = null; } catch {}
    if (!popup) return toast('Popup cetak diblokir browser. Izinkan popup untuk halaman POS.', true);
    const { sale, items } = state.receiptData; const type = $('print-type').value; const width = Number($('paper-width').value); const copies = type === 'dual_copy' ? 2 : Number($('print-copies').value);
    localStorage.setItem('tamasya_pos_paper_width', String(width));
    const op = operationId('pos-print');
    try {
      const data = await api('pos-sale-print-log', { method:'POST', body:{ operationId:op, saleId:sale.id, printType:type, paperWidth:width, copies }, operation:op });
      const label = data.copyLabel || 'COPY'; const docs = [];
      if (type === 'dual_copy') { docs.push(receiptDocument(sale,items,'customer_receipt',label,1,2)); docs.push(receiptDocument(sale,items,'archive_copy','ARSIP',2,2)); }
      else for (let i=1;i<=copies;i++) docs.push(receiptDocument(sale,items,type,i===1?label:'COPY',i,copies));
      popup.document.open(); popup.document.write(thermalPrintHtml(docs,width)); popup.document.close();
      state.receiptData.sale = data.sale || sale; toast(`Dokumen ${label} siap dicetak pada kertas ${width} mm.`); await loadBootstrap(false);
    } catch (e) { popup.close(); toast(e.message,true); }
  }

  async function voidSale(id) {
    const reason = prompt('Alasan pembatalan/void (minimal 5 karakter):'); if (!reason) return;
    if (!confirm('Void akan mengembalikan stok dan membuat jurnal pembalik. Lanjutkan?')) return;
    const op = operationId('pos-void'); loading(true);
    try { await api('pos-sale-void', { method: 'POST', body: { operationId: op, saleId: id, reason }, operation: op }); toast('Penjualan berhasil dibatalkan.'); await loadBootstrap(false); }
    catch (e) { toast(e.message, true); } finally { loading(false); }
  }

  function updateDeliveryCount() {
    const open = Number(state.summary.openDeliveryCount ?? state.recentSales.filter(s => s.paymentMethod === 'room_charge' && s.status === 'posted' && ['created','preparing','out_for_delivery'].includes(s.deliveryStatus)).length);
    $('delivery-count').textContent = String(open); $('delivery-count').classList.toggle('hidden', open === 0);
  }

  function switchView(view) {
    state.currentView = view;
    if (view === 'sales') syncSalesDateFilter(false);
    document.querySelectorAll('.nav-tab').forEach(x => x.classList.toggle('active', x.dataset.view === view));
    document.querySelectorAll('.view-panel').forEach(x => x.classList.toggle('active', x.id === `view-${view}`));
  }

  async function loadDeliveries(showLoader = true) {
    if (showLoader) loading(true);
    try { const data = await api('pos-deliveries', { query:{ status:$('delivery-filter').value } }); state.deliveries = data.deliveries || []; if ($('delivery-filter').value === 'open') { state.summary.openDeliveryCount = state.deliveries.length; updateDeliveryCount(); } renderDeliveries(); }
    catch(e){ toast(e.message,true); } finally { if(showLoader) loading(false); }
  }

  function nextDeliveryAction(sale) {
    if (sale.deliveryStatus === 'created') return ['preparing','Mulai siapkan'];
    if (sale.deliveryStatus === 'preparing') return ['out_for_delivery','Bawa ke kamar'];
    if (sale.deliveryStatus === 'out_for_delivery') return ['delivered','Konfirmasi diterima'];
    return null;
  }

  function renderDeliveries() {
    const rows = state.deliveries || [];
    $('delivery-list').innerHTML = rows.length ? rows.map(s => {
      const next = nextDeliveryAction(s);
      return `<article class="delivery-card status-${esc(s.deliveryStatus)}"><div class="delivery-card-head"><div><p class="eyebrow">${esc(s.receiptNumber)}</p><h3>Kamar ${esc(s.roomNumber || '—')}</h3><p>${esc(s.guestName || 'Tamu')}</p></div>${deliveryBadge(s.deliveryStatus)}</div><div class="delivery-info"><span>Total folio</span><strong>${money(s.grossAmount)}</strong><span>Dibuat</span><strong>${esc(new Date(s.createdAt).toLocaleString('id-ID'))}</strong><span>Catatan</span><strong>${esc(s.deliveryNote || s.notes || '—')}</strong></div><div class="delivery-progress"><span class="${['preparing','out_for_delivery','delivered'].includes(s.deliveryStatus)?'done':''}">Siapkan</span><span class="${['out_for_delivery','delivered'].includes(s.deliveryStatus)?'done':''}">Bawa</span><span class="${s.deliveryStatus==='delivered'?'done':''}">Diterima</span></div><div class="delivery-actions"><button class="btn btn-secondary print-pick" data-id="${esc(s.id)}" type="button">Slip Ambil</button><button class="btn btn-secondary print-delivery" data-id="${esc(s.id)}" type="button">Slip Antar</button>${next ? `<button class="btn btn-primary delivery-step" data-id="${esc(s.id)}" data-status="${next[0]}" type="button">${next[1]}</button>` : ''}</div>${s.receivedByName ? `<p class="received-line">Diterima: <strong>${esc(s.receivedByName)}</strong> · ${esc(s.deliveredByName || '')}</p>` : ''}</article>`;
    }).join('') : '<div class="empty-state">Tidak ada pesanan kamar pada filter ini.</div>';
    document.querySelectorAll('.print-pick').forEach(b=>b.addEventListener('click',()=>showReceipt(b.dataset.id,'picking_slip')));
    document.querySelectorAll('.print-delivery').forEach(b=>b.addEventListener('click',()=>showReceipt(b.dataset.id,'room_delivery')));
    document.querySelectorAll('.delivery-step').forEach(b=>b.addEventListener('click',()=>advanceDelivery(b.dataset.id,b.dataset.status)));
  }

  async function advanceDelivery(saleId,status) {
    let receivedByName = null; let note = null;
    if (status === 'delivered') { receivedByName = prompt('Nama tamu/penerima barang:'); if (!receivedByName) return; note = prompt('Catatan penerimaan (opsional):') || null; }
    else note = prompt(status === 'preparing' ? 'Catatan persiapan (opsional):' : 'Nama petugas/catatan pengantaran (opsional):') || null;
    const op=operationId('pos-delivery'); loading(true);
    try { await api('pos-delivery-update',{method:'POST',body:{operationId:op,saleId,status,note,receivedByName},operation:op}); toast('Status pengantaran diperbarui.'); await Promise.all([loadBootstrap(false),loadDeliveries(false)]); }
    catch(e){toast(e.message,true);} finally{loading(false);}
  }

  function setPaymentVisibility() {
    const value = $('payment-method').value;
    $('bank-wrap').classList.toggle('hidden', !['qris','transfer','card'].includes(value));
    $('booking-wrap').classList.toggle('hidden', value !== 'room_charge');
    renderPaymentAccounts();
  }

  function initDates() { syncSalesDateFilter(true); }

  function bindBackNavigation() {
    const back = $('pos-back-link');
    if (!back) return;
    back.addEventListener('click', (event) => {
      let referrer = null;
      try { referrer = document.referrer ? new URL(document.referrer, window.location.href) : null; } catch {}
      if (referrer && referrer.origin === window.location.origin && window.history.length > 1) {
        event.preventDefault();
        window.history.back();
      }
    });
  }

  function bindEvents() {
    bindBackNavigation();
    document.querySelectorAll('.nav-tab').forEach(btn => btn.addEventListener('click', () => {
      switchView(btn.dataset.view); if (btn.dataset.view === 'deliveries') loadDeliveries(false);
    }));
    $('product-search').addEventListener('input', (e) => { state.search = e.target.value; renderProductGrid(); });
    $('clear-cart').addEventListener('click', () => { state.cart.clear(); renderCart(); });
    $('payment-method').addEventListener('change', setPaymentVisibility); $('checkout-btn').addEventListener('click', checkout);
    $('discount-type').addEventListener('change', updateDiscountFields); $('discount-value').addEventListener('input', renderCart); $('discount-reason').addEventListener('input', renderCart);
    $('booking-select').addEventListener('change', () => {
      const booking = state.activeBookings.find(b => b.id === $('booking-select').value);
      if (!booking) { $('booking-help').textContent = state.activeBookings.length ? `${state.activeBookings.length} booking aktif terhubung.` : 'Tidak ada booking aktif.'; return; }
      const warnings = [booking.isOverdue ? 'tanggal checkout sudah lewat' : '', booking.roomStatusMismatch ? `cache master kamar: ${booking.storedRoomStatus || 'tidak ditemukan'} → canonical ${booking.roomStatus || 'unknown'}` : ''].filter(Boolean);
      $('booking-help').textContent = `Kamar ${booking.roomNumber} · ${booking.guestName} · Total folio ${money(booking.totalAmount)} · Sisa ${money(booking.balanceDue)}${warnings.length ? ` · PERIKSA: ${warnings.join('; ')}` : ''}`;
    });
    $('refresh-btn').addEventListener('click', () => loadBootstrap(true)); $('add-product-btn').addEventListener('click', () => openProductDialog());
    $('manage-categories-btn').addEventListener('click', openCategoryDialog);
    $('close-category-dialog').addEventListener('click', closeCategoryDialog); $('cancel-category-dialog').addEventListener('click', closeCategoryDialog); $('reset-category-form').addEventListener('click', resetCategoryForm);
    $('category-dialog').addEventListener('click', (event) => { if (event.target === $('category-dialog')) closeCategoryDialog(); }); $('category-form').addEventListener('submit', saveCategory);
    $('close-product-dialog').addEventListener('click', closeProductDialog); $('cancel-product-dialog').addEventListener('click', closeProductDialog);
    $('product-dialog').addEventListener('click', (event) => { if (event.target === $('product-dialog')) closeProductDialog(); });
    $('product-form').addEventListener('submit', saveProduct); $('stock-form').addEventListener('submit', submitStock);
    $('sales-from').addEventListener('input', markSalesDateFilterCustom); $('sales-to').addEventListener('input', markSalesDateFilterCustom);
    $('load-sales').addEventListener('click', loadSales);
    $('close-receipt').addEventListener('click', () => $('receipt-dialog').close()); $('print-receipt').addEventListener('click', printReceipt);
    $('print-type').addEventListener('change', () => { $('print-copies').disabled = $('print-type').value === 'dual_copy'; $('print-copies').value = $('print-type').value === 'dual_copy' ? '2' : '1'; refreshReceiptPreview(); }); $('paper-width').addEventListener('change', refreshReceiptPreview);
    $('load-deliveries').addEventListener('click', () => loadDeliveries(true));
  }

  function applyOwnerReadOnly(){if(role!=='owner')return;document.querySelectorAll('form').forEach(f=>f.querySelectorAll('input,select,textarea,button').forEach(el=>el.disabled=true));['add-product-btn','manage-categories-btn','checkout-btn'].forEach(id=>{const el=$(id);if(el)el.disabled=true;});const user=$('current-user');if(user)user.textContent=`${staffName} · owner · READ-ONLY`; }

  async function init() {
    const allowed = ['admin','manager','receptionist','finance','owner'];
    if (sessionStorage.getItem('hotel_logged_in') !== 'true' || !token() || !allowed.includes(role)) {
      window.location.href = './login'; return;
    }
    try {
      const perms = JSON.parse(sessionStorage.getItem('hotel_permissions') || '{}');
      if (perms.desktopTabs && Object.prototype.hasOwnProperty.call(perms.desktopTabs, 'pos') && !perms.desktopTabs.pos) {
        document.body.innerHTML = '<div style="padding:40px;color:white;font-family:system-ui">Akses menu POS dinonaktifkan untuk akun ini. <a href="./" style="color:#7dd3fc">Kembali</a></div>'; return;
      }
    } catch {}
    $('current-user').textContent = `${staffName} · ${role}`;
    bindEvents(); setPaymentVisibility(); updateDiscountFields(); await loadBootstrap(true); initDates(); applyOwnerReadOnly();
    window.setInterval(() => {
      if (document.visibilityState !== 'visible') return;
      if ($('product-dialog')?.open || $('category-dialog')?.open || $('receipt-dialog')?.open) return;
      loadBootstrap(false);
    }, 30000);
    window.addEventListener('focus', () => {
      if (!$('product-dialog')?.open && !$('category-dialog')?.open && !$('receipt-dialog')?.open) loadBootstrap(false);
    });
  }

  document.addEventListener('DOMContentLoaded', init);
})();
