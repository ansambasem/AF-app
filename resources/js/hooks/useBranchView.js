import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { branchViewUrl } from '@/lib/branchView';

/** A branch picked in any page banner becomes the Super Admin's view for subsequent pages. */
export function useBranchView() {
    const { branchView } = usePage().props;
    const [switching, setSwitching] = useState(false);

    function changeBranch(value) {
        setSwitching(true);
        router.flushAll();
        router.visit(branchViewUrl(value, window.location.href), {
            preserveState: false,
            preserveScroll: true,
            onFinish: () => setSwitching(false),
        });
    }

    return {
        available: Boolean(branchView),
        value: branchView?.current?.id ?? '',
        options: branchView?.options.map((branch) => ({ value: String(branch.id), label: branch.name })) ?? [],
        switching,
        changeBranch,
    };
}
