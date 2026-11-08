// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

(() => {
  'use strict';

  const storageKey = 'libresign-coverage-theme';
  const root = document.documentElement;
  const stored = (() => {
    try {
      return window.localStorage.getItem(storageKey);
    } catch (_) {
      return null;
    }
  })();

  const systemDark = window.matchMedia &&
    window.matchMedia('(prefers-color-scheme: dark)').matches;
  let currentTheme = stored === 'dark' || stored === 'light'
    ? stored
    : (systemDark ? 'dark' : 'light');

  // Apply before rendering the body to avoid a flash of the wrong theme.
  root.setAttribute('data-coverage-theme', currentTheme);

  function createLink(label, url) {
    const link = document.createElement('a');
    link.href = url;
    link.textContent = label;
    link.rel = 'noopener noreferrer';
    return link;
  }

  function getSourceFileUrl() {
    const marker = '/libresign/';
    const path = window.location.pathname;
    const index = path.indexOf(marker);
    if (index === -1) return null;
    const filename = path.slice(index + marker.length);
    if (!filename.endsWith('.php.html')) return null;
    const sha = document.querySelector('meta[name="coverage-source-commit"]')
      ?.getAttribute('content');
    if (!sha || !/^[0-9a-f]{40}$/.test(sha)) return null;
    const parts = filename.slice(0, -5).split('/').map(encodeURIComponent);
    return 'https://github.com/LibreSign/libresign/blob/' +
      sha + '/lib/' + parts.join('/');
  }

  function addToolbar() {
    if (document.getElementById('coverage-toolbar')) return;

    const toolbar = document.createElement('nav');
    toolbar.id = 'coverage-toolbar';
    toolbar.setAttribute('aria-label', 'Coverage navigation');

    const fileUrl = getSourceFileUrl();
    if (fileUrl) {
      toolbar.append(createLink('Source file', fileUrl));
    }
    toolbar.append(createLink('LibreSign code', 'https://github.com/LibreSign/libresign'));
    toolbar.append(createLink('Site code', 'https://github.com/LibreSign/coverage'));

    const toggle = document.createElement('button');
    toggle.id = 'coverage-theme-toggle';
    toggle.type = 'button';
    function updateButton() {
      const dark = currentTheme === 'dark';
      toggle.textContent = dark ? '☀ Light' : '☾ Dark';
      toggle.setAttribute('aria-label', dark ? 'Use light mode' : 'Use dark mode');
      toggle.setAttribute('aria-pressed', String(dark));
    }
    toggle.addEventListener('click', () => {
      currentTheme = currentTheme === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-coverage-theme', currentTheme);
      try {
        window.localStorage.setItem(storageKey, currentTheme);
      } catch (_) {
        // Private browsing or disabled storage must not break the toggle.
      }
      updateButton();
    });
    updateButton();
    toolbar.append(toggle);
    document.body.append(toolbar);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addToolbar, {once: true});
  } else {
    addToolbar();
  }
})();
