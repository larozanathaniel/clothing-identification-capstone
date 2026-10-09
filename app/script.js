// Intelligent Laundry Management System - PHP REST API frontend
// Staff flow: Login -> Intake (scan + label) -> Sorting (scan) -> Manual (if not identified) -> List (per customer)
const $ = s => document.querySelector(s);
const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

let S = { users: [], batches: [], flags: [], thr: 80, seq: 1000, gseq: 1 };
let me = null, cur = null, stream = null, draft = {name:'', items:[]}, pend = null, pick = null, fileFor = null;
const IMG_W = 320;                                   // every scan is resized to this width (intake AND sorting)
const TABS = [['Open','In Laundry'], ['Completed','Completed']];
const LABEL = Object.fromEntries(TABS);
const B = id => S.batches.find(b => b.id === String(id));
const count = (b, st) => (b?.items || []).filter(i => i.st === st).length;
const waiting = () => S.batches.filter(b => b.status === 'Open').flatMap(b => b.items.filter(i => i.st === 'pending').map(i => ({b, i})));
const findItem = id => { for (const b of S.batches) { const i = b.items.find(x => x.id === String(id)); if (i) return {b, i}; } return null; };
const unresolved = () => S.flags;

/* ---------- API ---------- */
async function api(endpoint, options = {}) {
  const res = await fetch(endpoint, { headers: {'Content-Type': 'application/json'}, ...options });
  let data = {};
  const raw = await res.text();
  try { data = JSON.parse(raw); } catch (e) { throw new Error('Server error (' + res.status + '): ' + raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160)); }
  if (!res.ok) {
    if (res.status === 401 && me) { me = null; $('#sidebar').classList.add('hidden'); navigateTo('login'); throw new Error('Session expired. Please log in again.'); }
    throw new Error(data.error || 'Request failed');
  }
  return data;
}
async function refreshState() {
  try {
    const d = await api('api/batches.php');
    S.batches = d.batches || []; S.flags = d.flags || []; S.seq = d.seq || S.seq; S.gseq = d.gseq || S.gseq; S.thr = d.thr || S.thr;
  } catch (e) { if (me) toast('Could not load data: ' + e.message); }
}

/* ---------- UI helpers ---------- */
function toast(m) { const t = $('#toast'); t.textContent = m; t.classList.remove('hidden'); clearTimeout(t._t); t._t = setTimeout(() => t.classList.add('hidden'), 3200); }
function modal(html) { $('#modal-box').innerHTML = html; $('#modal').classList.remove('hidden'); }
function closeModal() { $('#modal').classList.add('hidden'); }
function confirmBox(msg, yes) {
  window._yes = () => { closeModal(); yes(); };
  modal(`<h3>Are you sure?</h3><p>${msg}</p><div class="btn-group"><button class="btn-secondary" onclick="closeModal()">CANCEL</button><button class="btn-primary" onclick="_yes()">YES, CONTINUE</button></div>`);
}
const img = (src, n) => src ? `<img src="${esc(src)}" alt="${esc(n)}">` : `<div class="ph">&#128085;</div>`;
const stBadge = st => `<span class="badge ${{pending:'muted',auto:'success',manual:'info'}[st] || ''}">${{pending:'Waiting',auto:'Sorted (auto)',manual:'Sorted (manual)'}[st] || esc(st)}</span>`;

/* ---------- navigation ---------- */
const HOME = () => me && me.role === 'admin' ? 'admin' : 'intake';
const ALLOWED = {
  staff: ['intake','sorting','verification','batches','detail','password'],
  admin: ['admin','batches','detail']
};
function buildNav() {
  const n = unresolved().length;
  const items = me.role === 'admin'
    ? [['admin','Admin Dashboard'],['batches','List']]
    : [['intake','Intake'],['sorting','Sorting'],['verification','Manual' + (n ? `<span class="pill">${n}</span>` : '')],['batches','List'],['password','Profile']];
  $('#nav').innerHTML = items.map(([id,l]) => `<li data-s="${id}" onclick="navigateTo('${id}')">${l}</li>`).join('') + `<li onclick="logout()" class="logout">Logout</li>`;
}
function toggleMenu(force) {
  const open = force === undefined ? !$('#sidebar').classList.contains('open') : force;
  $('#sidebar').classList.toggle('open', open); $('#scrim').classList.toggle('show', open);
}
async function navigateTo(id) {
  stopCam(); toggleMenu(false);
  if (me && id !== 'login' && !ALLOWED[me.role].includes(id)) id = HOME();
  if (me) await refreshState();
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  document.body.classList.toggle('staff', !!me && me.role === 'staff');
  const hd = document.querySelector(`#${id}-screen .header-bar h2`); $('#topbar-title').textContent = hd ? hd.textContent : 'Laundry Mgmt';
  const t = $('#' + id + '-screen'); if (t) t.classList.add('active');
  if (me) { buildNav(); const li = document.querySelector(`#nav li[data-s="${id === 'detail' ? 'batches' : id}"]`); if (li) li.classList.add('active'); }
  ({intake:renderIntake, batches:renderBatches, detail:renderDetail, sorting:renderSorting, verification:renderVerify, admin:renderAdmin,
    password:() => { $('#pw-role').textContent = me.role; $('#pf-user').value = me.u || ''; ['old','new','new2'].forEach(k => $('#pw-'+k).value=''); $('#pw-err').textContent=''; }}[id] || (()=>{}))();
}

