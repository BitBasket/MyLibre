(() => {
    const FRESH_SECONDS = 180;
    const STALE_SECONDS = 600;

    const valueEl = document.getElementById('value');
    const arrowEl = document.getElementById('arrow');
    const trendEl = document.getElementById('trend');
    const ageEl = document.getElementById('age');
    const bannerEl = document.getElementById('banner');
    const sourceEl = document.getElementById('source-line');
    const buttons = [...document.querySelectorAll('.ranges button')];
    const canvas = document.getElementById('chart');

    let hours = 3;
    let pollSeconds = 5;
    let chart;
    let lastKnown = null;
    let offline = false;

    function fetchLive(path) {
        return fetch(`${path}?t=${Date.now()}`, { cache: 'no-store' });
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

    function inRange(readings, rangeHours) {
        const cutoff = Date.now() - rangeHours * 3600 * 1000;
        return (readings || []).filter((point) => Date.parse(point.timestamp) >= cutoff);
    }

    function utcYmd(date) {
        const year = date.getUTCFullYear();
        const month = String(date.getUTCMonth() + 1).padStart(2, '0');
        const day = String(date.getUTCDate()).padStart(2, '0');
        return `${year}${month}${day}`;
    }

    function historyPaths(rangeHours) {
        const end = new Date();
        const start = new Date(end.getTime() - rangeHours * 3600 * 1000);
        const paths = [];
        let cursor = Date.UTC(start.getUTCFullYear(), start.getUTCMonth(), start.getUTCDate());
        const last = Date.UTC(end.getUTCFullYear(), end.getUTCMonth(), end.getUTCDate());
        while (cursor <= last) {
            paths.push(`/history-${utcYmd(new Date(cursor))}.json`);
            cursor += 24 * 60 * 60 * 1000;
        }
        return paths;
    }

    async function loadHistory(rangeHours) {
        const responses = await Promise.all(historyPaths(rangeHours).map((path) => fetchLive(path)));
        const readings = [];
        for (const response of responses) {
            if (!response.ok) {
                continue;
            }
            const payload = await response.json();
            readings.push(...(payload.readings || []));
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

    function renderChart(points) {
        const labels = points.map((point) => {
            const date = new Date(point.timestamp);
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        });
        const data = points.map((point) => point.glucoseMgDl);

        if (!chart) {
            chart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        data,
                        borderColor: '#3ddc97',
                        backgroundColor: 'rgba(61, 220, 151, 0.12)',
                        fill: true,
                        tension: 0.25,
                        pointRadius: 0,
                        borderWidth: 2,
                    }],
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: {
                            ticks: { color: '#8aa4ad', maxTicksLimit: 6 },
                            grid: { color: '#1d3a46' },
                        },
                        y: {
                            suggestedMin: 70,
                            suggestedMax: 250,
                            ticks: { color: '#8aa4ad' },
                            grid: { color: '#1d3a46' },
                        },
                    },
                },
            });
            return;
        }

        chart.data.labels = labels;
        chart.data.datasets[0].data = data;
        chart.update('none');
    }

    async function loadConfig() {
        const response = await fetchLive('/status.json');
        if (!response.ok) {
            return;
        }
        const status = await response.json();
        if (status.browserPollSeconds) {
            pollSeconds = status.browserPollSeconds;
        }
    }

    async function refresh() {
        try {
            const [currentRes, statusRes, historyReadings] = await Promise.all([
                fetchLive('/current.json'),
                fetchLive('/status.json'),
                loadHistory(Math.max(hours, 24)),
            ]);
            if (!currentRes.ok) {
                throw new Error('snapshot');
            }
            offline = false;
            const current = await currentRes.json();
            const readings = inRange(historyReadings, hours);
            renderCurrent(current);
            renderChart(readings);
            if (statusRes.ok) {
                const status = await statusRes.json();
                sourceEl.textContent = `local snapshot · ${status.provider || 'unknown'}`;
            }
            localStorage.setItem('mylibre.current', JSON.stringify(current));
            localStorage.setItem('mylibre.history', JSON.stringify(historyReadings));
        } catch (error) {
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
                renderChart(inRange(cachedHistory, hours));
            }
        }
    }

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            hours = Number(button.dataset.hours);
            buttons.forEach((item) => item.classList.toggle('active', item === button));
            refresh();
        });
    });

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/service-worker.js').catch(() => {});
    }

    loadConfig()
        .catch(() => {})
        .finally(() => {
            refresh();
            setInterval(refresh, pollSeconds * 1000);
        });
})();
