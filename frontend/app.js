import { createScanner } from './scanner.js';
const $ = (selector, root = document) => root.querySelector(selector);
const nutrients = ['calories', 'protein', 'carbs', 'fat'];
const apiBase = new URL('./api/', document.baseURI);
const format = (value, digits = 0) => Number(value).toLocaleString(undefined, { maximumFractionDigits: digits });
const state = { csrf: '', today: '', date: '', targets: {}, entries: [], foods: [], food: null, entry: null, editFood: null, requestKey: null };
let journalVersion = 0;
let searchVersion = 0;
let noticeTimeout;

async function api(path, method = 'GET', data) {
  const response = await fetch(new URL(path.replace(/^\//, ''), apiBase), {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf },
    body: data === undefined ? undefined : JSON.stringify(data),
  });
  let result;
  try { result = await response.json(); } catch { throw new Error('The server returned an unexpected response. Please try again.'); }
  if (!response.ok) {
    if (response.status === 401 && path !== '/login') {
      document.querySelectorAll('dialog[open]').forEach(dialog => dialog.close());
      $('#app').hidden = true;
      $('#login-screen').hidden = false;
    }
    throw new Error(result.error || 'Could not complete the request.');
  }
  return result;
}

function notify(message) {
  clearTimeout(noticeTimeout);
  $('#notice').textContent = message;
  $('#notice').hidden = false;
  noticeTimeout = setTimeout(() => { $('#notice').hidden = true; }, 4500);
}

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function empty(container, title, subtitle, action) {
  const box = element('div', 'empty-state');
  box.append(element('span', 'empty-symbol', '＋'), element('h3', '', title), element('p', '', subtitle));
  if (action) {
    const button = element('button', 'text-button', action.label);
    button.addEventListener('click', action.run);
    box.append(button);
  }
  container.replaceChildren(box);
}

function openDialog(id) {
  const dialog = $(id);
  $('.form-error', dialog).textContent = '';
  dialog.showModal();
}

async function submit(form, work) {
  if (form.dataset.busy) return;
  form.dataset.busy = 'true';
  const controls = [...form.querySelectorAll('button, input, select')];
  $('.form-error', form).textContent = '';
  controls.forEach(control => { control.disabled = true; });
  try { await work(); } catch (error) { $('.form-error', form).textContent = error.message; }
  finally { controls.forEach(control => { control.disabled = false; }); delete form.dataset.busy; }
}

function showView(view) {
  const journal = view === 'journal';
  $('#journal-view').hidden = !journal;
  $('#foods-view').hidden = journal;
  for (const name of ['journal', 'foods']) {
    $(`#${name}-tab`).classList.toggle('active', name === view);
    if (name === view) $(`#${name}-tab`).setAttribute('aria-current', 'page');
    else $(`#${name}-tab`).removeAttribute('aria-current');
  }
  if (!journal) {
    $('#logging-date').textContent = `LOGGING FOR ${state.date === state.today ? 'TODAY' : state.date}`;
    loadFoods();
    $('#food-search').focus();
  }
}

function renderJournal(data) {
  state.entries = data.entries;
  $('#entry-count').textContent = data.entries.length;
  for (const nutrient of nutrients) {
    const total = data.totals[nutrient];
    const goal = Number(state.targets[nutrient] || 0);
    $(`#${nutrient}-total`).textContent = format(total, nutrient === 'calories' ? 0 : 1);
    $(`#${nutrient}-goal`).textContent = nutrient === 'calories'
      ? (goal ? `of ${format(goal)} kcal` : 'kcal logged')
      : (goal ? ` / ${format(goal)} g` : ' g');
    if (nutrient !== 'calories') {
      $(`#${nutrient}-progress`).value = goal ? Math.min(100, total / goal * 100) : 0;
    }
  }
  const target = Number(state.targets.calories || 0);
  const ratio = target ? Math.min(100, data.totals.calories / target * 100) : 0;
  $('#energy-progress').setAttribute('stroke-dasharray', `${ratio} 100`);
  const remaining = target - data.totals.calories;
  $('#calories-remaining').textContent = target
    ? `${format(Math.abs(remaining))} kcal ${remaining >= 0 ? 'remaining' : 'above your target'}`
    : 'Set daily targets whenever you’re ready.';
  const container = $('#entries');
  container.replaceChildren();
  if (!data.entries.length) {
    empty(container, 'A fresh page for your day.', 'Add your first food to start building the picture.', { label: 'Find a food to log', run: () => showView('foods') });
  }
  for (const entry of data.entries) {
    const row = element('div', 'entry-row');
    const info = element('div', 'row-info');
    info.append(element('h3', '', entry.food_name), element('p', '', `${format(entry.quantity, 3)} ${entry.basis_unit} · P ${format(entry.totals.protein, 1)} · C ${format(entry.totals.carbs, 1)} · F ${format(entry.totals.fat, 1)}`));
    const energy = element('div', 'row-energy');
    energy.append(element('strong', '', format(entry.totals.calories)), document.createTextNode('kcal'));
    const edit = element('button', 'text-button', 'Edit');
    edit.setAttribute('aria-label', `Edit ${entry.food_name}`);
    edit.addEventListener('click', () => openQuantity(entry, true));
    const icon = element('span', 'entry-icon', '◒');
    icon.setAttribute('aria-hidden', 'true');
    row.append(icon, info, energy, edit);
    container.append(row);
  }
}

async function loadJournal() {
  const version = ++journalVersion;
  const date = state.date;
  $('#journal-view').setAttribute('aria-busy', 'true');
  $('#journal-error').hidden = true;
  // Remove the previous day's values while the requested day loads.
  for (const key of nutrients) {
    $(`#${key}-total`).textContent = '—';
    if (key !== 'calories') $(`#${key}-progress`).value = 0;
  }
  $('#energy-progress').setAttribute('stroke-dasharray', '0 100');
  $('#calories-remaining').textContent = 'Loading your day…';
  $('#entries').replaceChildren(element('div', 'empty-state', 'Loading your journal…'));
  try {
    const [data, targets] = await Promise.all([api(`/logs?date=${date}`), api('/targets')]);
    if (version !== journalVersion) return;
    state.targets = targets;
    renderJournal(data);
  } catch (error) {
    if (version !== journalVersion) return;
    $('#journal-error').textContent = error.message;
    $('#journal-error').hidden = false;
    $('#calories-remaining').textContent = 'Your day could not be loaded.';
    empty($('#entries'), 'Couldn’t load your journal.', 'Your saved entries are still on the server.', { label: 'Try again', run: loadJournal });
  } finally {
    if (version === journalVersion) $('#journal-view').removeAttribute('aria-busy');
  }
}

function setDate(date) {
  state.date = date;
  $('#log-date').value = date;
  $('#date-caption').textContent = new Date(`${date}T12:00:00`).toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }).toUpperCase();
  $('#journal-title').textContent = date === state.today ? 'One day at a time.' : 'A look at your day.';
  return loadJournal();
}

