(() => {
    const FRESH_SECONDS = 180;
    const STALE_SECONDS = 600;
    const TARGET_LOW = 70;
    const TARGET_HIGH = 180;
    const RANGE_YELLOW_MAX = 240;
    const RANGE_ORANGE_MAX = 349;
    const RANGE_GREEN = '#3ddc97';
    const RANGE_YELLOW = '#f4c95d';
    const RANGE_ORANGE = '#f08c32';
    const RANGE_DARK_RED = '#b71c1c';
    const FILL_ALPHA = 0.55;
    const MIN_WINDOW_MS = 15 * 60 * 1000;
    // LibreLinkUp graphData is ~15-minute samples; keep those connected after a backfill.
    const GAP_MS = 20 * 60 * 1000;
    const MINUTE_MS = 60 * 1000;

    const valueEl = document.getElementById('value');
    const arrowEl = document.getElementById('arrow');
    const trendEl = document.getElementById('trend');
    const ageEl = document.getElementById('age');
    const bannerEl = document.getElementById('banner');
    const sourceEl = document.getElementById('source-line');
    const unlockForm = document.getElementById('unlock-form');
    const unlockError = document.getElementById('unlock-error');
    const unlockBtn = document.getElementById('unlock-btn');
    const forgetKeysBtn = document.getElementById('forget-keys');
    const lockBtn = document.getElementById('lock-btn');
    const keyFields = document.getElementById('key-fields');
    const publicKeyEl = document.getElementById('public-key');
    const privateKeyEl = document.getElementById('private-key');
    const publicKeyFile = document.getElementById('public-key-file');
    const privateKeyFile = document.getElementById('private-key-file');
    const passphraseEl = document.getElementById('pgp-passphrase');
    const buttons = [...document.querySelectorAll('.ranges button')];
    const canvas = document.getElementById('chart');
    const overviewCanvas = document.getElementById('chart-overview');
    const tooltipEl = document.getElementById('chart-tooltip');
    const windowEl = document.getElementById('chart-window');
    const statsEl = document.getElementById('chart-stats');

    let rangeHours = 3;
    let pollSeconds = 5;
    let chart;
    let overviewChart;
    let lastKnown = null;
    let offline = false;
    let allReadings = [];
    let historyCache = new Map();
    let historyRevisions = new Map();
    let discoveredDays = null;
    let viewStart = null;
    let viewEnd = null;
    let customView = false;
    let hoverIndex = -1;
    let hoverTime = null;
    let panState = null;
    let brushState = null;
    let vaultKeys = null;
    let refreshTimer = null;

    function fetchLive(path) {
        return fetch(`${path}?t=${Date.now()}`, { cache: 'no-store' });
    }

    function isOkResponse(response) {
        return response.ok;
    }

    async function readSnapshot(response) {
        const text = await response.text();
        if (!response.ok) {
            throw new Error(`Snapshot missing from ${response.url} (${response.status})`);
        }
        try {
            return await PgpVault.decryptJson(text, vaultKeys);
        } catch (error) {
            throw new Error(`Unable to decrypt ${response.url}: ${error.message}`);
        }
    }

    async function readFileAsText(file) {
        return await file.text();
    }

    function staleLevel(ageSeconds) {
        if (ageSeconds == null) {
            return 'missing';
        }
        if (ageSeconds < FRESH_SECONDS) {
            return 'fresh';
        }
        if (ageSeconds < STALE_SECONDS) {
            return 'stale';
        }
        return 'disconnected';
    }

    function withAge(payload) {
        const reading = payload || {};
        if (!reading.timestamp) {
            return {
                glucoseMgDl: null,
                trend: null,
                trendArrow: null,
                timestamp: null,
                ageSeconds: null,
                stale: true,
                staleLevel: 'missing',
            };
        }
        const ageSeconds = Math.max(0, Math.floor((Date.now() - Date.parse(reading.timestamp)) / 1000));
        const level = staleLevel(ageSeconds);
        return {
            glucoseMgDl: reading.glucoseMgDl,
            trend: reading.trend || null,
            trendArrow: reading.trendArrow || null,
            timestamp: reading.timestamp,
            ageSeconds,
            stale: level !== 'fresh',
            staleLevel: level,
        };
    }

    function utcYmd(date) {
        const year = date.getUTCFullYear();
        const month = String(date.getUTCMonth() + 1).padStart(2, '0');
        const day = String(date.getUTCDate()).padStart(2, '0');
        return `${year}${month}${day}`;
    }

    function addUtcDays(ymd, days) {
        const year = Number(ymd.slice(0, 4));
        const month = Number(ymd.slice(4, 6)) - 1;
        const day = Number(ymd.slice(6, 8));
        return utcYmd(new Date(Date.UTC(year, month, day + days)));
    }

    function daysFromStatus(status) {
        if (Array.isArray(status?.historyDays) && status.historyDays.length) {
            return status.historyDays.map(String);
        }
        if (!status?.earliestReadingAt) {
            return null;
        }
        const end = new Date();
        const start = new Date(status.earliestReadingAt);
        const days = [];
        let cursor = utcYmd(start);
        const last = utcYmd(end);
        while (cursor <= last) {
            days.push(cursor);
            cursor = addUtcDays(cursor, 1);
        }
        return days;
    }

    async function discoverDays() {
        const days = [];
        let cursor = utcYmd(new Date());
        let misses = 0;
        for (let i = 0; i < 400 && misses < 2; i += 1) {
            const response = await fetchLive(`/history-${cursor}.json.asc`);
            if (!isOkResponse(response)) {
                misses += 1;
                cursor = addUtcDays(cursor, -1);
                continue;
            }
            misses = 0;
            const payload = await readSnapshot(response);
            historyCache.set(cursor, payload.readings || []);
            days.push(cursor);
            cursor = addUtcDays(cursor, -1);
        }
        return days.reverse();
    }

    async function resolveHistoryDays(status) {
        const listed = daysFromStatus(status);
        if (listed) {
            discoveredDays = listed;
            return listed;
        }
        if (discoveredDays) {
            const today = utcYmd(new Date());
            if (!discoveredDays.includes(today)) {
                discoveredDays = [...discoveredDays, today];
            }
            return discoveredDays;
        }
        discoveredDays = await discoverDays();
        return discoveredDays;
    }

    async function loadHistory(days, status = {}) {
        const today = utcYmd(new Date());
        const yesterday = addUtcDays(today, -1);
        const listedDays = new Set(days);
        for (const day of historyCache.keys()) {
            if (!listedDays.has(day)) {
                historyCache.delete(day);
                historyRevisions.delete(day);
            }
        }
        const revisions = status && status.historyRevisions && typeof status.historyRevisions === 'object'
            ? status.historyRevisions
            : null;
        const fetches = [];

        for (const day of days) {
            const revision = revisions ? revisions[day] : null;
            const revisionChanged = revisions && revision != null && historyRevisions.get(day) !== revision;
            if (day !== today && day !== yesterday && historyCache.has(day) && !revisionChanged) {
                continue;
            }
            fetches.push((async () => {
                const response = await fetchLive(`/history-${day}.json.asc`);
                if (!isOkResponse(response)) {
                    if (!historyCache.has(day)) {
                        historyCache.set(day, []);
                    }
                    return;
                }
                const payload = await readSnapshot(response);
                historyCache.set(day, payload.readings || []);
                if (revisions && revision != null) {
                    historyRevisions.set(day, revision);
                }
            })());
        }

        await Promise.all(fetches);

        const readings = [];
        const seen = new Set();
        for (const day of [...historyCache.keys()].sort()) {
            for (const point of historyCache.get(day) || []) {
                if (!point?.timestamp || seen.has(point.timestamp)) {
                    continue;
                }
                seen.add(point.timestamp);
                readings.push(point);
            }
        }
        readings.sort((a, b) => Date.parse(a.timestamp) - Date.parse(b.timestamp));
        return readings;
    }

    function formatAge(seconds) {
        if (seconds == null) {
            return 'no reading stored yet';
        }
        if (seconds < 60) {
            return `updated ${seconds} sec ago`;
        }
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) {
            return `Last reading ${minutes} minute${minutes === 1 ? '' : 's'} ago`;
        }
        const hrs = Math.floor(minutes / 60);
        return `Last reading ${hrs} hour${hrs === 1 ? '' : 's'} ago`;
    }

    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function formatClock(date) {
        return `${pad2(date.getHours())}:${pad2(date.getMinutes())}`;
    }

    function formatDateTime(date, { withDate = true } = {}) {
        const time = formatClock(date);
        if (!withDate) {
            return time;
        }
        const month = date.toLocaleString(undefined, { month: 'short' });
        return `${month} ${date.getDate()}, ${time}`;
    }

    function formatTick(value, spanMs) {
        const date = new Date(value);
        if (spanMs > 5 * 24 * 3600 * 1000) {
            return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        }
        if (spanMs > 24 * 3600 * 1000) {
            return `${date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} ${formatClock(date)}`;
        }
        return formatClock(date);
    }

    function formatWindowLabel(start, end) {
        if (start == null || end == null) {
            return '';
        }
        const span = end - start;
        const startDate = new Date(start);
        const endDate = new Date(end);
        if (span > 36 * 3600 * 1000) {
            return `${formatDateTime(startDate)} – ${formatDateTime(endDate)}`;
        }
        const sameDay = startDate.toDateString() === endDate.toDateString();
        if (sameDay) {
            return `${startDate.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })}  ${formatClock(startDate)} – ${formatClock(endDate)}`;
        }
        return `${formatDateTime(startDate)} – ${formatDateTime(endDate)}`;
    }

    function setBanner(level) {
        bannerEl.className = 'banner';
        if (level === 'stale') {
            bannerEl.textContent = 'DATA STALE';
            bannerEl.classList.add('stale');
        } else if (level === 'disconnected') {
            bannerEl.textContent = 'DATA STALE / DISCONNECTED';
            bannerEl.classList.add('disconnected');
        } else if (level === 'missing') {
            bannerEl.textContent = 'NO READING YET';
            bannerEl.classList.add('missing');
        } else if (offline) {
            bannerEl.textContent = 'OFFLINE — showing last known data';
            bannerEl.classList.add('stale');
        } else {
            bannerEl.classList.add('hidden');
            return;
        }
    }

    function renderCurrent(payload) {
        const current = withAge(payload);
        lastKnown = current;
        const level = current.staleLevel || 'missing';
        document.body.className = level;
        setBanner(offline ? 'stale' : level);

        valueEl.textContent = current.glucoseMgDl == null ? '--' : String(current.glucoseMgDl);
        arrowEl.textContent = current.trendArrow || '';
        trendEl.textContent = current.trend || '';
        ageEl.textContent = formatAge(current.ageSeconds);
        if (offline) {
            ageEl.textContent += ' (cached)';
        }
    }

    function colorForGlucose(value) {
        if (value == null || !Number.isFinite(value)) {
            return RANGE_GREEN;
        }
        if (value <= TARGET_HIGH) {
            return RANGE_GREEN;
        }
        if (value <= RANGE_YELLOW_MAX) {
            return RANGE_YELLOW;
        }
        if (value <= RANGE_ORANGE_MAX) {
            return RANGE_ORANGE;
        }
        return RANGE_DARK_RED;
    }

    function hexToRgba(hex, alpha) {
        const value = hex.replace('#', '');
        const n = parseInt(value, 16);
        return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
    }

    function glucoseGradient(chart, alpha) {
        const { ctx, chartArea, scales } = chart || {};
        const points = chart?.data?.datasets?.[0]?.data || [];
        if (!ctx || !chartArea || !scales?.x) {
            return hexToRgba(RANGE_GREEN, alpha);
        }
        const { left, right } = chartArea;
        const width = right - left;
        if (width <= 0) {
            return hexToRgba(RANGE_GREEN, alpha);
        }

        const samples = points.filter((point) => point && point.y != null && Number.isFinite(point.x));
        if (!samples.length) {
            return hexToRgba(RANGE_GREEN, alpha);
        }

        const offsetFor = (x) => (scales.x.getPixelForValue(x) - left) / width;
        const thresholds = [TARGET_HIGH, RANGE_YELLOW_MAX, RANGE_ORANGE_MAX + 1];
        const stops = [];
        const addStop = (offset, color) => {
            stops.push({
                offset: Math.min(1, Math.max(0, offset)),
                color: hexToRgba(color, alpha),
            });
        };

        addStop(0, colorForGlucose(samples[0].y));

        for (let i = 0; i < samples.length; i += 1) {
            const curr = samples[i];
            if (i > 0) {
                const prev = samples[i - 1];
                if (colorForGlucose(prev.y) !== colorForGlucose(curr.y) && prev.y !== curr.y) {
                    thresholds.forEach((threshold) => {
                        const crosses = (prev.y < threshold && curr.y >= threshold)
                            || (prev.y >= threshold && curr.y < threshold);
                        if (!crosses) {
                            return;
                        }
                        const ratio = (threshold - prev.y) / (curr.y - prev.y);
                        const mid = offsetFor(prev.x + (curr.x - prev.x) * ratio);
                        if (mid <= 0 || mid >= 1) {
                            return;
                        }
                        addStop(mid - 0.0008, colorForGlucose(prev.y));
                        addStop(mid + 0.0008, colorForGlucose(curr.y));
                    });
                }
            }
            const offset = offsetFor(curr.x);
            if (offset < 0 || offset > 1) {
                continue;
            }
            addStop(offset, colorForGlucose(curr.y));
        }

        addStop(1, colorForGlucose(samples[samples.length - 1].y));

        stops.sort((a, b) => a.offset - b.offset);
        for (let i = 1; i < stops.length; i += 1) {
            if (stops[i].offset <= stops[i - 1].offset) {
                stops[i].offset = Math.min(1, stops[i - 1].offset + 0.0001);
            }
        }

        const gradient = ctx.createLinearGradient(left, 0, right, 0);
        stops.forEach((stop) => gradient.addColorStop(stop.offset, stop.color));
        return gradient;
    }

    function toPoints(readings) {
        return readings.map((point) => ({
            x: Date.parse(point.timestamp),
            y: point.glucoseMgDl,
            reading: point,
        })).filter((point) => Number.isFinite(point.x) && point.y != null);
    }

    function withGaps(points) {
        if (points.length === 0) {
            return [];
        }
        const series = [];
        let previous = null;
        for (const point of points) {
            if (previous && point.x - previous.x > GAP_MS) {
                series.push({ x: previous.x + 1, y: null, reading: null });
            }
            series.push(point);
            previous = point;
        }
        return series;
    }

    function visibleReadings(readings, start, end) {
        return readings.filter((point) => {
            const time = Date.parse(point.timestamp);
            return time >= start && time <= end;
        });
    }

    function nearestIndex(points, time) {
        if (!points.length) {
            return -1;
        }
        let low = 0;
        let high = points.length - 1;
        while (low < high) {
            const mid = Math.floor((low + high) / 2);
            if (points[mid].x < time) {
                low = mid + 1;
            } else {
                high = mid;
            }
        }
        let best = low;
        if (low > 0 && Math.abs(points[low - 1].x - time) <= Math.abs(points[low].x - time)) {
            best = low - 1;
        }
        while (best > 0 && points[best].y == null) {
            best -= 1;
        }
        while (best < points.length - 1 && points[best].y == null) {
            best += 1;
        }
        return points[best].y == null ? -1 : best;
    }

    function dataBounds(readings) {
        if (!readings.length) {
            const now = Date.now();
            return { start: now - 3 * 3600 * 1000, end: now };
        }
        return {
            start: Date.parse(readings[0].timestamp),
            end: Date.parse(readings[readings.length - 1].timestamp),
        };
    }

    function presetWindow(readings) {
        const bounds = dataBounds(readings);
        const end = Math.max(bounds.end, Date.now());
        if (rangeHours === 'all') {
            return { start: bounds.start, end };
        }
        const span = Number(rangeHours) * 3600 * 1000;
        return { start: Math.max(bounds.start, end - span), end };
    }

    function clampWindow(start, end, readings) {
        const bounds = dataBounds(readings);
        const maxEnd = Math.max(bounds.end, Date.now());
        let nextStart = start;
        let nextEnd = end;
        if (nextEnd - nextStart < MIN_WINDOW_MS) {
            const mid = (nextStart + nextEnd) / 2;
            nextStart = mid - MIN_WINDOW_MS / 2;
            nextEnd = mid + MIN_WINDOW_MS / 2;
        }
        const fullSpan = Math.max(MIN_WINDOW_MS, maxEnd - bounds.start);
        if (nextEnd - nextStart > fullSpan) {
            nextStart = bounds.start;
            nextEnd = maxEnd;
        }
        if (nextStart < bounds.start) {
            nextEnd += bounds.start - nextStart;
            nextStart = bounds.start;
        }
        if (nextEnd > maxEnd) {
            nextStart -= nextEnd - maxEnd;
            nextEnd = maxEnd;
        }
        nextStart = Math.max(bounds.start, nextStart);
        nextEnd = Math.min(maxEnd, Math.max(nextStart + MIN_WINDOW_MS, nextEnd));
        return { start: nextStart, end: nextEnd };
    }

    function applyPreset(readings) {
        const window = presetWindow(readings);
        viewStart = window.start;
        viewEnd = window.end;
        customView = false;
    }

    function setWindow(start, end, readings, userDriven = true) {
        const window = clampWindow(start, end, readings);
        viewStart = window.start;
        viewEnd = window.end;
        if (userDriven) {
            customView = true;
        }
    }

    function yLimits(points) {
        const values = points.map((point) => point.y).filter((value) => value != null);
        if (!values.length) {
            return { min: 60, max: 200 };
        }
        const min = Math.min(TARGET_LOW, ...values);
        const max = Math.max(TARGET_HIGH, ...values);
        return {
            min: Math.floor((min - 15) / 10) * 10,
            max: Math.ceil((max + 15) / 10) * 10,
        };
    }

    function downsample(points, maxPoints) {
        if (points.length <= maxPoints) {
            return points;
        }
        const step = (points.length - 1) / (maxPoints - 1);
        const sampled = [];
        for (let i = 0; i < maxPoints; i += 1) {
            sampled.push(points[Math.round(i * step)]);
        }
        return sampled;
    }

    function overviewSeries(points, maxPoints) {
        if (!points.length) {
            return [];
        }
        const sorted = [...points].sort((a, b) => a.x - b.x);
        const segments = [];
        let segment = [sorted[0]];
        for (let i = 1; i < sorted.length; i += 1) {
            if (sorted[i].x - sorted[i - 1].x > GAP_MS) {
                segments.push(segment);
                segment = [];
            }
            segment.push(sorted[i]);
        }
        segments.push(segment);

        const total = sorted.length;
        const series = [];
        segments.forEach((part, index) => {
            const budget = Math.max(2, Math.round(maxPoints * part.length / total));
            series.push(...downsample(part, budget));
            if (index < segments.length - 1) {
                series.push({ x: part[part.length - 1].x + 1, y: null, reading: null });
            }
        });
        return series;
    }

    function renderStats(points) {
        windowEl.textContent = formatWindowLabel(viewStart, viewEnd);
        const values = points.map((point) => point.y).filter((value) => value != null);
        if (!values.length) {
            statsEl.textContent = 'no readings in this window';
            return;
        }
        const high = Math.max(...values);
        const low = Math.min(...values);
        const avg = Math.round(values.reduce((sum, value) => sum + value, 0) / values.length);
        const change = high - low;
        statsEl.innerHTML = `High <strong>${high}</strong> · Low <strong>${low}</strong> · Avg <strong>${avg}</strong> · Δ <strong>${change}</strong>`;
    }

    function eventPosition(event, instance) {
        if (Chart.helpers && typeof Chart.helpers.getRelativePosition === 'function') {
            return Chart.helpers.getRelativePosition(event, instance);
        }
        const rect = instance.canvas.getBoundingClientRect();
        return {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top,
        };
    }

    function hideTooltip() {
        hoverIndex = -1;
        hoverTime = null;
        tooltipEl.classList.add('hidden');
        tooltipEl.innerHTML = '';
        tooltipEl.style.borderColor = '';
        if (chart) {
            chart.draw();
        }
    }

    function restoreHover() {
        if (hoverTime == null || !chart) {
            return;
        }
        const points = mainPoints();
        const index = nearestIndex(points, hoverTime);
        if (index < 0) {
            hideTooltip();
            return;
        }
        hoverIndex = index;
        const point = points[index];
        const element = chart.getDatasetMeta(0).data[index];
        if (!element || point.y == null) {
            return;
        }
        showTooltip(point, element.x, element.y);
        chart.draw();
    }

    function showTooltip(point, pixelX, pixelY) {
        const date = new Date(point.x);
        const reading = point.reading || {};
        const trend = [reading.trendArrow, reading.trend].filter(Boolean).join(' ');
        const color = colorForGlucose(point.y);
        tooltipEl.innerHTML = `
            <p class="t-time">${formatDateTime(date)}</p>
            <p class="t-value" style="color:${color}">${point.y}</p>
            <p class="t-trend">${trend ? `${trend} · mg/dL` : 'mg/dL'}</p>
        `;
        tooltipEl.style.borderColor = color;
        tooltipEl.classList.remove('hidden');

        const wrap = canvas.parentElement.getBoundingClientRect();
        const left = Math.min(wrap.width - 16, Math.max(16, pixelX));
        const top = Math.max(18, pixelY);
        tooltipEl.style.left = `${left}px`;
        tooltipEl.style.top = `${top}px`;
    }

    const guidesPlugin = {
        id: 'glucoseGuides',
        afterDraw(instance) {
            if (instance !== chart || hoverIndex < 0) {
                return;
            }
            const meta = instance.getDatasetMeta(0);
            const element = meta.data[hoverIndex];
            const point = instance.data.datasets[0].data[hoverIndex];
            if (!element || !point || point.y == null) {
                return;
            }
            const { ctx, chartArea } = instance;
            const x = element.x;
            const y = element.y;
            ctx.save();
            ctx.strokeStyle = 'rgba(232, 244, 242, 0.45)';
            ctx.lineWidth = 1;
            ctx.setLineDash([4, 4]);
            ctx.beginPath();
            ctx.moveTo(x, chartArea.top);
            ctx.lineTo(x, chartArea.bottom);
            ctx.moveTo(chartArea.left, y);
            ctx.lineTo(chartArea.right, y);
            ctx.stroke();
            ctx.setLineDash([]);
            ctx.beginPath();
            ctx.fillStyle = colorForGlucose(point.y);
            ctx.strokeStyle = '#e8f4f2';
            ctx.lineWidth = 2;
            ctx.arc(x, y, 5, 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();
            ctx.restore();
        },
    };

    const brushPlugin = {
        id: 'historyBrush',
        afterDraw(instance) {
            if (instance !== overviewChart || viewStart == null || viewEnd == null) {
                return;
            }
            const { ctx, chartArea, scales } = instance;
            if (!chartArea || !scales.x) {
                return;
            }
            const left = Math.max(chartArea.left, scales.x.getPixelForValue(viewStart));
            const right = Math.min(chartArea.right, scales.x.getPixelForValue(viewEnd));
            ctx.save();
            ctx.fillStyle = 'rgba(7, 19, 26, 0.45)';
            ctx.fillRect(chartArea.left, chartArea.top, left - chartArea.left, chartArea.bottom - chartArea.top);
            ctx.fillRect(right, chartArea.top, chartArea.right - right, chartArea.bottom - chartArea.top);
            ctx.strokeStyle = 'rgba(61, 220, 151, 0.85)';
            ctx.lineWidth = 2;
            ctx.strokeRect(left, chartArea.top, Math.max(2, right - left), chartArea.bottom - chartArea.top);
            ctx.fillStyle = '#3ddc97';
            ctx.fillRect(left - 2, chartArea.top, 4, chartArea.bottom - chartArea.top);
            ctx.fillRect(right - 2, chartArea.top, 4, chartArea.bottom - chartArea.top);
            ctx.restore();
        },
    };

    function commonScaleOptions(spanMs, y) {
        return {
            x: {
                type: 'linear',
                min: viewStart,
                max: viewEnd,
                ticks: {
                    color: '#8aa4ad',
                    maxTicksLimit: 8,
                    autoSkip: true,
                    callback: (value) => formatTick(value, spanMs),
                    maxRotation: 0,
                },
                grid: { color: '#1a2d35' },
                border: { color: '#1a2d35' },
            },
            y: {
                min: y.min,
                max: y.max,
                ticks: { color: '#8aa4ad' },
                grid: { color: '#1a2d35' },
                border: { color: '#1a2d35' },
            },
        };
    }

    const rangeColorPlugin = {
        id: 'glucoseRangeColors',
        beforeDatasetsDraw(instance) {
            const ds = instance.data.datasets[0];
            if (!ds) {
                return;
            }
            const line = glucoseGradient(instance, 1);
            const fill = glucoseGradient(instance, FILL_ALPHA);
            ds.borderColor = line;
            ds.backgroundColor = fill;
            const meta = instance.getDatasetMeta(0);
            if (meta?.dataset?.options) {
                meta.dataset.options.borderColor = line;
                meta.dataset.options.backgroundColor = fill;
            }
        },
    };

    function pointRadiusAt(points, index, lastDot) {
        const point = points[index];
        if (!point || point.y == null) {
            return 0;
        }
        if (lastDot && index === points.length - 1) {
            return 3.5;
        }
        const prev = points[index - 1];
        const next = points[index + 1];
        const isolated = (!prev || prev.y == null) && (!next || next.y == null);
        return isolated ? 3 : 0;
    }

    function dataset(points, { lastDot = false } = {}) {
        return {
            data: points,
            borderColor: RANGE_GREEN,
            backgroundColor: hexToRgba(RANGE_GREEN, FILL_ALPHA),
            fill: true,
            tension: 0.15,
            spanGaps: false,
            pointRadius: (ctx) => pointRadiusAt(points, ctx.dataIndex, lastDot),
            pointBackgroundColor: (ctx) => colorForGlucose(ctx.parsed?.y ?? ctx.raw?.y),
            pointBorderColor: (ctx) => colorForGlucose(ctx.parsed?.y ?? ctx.raw?.y),
            pointHoverRadius: 0,
            pointHitRadius: 0,
            borderWidth: lastDot ? 2 : 1.25,
        };
    }

    function ensureCharts() {
        if (!chart) {
            chart = new Chart(canvas, {
                type: 'line',
                data: { datasets: [dataset([])] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    parsing: false,
                    normalized: true,
                    interaction: { mode: 'nearest', axis: 'x', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: false },
                    },
                    scales: commonScaleOptions(3 * 3600 * 1000, { min: 60, max: 200 }),
                },
                plugins: [rangeColorPlugin, guidesPlugin],
            });
        }
        if (!overviewChart) {
            overviewChart = new Chart(overviewCanvas, {
                type: 'line',
                data: { datasets: [dataset([])] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    parsing: false,
                    normalized: true,
                    events: [],
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: false },
                    },
                    scales: {
                        x: {
                            type: 'linear',
                            display: false,
                        },
                        y: {
                            display: false,
                        },
                    },
                    layout: { padding: { top: 6, bottom: 6 } },
                },
                plugins: [rangeColorPlugin, brushPlugin],
            });
        }
    }

    function renderChart(readings) {
        allReadings = readings;
        if (viewStart == null || viewEnd == null || !customView) {
            applyPreset(readings);
        } else {
            setWindow(viewStart, viewEnd, readings, false);
        }

        const spanMs = Math.max(1, viewEnd - viewStart);
        const windowReadings = visibleReadings(readings, viewStart, viewEnd);
        const mainPoints = withGaps(toPoints(windowReadings));
        const overviewPoints = overviewSeries(toPoints(readings), 800);
        const y = yLimits(mainPoints);
        const bounds = dataBounds(readings);

        ensureCharts();

        chart.data.datasets[0] = dataset(mainPoints, { lastDot: true });
        chart.options.scales = commonScaleOptions(spanMs, y);
        chart.update('none');

        overviewChart.data.datasets[0] = dataset(overviewPoints, { lastDot: true });
        overviewChart.options.scales.x.min = bounds.start;
        overviewChart.options.scales.x.max = Math.max(bounds.end, Date.now());
        overviewChart.options.scales.y.min = yLimits(overviewPoints).min;
        overviewChart.options.scales.y.max = yLimits(overviewPoints).max;
        overviewChart.update('none');

        renderStats(mainPoints);
        restoreHover();
    }

    function mainPoints() {
        return chart?.data?.datasets?.[0]?.data || [];
    }

    function handleMainMove(event) {
        if (!chart || panState) {
            return;
        }
        const area = chart.chartArea;
        const pos = eventPosition(event, chart);
        if (pos.x < area.left || pos.x > area.right || pos.y < area.top || pos.y > area.bottom) {
            hideTooltip();
            return;
        }
        const time = Math.round(chart.scales.x.getValueForPixel(pos.x) / MINUTE_MS) * MINUTE_MS;
        const points = mainPoints();
        const index = nearestIndex(points, time);
        if (index < 0) {
            hideTooltip();
            return;
        }
        const point = points[index];
        const element = chart.getDatasetMeta(0).data[index];
        if (!element || point.y == null) {
            hideTooltip();
            return;
        }
        hoverIndex = index;
        hoverTime = point.x;
        showTooltip(point, element.x, element.y);
        chart.draw();
    }

    function zoomAt(event, factor) {
        if (!chart || !allReadings.length) {
            return;
        }
        const pos = eventPosition(event, chart);
        const cursorTime = chart.scales.x.getValueForPixel(pos.x);
        const nextStart = cursorTime - (cursorTime - viewStart) * factor;
        const nextEnd = cursorTime + (viewEnd - cursorTime) * factor;
        setWindow(nextStart, nextEnd, allReadings);
        renderChart(allReadings);
    }

    canvas.addEventListener('mousemove', handleMainMove);
    canvas.addEventListener('mouseleave', () => {
        if (!panState) {
            hideTooltip();
        }
    });
    canvas.addEventListener('wheel', (event) => {
        event.preventDefault();
        zoomAt(event, event.deltaY > 0 ? 1.18 : 0.82);
    }, { passive: false });

    canvas.addEventListener('pointerdown', (event) => {
        if (!chart) {
            return;
        }
        panState = {
            pointerId: event.pointerId,
            x: event.clientX,
            start: viewStart,
            end: viewEnd,
        };
        canvas.setPointerCapture(event.pointerId);
        hideTooltip();
    });
    canvas.addEventListener('pointermove', (event) => {
        if (!panState || panState.pointerId !== event.pointerId || !chart) {
            return;
        }
        const span = panState.end - panState.start;
        const rect = canvas.getBoundingClientRect();
        const width = chart.chartArea.right - chart.chartArea.left;
        const pixelDelta = event.clientX - panState.x;
        const timeDelta = -(pixelDelta / width) * span;
        setWindow(panState.start + timeDelta, panState.end + timeDelta, allReadings);
        renderChart(allReadings);
    });
    const endPan = (event) => {
        if (panState && panState.pointerId === event.pointerId) {
            panState = null;
        }
    };
    canvas.addEventListener('pointerup', endPan);
    canvas.addEventListener('pointercancel', endPan);

    function overviewTime(event) {
        const pos = eventPosition(event, overviewChart);
        return overviewChart.scales.x.getValueForPixel(pos.x);
    }

    function brushMode(event) {
        const time = overviewTime(event);
        const pos = eventPosition(event, overviewChart);
        const startX = overviewChart.scales.x.getPixelForValue(viewStart);
        const endX = overviewChart.scales.x.getPixelForValue(viewEnd);
        if (Math.abs(pos.x - startX) <= 10) {
            return { mode: 'start', time };
        }
        if (Math.abs(pos.x - endX) <= 10) {
            return { mode: 'end', time };
        }
        if (time >= viewStart && time <= viewEnd) {
            return { mode: 'move', time, start: viewStart, end: viewEnd };
        }
        return { mode: 'jump', time };
    }

    overviewCanvas.addEventListener('pointerdown', (event) => {
        if (!overviewChart || !allReadings.length) {
            return;
        }
        const next = brushMode(event);
        if (next.mode === 'jump') {
            const span = viewEnd - viewStart;
            setWindow(next.time - span / 2, next.time + span / 2, allReadings);
            renderChart(allReadings);
            brushState = { mode: 'move', pointerId: event.pointerId, time: next.time, start: viewStart, end: viewEnd };
        } else {
            brushState = { ...next, pointerId: event.pointerId };
        }
        overviewCanvas.setPointerCapture(event.pointerId);
    });
    overviewCanvas.addEventListener('pointermove', (event) => {
        if (!brushState || brushState.pointerId !== event.pointerId) {
            return;
        }
        const time = overviewTime(event);
        if (brushState.mode === 'start') {
            setWindow(Math.min(time, viewEnd - MIN_WINDOW_MS), viewEnd, allReadings);
        } else if (brushState.mode === 'end') {
            setWindow(viewStart, Math.max(time, viewStart + MIN_WINDOW_MS), allReadings);
        } else if (brushState.mode === 'move') {
            const delta = time - brushState.time;
            setWindow(brushState.start + delta, brushState.end + delta, allReadings);
        }
        renderChart(allReadings);
    });
    const endBrush = (event) => {
        if (brushState && brushState.pointerId === event.pointerId) {
            brushState = null;
        }
    };
    overviewCanvas.addEventListener('pointerup', endBrush);
    overviewCanvas.addEventListener('pointercancel', endBrush);

    async function loadConfig() {
        const response = await fetchLive('/status.json.asc');
        if (!isOkResponse(response)) {
            return;
        }
        const status = await readSnapshot(response);
        if (status.browserPollSeconds) {
            pollSeconds = status.browserPollSeconds;
        }
    }

    async function refresh() {
        if (!vaultKeys) {
            return;
        }
        try {
            const [currentRes, statusRes] = await Promise.all([
                fetchLive('/current.json.asc'),
                fetchLive('/status.json.asc'),
            ]);
            if (!isOkResponse(currentRes)) {
                throw new Error('snapshot');
            }
            offline = false;
            const current = await readSnapshot(currentRes);
            const status = isOkResponse(statusRes) ? await readSnapshot(statusRes) : {};
            const readings = await loadHistory(await resolveHistoryDays(status), status);
            renderCurrent(current);
            renderChart(readings);
            if (statusRes.ok) {
                sourceEl.textContent = `encrypted snapshot · ${status.provider || 'unknown'}`;
            }
            localStorage.setItem('mylibre.current', JSON.stringify(current));
            localStorage.setItem('mylibre.history', JSON.stringify(readings));
        } catch (error) {
            console.error(error);
            offline = true;
            const cachedCurrent = lastKnown || JSON.parse(localStorage.getItem('mylibre.current') || 'null');
            const cachedHistory = JSON.parse(localStorage.getItem('mylibre.history') || '[]');
            if (cachedCurrent) {
                renderCurrent(cachedCurrent);
            } else {
                renderCurrent({
                    glucoseMgDl: null,
                    staleLevel: 'missing',
                    ageSeconds: null,
                });
            }
            if (cachedHistory.length) {
                renderChart(cachedHistory);
            }
        }
    }

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            const value = button.dataset.hours;
            rangeHours = value === 'all' ? 'all' : Number(value);
            buttons.forEach((item) => item.classList.toggle('active', item === button));
            applyPreset(allReadings);
            if (allReadings.length) {
                renderChart(allReadings);
            } else {
                refresh();
            }
        });
    });

    function showUnlockError(message) {
        unlockError.textContent = message;
        unlockError.classList.toggle('hidden', !message);
    }

    function startPolling() {
        if (refreshTimer) {
            clearInterval(refreshTimer);
        }
        loadConfig()
            .catch(() => {})
            .finally(() => {
                refresh();
                refreshTimer = setInterval(refresh, pollSeconds * 1000);
            });
    }

    async function enterUnlocked(keys, persistKeys) {
        vaultKeys = keys;
        if (persistKeys) {
            await PgpVault.saveKeys(keys.publicArmored, keys.privateArmored);
        }
        document.body.classList.remove('locked');
        document.body.classList.add('unlocked');
        passphraseEl.value = '';
        showUnlockError('');
        startPolling();
    }

    function lock(forgetSaved) {
        vaultKeys = null;
        if (refreshTimer) {
            clearInterval(refreshTimer);
            refreshTimer = null;
        }
        document.body.classList.add('locked');
        document.body.classList.remove('unlocked');
        passphraseEl.value = '';
        if (forgetSaved) {
            PgpVault.clearKeys().catch(() => {});
            publicKeyEl.value = '';
            privateKeyEl.value = '';
            keyFields.classList.remove('hidden');
            forgetKeysBtn.classList.add('hidden');
        }
    }

    [publicKeyFile, privateKeyFile].forEach((input, index) => {
        input.addEventListener('change', async () => {
            const file = input.files && input.files[0];
            if (!file) {
                return;
            }
            const text = await readFileAsText(file);
            (index === 0 ? publicKeyEl : privateKeyEl).value = text;
        });
    });

    unlockForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        unlockBtn.disabled = true;
        showUnlockError('');
        try {
            const saved = await PgpVault.loadKeys();
            const publicArmored = publicKeyEl.value.trim() || saved?.publicArmored || '';
            const privateArmored = privateKeyEl.value.trim() || saved?.privateArmored || '';
            const keys = await PgpVault.unlock(publicArmored, privateArmored, passphraseEl.value);
            await enterUnlocked(keys, true);
        } catch (error) {
            showUnlockError(error.message || String(error));
        } finally {
            unlockBtn.disabled = false;
        }
    });

    forgetKeysBtn.addEventListener('click', () => lock(true));
    lockBtn.addEventListener('click', () => lock(false));

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/service-worker.js').catch(() => {});
    }

    PgpVault.loadKeys().then((saved) => {
        if (!saved) {
            return;
        }
        publicKeyEl.value = saved.publicArmored;
        privateKeyEl.value = saved.privateArmored;
        keyFields.classList.add('hidden');
        forgetKeysBtn.classList.remove('hidden');
    }).catch(() => {});
})();
