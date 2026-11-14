import { useForm } from '@inertiajs/react';
import PasswordInput from '@/Components/PasswordInput';
import InputError from '@/Components/InputError';
import Icon from '@/Components/Icon';
import { passwordStrength } from '@/lib/profilePassword';

const STRENGTH_LABELS = ['—', 'ضعيفة', 'متوسطة', 'جيدة', 'قوية'];

export default function UpdatePasswordForm() {
    const { data, setData, put, processing, errors, reset, recentlySuccessful } = useForm({
        current_password: '', password: '', password_confirmation: '',
    });
    const bag = errors.updatePassword ?? errors;
    const strength = passwordStrength(data.password, data.current_password);
    const matching = Boolean(data.password_confirmation) && data.password_confirmation === data.password;

    function submit(event) {
        event.preventDefault();
        put('/password', { preserveScroll: true, onSuccess: () => reset() });
    }

    return (
        <section className="pp-panel">
            <h3><span className="pp-panel-icon"><Icon name="key" /></span>تغيير كلمة المرور</h3>
            <p>8 خانات على الأقل، ويمكن استخدام حروف فقط أو أرقام فقط.</p>
            <form onSubmit={submit}>
                <div className={`pp-field ${bag.current_password ? 'bad' : ''}`}>
                    <label className="l" htmlFor="current_password">كلمة المرور الحالية</label>
                    <div className="pp-input"><PasswordInput id="current_password" dir="ltr" toggleTabIndex={0} value={data.current_password} autoComplete="current-password" onChange={(e) => setData('current_password', e.target.value)} required /></div>
                    <InputError message={bag.current_password} />
                </div>
                <div className={`pp-field ${bag.password ? 'bad' : ''}`}>
                    <label className="l" htmlFor="password">كلمة المرور الجديدة</label>
                    <div className="pp-input"><PasswordInput id="password" dir="ltr" toggleTabIndex={0} value={data.password} autoComplete="new-password" onChange={(e) => setData('password', e.target.value)} required /></div>
                    <div className={`pp-strength level-${strength.score}`} aria-live="polite">
                        <div className="bars">{[1, 2, 3, 4].map((level) => <i key={level} className={level <= strength.score ? 'filled' : ''} />)}</div>
                        <div className="lbl"><span>قوة كلمة المرور (إرشادية فقط)</span><b>{STRENGTH_LABELS[strength.score]}</b></div>
                    </div>
                    <ul className="pp-rules" aria-label="الحد الأدنى لكلمة المرور"><li className={strength.checks[0] ? 'on' : ''}><i><Icon name="check" /></i>8 خانات على الأقل</li></ul>
                    <InputError message={bag.password} />
                </div>
                <div className={`pp-field ${bag.password_confirmation || (data.password_confirmation && !matching) ? 'bad' : ''}`}>
                    <label className="l" htmlFor="password_confirmation">تأكيد كلمة المرور الجديدة</label>
                    <div className="pp-input"><PasswordInput id="password_confirmation" dir="ltr" toggleTabIndex={0} value={data.password_confirmation} autoComplete="new-password" onChange={(e) => setData('password_confirmation', e.target.value)} required /></div>
                    {data.password_confirmation && <p className={matching ? 'pp-match' : 'pp-error'}>{matching ? '✓ كلمتا المرور متطابقتان' : 'كلمتا المرور غير متطابقتين.'}</p>}
                    <InputError message={bag.password_confirmation} />
                </div>
                <div className="pp-actions">
                    <span className="pp-note" role="status">{recentlySuccessful ? 'تم تغيير كلمة المرور' : 'بعد التغيير يبقى هذا الجهاز مسجّل الدخول.'}</span>
                    <button type="submit" className="pp-button primary" disabled={processing}><Icon name="key" />{processing ? 'جارٍ الحفظ…' : 'تغيير كلمة المرور'}</button>
                </div>
            </form>
        </section>
    );
}