/* ---------- auth ---------- */
async function login() {
  const u = $('#username').value.trim(), p = $('#password').value;
  if (!u || !p) { $('#login-err').textContent = 'Please enter username and password.'; return; }
  try {
    const res = await api('api/auth.php?action=login', { method: 'POST', body: JSON.stringify({ username: u, password: p }) });
    me = res.user; $('#login-err').textContent = ''; $('#sidebar').classList.remove('hidden');
    navigateTo(HOME());
  } catch (e) { $('#login-err').textContent = e.message || 'Invalid username or password.'; }
}
async function logout() {
  try { await api('api/auth.php?action=logout', { method: 'POST' }); } catch (e) {}
  me = null; pend = null; $('#sidebar').classList.add('hidden'); navigateTo('login');
  $('#username').value = ''; $('#password').value = '';
}
async function changePassword() {
  const o = $('#pw-old').value, n = $('#pw-new').value, n2 = $('#pw-new2').value, e = $('#pw-err');
  if (!o || !n || !n2) return e.textContent = 'All fields are required.';
  if (n.length < 6) return e.textContent = 'New password must be at least 6 characters.';
  if (n !== n2) return e.textContent = 'New passwords do not match.';
  try {
    await api('api/auth.php?action=change_password', { method: 'POST', body: JSON.stringify({ old_password: o, new_password: n, confirm_password: n2 }) });
    me.p = n; navigateTo('password'); toast('Password updated');
  } catch (err) { e.textContent = err.message || 'Failed to update password.'; }
}

/* ---------- camera ---------- */
async function startCam(sec) {
  const box = $(`#${sec}-screen .camera-preview`), v = box.querySelector('video'), m = box.querySelector('.cam-msg');
  box.classList.remove('live'); m.textContent = 'Starting camera...';
  try {
    if (!navigator.mediaDevices?.getUserMedia) throw 0;
    stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment'}}); v.srcObject = stream; box.classList.add('live');
  } catch (e) { m.innerHTML = '&#9888; Camera blocked or unavailable.<br>Allow camera access in your browser, or use <b>Upload Photo</b>.'; }
}
function stopCam() { if (stream) stream.getTracks().forEach(t => t.stop()); stream = null; }
function toJpeg(src, w, h) {
  const c = document.createElement('canvas'); c.width = IMG_W; c.height = Math.round(IMG_W * h / w);
  c.getContext('2d').drawImage(src, 0, 0, c.width, c.height); return c.toDataURL('image/jpeg', .7);
}
function snap(sec) { const v = $(`#${sec}-screen video`); if (!stream || !v || !v.videoWidth) return null; return toJpeg(v, v.videoWidth, v.videoHeight); }
function shrink(file, cb) {
  const r = new FileReader(); r.onload = () => { const im = new Image(); im.onload = () => cb(toJpeg(im, im.width, im.height)); im.src = r.result; }; r.readAsDataURL(file);
}
function pickFile(sec) { fileFor = sec; $('#file').value = ''; $('#file').click(); }
$('#file').addEventListener('change', e => { const f = e.target.files[0]; if (f) shrink(f, d => gotPhoto(fileFor, d)); });
function capture(sec) { const d = snap(sec); if (d) gotPhoto(sec, d); else toast('Camera not available - use Upload Photo'); }
function gotPhoto(sec, d) { sec === 'intake' ? addItem(d) : runMatch(d); }

