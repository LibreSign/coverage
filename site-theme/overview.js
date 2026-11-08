// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
(() => {
  'use strict';

  const root = document.getElementById('coverage-explorer');
  if (!root) return;

  const directories = root.querySelector('#coverage-directory-list');
  const files = root.querySelector('#coverage-file-list');
  const count = root.querySelector('#coverage-file-count');
  const heading = root.querySelector('#coverage-files-title');
  const search = root.querySelector('#coverage-file-search');
  const sort = root.querySelector('#coverage-file-sort');
  const moreDirectories = root.querySelector('#coverage-more-directories');
  const moreFiles = root.querySelector('#coverage-more-files');
  const clearDirectory = root.querySelector('#coverage-clear-directory');
  const number = new Intl.NumberFormat('en-US');
  const percentage = value => value === null ? 'N/A' :
    new Intl.NumberFormat('en-US', {maximumFractionDigits: 1, minimumFractionDigits: 1}).format(value) + '%';

  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const anchor = (label, href, className) => {
    const link = element('a', className, label);
    link.href = href;
    return link;
  };

  function bar(pct) {
    const track = element('span', 'coverage-progress');
    track.setAttribute('aria-hidden', 'true');
    // Descriptive tiers, not pass/fail thresholds. Adjacent values remain
    // visible so meaning never depends on color alone.
    const level = pct === null ? 'empty' : pct < 50 ? 'low' : pct < 80 ? 'medium' : 'high';
    const value = element('span', 'coverage-progress-fill is-' + level);
    value.style.width = (pct === null ? 0 : Math.max(0, Math.min(100, pct))) + '%';
    track.append(value);
    return track;
  }

  let report;
  let selected = null;
  let allDirectories = false;
  let showFiles = 10;

  function renderDirectories() {
    directories.replaceChildren();
    const rows = allDirectories ? report.directories : report.directories.slice(0, 8);
    for (const directory of rows) {
      const row = element('div', 'coverage-directory-row' + (selected === directory.name ? ' is-selected' : ''));
      const action = element('button', 'coverage-directory-button');
      action.type = 'button';
      action.setAttribute('aria-pressed', String(selected === directory.name));
      action.setAttribute('aria-label', 'Filter files in ' + directory.name);
      const text = element('span', 'coverage-directory-caption');
      text.append(
        element('span', 'coverage-directory-name', directory.name.replace(/^lib\//, '')),
        element('span', 'coverage-directory-stat', number.format(directory.uncovered) + ' uncovered · ' +
          number.format(directory.files) + ' files'),
      );
      const coverage = element('span', 'coverage-directory-coverage');
      coverage.append(element('span', 'coverage-directory-pct', percentage(directory.percent)), bar(directory.percent));
      action.append(text, coverage);
      action.addEventListener('click', () => {
        selected = selected === directory.name ? null : directory.name;
        showFiles = 10;
        renderDirectories();
        renderFiles();
      });
      row.append(action);
      if (directory.report_url) {
        row.append(anchor('Report', directory.report_url, 'coverage-directory-report'));
      }
      directories.append(row);
    }
    moreDirectories.hidden = report.directories.length <= 8;
    moreDirectories.textContent = allDirectories ? 'Show fewer directories' :
      'Show all ' + number.format(report.directories.length) + ' directories';
    clearDirectory.hidden = selected === null;
  }

  function renderFiles() {
    const term = search.value.trim().toLowerCase();
    let matching = report.files.filter(file => {
      return (!selected || selected === file.directory) &&
        (!term || file.path.toLowerCase().includes(term));
    });
    if (sort.value === 'percent') {
      matching = [...matching].sort((a, b) => {
        const ap = a.percent === null ? 101 : a.percent;
        const bp = b.percent === null ? 101 : b.percent;
        return ap - bp || b.uncovered - a.uncovered || a.path.localeCompare(b.path);
      });
    } else if (sort.value === 'name') {
      matching = [...matching].sort((a, b) => a.path.localeCompare(b.path));
    }
    heading.textContent = selected ? selected.replace(/^lib\//, '') + ' files' : 'Files to review';
    count.textContent = number.format(matching.length) + ' files';
    files.replaceChildren();

    if (!matching.length) {
      const row = element('tr');
      const cell = element('td', 'coverage-no-results', 'No matching files.');
      cell.colSpan = 3;
      row.append(cell);
      files.append(row);
    } else {
      for (const file of matching.slice(0, showFiles)) {
        const row = element('tr');
        const nameCell = element('td');
        const link = anchor(file.path.replace(/^lib\//, ''), file.report_url || file.source_url, 'coverage-file-link');
        link.title = file.report_url ? 'View PHPUnit source-line coverage' : 'View source code';
        nameCell.append(link);
        const coverageCell = element('td', 'coverage-file-percent');
        coverageCell.append(element('span', '', percentage(file.percent)), bar(file.percent));
        const uncoveredCell = element('td', 'coverage-file-uncovered', number.format(file.uncovered));
        row.append(nameCell, coverageCell, uncoveredCell);
        files.append(row);
      }
    }
    moreFiles.hidden = matching.length <= showFiles;
    moreFiles.textContent = 'Show more files (' + number.format(matching.length - showFiles) + ' remaining)';
  }

  moreDirectories.addEventListener('click', () => {
    allDirectories = !allDirectories;
    renderDirectories();
  });
  moreFiles.addEventListener('click', () => {
    showFiles += 20;
    renderFiles();
  });
  clearDirectory.addEventListener('click', () => {
    selected = null;
    showFiles = 10;
    renderDirectories();
    renderFiles();
  });
  search.addEventListener('input', () => {
    showFiles = 10;
    renderFiles();
  });
  sort.addEventListener('change', () => {
    showFiles = 10;
    renderFiles();
  });

  fetch('assets/coverage-summary.json', {credentials: 'omit'})
    .then(response => {
      if (!response.ok) throw new Error('Summary fetch failed');
      return response.json();
    })
    .then(data => {
      if (data.schema !== 1 || data.repository !== 'LibreSign/libresign' ||
          !Array.isArray(data.directories) || !Array.isArray(data.files)) {
        throw new Error('Invalid coverage summary data');
      }
      report = data;
      renderDirectories();
      renderFiles();
    })
    .catch(() => {
      directories.textContent = 'Coverage details are temporarily unavailable. ';
      directories.append(anchor('Open the PHPUnit report', 'libresign/'));
      files.replaceChildren();
      count.textContent = '';
    });
})();