async function loadFoods() {
  const version = ++searchVersion;
  const search = $('#food-search').value.trim();
  $('#food-results').setAttribute('aria-busy', 'true');
  try {
    const foods = await api(`/foods?q=${encodeURIComponent(search)}`);
    if (version !== searchVersion) return;
    state.foods = foods;
    $('#food-list-caption').textContent = search ? 'SEARCH RESULTS · UP TO 50' : 'RECENTLY USED & ADDED · UP TO 50';
    const container = $('#food-results');
    container.replaceChildren();
    if (!foods.length) empty(container, search ? 'No foods found.' : 'Make this space your own.', search ? 'Try a different name, or save a new food.' : 'Create a food from its nutrition label. Your favorites will be easy to find here.', { label: 'Create a food', run: () => openFood() });
    for (const food of foods) {
      const row = element('div', 'food-row');
      const info = element('div', 'row-info');
      info.append(element('h3', '', food.name), element('p', '', `${format(food.calories)} kcal per ${format(food.basis_amount, 3)} ${food.basis_unit} · P ${format(food.protein, 1)} · C ${format(food.carbs, 1)} · F ${format(food.fat, 1)}`));
      const actions = element('div', 'food-actions');
      const edit = element('button', 'text-button', 'Edit');
      edit.setAttribute('aria-label', `Edit ${food.name}`);
      edit.addEventListener('click', () => openFood(food));
      const add = element('button', 'button', '＋ Log');
      add.setAttribute('aria-label', `Log ${food.name}`);
      add.addEventListener('click', () => openQuantity(food));
      actions.append(edit, add);
      row.append(info, actions);
      container.append(row);
    }
  } catch (error) {
    if (version === searchVersion) empty($('#food-results'), 'Couldn’t load your foods.', error.message, { label: 'Try again', run: loadFoods });
  } finally {
    if (version === searchVersion) $('#food-results').removeAttribute('aria-busy');
  }
}

function openFood(food = null, draft = null, note = '') {
  state.editFood = food;
  const form = $('#food-form');
  form.reset();
  if (food || draft) for (const key of ['name', 'barcode', 'basis_amount', 'basis_unit', ...nutrients]) form.elements[key].value = (food || draft)[key] ?? '';
  else form.elements.name.value = $('#food-search').value.trim();
  $('#food-dialog-title').textContent = food ? 'Edit food' : draft ? 'Review this food' : 'Create a food';
  $('#barcode-review-note').textContent = note;
  $('#barcode-review-note').hidden = !note;
  $('#food-edit-note').hidden = !food;
  openDialog('#food-dialog');
}