/* ---------- intake: scan + label each garment ---------- */
function renderIntake() { startCam('intake'); drawDraft(); }
function drawDraft() {
  $('#in-name').value = draft.name; $('#in-id').value = 'ITEM-' + (S.gseq + draft.items.length);
  $('#cust-list').innerHTML = [...new Set(S.batches.map(b => b.customer))].map(n => `<option value="${esc(n)}">`).join('');
  $('#in-thumbs').innerHTML = draft.items.length
    ? draft.items.map((i,k) => `<div class="thumb">${img(i.img,i.name)}<span class="text-sm">ITEM-${S.gseq + k}</span><input class="lbl" maxlength="100" value="${esc(i.name)}" oninput="draft.items[${k}].name=this.value" aria-label="Label"><a href="#" onclick="rmItem(${k});return false">&times; remove</a></div>`).join('')
    : '<div class="empty-state w-full">No garments scanned yet.</div>';
}
function addItem(d) { draft.items.push({name: 'Item ' + (draft.items.length + 1), img: d}); drawDraft(); }
function rmItem(k) { draft.items.splice(k, 1); draft.items.forEach((i, n) => { if (/^Item \d+$/.test(i.name)) i.name = 'Item ' + (n + 1); }); drawDraft(); }
function resetDraft() { draft = {name:'', items:[]}; drawDraft(); }

async function saveBatch() {
  if (!draft.name.trim()) return toast('Enter the customer name first');
  if (!draft.items.length) return toast('Scan at least one garment');
  const btn = $('#in-save'); btn.disabled = true;
  try {
    const items = draft.items.map((i, k) => ({ name: i.name.trim() || 'Item ' + (k + 1), img: i.img }));
    const res = await api('api/batches.php', { method: 'POST', body: JSON.stringify({ customer: draft.name.trim(), items }) });
    draft = {name:'', items:[]};
    toast(`Batch #${res.id} saved`);
    $('#search').value = '';
    await navigateTo('batches');
  } catch (e) { toast('Not saved: ' + e.message); }   // never pretend it was saved
  btn.disabled = false;
}

/* ---------- list: clothes per customer ---------- */
function groupByCustomer(batches) {
  const m = new Map();
  batches.forEach(b => { if (!m.has(b.customer_id)) m.set(b.customer_id, {id: b.customer_id, name: b.customer, batches: []}); m.get(b.customer_id).batches.push(b); });
  return [...m.values()];
}
function renderBatches() {
  const q = ($('#search').value || '').toLowerCase();
  const list = S.batches.filter(b => (b.id + ' ' + b.customer).toLowerCase().includes(q));
  const groups = groupByCustomer(list);
  $('#batch-list').innerHTML = groups.length ? groups.map(g => {
    const items = g.batches.flatMap(b => b.items), w = items.filter(i => i.st === 'pending').length;
    return `<div class="batch-item" onclick="cur='${g.id}';navigateTo('detail')"><div><b>${esc(g.name)}</b><br><span class="text-sm">${items.length} garment${items.length === 1 ? '' : 's'} - ${w} waiting, ${items.length - w} sorted</span></div><span class="badge ${w ? 'muted' : 'success'}">${w ? w + ' waiting' : 'All sorted'}</span></div>`;
  }).join('') : `<div class="empty-state">${q ? 'No customers match your search.' : 'No garments recorded yet.'}</div>`;
}
function renderDetail() {
  const bs = S.batches.filter(b => b.customer_id === String(cur));
  if (!bs.length) return navigateTo('batches');
  const items = bs.flatMap(b => b.items), w = items.filter(i => i.st === 'pending').length;
  $('#d-title').textContent = bs[0].customer.toUpperCase(); $('#d-status').textContent = w ? w + ' waiting' : 'All sorted';
  $('#detail-body').innerHTML = `<p class="text-sm">${items.length} garments - ${count({items},'auto')} auto-sorted, ${count({items},'manual')} manual, ${w} waiting</p>`
    + bs.map(b => `<div class="batch-group"><p><b>Batch #${b.id}</b> <span class="badge ${b.status === 'Completed' ? 'success' : 'muted'}">${LABEL[b.status] || b.status}</span> <span class="text-sm">received ${new Date(b.created).toLocaleString()}${b.done ? ' - completed ' + new Date(b.done).toLocaleString() : ''}</span></p>
        <div class="garments">${b.items.map(i => `<div class="thumb">${img(i.img,i.name)}${esc(i.name)}<br><span class="text-sm">ITEM-${i.id}</span><br>${stBadge(i.st)}</div>`).join('')}</div></div>`).join('')
    + `<div class="btn-group mt-2"><button class="btn-secondary" onclick="navigateTo('batches')">&larr; BACK TO LIST</button></div>`;
}

