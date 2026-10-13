let currentUser = null;
let currentPeriod = 'month';
let currentPoints = [];
let inLabel = '';
let outLabel = '';
let tableVisible = false;

window.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  if (currentUser.role !== 'client' && currentUser.role !== 'freelancer') {
    location.href = 'dashboard.html';
    return;
  }

  setupCabinetTabs(currentUser);
  document.getElementById('current-balance').textContent = formatUAH(currentUser.balance);

  document.querySelectorAll('.period-btn').forEach(btn => btn.addEventListener('click', () => selectPeriod(btn)));
  loadReport();
});

function selectPeriod(btn) {
  currentPeriod = btn.dataset.period;
  document.querySelectorAll('.period-btn').forEach(b => b.classList.toggle('active', b === btn));
  loadReport();
}

async function loadReport() {
  hideError('finance-error');
  const wrap = document.getElementById('finance-chart-wrap');
  wrap.innerHTML = '<p class="text-muted mb-0">Завантаження...</p>';

  try {
    const res = await apiFetch(`/finance.php?period=${currentPeriod}`);
    currentPoints = res.points;
    inLabel = res.in_label;
    outLabel = res.out_label;
    renderTotals(res.totals);
    renderReport();
  } catch (e) {
    showError('finance-error', e.message);
    wrap.innerHTML = '';
  }
}

function renderTotals(totals) {
  document.getElementById('totals-in').textContent = `${inLabel}: ${formatUAH(totals.in)}`;
  document.getElementById('totals-out').textContent = `${outLabel}: ${formatUAH(totals.out)}`;
}

function renderReport() {
  const wrap = document.getElementById('finance-chart-wrap');
  const tableLink = document.getElementById('toggle-table-link');
  const hasData = currentPoints.some(p => p.transactions.length);

  if (!hasData) {
    wrap.innerHTML = `<div class="text-center text-muted py-5">
      <div class="fs-1 mb-2">📊</div>
      <p class="mb-0">За обраний період операцій поки немає.</p>
    </div>`;
    tableLink.style.display = 'none';
    document.getElementById('finance-table-wrap').innerHTML = '';
    return;
  }

  wrap.innerHTML = '';
  wrap.appendChild(buildChart(currentPoints));
  tableLink.style.display = '';
  renderTableIfVisible();
}

function toggleTable() {
  tableVisible = !tableVisible;
  document.getElementById('toggle-table-link').textContent = tableVisible ? 'Сховати таблицю' : 'Показати таблицю';
  renderTableIfVisible();
}

function renderTableIfVisible() {
  const tableWrap = document.getElementById('finance-table-wrap');
  tableWrap.innerHTML = tableVisible ? buildTable(currentPoints) : '';
}

