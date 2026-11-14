import { router } from '@inertiajs/react';
import BranchBanner from '@/Components/BranchBanner';

/**
 * Whose schedule a schedule page shows: every branch or one
 * branch's. The Super Admin picks it from the list; a Branch Admin only ever
 * has their own branch, so sees its name. A company save also replaces
 * schedules previously customized for individual branches.
 */
export default function BranchScopeBar({ path, scope, branch, branches, followsCompany, branchesWithOwn, locked }) {
    let note;
    if (!branch) {
        note = `عند الحفظ تُطبّق مواعيد الشركة على جميع الفروع، وتتجاوز أي مواعيد مخصصة لها${branchesWithOwn > 0 ? `، بما فيها إعدادات ${branchesWithOwn} من الفروع المحفوظة` : ''}. تبقى القراءات والأسابيع السابقة محفوظة بتواريخها.`;
    } else if (followsCompany) {
        note = `الفرع «${branch.name}» يتبع حاليًا إعدادات الشركة. يمكنك تخصيص مواعيده هنا؛ وعند تعميم مواعيد الشركة لاحقًا تُستبدل مواعيد هذا الفرع أيضًا.`;
    } else {
        note = `الحفظ هنا يخص الفرع «${branch.name}» وحده. عند تعميم مواعيد الشركة تُستبدل هذه المواعيد بإعدادات الشركة.`;
    }

    function pick(event) {
        const value = event.target.value;
        router.get(path, { branch: value || 'all' });
    }

    return (
        <BranchBanner scope={scope} eyebrow="تضبط الآن مواعيد القراءات لـ" allLabel="إعدادات الشركة" className="mt-5"
            controls={branches.length > 0 ? (
                <label className="flex w-full flex-col gap-1.5 sm:w-64" htmlFor="schedule-branch">
                    <span className="text-xs font-semibold text-white/70">نطاق المواعيد</span>
                    <select id="schedule-branch" value={branch?.id ?? ''} disabled={locked} onChange={pick}
                        className="h-12 w-full rounded-control border border-white/15 bg-white/10 px-3.5 font-bold text-white [color-scheme:dark] focus:border-white/40 focus:ring-2 focus:ring-white/30 disabled:opacity-50"
                        title={locked ? 'احفظ التغييرات أو تراجع عنها قبل تغيير الفرع.' : undefined}>
                        <option value="">جميع الفروع — إعدادات الشركة</option>
                        {branches.map((item) => (
                            <option key={item.value} value={item.value}>
                                {item.label}
                                {item.hasOwn ? ' · إعدادات محفوظة' : ''}
                            </option>
                        ))}
                    </select>
                </label>
            ) : null}>
            <p className="text-sm leading-relaxed text-white/80">{note}</p>
        </BranchBanner>
    );
}
