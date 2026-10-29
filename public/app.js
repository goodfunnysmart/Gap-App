'use strict';

/* Task text and photos stay in memory for this page only. Nothing is written to localStorage. */

var SYMPTOMS = [
  { id: 'start', label: "Don't know where to start", tone: 'green', icon: 'sad' },
  { id: 'wrong', label: 'Keep getting it wrong', tone: 'amber', icon: 'meh' },
  { id: 'understand', label: "Don't understand the task", tone: 'plain', icon: 'help' },
  { id: 'partway', label: 'Get stuck partway', tone: 'plain', icon: 'sign' },
  { id: 'ages', label: 'Takes ages', tone: 'plain', icon: 'clock' },
  { id: 'forgets', label: 'Forgets it next day', tone: 'plain', icon: 'brain' }
];

var state = {
  csrf: '',
  ai: false,
  step: 'task',
  task: '',
  draft: '',
  sample: false,
  file: null,
  preview: '',
  wordsOpen: false,
  pasteNote: '',
  candidates: [],
  selected: '',
  symptoms: {},
  history: [],
  plan: null,
  wording: {},
  polished: false,
  busy: false,
  error: ''
};

var app = document.getElementById('app');

function el(tag, props) {
  var node = document.createElement(tag);
  var kids = Array.prototype.slice.call(arguments, 2);
  Object.keys(props || {}).forEach(function (key) {
    var value = props[key];
    if (value == null || value === false) return;
    if (key === 'class') node.className = value;
    else if (key === 'text') node.textContent = value;
    else if (key === 'click') node.addEventListener('click', value);
    else if (key === 'input') node.addEventListener('input', value);
    else node.setAttribute(key, value === true ? '' : value);
  });
  kids.forEach(function (kid) {
    if (kid == null || kid === false) return;
    node.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
  });
  return node;
}

function svgWrap(markup) {
  var span = document.createElement('span');
  span.className = 'ico';
  span.setAttribute('aria-hidden', 'true');
  span.innerHTML = markup;
  return span;
}

function icon(name) {
  var common = 'viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
  var paths = {
    camera: '<svg ' + common + '><path d="M4 8h3l2-2h6l2 2h3v10H4z"/><circle cx="12" cy="13" r="3.2"/></svg>',
    clip: '<svg ' + common + '><rect x="6" y="4" width="12" height="16" rx="2"/><path d="M9 4.5h6M9 9h6M9 13h4"/></svg>',
    pencil: '<svg ' + common + ' width="18" height="18"><path d="M4 16.5l3.2-.6L16 7.2 13.3 4.5 4.6 13.2z"/><path d="M12.2 5.6l2.7 2.7"/></svg>',
    shield: '<svg ' + common + '><path d="M12 3l7 3v6c0 4.2-2.8 7.2-7 8.5C7.8 19.2 5 16.2 5 12V6z"/><path d="M9 12l2 2 4-4"/></svg>',
    sad: '<svg ' + common + '><circle cx="12" cy="12" r="8"/><path d="M8.5 10h.01M15.5 10h.01M8.5 15.5c1.2-1.2 5.8-1.2 7 0"/></svg>',
    meh: '<svg ' + common + '><circle cx="12" cy="12" r="8"/><path d="M8.5 10h.01M15.5 10h.01M8.5 15h7"/></svg>',
    help: '<svg ' + common + '><circle cx="12" cy="12" r="8"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.2 2.4c-.7.3-1.2.9-1.2 1.6V14"/><path d="M12 17h.01"/></svg>',
    sign: '<svg ' + common + '><path d="M5 20V4M5 6h11l-2 3 2 3H5"/></svg>',
    clock: '<svg ' + common + '><circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/></svg>',
    brain: '<svg ' + common + '><path d="M9 7a3 3 0 0 1 5.8-1A3 3 0 0 1 18 9a2.5 2.5 0 0 1 .5 5 3 3 0 0 1-4 3.5M9 7a3 3 0 0 0-3 3 2.5 2.5 0 0 0 .2 5A3 3 0 0 0 10 18"/><path d="M12 7v11"/></svg>',
    flag: '<svg ' + common + '><path d="M6 20V4"/><path d="M6 5h11l-2 3 2 3H6"/></svg>',
    check: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M5 12.5l4.2 4.2L19 7"/></svg>'
  };
  return svgWrap(paths[name] || paths.help);
}

