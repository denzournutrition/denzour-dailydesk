(function () {
  'use strict';
  var q = function (s, r) { return (r || document).querySelector(s); };
  var qa = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // show / hide password
  qa('[data-eye]').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = q('input', b.parentNode); if (!i) return;
      var show = i.type === 'password';
      i.type = show ? 'text' : 'password';
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
      b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      b.classList.toggle('is-on', show);
    });
  });

  // auto-submit filters / date picker
  qa('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { if (el.form) el.form.submit(); });
  });

  // date picker that opens another page
  qa('[data-goto]').forEach(function (el) {
    el.addEventListener('change', function () { if (el.value) { window.location.href = el.getAttribute('data-goto') + encodeURIComponent(el.value); } });
  });

  // confirm prompts (CSP blocks inline handlers)
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirm]');
    if (b && !window.confirm(b.getAttribute('data-confirm'))) { e.preventDefault(); }
  });

  // mobile menu
  qa('[data-menu]').forEach(function (b) {
    b.addEventListener('click', function () { document.body.classList.toggle('menu-open'); });
  });

  // dashboard widgets: show / hide, remembered in this browser
  var wdDlg = q('#widgets');
  if (wdDlg) {
    var KEY = 'dd_hidden_widgets', hidden = [];
    try { hidden = JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { hidden = []; }
    var paint = function () {
      qa('[data-widget]').forEach(function (w) { w.classList.toggle('is-hidden', hidden.indexOf(w.getAttribute('data-widget')) > -1); });
      qa('[data-toggle]', wdDlg).forEach(function (b) {
        var off = hidden.indexOf(b.getAttribute('data-toggle')) > -1;
        b.textContent = off ? 'Select' : 'Selected';
        b.classList.toggle('btn--ghost', !off);
      });
    };
    qa('[data-open="widgets"]').forEach(function (b) { b.addEventListener('click', function () { if (wdDlg.showModal) wdDlg.showModal(); }); });
    qa('[data-close]', wdDlg).forEach(function (b) { b.addEventListener('click', function () { wdDlg.close(); }); });
    wdDlg.addEventListener('click', function (e) { if (e.target === wdDlg) wdDlg.close(); });
    qa('[data-toggle]', wdDlg).forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-toggle'), i = hidden.indexOf(k);
        if (i > -1) hidden.splice(i, 1); else hidden.push(k);
        try { localStorage.setItem(KEY, JSON.stringify(hidden)); } catch (e) {}
        paint();
      });
    });
    paint();
  }

  // report form
  var form = q('#reportform');
  if (!form) return;
  var tasks = q('#tasks'), tpl = q('#tasktpl'), total = q('#totalhours'), bar = q('.savebar');
  var leave = q('#leavebox'), area = q('#taskarea'), maxDay = parseFloat(form.getAttribute('data-max-day')) || 24;

  function recalc() {
    var sum = 0;
    qa('input[name="hours[]"]', tasks).forEach(function (i) { var v = parseFloat(String(i.value).replace(',', '.')); if (v > 0) sum += v; });
    if (leave && leave.checked) sum = 0;
    total.textContent = (Math.round(sum * 100) / 100).toString();
    if (bar) bar.classList.toggle('is-over', sum > maxDay);
  }
  function renumber() {
    qa('.task', tasks).forEach(function (t, i) {
      var l = q('legend', t); if (l) l.textContent = 'Task ' + (i + 1);
      var r = q('[data-remove]', t); if (r) r.hidden = tasks.children.length < 2;
    });
  }
  var add = q('#addtask');
  if (add) add.addEventListener('click', function () {
    tasks.appendChild(tpl.content.cloneNode(true));
    renumber();
    var last = tasks.lastElementChild; var ta = last && q('textarea', last); if (ta) ta.focus();
  });
  tasks.addEventListener('click', function (e) {
    var b = e.target.closest('[data-remove]'); if (!b) return;
    var t = b.closest('.task'); if (tasks.children.length > 1) { t.remove(); renumber(); recalc(); }
  });
  tasks.addEventListener('input', function (e) { if (e.target.name === 'hours[]') recalc(); });
  if (leave) leave.addEventListener('change', function () { area.hidden = leave.checked; recalc(); });
  // unsaved-changes guard
  var dirty = false;
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('submit', function () { dirty = false; var s = q('#savebtn'); if (s) { s.disabled = true; s.textContent = 'Saving…'; } });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  renumber(); recalc();
})();
