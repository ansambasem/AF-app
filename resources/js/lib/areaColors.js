// The hues a منطقة 2 is shown in: spread around the wheel and clear of the brand's burgundy and the status reds.
const AREA_HUES = [210, 150, 30, 280, 305, 185, 100, 55];

/**
 * The same hue for the same area every time, so a collector learns their
 * area's colour. Taken from the area's id, so neighbouring areas (added one
 * after another) never share one; the name is the fallback.
 */
export function areaHue(id, name = '') {
    if (Number.isInteger(id)) {
        return AREA_HUES[id % AREA_HUES.length];
    }

    let hash = 0;

    for (const character of String(name ?? '')) {
        hash = (hash * 31 + character.codePointAt(0)) >>> 0;
    }

    return AREA_HUES[hash % AREA_HUES.length];
}