function glyph(kind) {
  var box = el('span', { class: 'glyph' + (kind === 'square' ? ' amber' : ''), 'aria-hidden': 'true' });
  if (kind === 'area') {
    box.append(svgWrap('<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4.5" y="4.5" width="15" height="15" rx="1.5"/></svg>'));
    return box;
  }
  box.textContent = { fraction: 'a/b', square: 'n²', measure: 'cm', number: '#' }[kind] || '#';
  return box;
}

function dots(step) {
  var row = el('div', { class: 'dots', role: 'img', 'aria-label': 'Step ' + step + ' of 3' });
  for (var i = 1; i <= 3; i++) row.append(el('span', { class: i <= step ? 'dot on' : 'dot' }));
  return row;
}

function stepRow(n) {
  return el('div', { class: 'step-row' },
    el('span', { class: 'step-label', text: 'Step ' + n + ' of 3' }),
    dots(n)
  );
}

function goButton(label, enabled, click) {
  return el('button', { class: 'go', type: 'button', disabled: enabled ? null : true, click: enabled ? click : null, text: label });
}

function showError(message) {
  state.error = message || '';
  state.busy = false;
  render();
}

async function boot() {
  try {
    var response = await fetch('identify.php', { headers: { Accept: 'application/json' } });
    var data = await response.json();
    if (!data.ok) throw new Error(data.error || 'Gap App could not start.');
    state.csrf = data.csrf;
    state.ai = !!data.ai;
  } catch (error) {
    state.error = 'Gap App could not start. Reload the page.';
  }
  render();
}

async function postJson(url, body) {
  var response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-Token': state.csrf
    },
    body: JSON.stringify(body)
  });
  var data = await response.json().catch(function () { return { ok: false, error: 'Something went wrong.' }; });
  if (!response.ok || data.ok === false) {
    throw new Error(data.error || 'Something went wrong.');
  }
  return data;
}

function taskReady() {
  return state.sample || state.file || state.draft.trim().length >= 8;
}

function clearPreview() {
  if (state.preview && state.preview.indexOf('blob:') === 0) URL.revokeObjectURL(state.preview);
  state.preview = '';
}

async function submitTask() {
  if (!taskReady() || state.busy) return;
  state.busy = true;
  state.error = '';
  render();
  try {
    var form = new FormData();
    if (state.sample && !state.file) form.set('sample', '1');
    if (state.draft.trim()) form.set('task', state.draft.trim());
    if (state.file) form.set('image', state.file);
    var response = await fetch('identify.php', {
      method: 'POST',
      headers: { Accept: 'application/json', 'X-CSRF-Token': state.csrf },
      body: form
    });
    var data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || 'The task could not be read.');
    state.task = data.task || state.draft.trim();
    state.candidates = data.candidates || [];
    state.selected = state.candidates[0] ? state.candidates[0].id : '';
    state.step = 'match';
    state.busy = false;
    render();
  } catch (error) {
    showError(error.message || 'The task could not be read.');
  }
}

async function loadPlan(history) {
  state.busy = true;
  state.error = '';
  render();
  try {
    var symptoms = SYMPTOMS.filter(function (item) { return state.symptoms[item.id]; }).map(function (item) { return item.id; });
    var data = await postJson('plan.php', {
      topicId: state.selected,
      symptoms: symptoms,
      task: state.task,
      history: history
    });
    state.history = history;
    state.plan = data;
    state.step = data.phase === 'start' ? 'start' : 'check';
    state.busy = false;
    render();
    if (state.ai && !state.polished && data.probeIds) polish(data.probeIds);
  } catch (error) {
    showError(error.message || 'The checks could not be prepared.');
  }
}

