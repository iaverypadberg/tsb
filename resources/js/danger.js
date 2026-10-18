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
export const SAFE_COLOR = '#2563eb';

/**
 * A road the rider has picked out to flag. Violet is nowhere on the danger
 * ramp, so "I chose this" never reads as "this is dangerous".
 */
export const SELECTED_COLOR = '#7c3aed';

export const DANGER_SCALE = [
    { upTo: 69, color: '#EFC744', label: 'Moderate' },
    { upTo: 89, color: '#FB7F4A', label: 'Bad' },
    { upTo: 100, color: '#b91c1c', label: 'Severe' },
];

export function dangerColor(score) {
    return (DANGER_SCALE.find((step) => score <= step.upTo) ?? DANGER_SCALE[DANGER_SCALE.length - 1]).color;
}

/**
 * The OpenStreetMap basemap, with the attribution its licence requires.
 */
export function tileLayer(L) {
    return L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    });
}

/**
 * Draws the stretches a flag covers: a white casing under a coloured line,
 * which keeps them legible wherever they cross dark features on the basemap.
 *
 * All the stretches go into one casing and one line, rather than a casing
 * and a line each. A road is dozens of OpenStreetMap ways, and drawing them
 * one pair at a time lets each casing paint over the end of the line before
 * it, nicking a white gap into the road at every join.
 *
 * The lines take no clicks of their own, so clicking a highlighted road
 * still reaches the road underneath it.
 *
 * @param {object} options `renderer` puts the lines in a given Leaflet
 *                         renderer, and so in its pane; `color` overrides
 *                         the selected-road violet.
 * @returns {Array} the drawn layer, so the caller can fit the map to it
 */
export function drawFlagParts(L, layer, parts, options = {}) {
    const { color = SELECTED_COLOR, renderer } = options;

    const strands = (parts ?? [])
        .map((part) => part.coordinates)
        .filter((coordinates) => Array.isArray(coordinates) && coordinates.length >= 2);

    if (strands.length === 0) {
        return [];
    }

    const style = { renderer, interactive: false };

    L.polyline(strands, { color: '#ffffff', weight: 10, opacity: 0.9, ...style }).addTo(layer);

    return [L.polyline(strands, { color, weight: 6, opacity: 1, ...style }).addTo(layer)];
}
