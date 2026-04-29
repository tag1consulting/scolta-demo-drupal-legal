(function (Drupal) {
  'use strict';

  // Wire up everything once after the DOM is ready. All targets are stable
  // top-level elements (nav, header) so a single DOMContentLoaded call is fine.
  document.addEventListener('DOMContentLoaded', function () {

    // ── Nav accordion ──────────────────────────────────────────────
    var nav = document.getElementById('complianceiq-nav');
    if (nav) {
      // Toggle on header click.
      nav.addEventListener('click', function (e) {
        var header = e.target.closest('.ciq-nav-section-header');
        if (header) {
          header.closest('.ciq-nav-section').classList.toggle('open');
        }
      });

      // Auto-open the section whose link best matches the current path,
      // and mark that link active. Runs on every page load.
      var currentPath = window.location.pathname;
      var bestMatch = { length: 0, link: null };
      nav.querySelectorAll('.ciq-nav-link').forEach(function (link) {
        var href = link.getAttribute('href');
        if (href && href !== '/' && currentPath.startsWith(href)) {
          if (href.length > bestMatch.length) {
            bestMatch = { length: href.length, link: link };
          }
        }
      });
      if (bestMatch.link) {
        bestMatch.link.classList.add('active');
        var section = bestMatch.link.closest('.ciq-nav-section');
        if (section) section.classList.add('open');
      }
    }

    // ── Mobile nav toggle ──────────────────────────────────────────
    var mobileBtn = document.querySelector('.ciq-mobile-nav-toggle');
    if (mobileBtn) {
      mobileBtn.addEventListener('click', function () {
        var n = document.getElementById('complianceiq-nav');
        if (n) n.classList.toggle('mobile-open');
      });
    }

    // ── Search chip clicks ─────────────────────────────────────────
    document.querySelectorAll('.ciq-search-chip').forEach(function (chip) {
      chip.addEventListener('click', function (e) {
        e.preventDefault();
        var q = chip.dataset.query || chip.textContent.trim();
        document.querySelectorAll('.ciq-search-input').forEach(function (inp) { inp.value = q; });
        var form = document.querySelector('.ciq-header-search form');
        if (form) form.submit();
      });
    });

    // ── PDF export tooltip ─────────────────────────────────────────
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.ciq-export-btn');
      if (!btn) return;
      e.preventDefault();
      if (btn.querySelector('.ciq-export-tip')) return;
      var tip = document.createElement('span');
      tip.className = 'ciq-export-tip';
      tip.style.cssText = 'position:absolute;background:#1E293B;color:#fff;font-size:0.6875rem;padding:0.25rem 0.5rem;border-radius:3px;margin-top:0.5rem;white-space:nowrap;pointer-events:none;';
      tip.textContent = 'PDF export coming soon';
      btn.style.position = 'relative';
      btn.appendChild(tip);
      setTimeout(function () { tip.remove(); }, 2000);
    });

    // ── Filter form auto-submit ────────────────────────────────────
    var filterForm = document.getElementById('ciq-filter-form');
    if (filterForm) {
      filterForm.addEventListener('change', function (e) {
        if (e.target.type === 'checkbox') {
          filterForm.submit();
        }
      });
    }

    // ── Async AI summary fetch ─────────────────────────────────────
    var summaryEl = document.querySelector('[data-summary-query]');
    if (summaryEl) {
      var query = summaryEl.getAttribute('data-summary-query');
      if (query) {
        fetch('/api/search/summary?q=' + encodeURIComponent(query))
          .then(function (r) { return r.json(); })
          .then(function (data) {
            var textEl = summaryEl.querySelector('.ciq-ai-summary__text');
            if (!textEl) return;
            if (data.summary) {
              textEl.classList.remove('ciq-ai-summary--loading');
              textEl.innerHTML = data.summary;
            } else {
              summaryEl.style.display = 'none';
            }
          })
          .catch(function () { summaryEl.style.display = 'none'; });
      }
    }

  });

})(Drupal);