async function polish(ids) {
  state.polished = true;
  try {
    var data = await postJson('wording.php', { ids: ids, task: state.task });
    (data.items || []).forEach(function (item) { state.wording[item.id] = item; });
    if (state.step === 'check' || state.step === 'start') render();
  } catch (error) {
    /* Marble wording stays on screen. */
  }
}

function applyWording(check) {
  var extra = state.wording[check.id];
  if (!extra) return check;
  return Object.assign({}, check, {
    ask: extra.ask || check.ask,
    answer: extra.answer || check.answer,
    activity: extra.activity || check.activity
  });
}

function render() {
  app.replaceChildren();
  if (state.error) app.append(el('p', { class: 'error', role: 'alert', text: state.error }));
  if (state.step === 'task') renderTask();
  else if (state.step === 'match') renderMatch();
  else if (state.step === 'happening') renderHappening();
  else if (state.step === 'check') renderCheck();
  else renderStart();
}

function renderTask() {
  app.append(stepRow(1));
  app.append(el('h1', { text: 'Current challenge' }));
  app.append(el('p', { class: 'sub', text: 'Show us the task' }));
  if (state.pasteNote) app.append(el('p', { class: 'hint', text: state.pasteNote }));

  var photoKids = [
    state.preview ? el('img', { class: 'thumb', src: state.preview, alt: 'The task photo, ready to read' }) : icon('camera'),
    el('strong', { text: state.preview ? 'Photo ready' : 'Take a photo' }),
    el('span', { text: state.preview ? 'Tap to change it' : 'Use your camera' })
  ];
  if (!state.preview) photoKids.push(el('span', { class: 'cam-fab' }, icon('camera')));
  var photo = el('button', { class: 'photo-card', type: 'button', click: function () { document.getElementById('photo-input').click(); } });
  photoKids.forEach(function (kid) { photo.append(kid); });
  app.append(photo);
  var input = el('input', { id: 'photo-input', class: 'sr-only', type: 'file', accept: 'image/*' });
  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    if (!file) return;
    if (file.size > 6000000) {
      showError('That photo is too large. Try a closer crop of the question.');
      return;
    }
    clearPreview();
    state.file = file;
    state.sample = false;
    state.preview = URL.createObjectURL(file);
    state.error = '';
    render();
  });
  app.append(input);

  var paste = el('button', {
    class: 'paste-card',
    type: 'button',
    click: pasteTask
  }, icon('clip'), el('span', {},
    el('strong', { text: 'Paste the task' }),
    el('span', { text: state.draft && !state.wordsOpen ? state.draft.slice(0, 80) : 'From your clipboard' })
  ));
  app.append(paste);

  app.append(el('button', {
    class: 'text-link',
    type: 'button',
    click: function () {
      state.wordsOpen = !state.wordsOpen;
      render();
      var box = document.getElementById('words');
      if (box) box.focus();
    }
  }, icon('pencil'), document.createTextNode(' Or describe it in your own words')));

  if (state.wordsOpen || (state.draft && !state.file && !state.sample)) {
    var box = el('textarea', {
      id: 'words',
      class: 'describe',
      maxlength: '2000',
      placeholder: 'For example: a square with area 81 cm², find the side',
      input: function (event) { state.draft = event.target.value; syncContinue(); }
    });
    box.value = state.draft;
    app.append(box);
  }

  app.append(el('button', {
    class: 'text-link quiet',
    type: 'button',
    click: function () {
      clearPreview();
      state.file = null;
      state.sample = true;
      state.preview = 'assets/sample-task.png';
      state.draft = '';
      state.error = '';
      render();
    },
    text: 'Try the sample task'
  }));

  app.append(el('div', { class: 'note' },
    icon('shield'),
    el('span', {},
      el('strong', { text: 'No names or faces please' }),
      el('span', { text: "We're focused on the task, not the student." })
    )
  ));
  app.append(goButton(state.busy ? 'Reading the task…' : 'Continue', taskReady() && !state.busy, submitTask));
}

