/*
 * Bulk revalidation: the cohort picker, and select-many on the review queue.
 *
 * Vanilla and deferred, like combo.js — this application has no build step and
 * no framework, and one screen is not a reason to acquire either.
 *
 * DEGRADES. Without JavaScript the round form still submits: the community
 * panel is the one rendered enabled by Blade, and the other four are rendered
 * `disabled`, so exactly one cohort's ids are posted either way. The bulk bar
 * stays hidden and every row keeps its own single-record Accept button, which
 * is the behaviour that existed before this file.
 */
(function () {
  'use strict';

  /* ---------------------------------------------------------------- cohort */

  function initCohort() {
    var typeSelect = document.querySelector('[data-cohort-type]');

    if (!typeSelect) {
      return;
    }

    var panels = Array.prototype.slice.call(
      document.querySelectorAll('[data-cohort-panel]')
    );

    function show(type) {
      panels.forEach(function (panel) {
        var active = panel.getAttribute('data-cohort-panel') === type;
        var select = panel.querySelector('select');

        panel.hidden = !active;

        /*
         * Disabled controls are not submitted, which is what keeps the four
         * inactive cohorts out of the request. Clearing them too means
         * switching cohort twice cannot leave a stale selection behind that
         * the server would then have to second-guess.
         */
        if (select) {
          select.disabled = !active;

          if (!active) {
            Array.prototype.forEach.call(select.options, function (option) {
              option.selected = false;
            });
          }
        }
      });
    }

    typeSelect.addEventListener('change', function () {
      show(typeSelect.value);
    });

    show(typeSelect.value);
  }

  /* ------------------------------------------------------------------ bulk */

  function initBulk() {
    var bar = document.querySelector('[data-bulk-bar]');
    var all = document.querySelector('[data-bulk-all]');
    var counter = document.querySelector('[data-bulk-count]');

    var items = Array.prototype.slice.call(
      document.querySelectorAll('[data-bulk-item]')
    );

    if (!bar || items.length === 0) {
      return;
    }

    function selected() {
      return items.filter(function (item) {
        return item.checked;
      });
    }

    function sync() {
      var count = selected().length;

      bar.hidden = count === 0;

      if (counter) {
        counter.textContent = count + (count === 1 ? ' selected' : ' selected');
      }

      if (all) {
        all.checked = count === items.length && count > 0;
        // Some but not all — the tri-state box the browser draws for us.
        all.indeterminate = count > 0 && count < items.length;
      }
    }

    items.forEach(function (item) {
      item.addEventListener('change', sync);
    });

    if (all) {
      all.addEventListener('change', function () {
        items.forEach(function (item) {
          item.checked = all.checked;
        });
        sync();
      });
    }

    /*
     * Accepting is not reversible — `cancel()` refuses once a revalidation is
     * accepted, because an accepted check is part of the farmer's record. A
     * mis-click on a batch of ninety is worth one confirm.
     */
    var form = document.getElementById('bulk-accept');

    if (form) {
      form.addEventListener('submit', function (event) {
        var count = selected().length;

        if (count === 0) {
          event.preventDefault();

          return;
        }

        var message =
          'Accept ' + count + ' revalidation' + (count === 1 ? '' : 's') +
          '? This cannot be undone.';

        if (!window.confirm(message)) {
          event.preventDefault();
        }
      });
    }

    sync();
  }

  function init() {
    initCohort();
    initBulk();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