function openQuantity(item, editing = false) {
  state.entry = editing ? item : null;
  state.food = item;
  state.requestKey = crypto.randomUUID();
  $('#quantity-title').textContent = editing ? item.food_name : item.name;
  $('#quantity-caption').textContent = editing ? 'EDIT JOURNAL ENTRY' : `ADD TO ${state.date === state.today ? 'TODAY' : state.date}`;
  $('#quantity-basis').textContent = `${format(item.calories)} kcal per ${format(item.basis_amount, 3)} ${item.basis_unit}`;
  $('#quantity-unit').textContent = `(${item.basis_unit})`;
  $('#quantity-form').elements.quantity.value = editing ? item.quantity : item.basis_amount;
  $('#quantity-submit').textContent = editing ? 'Save changes' : 'Add to journal';
  $('#delete-entry').hidden = !editing;
  renderQuantity();
  openDialog('#quantity-dialog');
  $('#quantity-form').elements.quantity.select();
}

function renderQuantity() {
  const quantity = Number($('#quantity-form').elements.quantity.value);
  const container = $('#quantity-preview');
  container.replaceChildren();
  nutrients.forEach((key, index) => {
    const block = element('p');
    const value = quantity > 0 && quantity <= 1000000 ? format(Number(state.food[key]) * quantity / Number(state.food.basis_amount), key === 'calories' ? 0 : 1) : '—';
    block.append(element('strong', '', value), document.createTextNode(['kcal', 'protein (g)', 'carbs (g)', 'fat (g)'][index]));
    container.append(block);
  });
}