/* ---------- sorting: scan any garment, find its owner across ALL customers ---------- */
function renderSorting() { drawSort(); if (waiting().length) startCam('sorting'); else { $('#sorting-screen .cam-msg').textContent = 'Nothing waiting. Scan garments at Intake first.'; $('#sorting-screen .camera-preview').classList.remove('live'); } }
function drawSort() {
  const w = waiting(), r = $('#sort-result');
  $('#s-badge').textContent = w.length + ' waiting';
  $('#s-progress').textContent = w.length ? `${w.length} garment${w.length === 1 ? '' : 's'} waiting across ${new Set(w.map(x => x.b.customer_id)).size} customer(s). Scan any garment - the system finds its owner.` : 'All garments are sorted.';
  $('#s-scan').disabled = !w.length; $('#s-confirm').disabled = true; $('#s-reject').disabled = true; r.classList.add('hidden'); r.innerHTML = '';
}
async function runMatch(d) {
  const r = $('#sort-result'); r.classList.remove('hidden'); r.innerHTML = '<div class="loading w-full">Analyzing visual features...</div>';
  $('#s-confirm').disabled = true; $('#s-reject').disabled = true; $('#s-scan').disabled = true;
  try {
    const res = await api('api/match.php', { method: 'POST', body: JSON.stringify({ scanned_img: d }) });
    $('#s-scan').disabled = false;
    pend = { scan: d, list: res.list, top: res.top, thr: res.thr };
    const top = res.list[0], f = findItem(top.id);
    if (res.auto_matched) {
      r.innerHTML = `<div class="scanned-img">${img(d,'scan')}</div><div class="match-info"><p><strong>Owner: ${esc(top.customer)}</strong></p><p>${esc(top.name)} - ITEM-${top.id}</p><p class="text-sm">Similarity ${top.sim}% (threshold ${res.thr}%)</p><span class="badge success">RECOGNIZED (${res.duration}s)</span></div>${img(f ? f.i.img : '', top.name)}`;
      $('#s-confirm').disabled = false; $('#s-reject').disabled = false;
    } else {
      r.classList.add('hidden');
      toast(res.ambiguous ? `Two similar garments (${top.sim}% / ${res.list[1].sim}%) - choose manually` : `Low confidence (${top.sim}%) - choose manually`);
      navigateTo('verification');
    }
  } catch (e) { $('#s-scan').disabled = false; r.classList.add('hidden'); r.innerHTML = ''; toast('Matching failed: ' + e.message); }
}
function rejectMatch() { if (pend) navigateTo('verification'); }
async function confirmSort() {
  if (!pend?.top) return;
  $('#s-confirm').disabled = true;
  try {
    const sim = (pend.list.find(c => c.id === pend.top) || {}).sim;
    const res = await api('api/sort_confirm.php', { method: 'POST', body: JSON.stringify({ item_id: pend.top, st: 'auto', sim }) });
    await afterSort(res, 'stay');
  } catch (e) { toast(e.message); pend = null; await refreshState(); drawSort(); }
}
async function afterSort(res, goto) {
  pend = null;
  if (goto === 'stay') { await refreshState(); drawSort(); buildNav(); } else await navigateTo(goto);
  if (res.completed) {
    const b = B(res.batch_id);
    if (b) return modal(`<h3>&#9989; Batch #${b.id} complete</h3><p>All ${b.items.length} garments for <b>${esc(b.customer)}</b> are sorted and ready to hand back.</p><div class="btn-group"><button class="btn-primary" onclick="closeModal()">CONTINUE</button></div>`);
  }
  toast('Garment sorted');
}

