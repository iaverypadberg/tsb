import L from 'leaflet';
import { dangerColor, drawFlagParts, SELECTED_COLOR, tileLayer } from './danger.js';

/**
 * How long to wait after the map stops moving before asking for the roads
 * underneath it. Panning fires a lot of events, and each one is a query.
 */
const RELOAD_DELAY_MS = 300;

/**
 * Leaflet draws every path into one pane in the order it was added, so
 * reloading the roads after a pan would bury the selected road and its
 * handles under a fresh set of invisible click targets. Giving each its own
 * pane fixes the stacking whatever order things are drawn in.
 *
 * The numbers sit above Leaflet's overlay pane (400) and, for the handles,
 * above its marker and tooltip panes (600 and 650) as well.
 */
const SELECTION_PANE = 'flagSelection';
const HANDLE_PANE = 'flagHandles';
const PANE_Z_INDEX = { [SELECTION_PANE]: 450, [HANDLE_PANE]: 680 };

/**
 * How much more than the visible map to fetch roads for. Half a screen in
 * every direction: panning and zooming in then need no new roads at all,
 * and zooming out one step is already covered.
 */
const VIEW_PADDING = 0.5;

/**
 * The roads go on a canvas, the selected road and its handles stay as SVG.
 *
 * A view holds hundreds of roads, and as SVG that is hundreds of elements
 * the browser has to re-project and rewrite every time the map settles,
 * which is what makes zooming feel like it is catching up. On a canvas they
 * are one element that Leaflet scales during the zoom and repaints once.
 * The selected road stays SVG: there is only ever one of it, and the drag
 * handles need real elements to take pointer events.
 */
const ROAD_PADDING = 0.25;

/**
 * How far either side of a road a click still counts, in pixels. A road is
 * a few pixels wide and a finger is not, and on a canvas this replaces the
 * invisible fat line that used to be drawn over each road to catch them.
 */
const ROAD_CLICK_TOLERANCE = 12;

/**
 * A small box in the corner of the map, used for "zoom in" and for saying
 * what to click next.
 */
function hintControl(map, text) {
    const control = L.control({ position: 'topright' });

    control.onAdd = () => {
        const box = L.DomUtil.create('div');

        box.style.cssText =
            'background:#fff;padding:6px 10px;border-radius:6px;' +
            'box-shadow:0 1px 4px rgba(0,0,0,.3);font:12px/1.4 system-ui,sans-serif;color:#1f2937';
        box.textContent = text;

        return box;
    };

    control.addTo(map);

    return control;
}

/**
 * Lengths along a line, in degrees.
 *
 * Degrees rather than metres on purpose: PostGIS locates a point along a
 * 4326 line in planar degrees too, so the fraction the browser works out
 * while someone drags an end is the same fraction the server will cut at.
 */
function measureLine(line) {
    const cumulative = [0];

    for (let index = 1; index < line.length; index++) {
        const [previousLat, previousLng] = line[index - 1];
        const [lat, lng] = line[index];

        cumulative.push(cumulative[index - 1] + Math.hypot(lat - previousLat, lng - previousLng));
    }

    return cumulative;
}

/**
 * How far along a line the point nearest to a dragged handle sits, as a
 * fraction of the line's length.
 */
function fractionOfPoint(line, cumulative, latlng) {
    const total = cumulative[cumulative.length - 1];

    if (!total) {
        return 0;
    }

    let best = { distance: Infinity, fraction: 0 };

    for (let index = 1; index < line.length; index++) {
        const [fromLat, fromLng] = line[index - 1];
        const [toLat, toLng] = line[index];

        const runLat = toLat - fromLat;
        const runLng = toLng - fromLng;
        const squared = runLat * runLat + runLng * runLng;

        // Where the point falls along this piece, clamped to its ends.
        const along = squared === 0
            ? 0
            : Math.max(0, Math.min(1, ((latlng.lat - fromLat) * runLat + (latlng.lng - fromLng) * runLng) / squared));

        const distance = Math.hypot(
            latlng.lat - (fromLat + along * runLat),
            latlng.lng - (fromLng + along * runLng),
        );

        if (distance < best.distance) {
            best = {
                distance,
                fraction: (cumulative[index - 1] + along * Math.sqrt(squared)) / total,
            };
        }
    }

    return best.fraction;
}

