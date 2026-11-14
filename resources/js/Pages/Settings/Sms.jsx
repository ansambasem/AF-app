import { useEffect, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import InputError from '@/Components/InputError';
import Icon from '@/Components/Icon';
import InfoTip from '@/Components/InfoTip';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { notify } from '@/lib/notify';
import { SAMPLE_LOCAL_PHONE, missingForSending, phoneAs, requestPreview } from '@/lib/smsSettings';
import './ReadingSchedule.css';
import './SmsSettings.css';
import PageHeader from '@/Components/PageHeader';

const TEST_MESSAGE = 'رسالة تجربة من نظام التحصيل: إذا وصلتك فالربط يعمل.';
const FIELDS = [
    'enabled', 'provider_name', 'url', 'method', 'username_param', 'username', 'password_param', 'to_param', 'message_param',
    'sender_param', 'sender', 'phone_format', 'country_code', 'extra_params', 'success_match',
];

/** A panel's title, with what the user must know about it (`info`) behind an "!". */
function PanelTitle({ icon, info = null, children }) {
    return <h2><span className="rs-panel-icon"><Icon name={icon} /></span>{children}{info && <InfoTip label={`عن ${children}`}>{info}</InfoTip>}</h2>;
}

/** A labelled text field of the page; `code` sets it left to right in a fixed-width face, as API values are written. */
function Field({ id, label, hint, error, wide = false, code = false, value, onChange, ...input }) {
    return (
        <div className={`sm-field ${wide ? 'is-wide' : ''}`}>
            <span className="sm-label"><label htmlFor={id}>{label}</label>{hint && <InfoTip label={`عن حقل ${label}`}>{hint}</InfoTip>}</span>
            <input id={id} className={`sm-input ${code ? 'is-code' : ''}`} value={value ?? ''} onChange={(event) => onChange(event.target.value)}
                aria-invalid={Boolean(error)} aria-describedby={error ? `${id}-error` : undefined} {...input} />
            {error && <span id={`${id}-error`}><InputError message={error} /></span>}
        </div>
    );
}

/** Two or three choices as one control, the page's own segmented buttons. */
function Segments({ label, options, value, onChange, disabled = false }) {
    return (
        <div className="sm-segments" role="group" aria-label={label}>
            {options.map((option) => (
                <button key={option.value} type="button" aria-pressed={value === option.value} disabled={disabled} onClick={() => onChange(option.value)}>
                    {option.label}{option.hint && <small>{option.hint}</small>}
                </button>
            ))}
        </div>
    );
}

/**
 * A password or token: never shown once saved. Left blank, the saved one is
 * kept; "إزالة" clears it on save, and typing a new one replaces it.
 */
function SecretField({ id, label, hint, saved, cleared, value, error, onChange, onClear, onUndoClear }) {
    const [visible, setVisible] = useState(false);
    const state = cleared ? 'is-cleared' : saved && !value ? 'is-saved' : null;

    return (
        <div className="sm-field">
            <span className="sm-label">
                <label htmlFor={id}>{label}</label>
                {hint && !saved && <InfoTip label={`عن حقل ${label}`}>{hint}</InfoTip>}
                {state === 'is-saved' && <span className="sm-secret-state"><Icon name="lock" />محفوظة ومشفّرة</span>}
                {state === 'is-cleared' && <span className="sm-secret-state is-cleared"><Icon name="warning" />تُزال عند الحفظ</span>}
                {saved && !cleared && !value && <button type="button" className="sm-link" onClick={onClear}>إزالة</button>}
                {cleared && <button type="button" className="sm-link" onClick={onUndoClear}>تراجع</button>}
            </span>
            <div className="sm-secret">
                <input id={id} className="sm-input is-code" type={visible ? 'text' : 'password'} autoComplete="new-password" value={value}
                    placeholder={saved && !cleared ? '••••••••' : ''} onChange={(event) => onChange(event.target.value)}
                    aria-invalid={Boolean(error)} />
                <button type="button" className="sm-icon-button" onClick={() => setVisible(!visible)} aria-label={visible ? 'إخفاء' : 'إظهار'} title={visible ? 'إخفاء' : 'إظهار'}>
                    <Icon name={visible ? 'eye-off' : 'eye'} />
                </button>
            </div>
            <InputError message={error} />
        </div>
    );
}

/**
 * The company's SMS provider, on the settings pages' shared look: whether
 * messages go out now, the provider's address and fields as its API
 * documentation names them, the account's credentials (kept encrypted, never
 * shown again), the request a message makes with all of it, and a test
 * message to check it before turning it on.
 */
export default function Sms({ setting, delivery, week }) {
    const { flash } = usePage();
    const initial = { ...Object.fromEntries(FIELDS.map((key) => [key, setting[key] ?? ''])), enabled: setting.enabled, password: '', token: '', clear_password: false, clear_token: false };
    const { data, setData, put, processing, errors, isDirty, resetAndClearErrors, setDefaults } = useForm(initial);
    const [confirmingSave, setConfirmingSave] = useState(false);
    const [testPhone, setTestPhone] = useState('');
    const [testMessage, setTestMessage] = useState(TEST_MESSAGE);
    const [testing, setTesting] = useState(false);
    const [testErrors, setTestErrors] = useState({});
    const [testResult, setTestResult] = useState(null);
    const missing = missingForSending(data);
    const preview = requestPreview(data, { hasPassword: setting.hasPassword, hasToken: setting.hasToken, message: testMessage || 'نص الرسالة' });
    const sending = isDirty ? data.enabled && missing.length === 0 : delivery.delivers;
    const providerName = data.provider_name || 'المزوّد';

    useEffect(() => {
        if (flash?.smsTestResult) {
            setTestResult(flash.smsTestResult);
        }
    }, [flash]);

    let title = 'الرسائل لا تُرسل الآن';
    let hint = 'تُحفظ في سجل الرسائل فقط ولا تصل للمشتركين، حتى تُدخل بيانات المزوّد وتُفعّل البوابة.';

    if (isDirty && data.enabled) {
        title = `الرسائل ستُرسل عبر ${providerName}`;
        hint = 'بعد الحفظ تخرج كل رسائل SMS من النظام عبر هذا المزوّد.';
    } else if (isDirty && setting.enabled) {
        title = 'الرسائل ستتوقف عن الإرسال';
        hint = 'بعد الحفظ تُحفظ الرسائل في السجل فقط ولا تصل للمشتركين.';
    } else if (delivery.source === 'dashboard') {
        title = `الرسائل تُرسل الآن عبر ${providerName}`;
        hint = 'كل رسائل SMS من النظام تخرج عبر هذا المزوّد.';
    } else if (delivery.source === 'server') {
        title = 'الرسائل تُرسل الآن عبر إعداد السيرفر';
        hint = 'الربط مضبوط في ملف إعدادات السيرفر. عند تفعيل البوابة هنا يُستخدم إعداد هذه الصفحة بدلًا منه.';
    }

    function submit(event) {
        event.preventDefault();

        if (isDirty && !processing) {
            setConfirmingSave(true);
        }
    }

    function save() {
        setConfirmingSave(false);
        put('/settings/sms', {
            preserveScroll: true,
            onSuccess: () => {
                setData((current) => ({ ...current, password: '', token: '', clear_password: false, clear_token: false }));
                setDefaults();
            },
        });
    }

    function sendTest() {
        setTesting(true);
        setTestErrors({});
        setTestResult(null);
        router.post('/settings/sms/test', { ...data, test_phone: testPhone, test_message: testMessage }, {
            preserveScroll: true,
            preserveState: true,
            onError: (found) => setTestErrors(found),
            onSuccess: (page) => {
                if (page.props.flash?.smsTestResult?.ok === false) {
                    notify('رفضت بوابة الرسائل رسالة التجربة؛ راجع الرد أدناه.', 'warning');
                }
            },
            onFinish: () => setTesting(false),
        });
    }

    const confirmation = [
        data.enabled
            ? `تُفعَّل البوابة، وتُرسل كل رسائل SMS من النظام عبر ${providerName} من الرابط ${data.url}${data.sender ? ` باسم المرسل «${data.sender}»` : ''}.`
            : 'تبقى البوابة متوقفة: تُحفظ الرسائل في السجل فقط ولا تصل للمشتركين.',
        data.password ? 'تُستبدل كلمة المرور المحفوظة بالجديدة.' : data.clear_password ? 'تُزال كلمة المرور المحفوظة.' : null,
        data.token ? 'يُستبدل المفتاح (Token) المحفوظ بالجديد.' : data.clear_token ? 'يُزال المفتاح (Token) المحفوظ.' : null,
        data.enabled && !setting.lastTest?.ok ? 'لم تنجح رسالة تجربة بعد؛ يُفضّل إرسال تجربة قبل التفعيل.' : null,
    ].filter(Boolean).join(' ');

    return (
        <SettingsLayout header={<PageHeader title="إعدادات الرسائل" info="اربط مزوّد الرسائل القصيرة (SMS)، مثل جوال، لتصل الرسائل التي ترسلها من النظام إلى جوالات المشتركين. كل البيانات من وثيقة الربط (API) التي يعطيها المزوّد." />}>
            <Head title="إعدادات الرسائل" />
            <form onSubmit={submit} className="sms-settings" dir="rtl" autoComplete="off">
                <section className={`rs-status ${sending ? 'is-open' : ''}`} aria-live="polite">
                    <span className="rs-status-dot" aria-hidden="true"><i /></span>
                    <div className="rs-status-copy">
                        <b>{title}</b>
                        <p>{hint}{isDirty && <span className="rs-preview-note"> · معاينة قبل الحفظ</span>}</p>
                        <div className="sm-stats" aria-label="رسائل SMS">
                            <span className="sm-stat"><b>{week.sent.toLocaleString('en')}</b>أُرسلت آخر 7 أيام</span>
                            <span className={`sm-stat ${week.failed ? 'is-failed' : ''}`}><b>{week.failed.toLocaleString('en')}</b>فشلت</span>
                            <span className="sm-stat"><b>{week.pending.toLocaleString('en')}</b>بانتظار الإرسال</span>
                        </div>
                    </div>
                    <div>
                        <div className="rs-modes" role="group" aria-label="حالة البوابة">
                            <button type="button" aria-pressed={data.enabled} disabled={processing || missing.length > 0} onClick={() => setData('enabled', true)}>
                                <Icon name="send" />مفعّلة
                            </button>
                            <button type="button" aria-pressed={!data.enabled} disabled={processing} onClick={() => setData('enabled', false)}>
                                <Icon name="power" />متوقفة
                            </button>
                        </div>
                        {missing.length > 0 && <p className="sm-switch-note">للتفعيل أدخل: <b>{missing.join('، ')}</b></p>}
                    </div>
                </section>
                <InputError message={errors.enabled} className="mt-2" />

                <div className="rs-grid">
                    <div className="rs-column">
                        <section className="rs-panel">
                            <PanelTitle icon="send" info="رابط الإرسال وطريقته كما في وثيقة الربط. تُرسل كل رسالة طلبًا واحدًا إلى هذا الرابط.">المزوّد ورابط الإرسال</PanelTitle>
                            <div className="sm-fields">
                                <Field id="provider-name" label="اسم المزوّد" hint="للعرض فقط" placeholder="جوال" value={data.provider_name} error={errors.provider_name} onChange={(value) => setData('provider_name', value)} />
                                <div className="sm-field">
                                    <span className="sm-label">طريقة الإرسال</span>
                                    <Segments label="طريقة الإرسال" value={data.method} onChange={(value) => setData('method', value)}
                                        options={[{ value: 'post', label: 'POST', hint: 'نموذج' }, { value: 'get', label: 'GET', hint: 'في الرابط' }]} />
                                    <InputError message={errors.method} />
                                </div>
                                <Field id="url" label="رابط الإرسال (API URL)" wide code type="url" inputMode="url" placeholder="https://…/api/send" value={data.url} error={errors.url} onChange={(value) => setData('url', value)} />
                            </div>
                        </section>

                        <section className="rs-panel">
                            <PanelTitle icon="key" info="من حساب شركتكم عند المزوّد. تُحفظ كلمة المرور والمفتاح مشفّرين، ولا يظهران بعد الحفظ لأحد.">بيانات الدخول</PanelTitle>
                            <div className="sm-fields">
                                <div className="sm-field is-wide">
                                    <span className="sm-label">اسم المستخدم<small>اسم الحقل كما في الوثيقة، ثم القيمة</small></span>
                                    <div className="sm-pair">
                                        <input className="sm-input is-code" aria-label="اسم حقل المستخدم" placeholder="username" value={data.username_param} onChange={(event) => setData('username_param', event.target.value)} aria-invalid={Boolean(errors.username_param)} />
                                        <input className="sm-input is-code" aria-label="اسم المستخدم" placeholder="af-power" value={data.username} onChange={(event) => setData('username', event.target.value)} autoComplete="off" />
                                    </div>
                                    <InputError message={errors.username_param || errors.username} />
                                </div>
                                <div className="sm-field">
                                    <label htmlFor="password-param">اسم حقل كلمة المرور</label>
                                    <input id="password-param" className="sm-input is-code" placeholder="password" value={data.password_param} onChange={(event) => setData('password_param', event.target.value)} aria-invalid={Boolean(errors.password_param)} />
                                    <InputError message={errors.password_param} />
                                </div>
                                <SecretField id="password" label="كلمة المرور" hint="لحساب شركتكم عند المزوّد" saved={setting.hasPassword} cleared={data.clear_password} value={data.password} error={errors.password}
                                    onChange={(value) => setData((current) => ({ ...current, password: value, clear_password: false }))}
                                    onClear={() => setData('clear_password', true)} onUndoClear={() => setData('clear_password', false)} />
                                <div className="sm-field is-wide">
                                    <SecretField id="token" label="مفتاح API (Token)" hint="فقط إن أعطاكم المزوّد مفتاحًا بدل كلمة المرور" saved={setting.hasToken} cleared={data.clear_token} value={data.token} error={errors.token}
                                        onChange={(value) => setData((current) => ({ ...current, token: value, clear_token: false }))}
                                        onClear={() => setData('clear_token', true)} onUndoClear={() => setData('clear_token', false)} />
                                </div>
                            </div>
                        </section>

                        <section className="rs-panel">
                            <PanelTitle icon="chat" info="أسماء الحقول التي يقرأ منها المزوّد رقم المشترك ونص الرسالة واسم المرسل، كما تُكتب في وثيقة الربط حرفيًا.">حقول الرسالة</PanelTitle>
                            <div className="sm-fields">
                                <Field id="to-param" label="حقل رقم الجوال" code placeholder="to" value={data.to_param} error={errors.to_param} onChange={(value) => setData('to_param', value)} />
                                <Field id="message-param" label="حقل نص الرسالة" code placeholder="message" value={data.message_param} error={errors.message_param} onChange={(value) => setData('message_param', value)} />
                                <Field id="sender-param" label="حقل اسم المرسل" code placeholder="sender" value={data.sender_param} error={errors.sender_param} onChange={(value) => setData('sender_param', value)} />
                                <Field id="sender" label="اسم المرسل (Sender ID)" hint="المعتمد عند المزوّد" code maxLength={20} placeholder="AF-Power" value={data.sender} error={errors.sender} onChange={(value) => setData('sender', value)} />
                            </div>

                            <div className="sm-section">
                                <h3><Icon name="phone" />صيغة رقم الجوال</h3>
                                <p>كيف يريد المزوّد رقم المشترك. الأرقام في النظام تُكتب محليًا (<bdi dir="ltr">{SAMPLE_LOCAL_PHONE}</bdi>) وتُحوَّل تلقائيًا.</p>
                                <div className="sm-fields">
                                    <div className="sm-field">
                                        <span className="sm-label">الصيغة</span>
                                        <Segments label="صيغة رقم الجوال" value={data.phone_format} onChange={(value) => setData('phone_format', value)}
                                            options={[
                                                { value: 'international', label: 'دولية', hint: phoneAs('international', data.country_code) },
                                                { value: 'local', label: 'محلية', hint: phoneAs('local', data.country_code) },
                                            ]} />
                                        <InputError message={errors.phone_format} />
                                    </div>
                                    <Field id="country-code" label="رمز الدولة" hint="فلسطين 970" code inputMode="numeric" maxLength={4} placeholder="970" value={data.country_code} error={errors.country_code} onChange={(value) => setData('country_code', value.replace(/\D/g, ''))} />
                                </div>
                            </div>

                            <details className="sm-advanced" open={Boolean(data.extra_params || data.success_match || errors.extra_params || errors.success_match)}>
                                <summary><Icon name="chevron-down" />خيارات متقدمة<small>إن طلبتها وثيقة المزوّد</small></summary>
                                <div className="sm-fields">
                                    <Field id="extra-params" label="حقول ثابتة إضافية" hint="تُرسل مع كل رسالة" wide code placeholder="type=unicode&lang=ar" value={data.extra_params} error={errors.extra_params} onChange={(value) => setData('extra_params', value)} />
                                    <Field id="success-match" label="نص يدل على النجاح" hint="في رد المزوّد؛ فارغ = يكفي رد 200" wide code placeholder='"status":"success"' value={data.success_match} error={errors.success_match} onChange={(value) => setData('success_match', value)} />
                                </div>
                            </details>
                        </section>
                    </div>

                    <div className="rs-column">
                        <section className="rs-panel">
                            <PanelTitle icon="eye" info="هكذا يصل كل طلب إلى المزوّد بالإعدادات الحالية. قارنه بالمثال في وثيقة الربط.">الطلب الذي يُرسل</PanelTitle>
                            <div className="sm-request" aria-label="معاينة الطلب">
                                <div className="sm-request-line"><span className="sm-method">{preview.method}</span><span>{preview.url}</span></div>
                                <ul>
                                    {preview.headers.map((header) => <li key={header.name} className="is-secret"><span>{header.name}:</span><span>{header.value}</span></li>)}
                                    {preview.fields.length === 0 && <li className="sm-request-empty"><span>—</span><span>لا توجد حقول بعد</span></li>}
                                    {preview.fields.map((field) => (
                                        <li key={field.name} className={`is-${field.role ?? 'extra'} ${field.secret ? 'is-secret' : ''}`}><span>{field.name} =</span><span>{field.value}</span></li>
                                    ))}
                                </ul>
                            </div>
                            <div className="sm-request-legend">
                                <span><i style={{ background: '#86efac' }} />يتغير مع كل رسالة</span><span><i style={{ background: '#fbbf24' }} />مخفي للأمان</span>
                            </div>
                        </section>

                        <section className="rs-panel">
                            <PanelTitle icon="phone" info="تُرسل بالإعدادات المكتوبة الآن، حتى قبل الحفظ، إلى رقمك فقط. تُحتسب على رصيد حسابكم عند المزوّد.">رسالة تجربة</PanelTitle>
                            <div className="sm-fields">
                                <Field id="test-phone" label="رقم الجوال" wide code type="tel" inputMode="tel" placeholder={SAMPLE_LOCAL_PHONE} value={testPhone} error={testErrors.test_phone} onChange={setTestPhone} />
                                <div className="sm-field is-wide">
                                    <label htmlFor="test-message">نص الرسالة<small>{testMessage.length} حرف</small></label>
                                    <textarea id="test-message" className="sm-input" maxLength={300} value={testMessage} onChange={(event) => setTestMessage(event.target.value)} aria-invalid={Boolean(testErrors.test_message)} />
                                    <InputError message={testErrors.test_message} />
                                </div>
                            </div>
                            <div className="sm-test-actions">
                                <button type="button" className="sm-send" onClick={sendTest} disabled={testing || processing || !data.url || !testPhone.trim()}>
                                    <Icon name="send" />{testing ? 'جارٍ الإرسال…' : 'إرسال رسالة تجربة'}
                                </button>
                                {!data.url && <span className="sm-test-note">أدخل رابط الإرسال أولًا.</span>}
                            </div>
                            {testResult && (
                                <div className={`sm-result ${testResult.ok ? 'is-ok' : 'is-failed'}`} role="status">
                                    <Icon name={testResult.ok ? 'check' : 'alert'} />
                                    <div>
                                        {testResult.ok
                                            ? <>قبل المزوّد الرسالة إلى <bdi dir="ltr">{testResult.phone}</bdi>. تأكد أنها وصلت الجوال، ثم فعّل البوابة واحفظ.</>
                                            : <>رفض المزوّد الرسالة. راجع الحقول مع وثيقة الربط:<code>{testResult.error}</code></>}
                                    </div>
                                </div>
                            )}
                            {!testResult && setting.lastTest && (
                                <div className={`sm-result ${setting.lastTest.ok ? 'is-ok' : 'is-failed'}`}>
                                    <Icon name={setting.lastTest.ok ? 'check' : 'alert'} />
                                    <div>
                                        آخر تجربة <bdi>{setting.lastTest.at}</bdi>: {setting.lastTest.ok ? 'نجحت.' : 'فشلت.'}
                                        {!setting.lastTest.ok && setting.lastTest.error && <code>{setting.lastTest.error}</code>}
                                    </div>
                                </div>
                            )}
                        </section>

                        <section className="rs-panel">
                            <PanelTitle icon="document">ما تطلبه من المزوّد</PanelTitle>
                            <ol className="sm-checklist">
                                <li><div>وثيقة الربط (HTTP API)<small>الرابط، الطريقة، أسماء الحقول، وشكل رد النجاح.</small></div></li>
                                <li><div>بيانات الدخول<small>اسم مستخدم وكلمة مرور، أو مفتاح API.</small></div></li>
                                <li><div>اعتماد اسم المرسل<small>Sender ID باسم شركتكم، بالإنجليزية غالبًا.</small></div></li>
                                <li><div>دعم العربية (Unicode)<small>الجزء العربي نحو 70 حرفًا؛ اسأل عن سعر الجزء.</small></div></li>
                                <li><div>عنوان IP السيرفر<small>إن كان المزوّد يقبل الطلبات من عناوين محددة فقط.</small></div></li>
                            </ol>
                            {setting.updatedByName && <div className="rs-who"><span className="rs-avatar">{setting.updatedByName.split(' ').slice(0, 2).map((name) => name[0]).join('')}</span><span>آخر تعديل للإعدادات: {setting.updatedByName} · <bdi>{setting.updatedAt}</bdi></span></div>}
                        </section>
                    </div>
                </div>

                {isDirty && <div className="rs-savebar"><span role="status">لديك تغييرات غير محفوظة</span><button type="button" className="rs-revert" disabled={processing} onClick={() => resetAndClearErrors()}>تراجع</button><button type="submit" className="rs-save" disabled={processing}>{processing ? 'جارٍ الحفظ...' : 'حفظ'}</button></div>}
            </form>
            <ConfirmDialog show={confirmingSave} onConfirm={save} onCancel={() => setConfirmingSave(false)} title="حفظ إعدادات الرسائل؟"
                message={confirmation} confirmLabel="نعم، احفظ" cancelLabel="مراجعة الإعدادات" icon="send" />
        </SettingsLayout>
    );
}
