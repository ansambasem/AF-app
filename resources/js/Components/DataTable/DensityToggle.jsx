import Icon from '@/Components/Icon';
import { useTableDensity } from '@/hooks/useTableDensity';

const DENSITIES = [
    { value: 'comfortable', icon: 'rows-comfortable', label: 'صفوف مريحة' },
    { value: 'compact', icon: 'rows-compact', label: 'صفوف مضغوطة' },
];

/**
 * Switches every table between comfortable rows (floating cards) and
 * compact rows (about twice as many on screen); the choice is remembered.
 */
export default function DensityToggle() {
    const [density, setDensity] = useTableDensity();

    return (
        <div role="group" aria-label="كثافة الصفوف" className="inline-flex gap-0.5 rounded-control border border-gray-100 bg-gray-50 p-[3px]">
            {DENSITIES.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={density === option.value}
                    aria-label={option.label}
                    title={option.label}
                    onClick={() => setDensity(option.value)}
                    className={`flex h-7 w-8 items-center justify-center rounded-lg transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-gray-900 ${
                        density === option.value ? 'bg-surface text-gray-900 shadow-sm' : 'text-gray-400 hover:text-gray-900'
                    }`}
                >
                    <Icon name={option.icon} className="h-4 w-4" />
                </button>
            ))}
        </div>
    );
}
