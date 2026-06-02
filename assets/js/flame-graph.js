/**
 * WP Flame — SVG Flame Graph Renderer
 *
 * Reads window.wpFlameTrace (set by wp_add_inline_script) and renders
 * an interactive SVG flame graph.
 */
(function () {
    'use strict';

    var ROW_HEIGHT = 24;
    var MIN_WIDTH_PX = 2;
    var MAX_RENDER_SPANS = 5000;
    var MAX_RENDER_DEPTH = 200;
    var COLORS = {
        core: '#6c7086',
        plugin: '#7c3aed',
        theme: '#22c55e',
        db: '#ef4444',
        http: '#f59e0b',
        php: '#8b5cf6'
    };

    var container = document.getElementById('wp-flame-graph');
    var breadcrumbsEl = document.getElementById('wp-flame-breadcrumbs');
    var tooltipEl = document.getElementById('wp-flame-tooltip');

    if (!container || !window.wpFlameTrace) {
        return;
    }

    var trace = window.wpFlameTrace;
    var rawSpans = Array.isArray(trace.spans) ? trace.spans : [];
    var spans = [];
    for (var rawIndex = 0; rawIndex < rawSpans.length; rawIndex++) {
        if (spans.length >= MAX_RENDER_SPANS) {
            break;
        }

        var normalizedSpan = normalizeSpan(rawSpans[rawIndex], rawIndex);
        if (normalizedSpan) {
            spans.push(normalizedSpan);
        }
    }

    // Build tree structure from flat parent_id references
    var spanMap = Object.create(null);
    var roots = [];
    var i, span;

    for (i = 0; i < spans.length; i++) {
        span = spans[i];
        if (spanMap[span.id]) {
            span.id = span.id + '-' + i;
        }
        spanMap[span.id] = span;
    }

    for (i = 0; i < spans.length; i++) {
        span = spans[i];
        if (span.parent_id && spanMap[span.parent_id] && !wouldCreateCycle(span, spanMap[span.parent_id])) {
            spanMap[span.parent_id].children.push(span);
        } else {
            roots.push(span);
        }
    }

    // Compute max depth for SVG height
    function getDepth(node, depth) {
        depth = depth || 0;
        if (depth >= MAX_RENDER_DEPTH) {
            return 1;
        }

        var max = 0;
        for (var j = 0; j < node.children.length; j++) {
            var d = getDepth(node.children[j], depth + 1);
            if (d > max) max = d;
        }
        return max + 1;
    }

    var maxDepth = 0;
    for (i = 0; i < roots.length; i++) {
        var d = getDepth(roots[i], 0);
        if (d > maxDepth) maxDepth = d;
    }

    // View state for zoom
    var viewStart = 0;
    var viewEnd = toNumber(trace.total_ms, 0);
    var zoomStack = [];

    function safeText(str) {
        return String(str == null ? '' : str)
            .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '');
    }

    function normalizeSpan(raw, index) {
        if (!raw || typeof raw !== 'object') {
            return null;
        }

        var id = safeText(raw.id);
        if (!id) {
            id = 'span-' + index;
        }

        return {
            id: id,
            parent_id: safeText(raw.parent_id),
            name: safeText(raw.name) || 'unknown',
            type: safeText(raw.type) || 'php',
            source: safeText(raw.source) || 'unknown',
            start_ms: Math.max(0, toNumber(raw.start_ms, 0)),
            duration_ms: Math.max(0, toNumber(raw.duration_ms, 0)),
            children: []
        };
    }

    function wouldCreateCycle(span, parent) {
        var targetId = span.id;
        var seen = Object.create(null);
        var current = parent;

        while (current) {
            if (current.id === targetId) {
                return true;
            }

            if (seen[current.id]) {
                return true;
            }
            seen[current.id] = true;

            current = current.parent_id ? spanMap[current.parent_id] : null;
        }

        return false;
    }

    function toNumber(value, fallback) {
        var number = Number(value);
        return isFinite(number) ? number : fallback;
    }

    // Escape text for safe use in SVG attributes
    function escapeAttr(str) {
        return safeText(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    var AXIS_HEIGHT = 20; // px reserved at top for time axis

    function niceTickInterval(range, targetTicks) {
        if (!isFinite(range) || range <= 0) {
            return 1;
        }
        var roughInterval = range / targetTicks;
        var magnitude = Math.pow(10, Math.floor(Math.log(roughInterval) / Math.LN10));
        var candidates = [1, 2, 5, 10];
        var interval = magnitude;
        for (var k = 0; k < candidates.length; k++) {
            interval = candidates[k] * magnitude;
            if (interval >= roughInterval) break;
        }
        return interval;
    }

    function render() {
        // Use a zero-height probe div to measure the actual content width
        // (avoids clientWidth including padding and scrollbar width issues)
        var probe = document.createElement('div');
        probe.style.height = '0';
        container.appendChild(probe);
        var width = probe.offsetWidth || 800;
        container.removeChild(probe);
        var FULL_REQUEST_ROW = 1; // extra row for "Full request" bar
        var height = (maxDepth + FULL_REQUEST_ROW) * ROW_HEIGHT + AXIS_HEIGHT + 10;
        var timeRange = viewEnd - viewStart;
        if (!isFinite(timeRange) || timeRange <= 0) {
            timeRange = 1;
        }

        var svgParts = [];
        svgParts.push('<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '" class="wp-flame-svg">');

        // Time axis: tick marks and labels across the top AXIS_HEIGHT px
        var tickInterval = niceTickInterval(timeRange, 6);
        var firstTick = Math.ceil(viewStart / tickInterval) * tickInterval;
        for (var t = firstTick; t <= viewEnd; t += tickInterval) {
            var tx = ((t - viewStart) / timeRange) * width;
            // Vertical guide line (full graph height, light grey)
            svgParts.push('<line x1="' + tx.toFixed(1) + '" y1="' + AXIS_HEIGHT + '" x2="' + tx.toFixed(1) + '" y2="' + height + '" stroke="#ccc" stroke-width="0.5" opacity="0.5" />');
            // Tick label
            var tickLabel = Math.round(t) + 'ms';
            var anchor = tx < 30 ? 'start' : (tx > width - 30 ? 'end' : 'middle');
            svgParts.push('<text x="' + tx.toFixed(1) + '" y="' + (AXIS_HEIGHT - 5) + '" fill="#888" font-size="10" font-family="monospace" text-anchor="' + anchor + '">' + tickLabel + '</text>');
        }

        // "Full request" bar spanning the entire visible range
        var frY = AXIS_HEIGHT;
        svgParts.push('<rect x="0" y="' + frY + '" width="' + width + '" height="' + (ROW_HEIGHT - 2) + '" fill="#e0e0e0" rx="2" />');
        svgParts.push('<text x="4" y="' + (frY + ROW_HEIGHT - 7) + '" fill="#444" font-size="11" font-family="monospace">Full request</text>');

        function renderSpan(s, depth) {
            if (depth > MAX_RENDER_DEPTH) {
                return;
            }

            var startMs = toNumber(s.start_ms, 0);
            var durationMs = Math.max(0, toNumber(s.duration_ms, 0));
            var x = ((startMs - viewStart) / timeRange) * width;
            var w = (durationMs / timeRange) * width;

            if (w < MIN_WIDTH_PX) w = MIN_WIDTH_PX;

            // Skip spans entirely outside view
            if (x + w < 0 || x > width) return;

            var y = (depth + FULL_REQUEST_ROW) * ROW_HEIGHT + AXIS_HEIGHT;
            var color = COLORS[s.type] || COLORS.php;
            var totalMs = toNumber(trace.total_ms, 0);
            var pct = totalMs > 0 ? ((durationMs / totalMs) * 100).toFixed(1) : '0.0';

            svgParts.push('<g class="wp-flame-span" data-id="' + escapeAttr(s.id) + '" data-name="' + escapeAttr(s.name) + '" data-duration="' + durationMs.toFixed(2) + '" data-source="' + escapeAttr(s.source) + '" data-pct="' + pct + '">');
            svgParts.push('<rect x="' + x.toFixed(1) + '" y="' + y + '" width="' + w.toFixed(1) + '" height="' + (ROW_HEIGHT - 2) + '" fill="' + color + '" rx="2" />');

            // Text label (only if wide enough)
            if (w > 40) {
                var label = safeText(s.name);
                var maxChars = Math.floor(w / 7);
                if (label.length > maxChars) {
                    label = label.substring(0, maxChars - 1) + '\u2026';
                }
                svgParts.push('<text x="' + (x + 4).toFixed(1) + '" y="' + (y + ROW_HEIGHT - 7) + '" fill="#fff" font-size="11" font-family="monospace">' + escapeAttr(label) + '</text>');
            }

            svgParts.push('</g>');

            // Render children
            for (var j = 0; j < s.children.length; j++) {
                renderSpan(s.children[j], depth + 1);
            }
        }

        for (i = 0; i < roots.length; i++) {
            renderSpan(roots[i], 0);
        }

        svgParts.push('</svg>');

        // Build SVG via DOMParser to avoid raw innerHTML XSS risks
        var parser = new DOMParser();
        var doc = parser.parseFromString(svgParts.join(''), 'image/svg+xml');
        var svgEl = doc.documentElement;

        // Clear container safely and append parsed SVG
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }
        container.appendChild(document.importNode(svgEl, true));

        // Attach event listeners to rendered spans
        var groups = container.querySelectorAll('.wp-flame-span');
        for (var g = 0; g < groups.length; g++) {
            groups[g].addEventListener('mouseenter', showTooltip);
            groups[g].addEventListener('mouseleave', hideTooltip);
            groups[g].addEventListener('click', handleClick);
        }

        renderBreadcrumbs();
    }

    function showTooltip(e) {
        if (!tooltipEl) return;

        var el = e.currentTarget;
        var name = el.getAttribute('data-name');
        var duration = el.getAttribute('data-duration');
        var source = el.getAttribute('data-source');
        var pct = el.getAttribute('data-pct');

        // Build tooltip content safely using DOM methods
        while (tooltipEl.firstChild) {
            tooltipEl.removeChild(tooltipEl.firstChild);
        }

        var strong = document.createElement('strong');
        strong.textContent = name;
        tooltipEl.appendChild(strong);
        tooltipEl.appendChild(document.createElement('br'));
        tooltipEl.appendChild(document.createTextNode(duration + ' ms (' + pct + '%)'));
        tooltipEl.appendChild(document.createElement('br'));

        var sourceSpan = document.createElement('span');
        sourceSpan.style.opacity = '0.7';
        sourceSpan.textContent = source;
        tooltipEl.appendChild(sourceSpan);

        tooltipEl.style.display = 'block';
        document.addEventListener('mousemove', moveTooltip);
    }

    function moveTooltip(e) {
        if (!tooltipEl) return;

        var tipWidth = tooltipEl.offsetWidth;
        var tipHeight = tooltipEl.offsetHeight;
        var viewWidth = window.innerWidth;
        var viewHeight = window.innerHeight;

        // Position right of cursor by default, flip left if it would overflow
        var left = e.clientX + 12;
        if (left + tipWidth > viewWidth) {
            left = e.clientX - tipWidth - 12;
        }

        // Position below cursor by default, flip above if it would overflow
        var top = e.clientY + 16;
        if (top + tipHeight > viewHeight) {
            top = e.clientY - tipHeight - 8;
        }

        tooltipEl.style.left = left + 'px';
        tooltipEl.style.top = top + 'px';
    }

    function hideTooltip() {
        if (!tooltipEl) return;

        tooltipEl.style.display = 'none';
        document.removeEventListener('mousemove', moveTooltip);
    }

    function handleClick(e) {
        var el = e.currentTarget;
        var spanId = el.getAttribute('data-id');
        var s = spanMap[spanId];
        if (!s || s.children.length === 0) return;

        var startMs = toNumber(s.start_ms, 0);
        var durationMs = Math.max(0, toNumber(s.duration_ms, 0));
        zoomStack.push({ start: viewStart, end: viewEnd });
        viewStart = startMs;
        viewEnd = startMs + durationMs;
        render();
    }

    function zoomOut() {
        if (zoomStack.length === 0) return;
        var prev = zoomStack.pop();
        viewStart = prev.start;
        viewEnd = prev.end;
        render();
    }

    function resetZoom() {
        zoomStack = [];
        viewStart = 0;
        viewEnd = toNumber(trace.total_ms, 0);
        render();
    }

    function renderBreadcrumbs() {
        if (!breadcrumbsEl) return;

        // Build breadcrumbs safely using DOM methods
        while (breadcrumbsEl.firstChild) {
            breadcrumbsEl.removeChild(breadcrumbsEl.firstChild);
        }

        var resetBtn = document.createElement('button');
        resetBtn.className = 'button button-small';
        resetBtn.textContent = 'Full View';
        resetBtn.addEventListener('click', resetZoom);
        breadcrumbsEl.appendChild(resetBtn);

        if (zoomStack.length > 0) {
            var backBtn = document.createElement('button');
            backBtn.className = 'button button-small';
            backBtn.textContent = '\u2190 Back';
            backBtn.style.marginLeft = '4px';
            backBtn.addEventListener('click', zoomOut);
            breadcrumbsEl.appendChild(backBtn);

            var info = document.createElement('span');
            info.className = 'wp-flame-zoom-info';
            info.textContent = 'Viewing ' + viewStart.toFixed(1) + ' ms \u2013 ' + viewEnd.toFixed(1) + ' ms';
            breadcrumbsEl.appendChild(info);
        }
    }

    // Initial render
    render();

    // Re-render on window resize (debounced)
    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(render, 150);
    });
})();
