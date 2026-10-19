/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 *
 * Charts for Reports → Marketing → AI Agent Activity.
 *
 * Uses the Chart.js that ships with Magento (RequireJS alias "chartJs":
 * 3.9 in 2.4.6, 4.x in 2.4.7+); the options below work with both. No CDN,
 * no inline script: data comes from data-mage-init.
 */
define(['chartJs'], function (Chart) {
    'use strict';

    // Categorical order is fixed so a color always means the same channel.
    var CHANNEL_COLORS = {
            ucp: '#2a78d6',
            mcp: '#eb6834',
            webmcp: '#1baf7a',
            crawler: '#eda100'
        },
        FALLBACK = ['#e87ba4', '#008300', '#6250d6', '#e34948'],
        SINGLE = '#2a78d6',
        STATUS_COLORS = {'2xx': '#0ca30c', '3xx': '#898781', '4xx': '#ec835a', '5xx': '#d03b3b'},
        FUNNEL = ['#86b6ef', '#3987e5', '#1c5cab'],
        // MCP funnel: one hue (the MCP channel color), lighter to darker by step.
        MCP_FUNNEL = ['#f8c9b4', '#f4a885', '#ef8757', '#eb6834', '#c4501f', '#8f3914'],
        GRID = '#e1e0d9',
        INK = '#52514e',
        MUTED = '#898781',
        SURFACE = '#ffffff';

    function compact(value) {
        var abs = Math.abs(value);

        if (abs >= 1000000) {
            return (value / 1000000).toFixed(1) + 'M';
        }

        if (abs >= 10000) {
            return (value / 1000).toFixed(1) + 'K';
        }

        return String(value);
    }

    function baseOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: {
                legend: {display: false},
                tooltip: {
                    backgroundColor: '#0b0b0b',
                    padding: 10,
                    bodyFont: {weight: '600'},
                    displayColors: true,
                    boxWidth: 10,
                    boxHeight: 2
                }
            }
        };
    }

    function valueAxis() {
        return {
            beginAtZero: true,
            grid: {color: GRID, drawBorder: false},
            border: {display: false},
            ticks: {color: MUTED, precision: 0, callback: function (v) { return compact(v); }}
        };
    }

    function categoryAxis() {
        return {
            grid: {display: false, drawBorder: false},
            border: {color: GRID},
            ticks: {color: INK, autoSkip: true, maxRotation: 0}
        };
    }

    function empty(canvas, text) {
        var note = document.createElement('p');

        note.className = 'aa-empty';
        note.textContent = text;
        canvas.parentNode.replaceChild(note, canvas);
    }

    function hasValues(rows) {
        return rows && rows.some(function (r) { return r.value > 0; });
    }

    function horizontalBar(canvas, rows, colors, label) {
        if (!hasValues(rows)) {
            empty(canvas, '—');

            return null;
        }

        var options = baseOptions();

        options.indexAxis = 'y';
        options.scales = {x: valueAxis(), y: categoryAxis()};

        return new Chart(canvas, {
            type: 'bar',
            data: {
                labels: rows.map(function (r) { return r.label; }),
                datasets: [{
                    label: label,
                    data: rows.map(function (r) { return r.value; }),
                    backgroundColor: colors,
                    borderRadius: 4,
                    borderSkipped: 'start',
                    maxBarThickness: 18
                }]
            },
            options: options
        });
    }

    function daily(canvas, config, legend) {
        if (!config.series.length) {
            empty(canvas, '—');

            return null;
        }

        var options = baseOptions(),
            labels = config.days.map(function (d) { return d.slice(5); });

        options.interaction = {mode: 'index', intersect: false};
        options.scales = {
            x: Object.assign(categoryAxis(), {stacked: true}),
            y: Object.assign(valueAxis(), {stacked: true})
        };
        options.plugins.tooltip.callbacks = {
            title: function (items) { return items.length ? config.days[items[0].dataIndex] : ''; }
        };

        // Legend: always shown for two or more series; key mirrors the mark (rect).
        if (legend && config.series.length > 1) {
            config.series.forEach(function (s, i) {
                var item = document.createElement('span'),
                    key = document.createElement('i');

                item.className = 'aa-legend__item';
                key.className = 'aa-legend__key';
                key.style.background = CHANNEL_COLORS[s.key] || FALLBACK[i % FALLBACK.length];
                item.appendChild(key);
                item.appendChild(document.createTextNode(s.label));
                legend.appendChild(item);
            });
        }

        return new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: config.series.map(function (s, i) {
                    return {
                        label: s.label,
                        data: s.values,
                        backgroundColor: CHANNEL_COLORS[s.key] || FALLBACK[i % FALLBACK.length],
                        borderColor: SURFACE,
                        borderWidth: {top: 2},
                        borderSkipped: 'bottom',
                        maxBarThickness: 28
                    };
                })
            },
            options: options
        });
    }

    return function (config, element) {
        var find = function (name) { return element.querySelector('[data-chart="' + name + '"]'); },
            canvas;

        if (!Chart) {
            return;
        }

        Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", sans-serif';
        Chart.defaults.color = INK;

        if ((canvas = find('daily'))) {
            daily(canvas, config, element.querySelector('[data-legend="daily"]'));
        }

        if ((canvas = find('categories'))) {
            horizontalBar(canvas, config.categories, SINGLE, config.labels.calls);
        }

        if ((canvas = find('agents'))) {
            horizontalBar(canvas, config.agents, SINGLE, config.labels.calls);
        }

        if ((canvas = find('status'))) {
            horizontalBar(
                canvas,
                config.status,
                config.status.map(function (r) { return STATUS_COLORS[r.key] || MUTED; }),
                config.labels.calls
            );
        }

        if ((canvas = find('funnel')) && config.funnel) {
            horizontalBar(canvas, config.funnel, FUNNEL, config.labels.calls);
        }

        if ((canvas = find('mcpFunnel')) && config.mcpFunnel) {
            horizontalBar(canvas, config.mcpFunnel, MCP_FUNNEL, config.labels.calls);
        }
    };
});
