import { useForm } from '@inertiajs/react';
import InputError from '@/Components/InputError';
import Icon from '@/Components/Icon';

export default function UpdateProfileInformationForm({ user }) {
    const { data, setData, patch, processing, errors, reset, clearErrors, isDirty, setDefaults, recentlySuccessful } = useForm({
        name: user.name,
    });

    function submit(e) {
        e.preventDefault();
        patch('/profile', { preserveScroll: true, onSuccess: () => setDefaults() });
    }

    return (
        <section className="pp-panel">
            <h3><span className="pp-panel-icon"><Icon name="user" /></span>معلومات الحساب</h3>
            <p>يمكنك تعديل اسمك الظاهر. باقي البيانات يعدّلها المدير العام من صفحة المستخدمين.</p>
            <form onSubmit={submit}>
                <div className={`pp-field ${errors.name ? 'bad' : ''}`}>
                    <label className="l" htmlFor="name">الاسم الكامل</label>
                    <div className="pp-input"><input id="name" value={data.name} autoComplete="name" onChange={(e) => setData('name', e.target.value)} required maxLength={255} /></div>
                    <InputError message={errors.name} />
                </div>
                <div className="pp-field">
                    <label className="l" htmlFor="username">اسم المستخدم<small><Icon name="lock" />يعدّله المدير</small></label>
                    <div className="pp-input"><input id="username" value={user.username} dir="ltr" disabled /></div>
                </div>
                <div className="pp-readonly">
                    <div className="pp-readonly-box"><small><Icon name="badge" />المسمى الوظيفي</small><b>{user.roleLabel}</b></div>
                    <div className="pp-readonly-box"><small><Icon name="lock" />الفرع</small><b>{user.branchName}</b></div>
                </div>
                <div className="pp-actions">
                    <span className="pp-note" role="status">{isDirty ? 'تغييرات غير محفوظة' : recentlySuccessful ? 'حُفظ الاسم' : ''}</span>
                    {isDirty && <button className="pp-button" type="button" disabled={processing} onClick={() => { reset(); clearErrors(); }}>تراجع</button>}
                    <button className="pp-button primary" disabled={processing || !isDirty || !data.name.trim()}><Icon name="check" />{processing ? 'جارٍ الحفظ…' : 'حفظ الاسم'}</button>
                </div>
            </form>
        </section>
    );
}
