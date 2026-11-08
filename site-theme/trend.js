// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
(() => {
  'use strict';

  const host = document.getElementById('coverage-trend');
  if (!host) return;

  const chart = host.querySelector('svg');
  const period = host.querySelector('#trend-period');
  const description = host.querySelector('#trend-status');
  const pointDetails = host.querySelector('#trend-point-details');
  const tooltip = host.querySelector('#trend-hover-tooltip');
  const details = host.querySelector('#trend-table-details');
  const tbody = host.querySelector('#trend-data tbody');
  const ns = 'http://www.w3.org/2000/svg';
  const day = 86400000;
  const staleThreshold = 90 * day;
  const formatNumber = new Intl.NumberFormat('en-US');
  const formatPercent = value => new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 1, maximumFractionDigits: 2,
  }).format(value) + '%';
  const formatDate = value => new Date(value).toLocaleDateString('en-US', {
    timeZone: 'UTC', month: 'short', day: 'numeric', year: 'numeric',
  });
  const sources = {
    legacy_coveralls: {
      name: 'Coveralls',
      report: id => 'Coveralls build #' + id,
      url: id => 'https://coveralls.io/builds/' + id,
    },
    octocov_main: {
      name: 'Octocov',
      report: id => 'Octocov report · CI run #' + id,
      url: id => 'https://github.com/LibreSign/libresign/actions/runs/' + id,
    },
  };

  const node = (tag, attrs = {}, content) => {
    const element = document.createElementNS(ns, tag);
    Object.entries(attrs).forEach(([key, value]) => element.setAttribute(key, String(value)));
    if (content !== undefined) element.textContent = content;
    return element;
  };

  // Cut over at the first official main-branch Octocov reading. Preserve
  // legacy history before that date, but never connect unlike denominators.
  function combineHistory(data) {
    const legacy = data.series.legacy_coveralls.points.map(p => ({...p, origin: 'legacy_coveralls'}));
    const current = data.series.octocov_main.points.map(p => ({...p, origin: 'octocov_main'}));
    const cutover = current.length ? Date.parse(current[0].date) : Infinity;
    return [...legacy.filter(p => Date.parse(p.date) < cutover), ...current]
      .sort((a, b) => Date.parse(a.date) - Date.parse(b.date));
  }

  let history = [];
  let points = [];

  function reportInfo(point) {
    const source = sources[point.origin];
    return {
      label: source.report(point.run),
      href: source.url(point.run),
    };
  }

  function showPoint(point, stale = false, inspectedDate = null) {
    const report = reportInfo(point);
    const measured = formatDate(point.date);
    const count = formatNumber.format(point.covered) + ' / ' + formatNumber.format(point.total);
    pointDetails.textContent = stale
      ? 'No new measurement' + (inspectedDate ? ' on ' + formatDate(inspectedDate) : '') +
        '. Last known: ' + formatPercent(point.pct) + ' (measured ' + measured +
        ') · ' + report.label
      : formatPercent(point.pct) + ' · ' + measured + ' · ' + count +
        ' covered · ' + report.label;
    pointDetails.href = report.href;
    pointDetails.hidden = false;
    pointDetails.classList.toggle('coverage-stale-detail', stale);
  }

  function showTooltip(event) {
    if (!event || typeof event.clientX !== 'number') return;
    tooltip.textContent = pointDetails.textContent;
    tooltip.hidden = false;
    const rect = host.getBoundingClientRect();
    const x = event.clientX - rect.left + 12;
    const y = event.clientY - rect.top - 14;
    tooltip.style.left = Math.max(8, Math.min(x, rect.width - 310)) + 'px';
    tooltip.style.top = Math.max(8, y - tooltip.offsetHeight) + 'px';
  }

  function hideTooltip() {
    tooltip.hidden = true;
  }

  function populateTable() {
    tbody.replaceChildren();
    for (const point of [...points].reverse()) {
      const row = document.createElement('tr');
      for (const value of [formatDate(point.date), formatPercent(point.pct),
        formatNumber.format(point.covered) + ' / ' + formatNumber.format(point.total),
        point.sha.slice(0, 9)]) {
        const cell = document.createElement('td');
        cell.textContent = value;
        row.append(cell);
      }
      const linkCell = document.createElement('td');
      const link = document.createElement('a');
      const report = reportInfo(point);
      link.href = report.href;
      link.textContent = report.label;
      link.rel = 'noopener noreferrer';
      linkCell.append(link);
      row.append(linkCell);
      tbody.append(row);
    }
    if (!points.length) {
      const row = document.createElement('tr');
      const cell = document.createElement('td');
      cell.colSpan = 5;
      cell.textContent = 'No measurements in this period.';
      row.append(cell);
      tbody.append(row);
    }
  }

  function render() {
    if (!history.length) {
      description.textContent = 'No measurements available yet.';
      return;
    }
    const latest = history[history.length - 1];
    const end = Math.max(Date.now(), Date.parse(latest.date));
    const start = period.value === 'year' ? end - 365 * day
      : period.value === 'quarter' ? end - 90 * day
      : Date.parse(history[0].date);

    points = history.filter(p => {
      const date = Date.parse(p.date);
      return date >= start && date <= end;
    });
    const previous = [...history].reverse().find(p => Date.parse(p.date) < start);
    const allVisible = previous ? [previous, ...points] : points;

    description.textContent = points.length
      ? 'Last main-branch measurement: ' + formatPercent(latest.pct) + ' · ' + formatDate(latest.date)
      : 'No new measurements · Last known: ' +
        formatPercent(latest.pct) + ' (' + formatDate(latest.date) + ')';

    pointDetails.hidden = true;
    hideTooltip();
    details.open = false;
    tbody.replaceChildren();
    chart.replaceChildren();

    const compact = window.innerWidth < 660;
    const viewWidth = compact ? 420 : 1000;
    chart.setAttribute('viewBox', compact ? '0 0 420 310' : '0 0 1000 340');
    // The SVG contains real links. Expose them to assistive technology;
    // role=img would flatten interactive descendants into a single image.
    chart.setAttribute('role', 'group');
    chart.setAttribute('aria-label', 'Total coverage over time. ' + description.textContent);

    const left = compact ? 48 : 64;
    const top = 20, width = compact ? 350 : 910, height = compact ? 225 : 270;
    const X = time => left + (time - start) / (end - start || 1) * width;
    const Y = point => top + (100 - point.pct) / 100 * height;

    for (let i = 0; i <= 4; i++) {
      const y = top + i * height / 4;
      chart.append(node('line', {x1: left, y1: y, x2: left + width,
        y2: y, class: 'coverage-grid'}));
      chart.append(node('text', {x: 8, y: y + 5, class: 'coverage-axis'}, (100 - i * 25) + '%'));
    }

    const tickStep = period.value === 'quarter' ? 30 * day
      : period.value === 'year' ? 90 * day : 365 * day;
    for (let time = start; time <= end; time += tickStep) {
      const date = new Date(time);
      const text = period.value === 'all'
        ? String(date.getUTCFullYear())
        : date.toLocaleDateString('en-US', {
            timeZone: 'UTC', month: 'short',
            year: period.value === 'year' ? '2-digit' : undefined,
          });
      chart.append(node('text', {x: X(time), y: top + height + 28, class: 'coverage-axis'}, text));
    }

    function addGap(point, from, to, update = null) {
      if (to <= from) return;
      const y = Y(point);
      const info = 'No new measurement. Last known: ' +
        formatPercent(point.pct) + ' (measured ' + formatDate(point.date) +
        ') · ' + reportInfo(point).label;
      chart.append(node('line', {x1: X(from), y1: y, x2: X(to),
        y2: y, class: 'coverage-carry-line'}));

      const hit = node('line', {x1: X(from), y1: y, x2: X(to), y2: y,
        class: 'coverage-gap-hit', tabindex: 0, 'aria-label': info});
      hit.append(node('title', {}, info));
      const onHover = event => {
        let inspected = null;
        if (event && typeof event.clientX === 'number') {
          const rect = chart.getBoundingClientRect();
          const x = (event.clientX - rect.left) / rect.width * viewWidth;
          const time = start + (x - left) / width * (end - start);
          inspected = new Date(Math.min(to, Math.max(from, time))).toISOString();
        }
        showPoint(point, true, inspected);
        showTooltip(event);
      };
      hit.addEventListener('pointerenter', onHover);
      hit.addEventListener('pointermove', onHover);
      hit.addEventListener('pointerleave', hideTooltip);
      hit.addEventListener('focus', () => showPoint(point, true));
      chart.append(hit);

      if (update) {
        // A vertical step means "new result on this date", not an invented reading.
        chart.append(node('line', {x1: X(to), y1: y, x2: X(to),
          y2: Y(update), class: 'coverage-update-line'}));
      }
    }

    for (let i = 1; i < allVisible.length; i++) {
      const point = allVisible[i - 1], next = allVisible[i];
      const a = Date.parse(point.date), b = Date.parse(next.date);
      if (b < start || a > end) continue;
      const from = Math.max(a, start), to = Math.min(b, end);
      const methodChanged = point.origin !== next.origin;
      const scopeChanged = point.total > 0 &&
        Math.abs(next.total - point.total) / point.total > .3;
      if (methodChanged || b - a > staleThreshold || scopeChanged) {
        // Never draw a vertical connector between Coveralls and Octocov.
        addGap(point, from, to, methodChanged ? null : next);
      } else {
        const ratio = (from - a) / (b - a || 1);
        const initialY = Y(point) + ratio * (Y(next) - Y(point));
        chart.append(node('line', {x1: X(from), y1: initialY,
          x2: X(to), y2: Y(next), class: 'coverage-trend-line'}));
      }
    }

    if (Date.parse(latest.date) < end) {
      addGap(latest, Math.max(start, Date.parse(latest.date)), end);
    }

    points.forEach(point => {
      const measured = formatDate(point.date) + ' · ' + formatPercent(point.pct) +
        ' · ' + reportInfo(point).label;
      const report = reportInfo(point);
      // A real SVG anchor is keyboard-accessible and opens the measurement
      // directly, without requiring users to chase the details link below.
      const link = node('a', {href: report.href, class: 'coverage-point-link',
        'aria-label': 'Open report for ' + measured});
      link.append(node('title', {}, 'Open ' + report.label));
      // Keep the visual dot small, with a larger invisible pointer target.
      link.append(node('circle', {cx: X(Date.parse(point.date)), cy: Y(point),
        r: 12, class: 'coverage-dot-hit', 'aria-hidden': 'true'}));
      link.append(node('circle', {cx: X(Date.parse(point.date)), cy: Y(point),
        r: 3.3, class: 'coverage-dot', 'aria-hidden': 'true'}));
      link.addEventListener('pointerenter', event => {
        showPoint(point);
        showTooltip(event);
      });
      link.addEventListener('pointermove', showTooltip);
      link.addEventListener('pointerleave', hideTooltip);
      link.addEventListener('focus', () => showPoint(point));
      chart.append(link);
    });
    showPoint(latest, !points.length);
  }

  period.addEventListener('change', render);
  let wasCompact = window.innerWidth < 660;
  window.addEventListener('resize', () => {
    const compact = window.innerWidth < 660;
    if (wasCompact !== compact) {
      wasCompact = compact;
      render();
    }
  });
  details.addEventListener('toggle', () => {
    if (details.open) populateTable();
  });

  fetch('assets/coverage-history.json', {credentials: 'omit'})
    .then(response => {
      if (!response.ok) throw new Error('History data unavailable');
      return response.json();
    })
    .then(data => {
      if (data.schema !== 1 || data.repository !== 'LibreSign/libresign' ||
          !data.series?.legacy_coveralls?.points || !data.series?.octocov_main?.points) {
        throw new Error('Invalid history format');
      }
      history = combineHistory(data);
      render();
    })
    .catch(() => {
      description.textContent = 'Coverage history is temporarily unavailable.';
    });
})();
