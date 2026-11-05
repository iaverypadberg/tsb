import L from 'leaflet';
import { DANGER_SCALE, dangerColor, SAFE_COLOR, tileLayer, trafficColor } from './danger.js';
import './flag-map.js';

/**
 * Leaflet map showing a route, coloured either by what an analysis made of it
 * or by how much traffic the roads under it carry.
 *
 * The two colourings are drawn once, into a layer group each, and swapped by
 * adding and removing those groups. Rebuilding the lines on every flip would
 * be the obvious way and the wrong one: a rider flips back and forth to
 * compare, and that has to be instant and leave the map where they panned it.
 *
 * Coordinates arrive as [latitude, longitude] pairs, which is Leaflet's
 * ordering — note that GeoJSON and PostGIS use the opposite.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('routeMap', (coordinates = [], sections = [], traffic = [], view = {}) => ({
        map: null,
        sectionLayers: {},
        trafficLayers: {},
        dangerLayer: null,
        trafficLayer: null,
        legend: null,

        /**
         * The label on the road the rider last picked, which stays up until
         * they pick another. One road is picked at a time, so one label.
         *
         * Its own tooltip, and a `permanent` one, for two reasons Leaflet
         * makes unavoidable:
         *
         *  - a tooltip bound to a line belongs to Leaflet's hover handlers
         *    (mouseover opens it, mouseout closes it). Opening one of those
         *    from a card leaves it with no mouseout to close it, so it stays
         *    up for ever — which is what strewed a label across the map for
         *    every road the rider clicked.
         *  - a tooltip that is not `permanent` also registers
         *    `preclick: this.close`, so the next click anywhere on the map
         *    shuts it behind our back and leaves this reference pointing at
         *    a tooltip that is already gone.
         *
         * Held, so it can be taken off by hand when the next road is picked.
         */
        taggedTooltip: null,

        /**
         * The listener that takes the label off when the rider moves the map.
         *
         * Held as the exact closure that was registered, because Leaflet
         * matches a listener for removal by identity and a method reached
         * through Alpine's proxy is not something to stake that on.
         */
        tagClearer: null,

        /**
         * Which colouring is on the map.
         *
         * A mirror of the server's property, not a reading of it: the list
         * beside the map has to be rendered from the server's copy, but the
         * layers on the map must not depend on what a re-render did around
         * them. The Danger / Traffic control is the only thing that moves
         * it, and it moves it there first.
         */
        colourBy: view.colourBy ?? 'danger',

        init() {
            this.map = L.map(this.$refs.map ?? this.$el, {
                scrollWheelZoom: true,
                attributionControl: true,
            });

            tileLayer(L).addTo(this.map);

            if (coordinates.length === 0) {
                this.map.setView([44.26, -72.58], 8);

                return;
            }

            const line = L.polyline(coordinates, {
                color: SAFE_COLOR,
                weight: 4,
                opacity: 0.9,
            }).addTo(this.map);

            this.dangerLayer = L.layerGroup();
            this.trafficLayer = L.layerGroup();

            this.drawSections();
            this.drawTraffic();
            this.showColouring();

            this.markEnd(coordinates[0], '#16a34a');
            this.markEnd(coordinates[coordinates.length - 1], '#dc2626');

            this.map.fitBounds(line.getBounds(), { padding: [24, 24] });

            // The container is often still being laid out on first paint.
            requestAnimationFrame(() => this.map.invalidateSize());

            // Follow the server's colouring, and redraw when it moves.
            this.$wire.$watch('colourBy', (value) => {
                this.colourBy = value;
            });

            this.$watch('colourBy', () => this.showColouring());

            // Clicking a stretch in either list zooms the map to it.
            this.$watch('$store.badroads.focused', (focus) => this.focus(focus));
            this.$watch('$store.badroads.focusedTraffic', (focus) => this.focusTraffic(focus));
        },

        /**
         * Put the chosen colouring on the map, with the key that goes with
         * it, and take the other one off. One ramp at a time: two sequential
         * scales on one line is not a thing anybody can read.
         */
        showColouring() {
            const wanted = this.colourBy === 'traffic' ? this.trafficLayer : this.dangerLayer;
            const other = this.colourBy === 'traffic' ? this.dangerLayer : this.trafficLayer;

            // The labelled line is about to come off the map, and a label
            // hanging over a line that is no longer there labels nothing.
            this.untag();

            this.map.removeLayer(other);
            wanted.addTo(this.map);

            this.addLegend();
        },

        /**
         * Sections sit on top of the route at a constant width, the colour
         * alone carrying how bad the road is. A white casing underneath keeps
         * them legible where they cross dark features on the basemap.
         *
         * The unflagged stretches are drawn too, in the safe colour the route
         * line already uses, so hovering anywhere on the route says what road
         * it is and clicking any card in either list has a layer to zoom to.
         *
         * Every casing goes down before any of the coloured lines: a run is
         * split wherever the road's name changes, so two flagged stretches
         * often meet, and a casing drawn after its neighbour's line would
         * nick a white gap into the join.
         */
        drawSections() {
            const drawable = sections.filter(
                (section) => section.coordinates && section.coordinates.length >= 2,
            );

            drawable.forEach((section) => {
                L.polyline(section.coordinates, {
                    color: '#ffffff',
                    weight: 9,
                    opacity: 0.9,
                    interactive: false,
                }).addTo(this.dangerLayer);
            });

            drawable.forEach((section) => {
                const layer = L.polyline(section.coordinates, {
                    color: section.flagged ? dangerColor(section.danger) : SAFE_COLOR,
                    weight: 5,
                    opacity: 1,
                }).addTo(this.dangerLayer);

                layer.bindTooltip(`${section.danger}/100 · ${section.label}`, { sticky: true });

                this.sectionLayers[section.id] = layer;
            });
        },

        /**
         * The traffic colouring, drawn the same way: casings first, then the
         * banded lines over them.
         */
        drawTraffic() {
            const drawable = traffic.filter(
                (section) => section.coordinates && section.coordinates.length >= 2,
            );

            drawable.forEach((section) => {
                L.polyline(section.coordinates, {
                    color: '#ffffff',
                    weight: 9,
                    opacity: 0.9,
                    interactive: false,
                }).addTo(this.trafficLayer);
            });

            drawable.forEach((section) => {
                const layer = L.polyline(section.coordinates, {
                    color: trafficColor(section.band),
                    weight: 5,
                    opacity: 1,
                }).addTo(this.trafficLayer);

                layer.bindTooltip(section.label, { sticky: true });

                // The tooltip says which road it is; the card beside the map
                // says how busy it is and what has been reported on it, which
                // is what the rider clicked the road to find out.
                layer.on('click', () => this.$store.badroads.reveal(section.id));

                this.trafficLayers[section.id] = layer;
            });
        },

        /**
         * Without a key the colours mean nothing to someone who has not read
         * the code. The key belongs to the colouring on show, so it is
         * replaced rather than added to when the two are swapped.
         *
         * The traffic key carries the counts each band covers and how far
         * the route runs on it: "Heavy" is one person's word for it, "over
         * 12,000 a day · 2.4 km" is what the rider is actually choosing.
         */
        addLegend() {
            const rows = this.colourBy === 'traffic' ? this.trafficRows() : this.dangerRows();

            if (this.legend) {
                this.map.removeControl(this.legend);
                this.legend = null;
            }

            if (rows.length === 0) {
                return;
            }

            const legend = L.control({ position: 'bottomright' });

            legend.onAdd = () => {
                const box = L.DomUtil.create('div');

                box.style.cssText =
                    'background:#fff;padding:6px 8px;border-radius:6px;' +
                    'box-shadow:0 1px 4px rgba(0,0,0,.3);font:11px/1.5 system-ui,sans-serif;color:#1f2937';

                box.innerHTML = rows.join('');

                return box;
            };

            legend.addTo(this.map);

            this.legend = legend;
        },

        /**
         * One key row: a line of the colour, and the words that go with it.
         */
        swatch(color, label) {
            return `<div style="display:flex;align-items:center;gap:6px">
                <span style="width:16px;height:4px;border-radius:2px;background:${color};flex:none"></span>${label}
            </div>`;
        },

        dangerRows() {
            if (sections.length === 0) {
                return [];
            }

            return [
                this.swatch(SAFE_COLOR, 'Not flagged'),
                ...DANGER_SCALE.map((step) => this.swatch(step.color, step.label)),
            ];
        },

        trafficRows() {
            return (view.key ?? []).map((band) =>
                this.swatch(trafficColor(band.band), `${band.label} · ${band.range} · ${band.km} km`),
            );
        },

        /**
         * Zoom to a section the rider picked out of the list. The list is of
         * what the analysis found, so the map is put back on that colouring
         * first — otherwise it would zoom to a line that is not on it.
         */
        focus(focus) {
            this.zoomTo(this.sectionLayers[focus?.id]);
        },

        /**
         * The same, for a road picked out of the traffic list.
         */
        focusTraffic(focus) {
            this.zoomTo(this.trafficLayers[focus?.id]);
        },

        /**
         * Zoom to a stretch the rider picked out of a list.
         *
         * Nothing here changes the colouring. The list beside the map is
         * always the list for the colouring that is on it, so there is
         * never one to change — and asking the server to set it to what it
         * already was cost a round trip, and a re-render around the map,
         * on every click of a map pin.
         */
        zoomTo(layer) {
            if (! layer) {
                return;
            }

            // Labelled first, so that a throw out of `fitBounds` — which has
            // happened here, from a stale overlay left subscribed to the
            // map's zoom events — cannot leave the road the rider picked as
            // the one road with no label on it.
            this.tag(layer);

            this.map.fitBounds(layer.getBounds(), { padding: [48, 48] });
        },

        /**
         * Label the picked road, taking the label off whichever road was
         * wearing it before.
         *
         * Pinned to a vertex of the line rather than the centre of its
         * bounding box, which on anything but a straight road is a point out
         * in a field beside it.
         *
         * It lasts until the rider pans. Panning is what carries the labelled
         * road off the screen and leaves the label naming something that is
         * no longer there; zooming keeps it in view, so a rider zooming in to
         * read the road they just picked gets to keep its name.
         */
        tag(layer) {
            this.untag();

            const points = layer.getLatLngs();

            this.taggedTooltip = L.tooltip({
                permanent: true,
                direction: 'top',
                offset: [0, -8],
            })
                .setLatLng(points[Math.floor(points.length / 2)])
                .setContent(layer.getTooltip()?.getContent() ?? '')
                .addTo(this.map);

            // `dragstart` rather than `movestart`, because `movestart` fires
            // on a zoom too — and rather than any guard against our own
            // `fitBounds`, because only a drag by the rider reaches here.
            // Leaflet holds it back until the pointer has travelled past
            // `clickTolerance`, so a click that does not move is not a pan.
            this.tagClearer = () => this.untag();

            this.map.on('dragstart', this.tagClearer);
        },

        /**
         * Take the label off, if one is up.
         *
         * `hasLayer` because the tooltip may already be off the map — the
         * map is torn down and rebuilt around it by a Livewire re-render —
         * and removing a layer twice is how stale handlers are left behind
         * in the first place.
         */
        untag() {
            if (this.tagClearer) {
                this.map?.off('dragstart', this.tagClearer);
                this.tagClearer = null;
            }

            if (this.taggedTooltip && this.map?.hasLayer(this.taggedTooltip)) {
                this.map.removeLayer(this.taggedTooltip);
            }

            this.taggedTooltip = null;
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
            this.untag();
            this.map?.remove();
            this.map = null;
            this.sectionLayers = {};
            this.trafficLayers = {};
            this.dangerLayer = null;
            this.trafficLayer = null;
            this.legend = null;
        },
    }));

    // Carries the map and the list beside it each other's picks: a card asks
    // the map to zoom to its stretch, and a stretch clicked on the map asks
    // the list to scroll to its card.
    window.Alpine.store('badroads', {
        focused: null,
        focusedTraffic: null,

        /**
         * Ask the map to zoom to a stretch.
         *
         * A fresh object each time rather than the bare id, so that asking
         * twice for the same stretch is two requests the map can see: a
         * rider who has panned away and wants to go back presses the same
         * pin again.
         */
        show(list, id) {
            this[list] = { id };
        },

        /**
         * Scroll the list beside the map to a road clicked on the map, and
         * ring its card so it is plain which of them was asked for.
         *
         * Done to the DOM rather than through a property the cards bind to.
         * The list is rendered by the server and re-rendered whole whenever
         * the ordering changes, so a ring held in Alpine would have to
         * survive a morph; this one is a pointer at the card the rider just
         * asked for, and outliving the next render is not part of its job.
         */
        reveal(id) {
            const card = document.querySelector(`[data-traffic-section="${id}"]`);

            if (! card) {
                return;
            }

            // Off every card first, this one included. The hold and the fade
            // are one CSS animation, and an animation only starts over if
            // the class was gone when the browser last looked at the
            // element — so a rider clicking a second road while the first
            // ring is still up gets a fresh three seconds on the new card,
            // and clicking the same road again gets a fresh three seconds
            // rather than nothing at all.
            document
                .querySelectorAll('[data-traffic-section].is-revealed')
                .forEach((previous) => previous.classList.remove('is-revealed'));

            // Reading a layout property is what makes the browser look.
            void card.offsetWidth;

            card.classList.add('is-revealed');

            this.scrollTo(card);
        },

        /**
         * Put the card at the top of the list, where a rider looks for it,
         * with the rest of the route below it to read on from.
         *
         * Where the list is its own scrolling column — beside the map on a
         * wide screen — the column is scrolled rather than the card asked to
         * scroll itself: scrollIntoView walks every scrollable ancestor, so
         * asking for the top of the column also asks the page to put the
         * card at the top of the window, which drags the map the rider is
         * working from off the screen.
         *
         * Below the map on a narrow screen the list is not a column and does
         * not scroll, and then the page has to move, which is what
         * scrollIntoView is for.
         */
        scrollTo(card) {
            const panel = card.closest('[data-scroll-panel]');

            if (! panel || panel.scrollHeight <= panel.clientHeight) {
                card.scrollIntoView({ behavior: 'smooth', block: 'start' });

                return;
            }

            // Measured against the panel rather than read off offsetTop,
            // which is relative to whichever ancestor happens to be
            // positioned.
            const offset = card.getBoundingClientRect().top - panel.getBoundingClientRect().top;

            // The top of the column is held by the ordering control, so the
            // top of the column is not where the card can be put: it would
            // arrive behind it. Measured rather than written down, because
            // what is pinned there is markup's business, not this function's.
            const header = panel.querySelector('[data-scroll-panel-header]');
            const pinned = header ? header.getBoundingClientRect().height : 0;

            // A few pixels of air so the card does not read as clipped by
            // whatever it ends up under.
            panel.scrollTo({ top: panel.scrollTop + offset - pinned - 8, behavior: 'smooth' });
        },
    });
});