function syncContinue() {
  var button = app.querySelector('.go');
  if (!button || state.step !== 'task') return;
  var ready = taskReady() && !state.busy;
  button.disabled = !ready;
}

async function pasteTask() {
  state.wordsOpen = true;
  state.pasteNote = '';
  try {
    var text = await navigator.clipboard.readText();
    if (text && text.trim()) {
      state.draft = text.trim().slice(0, 2000);
      state.pasteNote = '';
    } else {
      state.pasteNote = 'The clipboard was empty. Type the task instead.';
    }
  } catch (error) {
    state.pasteNote = 'Paste into the box, or allow clipboard access.';
  }
  render();
  var box = document.getElementById('words');
  if (box) box.focus();
}

function renderMatch() {
  app.append(el('button', { class: 'back', type: 'button', click: function () { state.step = 'task'; render(); }, text: 'Back' }));
  app.append(stepRow(2));
  app.append(el('h1', { text: 'Is it one of these?' }));
  if (state.task) app.append(el('p', { class: 'quote', text: state.task }));
  var stack = el('div', { class: 'stack' });
  state.candidates.forEach(function (card) {
    var pressed = state.selected === card.id;
    var button = el('button', {
      class: 'skill',
      type: 'button',
      'aria-pressed': pressed ? 'true' : 'false',
      click: function () { state.selected = card.id; render(); }
    }, glyph(card.icon), el('span', {}, el('b', { text: card.title }), el('small', { text: card.detail })), el('span', { class: 'mark', 'aria-hidden': 'true' }, pressed ? icon('check') : null));
    stack.append(button);
  });
  app.append(stack);
  app.append(el('button', {
    class: 'ghost',
    type: 'button',
    click: function () {
      state.step = 'task';
      state.wordsOpen = true;
      state.pasteNote = 'None of those fitted. Describe the task in a few everyday words.';
      state.candidates = [];
      render();
    },
    text: 'None of these'
  }));
  app.append(goButton('Continue', !!state.selected && !state.busy, function () {
    state.step = 'happening';
    render();
  }));
}

function renderHappening() {
  app.append(el('button', { class: 'back', type: 'button', click: function () { state.step = 'match'; render(); }, text: 'Back' }));
  app.append(stepRow(3));
  app.append(el('h1', { text: "What's happening?" }));
  app.append(el('p', { class: 'sub', text: 'Pick any that fit' }));
  var stack = el('div', { class: 'stack' });
  SYMPTOMS.forEach(function (item) {
    var pressed = !!state.symptoms[item.id];
    stack.append(el('button', {
      class: 'symptom',
      type: 'button',
      'data-tone': item.tone,
      'aria-pressed': pressed ? 'true' : 'false',
      click: function () {
        state.symptoms[item.id] = !state.symptoms[item.id];
        render();
      }
    }, icon(item.icon), el('b', { text: item.label }), el('span', { class: 'mark', 'aria-hidden': 'true' }, pressed ? icon('check') : null)));
  });
  app.append(stack);
  app.append(goButton(state.busy ? 'Finding the gap…' : 'Find the gap', !state.busy, function () {
    state.polished = false;
    state.wording = {};
    state.history = [];
    loadPlan([]);
  }));
}

function figure(spec) {
  if (!spec) return null;
  if (spec.type === 'grid') {
    var grid = el('div', { class: 'grid', 'data-cols': String(spec.cols) });
    var count = spec.cols * spec.rows;
    for (var i = 0; i < count; i++) grid.append(el('span', { class: 'cell' }));
    return el('div', { class: 'figure', 'aria-hidden': 'true' }, grid);
  }
  var shape = el('div', { class: 'shape ' + (spec.type === 'square' ? 'square' : 'rect'), 'aria-hidden': 'true' });
  var across = spec.side || spec.w;
  var down = spec.h || spec.side;
  shape.append(el('span', { class: 'side top', text: across }));
  shape.append(el('span', { class: 'side bottom', text: across }));
  shape.append(el('span', { class: 'side left', text: down }));
  shape.append(el('span', { class: 'side right', text: down }));
  return el('div', { class: 'figure' }, shape);
}

