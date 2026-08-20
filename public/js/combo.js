/*
 * Searchable select.
 *
 * The system has pickers with 1,842 options in them (every farmer) and pickers
 * where two options read identically because the same person holds two accounts.
 * A native <select> answers neither: you cannot type to narrow it beyond the
 * first letter, and you cannot tell two "Mohammed Aliyu" entries apart.
 *
 * PROGRESSIVE ENHANCEMENT, deliberately. The real <select> stays in the DOM and
 * stays the form control — it keeps its name, its value, its required attribute
 * and whatever old() put in it. This script only draws a filter box over it and
 * sets select.value on choice. If the script fails to load, or the browser is
 * ancient, or someone is on a 2G link that drops it, the page still works exactly
 * as it did: a plain select an operator can use.
 *
 * No dependencies and no build step, because the application has neither.
 */
(function () {
  'use strict';

  var MIN_OPTIONS = 8; // Below this a native select is genuinely easier.

  function textOf(option) {
    return (option.textContent || '').replace(/\s+/g, ' ').trim();
  }

  /*
   * An empty value is usually a placeholder ("—", "All") that should vanish when
   * you start typing and should leave the box empty when chosen. But it is
   * sometimes a REAL choice — the leave form's blank option means "Myself" — so
   * the label decides, not the value. Judged in one place so the filter and the
   * display can never disagree, which is what made "Myself" select as blank.
   */
  function isPlaceholder(option) {
    var label = textOf(option);

    return option.value === '' && (label === '' || /^[—–-]+$/.test(label) || /^select\b/i.test(label) || /^choose\b/i.test(label));
  }

  function enhance(select) {
    if (select.dataset.comboReady === '1') return;
    if (select.multiple || select.disabled) return;

    var options = Array.prototype.slice.call(select.options);
    if (options.length < MIN_OPTIONS) return;

    select.dataset.comboReady = '1';

    var wrap = document.createElement('div');
    wrap.className = 'combo';

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'combo-input';
    input.autocomplete = 'off';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-autocomplete', 'list');
    input.placeholder = select.dataset.comboPlaceholder || 'Type to search…';

    // The label points at the select; keep it pointing at what is now focusable.
    if (select.id) {
      input.id = select.id + '-combo';
      var label = document.querySelector('label[for="' + select.id + '"]');
      if (label) label.setAttribute('for', input.id);
    }

    var list = document.createElement('ul');
    list.className = 'combo-list';
    list.setAttribute('role', 'listbox');
    list.style.zIndex = '100000';
    list.hidden = true;

    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(input);
    wrap.appendChild(select);

    /*
     * The list is appended to <body>, not to the wrapper. A fixed element is
     * still trapped by an ancestor that establishes a containing block — any
     * transform, filter or backdrop-filter does it, and the modal overlay uses
     * backdrop-filter. Parenting to body sidesteps the whole class of problem.
     */
    document.body.appendChild(list);

    // The select still submits; it is simply no longer what you click.
    select.classList.add('combo-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    var active = -1;
    var visible = [];

    function currentLabel() {
      var chosen = select.options[select.selectedIndex];

      return chosen && ! isPlaceholder(chosen) ? textOf(chosen) : '';
    }

    function render(term) {
      list.innerHTML = '';
      visible = [];
      var needle = term.toLowerCase();

      options.forEach(function (option) {
        var label = textOf(option);

        /*
         * An empty value is usually a placeholder ("—", "All"), which should drop
         * out as soon as someone starts typing. But it is sometimes a real choice
         * — the leave form's blank option means "Myself" — so only placeholders
         * are hidden, judged by their label rather than by their value.
         */
        if (isPlaceholder(option) && needle !== '') return;

        if (needle !== '' && label.toLowerCase().indexOf(needle) === -1) return;

        var item = document.createElement('li');
        item.className = 'combo-item';
        item.setAttribute('role', 'option');
        item.textContent = label || '—';
        item.dataset.value = option.value;
        if (option.value === select.value) item.classList.add('is-chosen');

        item.addEventListener('mousedown', function (event) {
          event.preventDefault(); // Keep focus; blur would close the list first.
          choose(option.value, label);
        });

        list.appendChild(item);
        visible.push(item);
      });

      if (visible.length === 0) {
        var empty = document.createElement('li');
        empty.className = 'combo-empty';
        empty.textContent = 'No match';
        list.appendChild(empty);
      }

      active = -1;
    }

    /*
     * The list is position:fixed so it is not clipped by the modal's own
     * overflow, which means nothing positions it for us — we do it here from the
     * input's viewport rect, and flip above when there is more room up than down.
     */
    function place() {
      if (list.hidden) return;

      var rect = input.getBoundingClientRect();
      var below = window.innerHeight - rect.bottom - 8;
      var above = rect.top - 8;
      var flip = below < 180 && above > below;
      var maxHeight = Math.max(120, Math.min(260, flip ? above : below));

      list.style.width = rect.width + 'px';
      list.style.left = rect.left + 'px';
      list.style.maxHeight = maxHeight + 'px';

      if (flip) {
        list.style.top = 'auto';
        list.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
      } else {
        list.style.bottom = 'auto';
        list.style.top = (rect.bottom + 4) + 'px';
      }
    }

    function open() {
      render(input.value === currentLabel() ? '' : input.value);
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      place();
    }

    function close() {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.value = currentLabel();
      active = -1;
    }

    function choose(value, label) {
      select.value = value;
      // Show what was picked. Only a true placeholder leaves the box empty —
      // choosing "Myself" used to blank it, which read as choosing nothing.
      input.value = isPlaceholder(select.options[select.selectedIndex]) ? '' : label;
      // Anything listening to the select — validation, other scripts — still hears.
      select.dispatchEvent(new Event('change', { bubbles: true }));
      close();
    }

    function highlight(next) {
      if (visible.length === 0) return;
      if (active >= 0 && visible[active]) visible[active].classList.remove('is-active');
      active = (next + visible.length) % visible.length;
      visible[active].classList.add('is-active');
      visible[active].scrollIntoView({ block: 'nearest' });
    }

    input.value = currentLabel();

    input.addEventListener('focus', function () {
      open();
      // Auto-select text on focus so user can immediately type to replace without manually backspacing/deleting
      this.select();
    });
    input.addEventListener('input', function () {
      render(input.value);
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      place();
    });

    // Capture phase, so a scroll inside the modal is heard as well as the page.
    window.addEventListener('scroll', place, true);
    window.addEventListener('resize', place);

    input.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown') { event.preventDefault(); if (list.hidden) open(); highlight(active + 1); }
      else if (event.key === 'ArrowUp') { event.preventDefault(); highlight(active - 1); }
      else if (event.key === 'Enter') {
        if (!list.hidden && active >= 0 && visible[active]) {
          event.preventDefault();
          choose(visible[active].dataset.value, visible[active].textContent);
        }
      } else if (event.key === 'Escape') { close(); }
    });

    select.addEventListener('change', function () {
      input.value = currentLabel();
    });

    input.addEventListener('blur', function () {
      // Give a click on the list time to land.
      window.setTimeout(close, 120);
    });

    /*
     * The list lives on <body>, so it does not disappear with its modal. The
     * modals are :target-driven, so closing one is a hash change — without this
     * the list would be left floating over the page after the modal it belongs to
     * had gone.
     */
    window.addEventListener('hashchange', close);
    document.addEventListener('click', function (event) {
      if (!list.hidden && event.target !== input && !list.contains(event.target)) close();
    });
  }

  function enhanceAll(root) {
    (root || document).querySelectorAll('select[data-searchable]').forEach(enhance);
  }

  window.enhanceCombos = enhanceAll;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { enhanceAll(); });
  } else {
    enhanceAll();
  }
})();