$('#journal-tab').addEventListener('click', () => showView('journal'));
$('#foods-tab').addEventListener('click', () => showView('foods'));
$('#add-food').addEventListener('click', () => showView('foods'));
$('#create-food').addEventListener('click', () => openFood());
const scannerForm = $('#scanner-form');
const scanner = createScanner({
  elementId: 'barcode-camera',
  onState: status => {
    $('#start-camera').hidden = status !== 'idle';
    $('#stop-camera').hidden = status === 'idle';
    $('#camera-select-label').hidden = true;
    $('#camera-status').textContent = status === 'starting' ? 'Waiting for camera access…' : status === 'running' ? 'Scanning… Keep the bars vertical and the whole barcode inside the box, with white space at both ends.' : '';
  },
  onProgress: () => {
    $('#camera-status').textContent = 'Scanning, but no barcode read yet. Fill the box with the whole barcode, keep it level, and slowly move closer or farther away. You can also try another camera below.';
  },
  onCameras: (devices, selected) => {
    const select = $('#camera-select');
    select.replaceChildren();
    devices.forEach((device, index) => {
      const option = element('option', '', device.label || `Camera ${index + 1}`);
      option.value = device.deviceId;
      select.append(option);
    });
    select.value = selected;
    $('#camera-select-label').hidden = devices.length < 2;
  },
  onError: message => { $('#camera-status').textContent = message; },
  onScan: code => {
    if (!$('#scanner-dialog').open) return;
    scannerForm.elements.barcode.value = code;
    scannerForm.requestSubmit();
  },
});
$('#scan-food').addEventListener('click', () => {
  scannerForm.reset();
  $('#camera-status').textContent = '';
  openDialog('#scanner-dialog');
});
$('#start-camera').addEventListener('click', () => {
  if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
    $('#camera-status').textContent = 'Camera access needs HTTPS and a supported browser. You can enter the barcode below.';
    return;
  }
  void scanner.start();
});
$('#stop-camera').addEventListener('click', () => { void scanner.stop(); });
$('#camera-select').addEventListener('change', event => { void scanner.start(event.target.value); });
$('#scanner-dialog').addEventListener('close', () => { void scanner.stop(); });
document.addEventListener('visibilitychange', () => { if (document.hidden) void scanner.stop(); });
window.addEventListener('pagehide', () => { void scanner.stop(); });
scannerForm.addEventListener('submit', event => {
  event.preventDefault();
  const barcode = scannerForm.elements.barcode.value;
  submit(scannerForm, async () => {
    await scanner.stop();
    const result = await api('/foods/lookup', 'POST', { barcode });
    if (!$('#scanner-dialog').open) return;
    $('#scanner-dialog').close();
    if (result.cached) openQuantity(result.food);
    else openFood(null, result.food, result.found
      ? 'Check these values against the label. Confirm the unit (100 g or 100 ml), and fill in any missing nutrients before saving.'
      : 'This barcode was not found. Enter the label’s nutrition and save it for your next scan.');
  });
});
$('#manual-barcode').addEventListener('click', async () => {
  const barcode = scannerForm.elements.barcode.value.replace(/[\s-]+/g, '');
  if (!/^(?:\d{8}|\d{12,14})$/.test(barcode)) {
    $('.form-error', scannerForm).textContent = 'Enter an 8, 12, 13, or 14 digit product barcode first.';
    return;
  }
  await scanner.stop();
  $('#scanner-dialog').close();
  openFood(null, { barcode, basis_amount: 100, basis_unit: '' }, 'Enter the nutrition from the package. This barcode will be remembered.');
});
$('#log-date').addEventListener('change', event => { if (event.target.value && event.target.validity.valid) setDate(event.target.value); });
for (const [id, increment] of [['previous-day', -1], ['next-day', 1]]) {
  $(`#${id}`).addEventListener('click', () => {
    const date = new Date(`${state.date}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + increment);
    const value = date.toISOString().slice(0, 10);
    if (value >= '1000-01-01' && value <= '9999-12-31') setDate(value);
  });
}
$('#today-button').addEventListener('click', async () => {
  showView('journal');
  try { const session = await api('/session'); state.today = session.today; await setDate(state.today); } catch (error) { notify(error.message); }
});
let searchTimer;
$('#food-search').addEventListener('input', () => {
  ++searchVersion;
  clearTimeout(searchTimer);
  searchTimer = setTimeout(loadFoods, 180);
});
document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
document.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('cancel', event => { if ($('form', dialog).dataset.busy) event.preventDefault(); }));
$('#quantity-form').elements.quantity.addEventListener('input', () => { state.requestKey = crypto.randomUUID(); renderQuantity(); });

$('#food-form').addEventListener('submit', event => {
  event.preventDefault();
  const form = event.target;
  const data = Object.fromEntries(new FormData(form));
  submit(form, async () => {
    const editing = Boolean(state.editFood);
    const food = await api(editing ? `/foods/${state.editFood.id}` : '/foods', editing ? 'PUT' : 'POST', data);
    $('#food-dialog').close();
    $('#food-search').value = '';
    await loadFoods();
    notify(editing ? 'Food updated. Past entries are unchanged.' : 'Food saved to your library.');
    if (!editing) openQuantity(food);
  });
});
$('#quantity-form').addEventListener('submit', event => {
  event.preventDefault();
  const form = event.target;
  const quantity = form.elements.quantity.value;
  submit(form, async () => {
    const editing = Boolean(state.entry);
    if (editing) await api(`/logs/${state.entry.id}`, 'PATCH', { quantity });
    else await api('/logs', 'POST', { food_id: Number(state.food.id), quantity, date: state.date, request_key: state.requestKey });
    $('#quantity-dialog').close();
    showView('journal');
    await loadJournal();
    notify(editing ? 'Entry updated.' : 'Added to your journal.');
  });
});
$('#delete-entry').addEventListener('click', () => {
  if (!state.entry || !confirm(`Delete ${state.entry.food_name} from this day?`)) return;
  submit($('#quantity-form'), async () => {
    await api(`/logs/${state.entry.id}`, 'DELETE');
    $('#quantity-dialog').close();
    await loadJournal();
    notify('Entry deleted.');
  });
});
$('#targets-button').addEventListener('click', async () => {
  const button = $('#targets-button');
  button.disabled = true;
  try {
    const targets = await api('/targets');
    for (const key of nutrients) $('#targets-form').elements[key].value = targets[key];
    openDialog('#targets-dialog');
  } catch (error) { notify(error.message); }
  finally { button.disabled = false; }
});
$('#targets-form').addEventListener('submit', event => {
  event.preventDefault();
  const data = Object.fromEntries(new FormData(event.target));
  submit(event.target, async () => {
    state.targets = await api('/targets', 'PUT', data);
    $('#targets-dialog').close();
    await loadJournal();
    notify('Daily targets saved.');
  });
});
$('#login-form').addEventListener('submit', event => {
  event.preventDefault();
  const data = Object.fromEntries(new FormData(event.target));
  submit(event.target, async () => { await api('/login', 'POST', data); event.target.reset(); await start(); });
});
$('#logout-button').addEventListener('click', async () => {
  try { await api('/logout', 'POST', {}); await start(); } catch (error) { notify(error.message); }
});

async function start() {
  try {
    const session = await api('/session');
    state.csrf = session.csrf;
    state.today = session.today;
    $('#app').hidden = !session.authenticated;
    $('#login-screen').hidden = session.authenticated;
    $('#logout-button').hidden = session.local_development;
    if (session.authenticated) await setDate(state.date || session.today);
  } catch (error) {
    $('#login-screen').hidden = false;
    $('.form-error', $('#login-form')).textContent = `${error.message} Reload the page to reconnect.`;
  }
}
start();
