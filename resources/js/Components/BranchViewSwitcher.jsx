import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import Icon from '@/Components/Icon';

export default function BranchViewSwitcher() {
    const { branchView, branchScope, scope } = usePage().props;
    const [switching, setSwitching] = useState(false);
    if (!branchView || branchScope || ['branch', 'all', 'governorate'].includes(scope?.level)) {
        return null;
    }

    function changeBranch(event) {
        setSwitching(true);
        router.flushAll();
        router.put('/branch-view', { branch_id: event.target.value ? Number(event.target.value) : null }, {
            preserveState: false,
            onFinish: () => setSwitching(false),
        });
    }

    return (
        <section aria-label="فرع العمل الحالي" className="mb-5 flex flex-wrap items-center gap-3 rounded-2xl border border-brand-500/20 bg-brand-500/5 p-3 dark:bg-brand-500/10">
            <Icon name="building" className="h-5 w-5 shrink-0 text-brand-600" />
            <div className="min-w-0 flex-1">
                <p className="font-semibold text-gray-900">{branchView.current ? `تعمل الآن ضمن فرع ${branchView.current.name}` : 'تعرض الآن جميع الفروع'}</p>
                <p className="text-xs text-gray-500">يبقى اختيارك معك أثناء التنقل حتى تغيّره. صلاحيات المدير العام محفوظة.</p>
            </div>
            <label className="flex w-full items-center gap-2 text-sm font-semibold sm:w-auto">
                <span>تغيير فرع العمل</span>
                <select aria-label="تغيير فرع العمل" value={branchView.current?.id ?? ''} onChange={changeBranch} disabled={switching}
                    className="min-w-0 flex-1 rounded-control border-gray-300 bg-surface text-sm text-gray-900 sm:min-w-[12rem]">
                    <option value="">جميع الفروع</option>
                    {branchView.options.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                </select>
            </label>
        </section>
    );
}