function openInfo(info, title) {
  var dialog = document.getElementById('info-dialog');
  dialog.replaceChildren();
  var codes = (info.codes || []).length ? info.codes.join(', ') : 'No Australian Curriculum v9 code on this skill.';
  var list = el('ol', { class: 'path' });
  (info.path || []).forEach(function (step) {
    var line = step.name + (step.codes && step.codes.length ? ' · ' + step.codes.join(', ') : '');
    list.append(el('li', { class: step.current ? 'here' : '', text: line }));
  });
  dialog.append(
    el('div', { class: 'check-head' }, el('h2', { id: 'info-title', text: title || 'About this skill' }), el('button', { class: 'info', type: 'button', click: function () { dialog.close(); }, text: 'Close' })),
    el('p', {}, el('strong', { text: info.name || '' })),
    el('p', { class: 'codes', text: (info.subject ? info.subject + ' · ' + info.domain : '') }),
    el('h3', { text: 'Australian Curriculum v9' }),
    el('p', { text: codes }),
    info.why ? el('h3', { text: 'Why this comes here' }) : null,
    info.why ? el('p', { text: info.why }) : null,
    info.path && info.path.length ? el('h3', { text: 'Full path' }) : null,
    info.path && info.path.length ? list : null,
    el('p', { class: 'hint', text: 'This is a starting point, not a diagnosis. Curriculum standards have their own upstream licences.' })
  );
  if (info.link) {
    dialog.append(el('button', {
      class: 'text-link',
      type: 'button',
      click: function () { openFeedback(info); },
      text: 'This path looks wrong'
    }));
  }
  dialog.showModal();
}

function renderCheck() {
  var check = applyWording(state.plan.check);
  app.append(el('button', {
    class: 'back',
    type: 'button',
    click: function () { state.step = 'happening'; render(); },
    text: 'Back'
  }));
  app.append(el('div', { class: 'check-head' },
    el('h1', { text: 'Quick check ' + state.plan.number + ' of ' + state.plan.total }),
    el('button', { class: 'info', type: 'button', 'aria-label': 'About this check', click: function () { openInfo(check.info, 'About this check'); }, text: 'i' })
  ));
  app.append(el('p', { class: 'ask-label', text: 'Ask them:' }));
  var fig = figure(check.figure);
  if (fig) app.append(fig);
  app.append(el('p', { class: 'question', text: check.ask }));
  app.append(el('p', { class: 'answer-line' },
    el('button', { class: 'info', type: 'button', 'aria-label': 'Why this check', click: function () { openInfo(check.info, 'About this check'); }, text: 'i' }),
    document.createTextNode(check.answerLabel + ': ' + check.answer)
  ));
  app.append(el('div', { class: 'pair' },
    el('button', { class: 'got', type: 'button', disabled: state.busy ? true : null, click: function () { answer(true); }, text: 'Got it' }),
    el('button', { class: 'notyet', type: 'button', disabled: state.busy ? true : null, click: function () { answer(false); }, text: 'Not yet' })
  ));
}

function answer(got) {
  if (state.busy) return;
  var history = state.history.concat([{ id: state.plan.check.id, got: got }]);
  loadPlan(history);
}