/**
 * The point a given fraction of the way along a line.
 */
function pointAtFraction(line, cumulative, fraction) {
    const total = cumulative[cumulative.length - 1];
    const target = Math.max(0, Math.min(1, fraction)) * total;

    for (let index = 1; index < line.length; index++) {
        if (cumulative[index] < target) {
            continue;
        }

        const span = cumulative[index] - cumulative[index - 1];
        const along = span === 0 ? 0 : (target - cumulative[index - 1]) / span;

        return [
            line[index - 1][0] + along * (line[index][0] - line[index - 1][0]),
            line[index - 1][1] + along * (line[index][1] - line[index - 1][1]),
        ];
    }

    return line[line.length - 1];
}

/**
 * The part of a line between two fractions, for drawing a trim while it is
 * still being dragged.
 */
function sliceLine(line, cumulative, from, to) {
    const total = cumulative[cumulative.length - 1];
    const slice = [pointAtFraction(line, cumulative, from)];

    for (let index = 0; index < line.length; index++) {
        const fraction = cumulative[index] / total;

        if (fraction > from && fraction < to) {
            slice.push(line[index]);
        }
    }

    slice.push(pointAtFraction(line, cumulative, to));

    return slice;
}

document.addEventListener('alpine:init', () => {
    /**
     * The map riders flag roads on.
     *
     * Everything it knows about roads comes from the server: which ones are
     * under the current view, and what a click turned into. The browser
     * decides nothing about geometry, it only draws what it is handed.
     */
    window.Alpine.data('segmentFlagger', (centre = [44.26, -72.58], minZoom = 14) => ({
        map: null,
        roadLayer: null,
        selectionLayer: null,
        handleLayer: null,
        zoomHint: null,
        reloadTimer: null,
        onSelection: null,
        renderers: {},
        roadRenderer: null,

        /**
         * The area the drawn roads came from. While the map stays inside
         * it there is nothing to fetch and nothing to redraw, which is
         * what keeps zooming in smooth: a view holds hundreds of roads,
         * and rebuilding them all is not something to do on every zoom.
         */
        loadedBounds: null,

        /**
         * The selected road as one line, and the lengths along it, so a
         * dragged end can be turned into a fraction without asking the
         * server where it landed.
         */
        roadLine: [],
        cumulative: [],
        trim: [0, 1],

        init() {
            this.map = L.map(this.$el, { scrollWheelZoom: true, attributionControl: true });
            this.map.setView(centre, Math.max(minZoom, 15));

            tileLayer(L).addTo(this.map);

            this.roadRenderer = L.canvas({
                padding: ROAD_PADDING,
                tolerance: ROAD_CLICK_TOLERANCE,
            });

            Object.entries(PANE_Z_INDEX).forEach(([name, zIndex]) => {
                this.map.createPane(name).style.zIndex = zIndex;

                // Each pane gets a renderer with room to spare around the
                // view, so zooming out does not clip the selected road
                // while Leaflet catches up.
                this.renderers[name] = L.svg({ pane: name, padding: VIEW_PADDING });
            });

            this.roadLayer = L.layerGroup().addTo(this.map);
            this.selectionLayer = L.layerGroup().addTo(this.map);
            this.handleLayer = L.layerGroup().addTo(this.map);

            this.map.on('moveend', () => this.scheduleReload());

            this.onSelection = (event) => this.onSelectionChanged(event.detail);
            window.addEventListener('flag-selection', this.onSelection);

            requestAnimationFrame(() => {
                this.map.invalidateSize();
                this.loadRoads();
            });
        },

        scheduleReload() {
            clearTimeout(this.reloadTimer);
            this.reloadTimer = setTimeout(() => this.loadRoads(), RELOAD_DELAY_MS);
        },

        /**
         * Ask the server which roads are under the view, and make each of
         * them clickable.
         */
        async loadRoads() {
            const zoom = this.map.getZoom();

            // Too far out to tell one road from the next, so there is
            // nothing worth asking for.
            if (zoom < minZoom) {
                this.roadLayer.clearLayers();
                this.showZoomHint(true);
                this.loadedBounds = null;

                return;
            }

            this.showZoomHint(false);

            if (this.loadedBounds?.contains(this.map.getBounds())) {
                return;
            }

            const area = this.map.getBounds().pad(VIEW_PADDING);

            const { roads, zoomed_out: zoomedOut, capped } = await this.$wire.roadsInView(
                area.getSouth(),
                area.getWest(),
                area.getNorth(),
                area.getEast(),
                zoom,
            );

            this.roadLayer.clearLayers();
            this.showZoomHint(zoomedOut);

            roads.forEach((road) => this.drawRoad(road));

            // An answer the server had to cut short covers only part of
            // what was asked for, so it cannot stand in for the next view.
            this.loadedBounds = zoomedOut || capped ? null : area;
        },

        drawRoad(road) {
            if (!road.coordinates || road.coordinates.length < 2) {
                return;
            }

            const line = L.polyline(road.coordinates, {
                color: dangerColor(road.danger),
                weight: 4,
                opacity: 0.75,
                renderer: this.roadRenderer,
            }).addTo(this.roadLayer);

            line.bindTooltip(road.label, { sticky: true });
            line.on('click', () => this.$wire.selectRoad(road.id));
        },

        /**
         * Draw whatever the server says is now selected, and put the two
         * ends on the road if the rider is trimming it.
         */
        onSelectionChanged(detail) {
            this.selectionLayer.clearLayers();

            if (!detail?.selection) {
                this.roadLine = [];
                this.cumulative = [];
                this.handleLayer.clearLayers();

                return;
            }

            if (detail.selection.line?.length > 1) {
                this.roadLine = detail.selection.line;
                this.cumulative = measureLine(this.roadLine);
            }

            const drawn = drawFlagParts(L, this.selectionLayer, detail.selection.parts, {
                renderer: this.renderers[SELECTION_PANE],
            });

            if (detail.fit && drawn.length > 0) {
                this.map.fitBounds(L.featureGroup(drawn).getBounds(), { padding: [32, 32] });
            }

            this.trim = detail.trim ?? [0, 1];

            // The ends are live the moment a road is selected: they start
            // at its two ends, and dragging one in is the only way to pick
            // out part of a road.
            this.placeHandles();
        },

        /**
         * One draggable dot at each end of the stretch. Dragging one draws
         * the new stretch straight away and tells the server where it was
         * let go of.
         *
         * Each dot carries an invisible halo around it, because a dot big
         * enough to see is not big enough to hit with a thumb.
         */
        placeHandles() {
            this.handleLayer.clearLayers();

            if (this.roadLine.length < 2) {
                return;
            }

            [0, 1].forEach((end) => {
                const position = pointAtFraction(this.roadLine, this.cumulative, this.trim[end]);

                const halo = L.circleMarker(position, {
                    radius: 22,
                    opacity: 0,
                    fillOpacity: 0,
                    bubblingMouseEvents: false,
                    renderer: this.renderers[HANDLE_PANE],
                }).addTo(this.handleLayer);

                const dot = L.circleMarker(position, {
                    radius: 10,
                    color: '#ffffff',
                    weight: 3,
                    fillColor: SELECTED_COLOR,
                    fillOpacity: 1,
                    bubblingMouseEvents: false,
                    renderer: this.renderers[HANDLE_PANE],
                }).addTo(this.handleLayer);

                dot.bindTooltip(end === 0 ? 'Drag to move the start' : 'Drag to move the end');

                this.makeDraggable([halo, dot], end);
            });
        },

        /**
         * Leaflet drags markers, not circle markers, so the dot follows
         * the pointer by hand. Pointer events rather than mouse events, so
         * that a finger works as well as a mouse, and pointer capture so
         * that a drag which wanders off the map still ends when it is let
         * go of rather than leaving the dot stuck to the cursor.
         */
        makeDraggable(markers, end) {
            const elements = markers.map((marker) => marker.getElement()).filter(Boolean);

            if (elements.length === 0) {
                return;
            }

            const move = (event) => {
                const fraction = fractionOfPoint(
                    this.roadLine,
                    this.cumulative,
                    this.map.mouseEventToLatLng(event),
                );

                // The ends may not cross, or pass through each other.
                this.trim[end] = end === 0
                    ? Math.min(fraction, this.trim[1])
                    : Math.max(fraction, this.trim[0]);

                const position = pointAtFraction(this.roadLine, this.cumulative, this.trim[end]);

                markers.forEach((marker) => marker.setLatLng(position));

                this.previewTrim();
            };

            const release = (event) => {
                event.currentTarget.removeEventListener('pointermove', move);
                event.currentTarget.removeEventListener('pointerup', release);
                event.currentTarget.removeEventListener('pointercancel', release);

                this.map.dragging.enable();

                this.$wire.setTrim(this.trim[0], this.trim[1]);
            };

            elements.forEach((element) => {
                element.style.cursor = 'grab';
                element.style.touchAction = 'none';

                element.addEventListener('pointerdown', (event) => {
                    event.preventDefault();
                    event.stopPropagation();

                    this.map.dragging.disable();
                    element.setPointerCapture?.(event.pointerId);

                    element.addEventListener('pointermove', move);
                    element.addEventListener('pointerup', release);
                    element.addEventListener('pointercancel', release);
                });
            });
        },

        /**
         * What the stretch looks like mid-drag, before the server has cut
         * it out of the ways underneath.
         */
        previewTrim() {
            this.selectionLayer.clearLayers();

            drawFlagParts(L, this.selectionLayer, [{
                coordinates: sliceLine(this.roadLine, this.cumulative, this.trim[0], this.trim[1]),
            }], { renderer: this.renderers[SELECTION_PANE] });
        },

        showZoomHint(zoomedOut) {
            if (zoomedOut && !this.zoomHint) {
                this.zoomHint = hintControl(this.map, 'Zoom in to pick a road');

                return;
            }

            if (!zoomedOut && this.zoomHint) {
                this.map.removeControl(this.zoomHint);
                this.zoomHint = null;
            }
        },

        destroy() {
            window.removeEventListener('flag-selection', this.onSelection);
            clearTimeout(this.reloadTimer);
            this.map?.remove();
            this.map = null;
        },
    }));

    /**
     * A read-only map that shows one flag at a time, for the review queue.
     */
    window.Alpine.data('flagMap', (initial = null) => ({
        map: null,
        layer: null,
        onSelection: null,

        init() {
            this.map = L.map(this.$el, { scrollWheelZoom: true, attributionControl: true });
            this.map.setView([44.26, -72.58], 8);

            tileLayer(L).addTo(this.map);

            this.layer = L.layerGroup().addTo(this.map);

            this.onSelection = (event) => this.show(event.detail?.selection);
            window.addEventListener('flag-selection', this.onSelection);

            requestAnimationFrame(() => {
                this.map.invalidateSize();
                this.show(initial);
            });
        },

        show(selection) {
            this.layer.clearLayers();

            const drawn = drawFlagParts(L, this.layer, selection?.parts);

            if (drawn.length === 0) {
                return;
            }

            this.map.fitBounds(L.featureGroup(drawn).getBounds(), { padding: [32, 32] });
        },

        destroy() {
            window.removeEventListener('flag-selection', this.onSelection);
            this.map?.remove();
            this.map = null;
        },
    }));
});