/* ---------- manual: not identified automatically ---------- */
function flaggedHTML() {
  const u = unresolved();
  if (!u.length) return '';
  return `<p class="text-sm" style="margin-top:20px">Set aside - owner unknown (${u.length})</p>` + u.map(f => `<div class="row"><div>${img(f.img,'')}<span>Unidentified garment</span></div><button class="btn-primary" style="width:auto" onclick="openResolve('${f.id}')">Resolve</button></div>`).join('');
}
function renderVerify() {
  const el = $('#verify-body'); pick = null;
  if (!pend) return el.innerHTML = '<div class="empty-state">Nothing to verify. Garments the Sorting tab cannot identify appear here.</div>' + flaggedHTML() + '<button class="btn-secondary w-full mt-2" onclick="navigateTo(\'sorting\')">&larr; BACK TO SORTING</button>';
  el.innerHTML = `<p class="text-center text-sm">Not identified - select the correct owner</p>
    <div class="camera-preview small-preview"><img src="${esc(pend.scan)}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:contain"></div>
    <div class="form-group"><input type="text" id="v-search" placeholder="&#128269; Search customer or item (shows closest 5 by default)" oninput="drawCands()"></div>
    <div id="v-cands"></div>
    <div class="btn-group mt-2"><button class="btn-primary" id="v-ok" disabled onclick="confirmSel()">&#10003; CONFIRM SELECTED</button>
    <button class="btn-warning" onclick="confirmBox('Set this garment aside? You can assign it to a customer later from this tab.',setAside)">&#9888; SET ASIDE</button></div>` + flaggedHTML();
  drawCands();
}
function drawCands() {
  const q = ($('#v-search').value || '').toLowerCase().trim();
  let l = pend.list; l = q ? l.filter(c => (c.customer + ' ' + c.name + ' item-' + c.id).toLowerCase().includes(q)).slice(0, 20) : l.slice(0, 5);
  $('#v-cands').innerHTML = l.length ? l.map(c => { const f = findItem(c.id);
    return `<div class="row pick ${pick === c.id ? 'sel' : ''}" onclick="selCand('${c.id}')"><div>${img(f ? f.i.img : '', c.name)}<span><b>${esc(c.customer)}</b> - ${esc(c.name)}<br><span class="text-sm">ITEM-${c.id} - similarity ${c.sim}%</span></span></div><span class="badge muted">select</span></div>`; }).join('')
    : '<div class="empty-state">No waiting garment matches your search.</div>';
  $('#v-ok').disabled = !pick;
}
function selCand(id) { pick = id; drawCands(); }
async function confirmSel() {
  if (!pick) return;
  $('#v-ok').disabled = true;
  try {
    const sim = (pend.list.find(c => c.id === pick) || {}).sim;
    const res = await api('api/sort_confirm.php', { method: 'POST', body: JSON.stringify({ item_id: pick, st: 'manual', sim }) });
    await afterSort(res, 'sorting');
  } catch (e) { toast(e.message); $('#v-ok').disabled = false; }
}
async function setAside() {
  try {
    await api('api/flags.php?action=flag', { method: 'POST', body: JSON.stringify({ img: pend.scan }) });
    pend = null; await navigateTo('sorting'); toast('Garment set aside');
  } catch (e) { toast('Not saved: ' + e.message); }
}
function openResolve(fid) {
  const f = S.flags.find(x => x.id === fid); if (!f) return;
  const w = waiting();
  window._dis = () => confirmBox('Dismiss this garment? Use this only if it is not one of the garments waiting in the system.', async () => {
    try { await api('api/flags.php?action=dismiss', { method: 'POST', body: JSON.stringify({ flag_id: fid }) }); await navigateTo('verification'); toast('Dismissed'); }
    catch (e) { toast(e.message); }
  });
  if (!w.length) return modal(`<h3>Unidentified garment</h3><div class="thumbs">${img(f.img,'')}</div><p>No garments are waiting, so this one cannot be assigned.</p><div class="btn-group"><button class="btn-secondary" onclick="closeModal()">CLOSE</button><button class="btn-primary" onclick="_dis()">DISMISS</button></div>`);
  window._res = () => {
    const id = $('#res-sel').value; if (!id) return;
    confirmBox('Assign this garment to the selected owner?', async () => {
      try { const res = await api('api/flags.php?action=assign', { method: 'POST', body: JSON.stringify({ flag_id: fid, item_id: id }) }); await afterSort(res, 'verification'); }
      catch (e) { toast(e.message); }
    });
  };
  const groups = groupByCustomer(w.map(x => x.b).filter((b, k, a) => a.indexOf(b) === k));
  modal(`<h3>Who owns this garment?</h3><div class="thumbs">${img(f.img,'')}</div><div class="form-group" style="margin-top:14px"><label>Owner / item</label><select id="res-sel">${groups.map(g => `<optgroup label="${esc(g.name)}">${g.batches.flatMap(b => b.items.filter(i => i.st === 'pending').map(i => `<option value="${i.id}">${esc(i.name)} (Batch #${b.id})</option>`)).join('')}</optgroup>`).join('')}</select></div><div class="btn-group"><button class="btn-secondary" onclick="closeModal()">CANCEL</button><button class="btn-warning" onclick="_dis()">DISMISS</button><button class="btn-primary" onclick="_res()">ASSIGN</button></div>`);
}