function renderStart() {
  var gap = state.plan.gap;
  var activity = (state.wording[gap.id] && state.wording[gap.id].activity) || gap.activity;
  app.append(el('button', { class: 'back', type: 'button', click: function () { state.step = 'happening'; render(); }, text: 'Back' }));
  var banner = el('div', { class: 'start-banner' });
  banner.append(el('div', { class: 'banner-head' },
    el('span', { class: 'flag' }, icon('flag'), document.createTextNode('Start here')),
    el('button', { class: 'info', type: 'button', 'aria-label': 'About this gap', click: function () { openInfo(gap.info, 'About this gap'); }, text: 'i' })
  ));
  banner.append(el('h1', { text: gap.title }));
  app.append(banner);
  var sketch = null;
  if (gap.sketch === 'squares') {
    sketch = el('div', { class: 'sketch', 'aria-hidden': 'true' });
    for (var i = 0; i < 16; i++) sketch.append(el('i', { class: i < 4 || i === 5 || i === 6 || i === 9 ? 'on' : '' }));
  }
  app.append(el('section', { class: 'try-card' },
    el('p', { class: 'card-label', text: 'Try this' }),
    el('div', { class: 'try-row' }, sketch, el('p', { text: activity }))
  ));
  var list = el('ol', { class: 'next-list' });
  (state.plan.next || []).forEach(function (step) {
    list.append(el('li', {}, el('span', { class: 'num', text: String(step.n) }), el('span', { text: step.label })));
  });
  app.append(el('section', { class: 'next-card' }, el('p', { class: 'card-label', text: 'Then next' }), list));
  app.append(goButton('Re-check later', !state.busy, function () {
    state.history = [];
    state.polished = false;
    loadPlan([]);
  }));
  app.append(el('button', {
    class: 'text-link',
    type: 'button',
    click: function () {
      state.step = 'task';
      state.plan = null;
      state.history = [];
      state.candidates = [];
      state.selected = '';
      render();
    },
    text: 'New task'
  }));
}

function openFeedback(info) {
  var dialog = document.getElementById('feedback-dialog');
  var link = info && info.link ? info.link : null;
  dialog.dataset.kind = link ? 'link' : 'general';
  dialog.dataset.topicId = link ? link.topicId : '';
  dialog.dataset.topicName = link ? link.topicName : '';
  dialog.dataset.prereqId = link ? link.prerequisiteId : '';
  dialog.dataset.prereqName = link ? link.prerequisiteName : '';
  dialog.dataset.strength = link ? link.strength : '';
  dialog.dataset.reason = link ? (link.reason || '') : '';
  document.getElementById('feedback-status').textContent = '';
  var infoDialog = document.getElementById('info-dialog');
  if (infoDialog.open) infoDialog.close();
  dialog.showModal();
}

document.getElementById('feedback-open').addEventListener('click', function () { openFeedback(null); });
document.getElementById('feedback-close').addEventListener('click', function () { document.getElementById('feedback-dialog').close(); });
document.getElementById('feedback-form').addEventListener('submit', async function (event) {
  event.preventDefault();
  var dialog = document.getElementById('feedback-dialog');
  var data = new FormData(event.target);
  var payload = {
    kind: dialog.dataset.kind === 'link' ? 'link' : 'general',
    comment: String(data.get('comment') || ''),
    teacherName: String(data.get('teacherName') || ''),
    teacherEmail: String(data.get('teacherEmail') || ''),
    company: String(data.get('company') || ''),
    topicId: dialog.dataset.topicId || '',
    topicName: dialog.dataset.topicName || '',
    prerequisiteId: dialog.dataset.prereqId || '',
    prerequisiteName: dialog.dataset.prereqName || '',
    strength: dialog.dataset.strength || '',
    reason: dialog.dataset.reason || ''
  };
  if (payload.kind === 'general') {
    payload.topicId = '';
    payload.topicName = '';
    payload.prerequisiteId = '';
    payload.prerequisiteName = '';
    payload.strength = '';
    payload.reason = '';
  }
  var status = document.getElementById('feedback-status');
  status.textContent = 'Sending…';
  try {
    var result = await postJson('feedback.php', payload);
    status.textContent = result.message || 'Thank you. Your note was sent.';
    event.target.reset();
  } catch (error) {
    status.textContent = error.message || 'The note could not be sent.';
  }
});

boot();
