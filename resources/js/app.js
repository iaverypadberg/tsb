import L from 'leaflet';

/**
 * Danger is a magnitude, so it gets a sequential ramp: one hue, darkening as
 * the road gets worse. Blue is the safe end — the parts of the route nothing
 * was flagged on — and the reds run light to dark from there.
 *
 * Three steps rather than more: adjacent steps any closer than this are not
 * tellable apart, by colour-blind readers or anyone else. Every section also
 * carries its score as a number on its card, so the colour is never the only
 * thing saying how bad a road is.
 */
const SAFE_COLOR = '#2563eb';

const DANGER_SCALE = [
    { upTo: 69, color: '#EFC744', label: 'Moderate' },
    { upTo: 89, color: '#FB7F4A', label: 'Bad' },
    { upTo: 100, color: '#b91c1c', label: 'Severe' },
];

function dangerColor(score) {
    return (DANGER_SCALE.find((step) => score <= step.upTo) ?? DANGER_SCALE[DANGER_SCALE.length - 1]).color;
}

/**
 * Leaflet map showing a route and the stretches flagged as dangerous.
 *
 * Coordinates arrive as [latitude, longitude] pairs, which is Leaflet's
 * ordering — note that GeoJSON and PostGIS use the opposite.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('routeMap', (coordinates = [], flagged = []) => ({
        map: null,
        flaggedLayers: {},

        init() {
            this.map = L.map(this.$el, {
                scrollWheelZoom: true,
                attributionControl: true,
            });

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution:
                    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            }).addTo(this.map);

            if (coordinates.length === 0) {
                this.map.setView([44.26, -72.58], 8);

                return;
            }

            const line = L.polyline(coordinates, {
                color: SAFE_COLOR,
                weight: 4,
                opacity: 0.9,
            }).addTo(this.map);

            this.drawFlagged();

            if (flagged.length > 0) {
                this.addLegend();
            }

            this.markEnd(coordinates[0], '#16a34a');
            this.markEnd(coordinates[coordinates.length - 1], '#dc2626');

            this.map.fitBounds(line.getBounds(), { padding: [24, 24] });

            // The container is often still being laid out on first paint.
            requestAnimationFrame(() => this.map.invalidateSize());

            // Clicking a section in the list zooms the map to it.
            this.$watch('$store.badroads.focused', (id) => this.focus(id));
        },

        /**
         * Flagged stretches sit on top of the route at a constant width, the
         * colour alone carrying how bad the road is. A white casing underneath
         * keeps them legible where they cross dark features on the basemap.
         */
        drawFlagged() {
            flagged.forEach((section) => {
                if (!section.coordinates || section.coordinates.length < 2) {
                    return;
                }

                L.polyline(section.coordinates, {
                    color: '#ffffff',
                    weight: 9,
                    opacity: 0.9,
                }).addTo(this.map);

                const layer = L.polyline(section.coordinates, {
                    color: dangerColor(section.danger),
                    weight: 5,
                    opacity: 1,
                }).addTo(this.map);

                layer.bindTooltip(`${section.danger}/100 · ${section.label}`, { sticky: true });

                this.flaggedLayers[section.id] = layer;
            });

        },

        /**
         * Without a key the colours mean nothing to someone who has not read
         * the code.
         */
        addLegend() {
            const legend = L.control({ position: 'bottomright' });

            legend.onAdd = () => {
                const box = L.DomUtil.create('div');

                box.style.cssText =
                    'background:#fff;padding:6px 8px;border-radius:6px;' +
                    'box-shadow:0 1px 4px rgba(0,0,0,.3);font:11px/1.5 system-ui,sans-serif;color:#1f2937';

                const swatch = (color, label) =>
                    `<div style="display:flex;align-items:center;gap:6px">
                        <span style="width:14px;height:4px;border-radius:2px;background:${color}"></span>${label}
                    </div>`;

                box.innerHTML =
                    swatch(SAFE_COLOR, 'Not flagged') +
                    DANGER_SCALE.map((step) => swatch(step.color, step.label)).join('');

                return box;
            };

            legend.addTo(this.map);
        },

        focus(id) {
            const layer = this.flaggedLayers[id];

            if (layer) {
                this.map.fitBounds(layer.getBounds(), { padding: [48, 48] });
                layer.openTooltip();
            }
        },

        markEnd(position, color) {
            L.circleMarker(position, {
                radius: 6,
                color: '#ffffff',
                weight: 2,
                fillColor: color,
                fillOpacity: 1,
            }).addTo(this.map);
        },

        destroy() {
            this.map?.remove();
            this.map = null;
            this.flaggedLayers = {};
        },
    }));

    // Lets the section list tell the map which stretch to zoom to.
    window.Alpine.store('badroads', { focused: null });
});
