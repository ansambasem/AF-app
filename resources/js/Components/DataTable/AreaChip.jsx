import { areaHue } from '@/lib/areaColors';

/** A منطقة 2 as a soft chip in its own steady colour, so a collector picks out their area's rows at a glance. */
export default function AreaChip({ id, name }) {
    if (!name) {
        return '—';
    }

    return (
        <span className="area-chip" style={{ '--area-hue': areaHue(id, name) }}>
            {name}
        </span>
    );
}
