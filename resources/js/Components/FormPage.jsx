import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { SaveConfirmDialog } from '@/Components/ConfirmDialog';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import { secondaryButtonClass } from '@/Components/SecondaryButton';
import { clearErrorOnInput, submitOnCtrlEnter, validateFormFields } from '@/lib/formValidation';

/**
 * The standalone Create/Edit page every resource uses: the form's sections
 * (children, each its own card) and, under them, a bar that stays in view
 * holding Cancel and Save, laid out like a dialog's footer. Saving asks for
 * confirmation first; Ctrl + Enter saves from any field.
 *
 * `form` comes from useResourceForm(); `cancelHref` is where Cancel goes.
 * Pass `layout={SettingsLayout}` for pages under the Settings tabs.
 */
export default function FormPage({ title, form, cancelHref, layout: Layout = AuthenticatedLayout, widthClass = 'max-w-2xl', children }) {
    const [confirmingSave, setConfirmingSave] = useState(false);

    function submit(e) {
        e.preventDefault();

        if (validateFormFields(e.currentTarget, form)) {
            setConfirmingSave(true);
        }
    }

    function save() {
        setConfirmingSave(false);
        form.save();
    }

    return (
        <Layout header={<PageHeader title={title} />}>
            <Head title={title} />

            <form noValidate onSubmit={submit} onInput={(e) => clearErrorOnInput(e, form)} onKeyDown={submitOnCtrlEnter} className={`rise-in ${widthClass}`}>
                {children}

                <div className="sticky bottom-4 z-10 mt-6 flex items-center justify-end gap-3 rounded-card border border-gray-100 bg-surface/90 px-5 py-4 shadow-lift backdrop-blur">
                    <Link href={cancelHref} prefetch className={secondaryButtonClass}>
                        إلغاء
                    </Link>
                    <PrimaryButton disabled={form.processing}>{form.processing ? 'جارٍ الحفظ...' : 'حفظ'}</PrimaryButton>
                </div>
            </form>

            <SaveConfirmDialog show={confirmingSave} isEdit={form.isEdit} onConfirm={save} onCancel={() => setConfirmingSave(false)} />
        </Layout>
    );
}