/* ---------- admin ---------- */
async function renderAdmin() {
  try {
    const data = await api('api/admin.php');
    S.users = data.users || []; S.thr = data.thr || S.thr;
    const sc = data.status_counts || {}, mx = Math.max(1, ...TABS.map(([s]) => sc[s] || 0));
    $('#admin-body').innerHTML = `<div class="stats-grid"><div class="stat-box"><h3>${data.accuracy}%</h3><p>Match Accuracy</p></div><div class="stat-box"><h3>${data.avg_time}s</h3><p>Avg. Match Time</p></div></div>
      <div class="bars">${TABS.map(([s,l]) => { const n = sc[s] || 0; return `<div><i style="height:${n / mx * 100}px"></i>${n}<br>${l}</div>`; }).join('')}</div>
      <div class="mt-2"><p class="text-sm">Match threshold (similarity needed for auto-match)</p>
      <div class="row"><input type="number" id="thr" min="50" max="99" value="${S.thr}" style="max-width:100px"> <span>%</span><button class="btn-primary" style="width:auto" onclick="saveThr()">SAVE</button></div></div>
      <div class="user-management mt-2"><p class="text-sm">Staff / User Management (RBAC)</p>
      ${S.users.map((u,k) => `<div class="row"><div><b>${esc(u.u)}</b> <span class="badge muted">${u.role}</span> <span class="badge ${u.on ? 'success' : 'danger'}">${u.on ? 'Active' : 'Deactivated'}</span></div><button class="btn-secondary" onclick="userForm(${k})">Edit</button></div>`).join('')}
      <button class="btn-secondary w-full mt-2" onclick="userForm(-1)">+ ADD USER</button></div>`;
  } catch (e) { $('#admin-body').innerHTML = `<div class="empty-state">Could not load the dashboard: ${esc(e.message)}</div>`; }
}
async function saveThr() {
  const v = +$('#thr').value;
  if (!(v >= 50 && v <= 99)) return toast('Enter a value from 50 to 99');
  try { await api('api/admin.php?action=threshold', { method: 'POST', body: JSON.stringify({ thr: v }) }); S.thr = v; toast('Threshold saved'); }
  catch (e) { toast('Not saved: ' + e.message); }
}
function userForm(k) {
  const u = k < 0 ? {id: -1, u:'', p:'', role:'staff', on:true} : S.users[k];
  window._u = async () => {
    const n = $('#uf-u').value.trim(), p = $('#uf-p').value, e = $('#uf-err');
    if (!n) return e.textContent = 'Username is required.';
    if (k < 0 && p.length < 6) return e.textContent = 'Password must be at least 6 characters.';
    if (k >= 0 && p && p.length < 6) return e.textContent = 'Password must be at least 6 characters.';
    const on = $('#uf-on').checked, role = $('#uf-role').value;
    if (k >= 0 && S.users[k]?.u === me?.u && (!on || role !== 'admin')) return e.textContent = "You can't deactivate or demote your own account.";
    try { await api('api/admin.php?action=save_user', { method: 'POST', body: JSON.stringify({ id: u.id, u: n, p, role, on }) }); closeModal(); renderAdmin(); toast('User saved'); }
    catch (err) { e.textContent = err.message || 'Failed to save user.'; }
  };
  modal(`<h3>${k < 0 ? 'Add User' : 'Edit User'}</h3>
    <div class="form-group"><label>Username</label><input id="uf-u" value="${esc(u.u)}"></div>
    <div class="form-group"><label>Password ${k < 0 ? '' : '(leave blank to keep)'}</label><input type="password" id="uf-p"></div>
    <div class="form-group"><label>Role</label><select id="uf-role"><option value="staff" ${u.role === 'staff' ? 'selected' : ''}>Staff</option><option value="admin" ${u.role === 'admin' ? 'selected' : ''}>Admin</option></select></div>
    <label><input type="checkbox" class="check" id="uf-on" ${u.on ? 'checked' : ''}> Account active (untick to deactivate)</label>
    <p class="err" id="uf-err" style="margin-top:8px"></p>
    <div class="btn-group"><button class="btn-secondary" onclick="closeModal()">CANCEL</button><button class="btn-primary" onclick="_u()">SAVE USER</button></div>`);
}
