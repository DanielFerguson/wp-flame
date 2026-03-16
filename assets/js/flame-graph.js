/**
 * WP Flame — SVG Flame Graph Renderer
 *
 * Reads window.wpFlameTrace (set by wp_localize_script) and renders
 * an interactive SVG flame graph.
 */
(function () {
    'use strict';

    var ROW_HEIGHT = 24;
    var MIN_WIDTH_PX = 2;
    var COLORS = {
        core: '#9e9e9e',
        plugin: '#4285f4',
        theme: '#34a853',
        db: '#f4a742',
        http: '#ea4335',
        php: '#9c27b0'
    };

    var container = document.getElementById('wp-flame-graph');
    var breadcrumbsEl = document.getElementById('wp-flame-breadcrumbs');
    var tooltipEl = document.getElementById('wp-flame-tooltip');

    if (!container || !window.wpFlameTrace) {
        return;
    }

    var trace = window.wpFlameTrace;
    var spans = trace.spans || [];

    // Build tree structure from flat parent_id references
    var spanMap = {};
    var roots = [];
    var i, span;

    for (i = 0; i < spans.length; i++) {
        span = spans[i];
        span.children = [];
        spanMap[span.id] = span;
    }

    for (i = 0; i < spans.length; i++) {
        span = spans[i];
        if (span.parent_id && spanMap[span.parent_id]) {
            spanMap[span.parent_id].children.push(span);
        } else {
            roots.push(span);
        }
    }

    // Compute max depth for SVG height
    function getDepth(node) {
        var max = 0;
        for (var j = 0; j < node.children.length; j++) {
            var d = getDepth(node.children[j]);
            if (d > max) max = d;
        }
        return max + 1;
    }

    var maxDepth = 0;
    for (i = 0; i < roots.length; i++) {
        var d = getDepth(roots[i]);
        if (d > maxDepth) maxDepth = d;
    }

    // View state for zoom
    var viewStart = 0;
    var viewEnd = trace.total_ms;
    var zoomStack = [];

    // Escape text for safe use in SVG attributes
    function escapeAttr(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function render() {
        // clientWidth includes padding, so subtract it to fit within the content area
        var style = window.getComputedStyle(container);
        var padding = (parseFloat(style.paddingLeft) || 0) + (parseFloat(style.paddingRight) || 0);
        var width = (container.clientWidth - padding) || 800;
        var height = maxDepth * ROW_HEIGHT + 10;
        var timeRange = viewEnd - viewStart;

        var svgParts = [];
        svgParts.push('<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '" class="wp-flame-svg">');

        function renderSpan(s, depth) {
            var x = ((s.start_ms - viewStart) / timeRange) * width;
            var w = (s.duration_ms / timeRange) * width;

            if (w < MIN_WIDTH_PX) w = MIN_WIDTH_PX;

            // Skip spans entirely outside view
            if (x + w < 0 || x > width) return;

            var y = depth * ROW_HEIGHT;
            var color = COLORS[s.type] || COLORS.php;
            var pct = trace.total_ms > 0 ? ((s.duration_ms / trace.total_ms) * 100).toFixed(1) : '0.0';

            svgParts.push('<g class="wp-flame-span" data-id="' + escapeAttr(s.id) + '" data-name="' + escapeAttr(s.name) + '" data-duration="' + s.duration_ms.toFixed(2) + '" data-source="' + escapeAttr(s.source) + '" data-pct="' + pct + '">');
            svgParts.push('<rect x="' + x.toFixed(1) + '" y="' + y + '" width="' + w.toFixed(1) + '" height="' + (ROW_HEIGHT - 2) + '" fill="' + color + '" rx="2" />');

            // Text label (only if wide enough)
            if (w > 40) {
                var label = s.name;
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
        tooltipEl.style.left = (e.pageX + 12) + 'px';
        tooltipEl.style.top = (e.pageY - 10) + 'px';
    }

    function hideTooltip() {
        tooltipEl.style.display = 'none';
        document.removeEventListener('mousemove', moveTooltip);
    }

    function handleClick(e) {
        var el = e.currentTarget;
        var spanId = el.getAttribute('data-id');
        var s = spanMap[spanId];
        if (!s || s.children.length === 0) return;

        zoomStack.push({ start: viewStart, end: viewEnd });
        viewStart = s.start_ms;
        viewEnd = s.start_ms + s.duration_ms;
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
        viewEnd = trace.total_ms;
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
