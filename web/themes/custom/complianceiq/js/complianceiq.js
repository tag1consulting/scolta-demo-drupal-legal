(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.complianceiqNav = {
    attach: function (context, settings) {
      // Collapsible nav sections
      once('ciq-nav', '.ciq-nav-section-header', context).forEach(function (header) {
        header.addEventListener('click', function () {
          var section = header.closest('.ciq-nav-section');
          section.classList.toggle('open');
        });
      });

      // Open active section by default
      once('ciq-nav-active', '.ciq-nav-link.active', context).forEach(function (link) {
        var section = link.closest('.ciq-nav-section');
        if (section) section.classList.add('open');
      });

      // Dark mode toggle
      once('ciq-dark', '.ciq-dark-toggle', context).forEach(function (btn) {
        var stored = localStorage.getItem('ciq-theme');
        if (stored === 'dark') {
          document.documentElement.setAttribute('data-theme', 'dark');
          btn.textContent = 'Light Mode';
        }

        btn.addEventListener('click', function () {
          var current = document.documentElement.getAttribute('data-theme');
          if (current === 'dark') {
            document.documentElement.removeAttribute('data-theme');
            localStorage.setItem('ciq-theme', 'light');
            btn.textContent = 'Dark Mode';
          } else {
            document.documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('ciq-theme', 'dark');
            btn.textContent = 'Light Mode';
          }
        });
      });

      // PDF export tooltip
      once('ciq-export', '.ciq-export-btn', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var tip = btn.querySelector('.ciq-export-tip');
          if (!tip) {
            tip = document.createElement('span');
            tip.className = 'ciq-export-tip';
            tip.style.cssText = 'position:absolute;background:#1E293B;color:#fff;font-size:0.6875rem;padding:0.25rem 0.5rem;border-radius:3px;margin-top:0.5rem;white-space:nowrap;pointer-events:none;';
            tip.textContent = 'PDF export coming soon';
            btn.style.position = 'relative';
            btn.appendChild(tip);
            setTimeout(function () { tip.remove(); }, 2000);
          }
        });
      });

      // Mobile nav toggle
      once('ciq-mobile-nav', '.ciq-mobile-nav-toggle', context).forEach(function (btn) {
        btn.addEventListener('click', function () {
          var nav = document.getElementById('complianceiq-nav');
          if (nav) nav.classList.toggle('mobile-open');
        });
      });

      // Search chip clicks fill search bar
      once('ciq-chips', '.ciq-search-chip', context).forEach(function (chip) {
        chip.addEventListener('click', function (e) {
          e.preventDefault();
          var query = chip.dataset.query || chip.textContent.trim();
          var inputs = document.querySelectorAll('.ciq-search-input, .ciq-hero-search input');
          inputs.forEach(function (input) { input.value = query; });
          // Submit the header search form
          var form = document.querySelector('.ciq-header-search form');
          if (form) form.submit();
        });
      });
    }
  };

})(jQuery, Drupal);
