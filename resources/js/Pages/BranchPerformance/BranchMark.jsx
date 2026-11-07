import Icon from '@/Components/Icon';

/** "محافظة بغداد · الكرادة", from the parts a branch has. */
export function branchPlace(branch) {
    return [branch.governorateName, branch.areaName].filter(Boolean).join(' · ');
}

/** A branch's pin tile, with a dot for whether it is working (the name beside it says so in words). */
export default function BranchMark({ isActive, className = 'h-12 w-12' }) {
    return (
        <span className={`relative flex shrink-0 items-center justify-center rounded-2xl bg-graphite-gradient text-white shadow-sm ${className}`}>
            <Icon name="pin" className="h-5 w-5" />
            <span
                className={`absolute -bottom-0.5 -start-0.5 h-3.5 w-3.5 rounded-full ring-2 ring-surface ${isActive ? 'bg-emerald-500' : 'bg-gray-400'}`}
                aria-hidden="true"
            />
        </span>
    );
}
