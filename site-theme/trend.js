// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
(() => {
  'use strict';
  const host = document.getElementById('coverage-trend');
  if (!host) return;
  const chart = host.querySelector('svg');
  const source = host.querySelector('#trend-source');
  const period = host.querySelector('#trend-period');
  const description = host.querySelector('#trend-status');
  const pointDetails = host.querySelector('#trend-point-details');
  const tooltip = host.querySelector('#trend-hover-tooltip');
  const details = host.querySelector('#trend-table-details');
  const tbody = host.querySelector('#trend-data tbody');
  const ns = 'http://www.w3.org/2000/svg';

  const node = (tag, attrs, text) => {
    const n = document.createElementNS(ns, tag);
    Object.entries(attrs || {}).forEach(([k, v]) => n.setAttribute(k, String(v)));
    if (text !== undefined) n.textContent = text;
    return n;
  };
  const percent = value => new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 1, maximumFractionDigits: 2,
  }).format(value) + '%';
  const dateLabel = value => new Date(value).toLocaleDateString('pt-BR', {timeZone: 'UTC'});
  let series = null;
  let selected = null;
  let points = [];

  const day = 86400000;
  const staleThreshold = 90 * day;

  function sourceUrl(p) {
    return selected === 'legacy_coveralls'
      ? 'https://coveralls.io/builds/' + p.run
      : 'https://github.com/LibreSign/libresign/actions/runs/' + p.run;
  }

  function detailsFor(p, stale = false, inspectedDate = null) {
    const amount = new Intl.NumberFormat('pt-BR');
    const recorded = dateLabel(p.date);
    const source = ' (' + amount.format(p.covered) + ' de ' +
      amount.format(p.total) + ' itens analisados), commit ' + p.sha.slice(0, 9) + '.';
    pointDetails.textContent = stale
      ? 'Sem nova medição' + (inspectedDate ? ' em ' + dateLabel(inspectedDate) : '') +
        '. Último coverage conhecido: ' + percent(p.pct) +
        ', medido em ' + recorded + source
      : 'Medição real em ' + recorded + ': ' + percent(p.pct) + source;
    pointDetails.href = sourceUrl(p);
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
    points.forEach(p => {
      const row = document.createElement('tr');
      const cells = [dateLabel(p.date), percent(p.pct), p.covered + '/' + p.total, p.sha.slice(0, 9)];
      cells.forEach(v => {
        const cell = document.createElement('td');
        cell.textContent = v;
        row.append(cell);
      });
      const linkCell = document.createElement('td');
      const link = document.createElement('a');
      link.href = selected === 'legacy_coveralls'
        ? 'https://coveralls.io/builds/' + p.run
        : 'https://github.com/LibreSign/libresign/actions/runs/' + p.run;
      link.textContent = 'Fonte';
      link.rel = 'noopener noreferrer';
      linkCell.append(link);
      row.append(linkCell);
      tbody.append(row);
    });
  }

  function render() {
    if (!series) return;
    selected = source.value;
    const entry = series[selected];
    const raw = entry.points;
    const endTime = Math.max(Date.now(), raw.length ? Date.parse(raw[raw.length - 1].date) : 0);
    const startTime = period.value === 'year' ? endTime - 365 * day
      : period.value === 'quarter' ? endTime - 90 * day
      : raw.length ? Date.parse(raw[0].date) : endTime - 365 * day;
    points = raw.filter(p => {
      const t = Date.parse(p.date);
      return t >= startTime && t <= endTime;
    });
    const previous = [...raw].reverse().find(p => Date.parse(p.date) < startTime);
    const allVisible = previous ? [previous, ...points] : points;
    const mostRecent = raw.length ? raw[raw.length - 1] : null;
    const current = points.length ? points[points.length - 1] : null;
    if (!mostRecent) {
      description.textContent = 'Ainda não existem resultados oficiais da main para esta fonte.';
    } else if (!points.length) {
      description.textContent = 'Sem novas medições neste período. Último coverage conhecido: ' +
        percent(mostRecent.pct) + ', registrado em ' + dateLabel(mostRecent.date) + '.';
    } else {
      description.textContent = entry.label + ': ' + points.length +
        ' medições reais neste período; última em ' + dateLabel(current.date) +
        ': ' + percent(current.pct) + ' (' + current.covered + '/' + current.total + ').';
    }
    pointDetails.hidden = true;
    hideTooltip();
    details.open = false;
    tbody.replaceChildren();
    chart.replaceChildren();

    const compact = window.innerWidth < 660;
    const viewWidth = compact ? 420 : 1000;
    chart.setAttribute('viewBox', compact ? '0 0 420 310' : '0 0 1000 340');
    chart.setAttribute('role', 'img');
    chart.setAttribute('aria-label', entry.label + '. ' + description.textContent);
    const left = compact ? 48 : 64;
    const top = 20, width = compact ? 350 : 910, height = compact ? 225 : 270;
    const X = t => left + (t - startTime) / (endTime - startTime || 1) * width;
    const Y = p => top + (100 - p.pct) / 100 * height;

    for (let i = 0; i <= 4; i++) {
      const y = top + i * height / 4;
      chart.append(node('line', {x1: left, y1: y, x2: left + width,
        y2: y, class: 'coverage-grid'}));
      chart.append(node('text', {x: 8, y: y + 5, class: 'coverage-axis'}, String(100 - i * 25) + '%'));
    }

    const tickStep = period.value === 'quarter' ? 30 * day
      : period.value === 'year' ? 90 * day : 365 * day;
    for (let t = startTime; t <= endTime; t += tickStep) {
      const tick = new Date(t);
      const text = period.value === 'all'
        ? String(tick.getUTCFullYear())
        : tick.toLocaleDateString('pt-BR', {timeZone: 'UTC', month: 'short',
          year: period.value === 'year' ? '2-digit' : undefined});
      chart.append(node('text', {x: X(t), y: top + height + 28, class: 'coverage-axis'}, text));
    }

    const addGap = (p, from, to, withUpdate = false) => {
      if (to <= from) return;
      const y = Y(p);
      const info = 'Sem medições. Último valor conhecido: ' + percent(p.pct) +
        ', medido em ' + dateLabel(p.date) + '.';
      chart.append(node('line', {x1: X(from), y1: y, x2: X(to), y2: y,
        class: 'coverage-carry-line'}));
      const hit = node('line', {x1: X(from), y1: y, x2: X(to), y2: y,
        class: 'coverage-gap-hit', tabindex: 0,
        'aria-label': info, role: 'button'});
      hit.append(node('title', {}, info));
      const show = event => {
        let examined = null;
        if (event && typeof event.clientX === 'number') {
          const rect = chart.getBoundingClientRect();
          const x = (event.clientX - rect.left) / rect.width * viewWidth;
          const t = startTime + (x - left) / width * (endTime - startTime);
          examined = new Date(Math.min(to, Math.max(from, t))).toISOString();
        }
        detailsFor(p, true, examined);
        showTooltip(event);
      };
      hit.addEventListener('pointerenter', show);
      hit.addEventListener('pointermove', show);
      hit.addEventListener('pointerleave', hideTooltip);
      hit.addEventListener('focus', () => detailsFor(p, true));
      chart.append(hit);
      if (withUpdate) {
        // A new, separately measured value only becomes known on its own date.
        const updated = allVisible.find(q => Date.parse(q.date) === to);
        if (updated) {
          chart.append(node('line', {x1: X(to), y1: y, x2: X(to),
            y2: Y(updated), class: 'coverage-update-line'}));
        }
      }
    };

    for (let i = 1; i < allVisible.length; i++) {
      const p = allVisible[i - 1], q = allVisible[i];
      const a = Date.parse(p.date), b = Date.parse(q.date);
      if (b < startTime || a > endTime) continue;
      const from = Math.max(a, startTime), to = Math.min(b, endTime);
      const scopeChanged = p.total > 0 && Math.abs(q.total - p.total) / p.total > .3;
      if (b - a > staleThreshold || scopeChanged) {
        addGap(p, from, to, true);
      } else {
        const ratio = (from - a) / (b - a || 1);
        const clippedY = Y(p) + ratio * (Y(q) - Y(p));
        chart.append(node('line', {x1: X(from), y1: clippedY,
          x2: X(to), y2: Y(q), class: 'coverage-trend-line'}));
      }
    }

    // Keep the last *known* reading visible after the latest measurement,
    // but never manufacture additional observation points.
    if (mostRecent) {
      const lastDate = Date.parse(mostRecent.date);
      if (lastDate < endTime) {
        addGap(mostRecent, Math.max(startTime, lastDate), endTime);
      }
    }
    points.forEach(p => {
      const dot = node('circle', {cx: X(Date.parse(p.date)), cy: Y(p), r: 3.3,
        class: 'coverage-dot', tabindex: 0, role: 'button',
        'aria-label': dateLabel(p.date) + ': ' + percent(p.pct) + ', medição real'});
      dot.append(node('title', {}, dateLabel(p.date) + ' — ' + percent(p.pct)));
      dot.addEventListener('pointerenter', event => { detailsFor(p); showTooltip(event); });
      dot.addEventListener('pointermove', showTooltip);
      dot.addEventListener('pointerleave', hideTooltip);
      dot.addEventListener('click', () => detailsFor(p));
      dot.addEventListener('focus', () => detailsFor(p));
      chart.append(dot);
    });
    if (mostRecent) detailsFor(mostRecent, !points.length);
  }

  source.addEventListener('change',render);
  period.addEventListener('change',render);
  let lastCompact = window.innerWidth < 660;
  window.addEventListener('resize', () => {
    const compact = window.innerWidth < 660;
    if (lastCompact !== compact) {
      lastCompact = compact;
      render();
    }
  });
  details.addEventListener('toggle', () => {if (details.open) populateTable();});
  fetch('assets/coverage-history.json', {credentials:'omit'})
    .then(response => {if(!response.ok)throw new Error('Dados não disponíveis');return response.json();})
    .then(data => {
      if (data.schema !== 1 || data.repository !== 'LibreSign/libresign') throw new Error('Formato inválido');
      series = data.series;
      if (!series.octocov_main.points.length) {
        source.querySelector('[value="octocov_main"]').disabled=true;
      } else {
        source.value='octocov_main';
      }
      render();
    })
    .catch(() => {description.textContent='Não foi possível carregar a série histórica.';});
})();
