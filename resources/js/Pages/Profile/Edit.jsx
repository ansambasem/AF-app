import { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import PasswordInput from '@/Components/PasswordInput';
import InputError from '@/Components/InputError';
import { useTheme } from '@/hooks/useTheme';
import { ACTION_MESSAGES } from '@/lib/actionMessages';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import './Profile.css';
import PageHeader from '@/Components/PageHeader';

const THEMES = [
    { value: 'light', label: 'فاتح', colors: ['#eff1f4', '#fff'] },
    { value: 'dark', label: 'داكن', colors: ['#0b0d10', '#14171c'] },
    { value: 'auto', label: 'حسب الجهاز', colors: ['#eff1f4', '#14171c'] },
];

function PanelTitle({ icon, children }) {
    return <h3><span className="pp-panel-icon"><Icon name={icon} /></span>{children}</h3>;
}

function PermissionGroup({ group }) {
    return <div className="pp-permission-group"><b>{group.label}<span>{group.permissions.filter((permission) => permission.granted).length}/{group.permissions.length}</span></b><div className="pp-chips">{group.permissions.map((permission) => <span key={permission.key} className={permission.granted ? '' : 'no'}>{permission.label}</span>)}</div></div>;
}

function Permissions({ groups }) {
    const coreKeys = ['subscriptions', 'meter_readings', 'collections'];
    const core = coreKeys.map((key) => groups.find((group) => group.key === key)).filter(Boolean);
    const settings = groups.filter((group) => !coreKeys.includes(group.key));
    const granted = settings.filter((group) => group.permissions.some((permission) => permission.granted)).length;

    return <section className="pp-panel">
        <PanelTitle icon="shield">صلاحياتك</PanelTitle><p>ما يمكنك فعله في النظام حسب دورك.</p>
        <div className="pp-permissions">
            {core.map((group) => <PermissionGroup key={group.key} group={group} />)}
            <details className="pp-permission-group pp-settings-permissions">
                <summary><b>الإعدادات<span>{granted}/{settings.length}</span></b><div className="pp-chips">{settings.map((group) => <span key={group.key} className={group.permissions.some((permission) => permission.granted) ? '' : 'no'}>{group.label}</span>)}</div><small>عرض تفاصيل الصلاحيات <Icon name="chevron-down" /></small></summary>
                <div className="pp-permissions">{settings.map((group) => <PermissionGroup key={group.key} group={group} />)}</div>
            </details>
        </div>
        <div className="pp-permission-note"><Icon name="info" /><span>تُعدَّل الصلاحيات من صفحة <b>الصلاحيات</b>. إن احتجت صلاحية إضافية تواصل مع المدير العام.</span></div>
    </section>;
}

function Devices({ devices, dateLabel }) {
    const [target, setTarget] = useState(null);
    const form = useForm({ password: '', type: 'all', id: null });
    const others = devices.filter((device) => !device.current);

    function open(device) {
        form.reset();
        form.clearErrors();
        form.setData({ password: '', type: device?.type ?? 'all', id: device?.id ?? null });
        setTarget(device ?? { label: 'كل الأجهزة الأخرى' });
    }

    function close() {
        setTarget(null);
        form.reset();
        form.clearErrors();
    }

    function logout(event) {
        event.preventDefault();
        form.delete('/profile/devices', { preserveScroll: true, onSuccess: close, onError: () => form.reset('password') });
    }

    return (
        <section className="pp-panel">
            <PanelTitle icon="devices">الأجهزة والجلسات</PanelTitle>
            <p>الأجهزة المسجّل دخولك منها الآن.</p>
            <div className="pp-devices">{devices.map((device) => <div className="pp-device" key={`${device.type}-${device.id}`}>
                <span className="pp-device-icon"><Icon name={device.type === 'mobile' ? 'phone' : 'devices'} /></span>
                <div><b>{device.label}{device.current && <em>هذا الجهاز</em>}</b><small>{device.current ? 'نشط الآن' : `آخر نشاط ${dateLabel(device.lastActiveAt)}`}</small></div>
                {!device.current && <button type="button" className="pp-logout" onClick={() => open(device)}>تسجيل خروج</button>}
            </div>)}</div>
            <div className="pp-actions"><button type="button" className="pp-button" disabled={!others.length} onClick={() => open(null)}><Icon name="logout" />تسجيل الخروج من كل الأجهزة الأخرى</button></div>
            <Modal show={target !== null} onClose={close} maxWidth="md" centered>
                <form onSubmit={logout} className="profile-page pp-dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="device-logout-title">
                    <h3 id="device-logout-title">تسجيل الخروج من {target?.label}؟</h3>
                    <p>يبقى هذا الجهاز مسجّل الدخول. أدخل كلمة المرور للتأكيد.</p>
                    <div className="pp-field"><label className="l" htmlFor="device_password">كلمة المرور</label><div className="pp-input"><PasswordInput id="device_password" dir="ltr" toggleTabIndex={0} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoComplete="current-password" autoFocus required /></div><InputError message={form.errors.deviceLogout?.password ?? form.errors.password} /></div>
                    <div className="pp-actions"><button type="button" className="pp-button" disabled={form.processing} onClick={close}>إلغاء</button><button type="submit" className="pp-button danger" disabled={form.processing}><Icon name="logout" />تسجيل الخروج</button></div>
                </form>
            </Modal>
        </section>
    );
}

export default function Edit({ user, permissionGroups, devices, businessTimezone, canUpdatePassword }) {
    const { activity } = usePage().props;
    const { preference, changePreference } = useTheme();
    const initials = user.name.trim().split(/\s+/).filter((word) => !['أبو', 'عبد'].includes(word)).slice(0, 2).map((word) => word.replace(/^ال/, '')[0] || '').join('');
    const dateLabel = (value) => value ? new Intl.DateTimeFormat('ar-u-nu-latn', { timeZone: businessTimezone, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(value)) : '—';

    return (
        <AuthenticatedLayout header={<PageHeader title="الملف الشخصي" />}>
            <Head title="الملف الشخصي" />
            <div className="profile-page" dir="rtl">
                
                <section className="pp-hero">
                    <span className="pp-avatar">{initials}</span>
                    <div><h2>{user.name}</h2><span className="pp-username">@{user.username}</span>
                        <div className="pp-tags"><span className="pp-tag role"><Icon name="badge" />{user.roleLabel}</span><span className="pp-tag"><Icon name="pin" />{user.branchName}</span><span className="pp-tag"><i className={user.isActive ? 'active' : ''} />حساب {user.isActive ? 'فعّال' : 'متوقف'}</span></div>
                    </div>
                    <div className="pp-facts"><div><small>عضو منذ</small><b>{user.memberSince}</b></div><div><small>آخر نشاط</small><b>{dateLabel(user.lastActiveAt)}</b></div><div><small>عملياتك هذا الأسبوع</small><b>{user.weeklyActions.toLocaleString('en')}</b></div></div>
                </section>
                <div className="pp-grid">
                    <div>
                        <UpdateProfileInformationForm user={user} />
                        {canUpdatePassword ? <UpdatePasswordForm /> : (
                            <section className="pp-panel">
                                <PanelTitle icon="key">كلمة المرور</PanelTitle>
                                <p>لتغيير كلمة المرور، تواصل مع مدير الفرع أو مدير النظام.</p>
                            </section>
                        )}
                    </div>
                    <div>
                        <Permissions groups={permissionGroups} />
                        <Devices devices={devices} dateLabel={dateLabel} />
                        <section className="pp-panel"><PanelTitle icon="sun">المظهر</PanelTitle><div className="pp-theme" role="group" aria-label="مظهر النظام">{THEMES.map((theme) => <button type="button" key={theme.value} aria-pressed={preference === theme.value} onClick={() => changePreference(theme.value)}><span className="sw">{theme.colors.map((color) => <i key={color} style={{ background: color }} />)}</span>{theme.label}</button>)}</div></section>
                        <section className="pp-panel"><PanelTitle icon="clock">آخر نشاطاتك</PanelTitle>
                            <div className="pp-feed">{activity?.recent.length ? activity.recent.slice(0, 5).map((item) => <div className="pp-feed-item" key={item.id}><span className={`pp-feed-icon ${item.action.includes('password') ? 'warn' : item.action.includes('reading') ? 'info' : item.action.includes('payment') ? 'success' : ''}`}><Icon name={item.action.includes('password') ? 'key' : item.action.includes('reading') ? 'gauge' : item.action.includes('payment') ? 'cash' : 'pencil'} /></span><div><b>{ACTION_MESSAGES[item.action] ?? 'إجراء محفوظ'}</b>{item.subject && <small>{item.subject}</small>}</div><time dateTime={item.createdAt}>{dateLabel(item.createdAt)}</time></div>) : <p className="pp-empty">لا توجد نشاطات محفوظة بعد.</p>}</div>
                        </section>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