function buildTable(points) {
  const rows = points.map(p => `
    <tr>
      <td>${esc(bucketLabel(p.bucket))}</td>
      <td>${esc(formatUAH(p.in))}</td>
      <td>${esc(formatUAH(p.out))}</td>
    </tr>`).join('');
  return `
    <div class="table-responsive mt-2">
      <table class="table table-sm">
        <thead><tr><th>Період</th><th>${esc(inLabel)}</th><th>${esc(outLabel)}</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

// ─── LABEL FORMATTING ─────────────────────────────────────────────────────────
const MONTH_ABBR = ['січ', 'лют', 'бер', 'кві', 'тра', 'чер', 'лип', 'сер', 'вер', 'жов', 'лис', 'гру'];

function bucketLabel(bucket) {
  if (currentPeriod === 'month') {
    return String(Number(bucket.slice(8, 10)));
  }
  const [y, m] = bucket.split('-');
  return currentPeriod === 'year' ? MONTH_ABBR[Number(m) - 1] : `${MONTH_ABBR[Number(m) - 1]} ${y.slice(2)}`;
}

// ─── CHART (plain SVG, no external library) ──────────────────────────────────
const SVGNS = 'http://www.w3.org/2000/svg';
const CHART_W = 720;
const CHART_H = 280;
const PAD = { left: 52, right: 12, top: 16, bottom: 28 };
const COLOR_IN = '#2a78d6';
const COLOR_OUT = '#eb6834';
const COLOR_GRID = '#e1e0d9';
const COLOR_AXIS = '#c3c2b7';
const COLOR_MUTED = '#898781';
const COLOR_SURFACE = '#fcfcfb';

function svgEl(tag, attrs) {
  const el = document.createElementNS(SVGNS, tag);
  for (const k in attrs) el.setAttribute(k, attrs[k]);
  return el;
}

function niceMax(value) {
  if (value <= 0) return 100;
  const magnitude = Math.pow(10, Math.floor(Math.log10(value)));
  const residual = value / magnitude;
  let niceResidual;
  if (residual <= 1) niceResidual = 1;
  else if (residual <= 2) niceResidual = 2;
  else if (residual <= 5) niceResidual = 5;
  else niceResidual = 10;
  return niceResidual * magnitude;
}

function buildChart(points) {
  const maxVal = niceMax(Math.max(1, ...points.map(p => Math.max(p.in, p.out))));
  const plotW = CHART_W - PAD.left - PAD.right;
  const plotH = CHART_H - PAD.top - PAD.bottom;
  const n = points.length;
  const xStep = n > 1 ? plotW / (n - 1) : 0;
  const xAt = i => PAD.left + xStep * i;
  const yAt = v => PAD.top + plotH - (v / maxVal) * plotH;

  const svg = svgEl('svg', {
    viewBox: `0 0 ${CHART_W} ${CHART_H}`,
    class: 'finance-chart-svg',
    role: 'img',
    'aria-label': 'Графік операцій за обраний період',
  });

  // gridlines + y labels
  const gridSteps = 4;
  for (let i = 0; i <= gridSteps; i++) {
    const v = (maxVal / gridSteps) * i;
    const y = yAt(v);
    svg.appendChild(svgEl('line', { x1: PAD.left, x2: CHART_W - PAD.right, y1: y, y2: y, stroke: COLOR_GRID, 'stroke-width': 1 }));
    const label = svgEl('text', { x: PAD.left - 8, y: y + 3, 'text-anchor': 'end', 'font-size': 10, fill: COLOR_MUTED });
    label.textContent = Math.round(v).toLocaleString('uk-UA');
    svg.appendChild(label);
  }

  // baseline
  svg.appendChild(svgEl('line', { x1: PAD.left, x2: CHART_W - PAD.right, y1: yAt(0), y2: yAt(0), stroke: COLOR_AXIS, 'stroke-width': 1 }));

  // x labels — thin out so they never collide
  const labelEvery = Math.max(1, Math.ceil(n / 8));
  points.forEach((p, i) => {
    if (i % labelEvery !== 0 && i !== n - 1) return;
    const label = svgEl('text', { x: xAt(i), y: CHART_H - PAD.bottom + 16, 'text-anchor': 'middle', 'font-size': 10, fill: COLOR_MUTED });
    label.textContent = bucketLabel(p.bucket);
    svg.appendChild(label);
  });

  // series: area wash + line + markers
  [['in', COLOR_IN], ['out', COLOR_OUT]].forEach(([key, color]) => {
    const linePts = points.map((p, i) => `${xAt(i)},${yAt(p[key])}`).join(' ');
    const areaPts = `${xAt(0)},${yAt(0)} ${linePts} ${xAt(n - 1)},${yAt(0)}`;

    svg.appendChild(svgEl('polygon', { points: areaPts, fill: color, opacity: 0.1 }));
    svg.appendChild(svgEl('polyline', {
      points: linePts, fill: 'none', stroke: color, 'stroke-width': 2,
      'stroke-linejoin': 'round', 'stroke-linecap': 'round',
    }));
    points.forEach((p, i) => {
      svg.appendChild(svgEl('circle', {
        cx: xAt(i), cy: yAt(p[key]), r: 4, fill: color, stroke: COLOR_SURFACE, 'stroke-width': 2, class: 'finance-point',
      }));
    });
  });

  // crosshair (hidden until hover)
  const crosshair = svgEl('line', { x1: 0, x2: 0, y1: PAD.top, y2: CHART_H - PAD.bottom, stroke: COLOR_AXIS, 'stroke-width': 1 });
  crosshair.style.display = 'none';
  svg.appendChild(crosshair);

  // transparent hit layer — crosshair snaps to the nearest bucket, one
  // tooltip lists both series plus the transactions behind that bucket.
  const hitRect = svgEl('rect', { x: PAD.left, y: PAD.top, width: plotW, height: plotH, fill: 'transparent' });
  hitRect.style.cursor = 'crosshair';
  svg.appendChild(hitRect);

  const tooltip = document.getElementById('finance-tooltip');
  const chartWrap = document.getElementById('finance-chart-wrap');

  function showPoint(i, clientX, clientY) {
    const p = points[i];
    crosshair.setAttribute('x1', xAt(i));
    crosshair.setAttribute('x2', xAt(i));
    crosshair.style.display = '';

    tooltip.innerHTML = '';

    const title = document.createElement('div');
    title.className = 'fw-semibold small mb-1';
    title.textContent = bucketLabel(p.bucket);
    tooltip.appendChild(title);

    tooltip.appendChild(tooltipRow(COLOR_IN, inLabel, p.in));
    tooltip.appendChild(tooltipRow(COLOR_OUT, outLabel, p.out));

    if (p.transactions.length) {
      const hr = document.createElement('hr');
      hr.className = 'my-1';
      tooltip.appendChild(hr);
      p.transactions.slice(0, 5).forEach(t => {
        const row = document.createElement('div');
        row.className = 'small text-muted';
        row.textContent = `${t.description || t.type} — ${formatUAH(t.amount)}`;
        tooltip.appendChild(row);
      });
      if (p.transactions.length > 5) {
        const more = document.createElement('div');
        more.className = 'small text-muted fst-italic';
        more.textContent = `+ ще ${p.transactions.length - 5}`;
        tooltip.appendChild(more);
      }
    }

    tooltip.style.display = 'block';
    const wrapRect = chartWrap.getBoundingClientRect();
    let left = clientX - wrapRect.left + 12;
    if (left + tooltip.offsetWidth > wrapRect.width) left = clientX - wrapRect.left - tooltip.offsetWidth - 12;
    tooltip.style.left = left + 'px';
    tooltip.style.top = Math.max(0, clientY - wrapRect.top - 12) + 'px';
  }

  function hidePoint() {
    crosshair.style.display = 'none';
    tooltip.style.display = 'none';
  }

  hitRect.addEventListener('pointermove', (e) => {
    const rect = svg.getBoundingClientRect();
    const localX = (e.clientX - rect.left) * (CHART_W / rect.width);
    let idx = Math.round((localX - PAD.left) / (xStep || 1));
    idx = Math.max(0, Math.min(n - 1, idx));
    showPoint(idx, e.clientX, e.clientY);
  });
  hitRect.addEventListener('pointerleave', hidePoint);

  return svg;
}

function tooltipRow(color, label, value) {
  const row = document.createElement('div');
  row.className = 'small d-flex justify-content-between gap-3';

  const left = document.createElement('span');
  const key = document.createElement('span');
  key.className = 'finance-line-key';
  key.style.background = color;
  left.appendChild(key);
  left.appendChild(document.createTextNode(label));

  const strong = document.createElement('strong');
  strong.textContent = formatUAH(value);

  row.appendChild(left);
  row.appendChild(strong);
  return row;
}
