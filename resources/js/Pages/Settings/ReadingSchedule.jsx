import { useEffect, useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import InputError from '@/Components/InputError';
import Icon from '@/Components/Icon';
import InfoTip from '@/Components/InfoTip';
import ConfirmDialog from '@/Components/ConfirmDialog';
import BranchScopeBar from './BranchScopeBar';
import { WEEK_DAYS, formatWeekDay, weekDayName } from '@/lib/weekDays';
import { businessClock, entryDaysFrom, isEntryOpen, nextScheduleChange, shiftEntryDays, upcomingWeeks, validEntryHours, weekDates } from '@/lib/readingSchedule';
import './ReadingSchedule.css';
import PageHeader from '@/Components/PageHeader';

const MODES = {
    automatic: { label: 'تلقائي', icon: 'clock' },
    open: { label: 'مفتوح الآن', icon: 'unlock' },
    closed: { label: 'مغلق الآن', icon: 'lock' },
};
const shortDate = (date) => date.slice(5).split('-').reverse().join('/');
const dayLabel = (value) => WEEK_DAYS.find((day) => day.value === value).label;
const dayCount = (count) => count === 1 ? 'يوم واحد' : count === 2 ? 'يومان' : count <= 10 ? `${count} أيام` : `${count} يومًا`;

/** A panel's title, with what the user must know about it (`info`) behind an "!". */
function PanelTitle({ icon, info = null, children }) {
    return <h2><span className="rs-panel-icon"><Icon name={icon} /></span>{children}{info && <InfoTip label={`عن ${children}`}>{info}</InfoTip>}</h2>;
}

function DayChip({ day, selected, type, name, disabled = false, subtitle, onChange }) {
    return (
        <label className={`rs-day ${selected ? 'is-selected' : ''}`}>
            <input type={type} name={name} checked={selected} disabled={disabled} onChange={onChange} aria-label={day.label} />
            <span className="rs-check"><Icon name="check" strokeWidth={3} /></span>
            <b>{day.label}</b>
            <small>{subtitle || '\u00a0'}</small>
        </label>
    );
}

export default function ReadingSchedule({ setting, branch, branchScope, branches, followsCompany, branchesWithOwn, firstWeeks, currentWeeks, businessNow, businessTimezone }) {
    const { data, setData, put, processing, errors, isDirty, resetAndClearErrors, setDefaults } = useForm({
        branch_id: branch?.id ?? null,
        reading_day: setting.reading_day,
        assignment_lead_days: setting.assignment_lead_days ?? 1,
        open_days: [...setting.open_days].sort((a, b) => a - b),
        mode: setting.mode,
        opens_at: setting.opens_at,
        closes_at: setting.closes_at,
    });
    const [confirmingSave, setConfirmingSave] = useState(false);
    const [now, setNow] = useState(() => new Date(businessNow));
    useEffect(() => {
        const receivedAt = Date.now();
        const serverNow = Date.parse(businessNow);
        setNow(new Date(serverNow));
        const timer = setInterval(() => setNow(new Date(serverNow + Date.now() - receivedAt)), 10_000);
        return () => clearInterval(timer);
    }, [businessNow]);

    const clock = businessClock(now, businessTimezone);
    const openNow = isEntryOpen(data, clock);
    const nextChange = nextScheduleChange(data, clock);
    const automatic = data.mode === 'automatic';
    const entryDays = entryDaysFrom(data.reading_day).map((value) => WEEK_DAYS.find((day) => day.value === value));
    const readingDayChanged = data.reading_day !== setting.reading_day;
    const firstWeek = firstWeeks[data.reading_day];
    const currentWeek = currentWeeks[data.reading_day];
    const days = weekDates(currentWeek);
    const weeks = upcomingWeeks(firstWeek);
    const hoursError = !validEntryHours(data);
    const canSave = !processing && data.open_days.length > 0 && !hoursError;
    const canApplyCompany = !branch && branchesWithOwn > 0;
    const history = setting.reading_day_history.map((change, index, all) => ({
        ...change, to: all[index + 1]?.reading_day ?? setting.reading_day,
    })).reverse();

    let statusHint = 'لم يُحدَّد أي يوم للفتح.';
    if (data.mode === 'open') {
        statusHint = 'فُتح يدويًا، ويبقى مفتوحًا حتى تعيده إلى الوضع التلقائي.';
    } else if (data.mode === 'closed') {
        statusHint = 'أُغلق يدويًا، ويبقى مغلقًا حتى تعيده إلى الوضع التلقائي.';
    } else if (hoursError) {
        statusHint = 'حدد ساعات فتح صحيحة لمعاينة الحالة.';
    } else if (nextChange) {
        statusHint = `${nextChange.open ? 'يُفتح' : 'يُغلق'} تلقائيًا ${weekDayName(nextChange.date)} ${shortDate(nextChange.date)} الساعة ${nextChange.time}.`;
    } else if (openNow) {
        statusHint = 'مفتوح طوال أيام الأسبوع.';
    }

    function selectOpenDays(openDays) {
        setData('open_days', [...openDays].sort((a, b) => a - b));
    }

    function changeReadingDay(day) {
        setData((current) => ({
            ...current,
            reading_day: day,
            open_days: shiftEntryDays(current.open_days, current.reading_day, day),
        }));
    }

    function submit(event) {
        event.preventDefault();
        if (canSave && (isDirty || canApplyCompany)) {
            setConfirmingSave(true);
        }
    }

    function save() {
        setConfirmingSave(false);
        put('/settings/reading-schedule', { preserveScroll: true, onSuccess: () => setDefaults() });
    }

    const confirmation = [
        readingDayChanged ? `يصبح يوم القراءة ${dayLabel(data.reading_day)}، وأول أسبوع عليه من ${formatWeekDay(firstWeek.start)} إلى ${formatWeekDay(firstWeek.end)}.` : null,
        `طريقة الفتح: ${MODES[data.mode].label}.`,
        `أيام الإدخال التلقائي: ${entryDays.filter((day) => data.open_days.includes(day.value)).map((day) => day.label).join('، ')}، من ${data.opens_at} إلى ${data.closes_at} بتوقيت الشركة.`,
        branch
            ? `يتغير الموعد في الموقع والتطبيق الميداني لقرّاء الفرع «${branch.name}» ومدخلي بياناته، دون غيره من الفروع.`
            : 'ستُطبّق هذه المواعيد على جميع الفروع، وتستبدل أي مواعيد مخصصة لها، مع الحفاظ على تواريخ القراءات والأسابيع السابقة.',
    ].filter(Boolean).join(' ');

    return (
        <SettingsLayout header={<PageHeader title="مواعيد القراءات" info="حدد يوم القراءة وأيام وساعات الإدخال لكل الفروع، أو خصصها لفرع. تعميم إعدادات الشركة يتجاوز المواعيد المخصصة للفروع." />}>
            <Head title="مواعيد القراءات" />
            <form onSubmit={submit} className="reading-schedule" dir="rtl">
                <BranchScopeBar
                    path="/settings/reading-schedule"
                    scope={branchScope}
                    branch={branch}
                    branches={branches}
                    followsCompany={followsCompany}
                    branchesWithOwn={branchesWithOwn}
                    locked={isDirty}
                />

                <section className={`rs-status ${openNow ? 'is-open' : ''}`} aria-live="polite">
                    <span className="rs-status-dot" aria-hidden="true"><i /></span>
                    <div className="rs-status-copy">
                        <b>{openNow ? 'إدخال القراءات مفتوح الآن' : 'إدخال القراءات مغلق الآن'}</b>
                        <p>{statusHint} · ينطبق على الجميع ما عدا المدير العام {isDirty && <span className="rs-preview-note">· معاينة قبل الحفظ</span>}</p>
                    </div>
                    <div className="rs-modes" role="group" aria-label="طريقة الفتح">
                        {Object.entries(MODES).map(([value, mode]) => (
                            <button key={value} type="button" aria-pressed={data.mode === value} onClick={() => setData('mode', value)} disabled={processing}>
                                <Icon name={mode.icon} />{mode.label}
                            </button>
                        ))}
                    </div>
                </section>
                <InputError message={errors.mode} className="mt-2" />

                <div className="rs-grid">
                    <div className="rs-column">
                        <section className="rs-panel">
                            <PanelTitle icon="flag" info="ينتهي كل أسبوع قراءة في هذا اليوم، ويبدأ الأسبوع التالي في اليوم الذي يليه.">يوم القراءة الأسبوعي</PanelTitle>
                            <div className="rs-days" role="radiogroup" aria-label="يوم القراءة الأسبوعي">
                                {WEEK_DAYS.map((day) => (
                                    <DayChip key={day.value} day={day} type="radio" name="reading_day" selected={data.reading_day === day.value}
                                        subtitle={day.value === setting.reading_day ? 'الحالي' : ''} disabled={processing} onChange={() => changeReadingDay(day.value)} />
                                ))}
                            </div>
                            <InputError message={errors.reading_day} className="mt-2" />
                            {readingDayChanged && (
                                <div className="rs-hint rs-warn" role="status">
                                    <Icon name="warning" />
                                    <div>تبقى الأسابيع المنتهية كما هي، وآخرها انتهى <b>{formatWeekDay(setting.latestWeekEnd)}</b>. أول أسبوع على يوم <b>{dayLabel(data.reading_day)}</b> من <b>{shortDate(firstWeek.start)}</b> إلى <b>{shortDate(firstWeek.end)}</b> ({dayCount(weekDates(firstWeek).length)})، ثم تعود الأسابيع كاملة سبعة أيام.</div>
                                </div>
                            )}
                        </section>

                        <section className="rs-panel">
                            <PanelTitle icon="door">أيام فتح الإدخال</PanelTitle>
                            <p>{automatic ? 'اختر أيام إدخال قراءة الأسبوع، بدءًا من يوم القراءة وحتى اليوم السابق للقراءة التالية، خلال ساعات الفتح بتوقيت الشركة.' : 'تُستخدم في الوضع التلقائي فقط. الوضع اليدوي يتجاوز الأيام والساعات.'}</p>
                            <p>تبقى القراءة الناقصة لنفس الأسبوع حتى بداية يوم القراءة التالي؛ أيام الفتح بعد يوم القراءة مخصصة لاستكمالها، ولا تفتح قراءة الأسبوع القادم مسبقًا.</p>
                            <div className={`rs-days rs-open-days ${automatic ? '' : 'is-disabled'}`} role="group" aria-label="أيام فتح الإدخال">
                                {entryDays.map((day) => (
                                    <DayChip key={day.value} day={day} type="checkbox" selected={data.open_days.includes(day.value)}
                                        subtitle={day.value === data.reading_day ? 'يوم القراءة' : ''} disabled={!automatic || processing}
                                        onChange={() => selectOpenDays(data.open_days.includes(day.value) ? data.open_days.filter((value) => value !== day.value) : [...data.open_days, day.value])} />
                                ))}
                            </div>
                            {automatic && (
                                <div className="rs-quick">
                                    {['يوم القراءة فقط', 'يوم القراءة واليوم الذي يليه', 'يوم القراءة ويومان بعده'].map((label, index) => (
                                        <button key={label} type="button" disabled={processing} onClick={() => selectOpenDays(Array.from({ length: index + 1 }, (_, offset) => (data.reading_day + offset) % 7))}>{label}</button>
                                    ))}
                                </div>
                            )}
                            <InputError message={errors.open_days || (data.open_days.length === 0 ? 'اختر يومًا واحدًا على الأقل لفتح الإدخال.' : null)} className="mt-2" />
                            {automatic && data.open_days.length > 0 && !data.open_days.includes(data.reading_day) && (
                                <div className="rs-hint rs-info"><Icon name="info" /><div>يوم القراءة نفسه غير مفتوح للإدخال؛ تُدخل القراءات في الأيام المحددة فقط.</div></div>
                            )}

                            <div className="rs-hours">
                                <h3><Icon name="clock" />ساعات فتح الإدخال</h3>
                                <p>من بداية الوقت المحدد إلى نهاية الدقيقة المحددة، في كل يوم مفتوح.</p>
                                <div className="rs-time-fields">
                                    <div className="rs-time-field">
                                        <label htmlFor="opens-at">من الساعة</label>
                                        <input id="opens-at" type="time" step="60" required value={data.opens_at} disabled={!automatic || processing}
                                            onInput={(event) => setData('opens_at', event.currentTarget.value)} aria-invalid={!!errors.opens_at} aria-describedby="opens-at-error" />
                                        <span id="opens-at-error"><InputError message={errors.opens_at} /></span>
                                    </div>
                                    <div className="rs-time-field">
                                        <label htmlFor="closes-at">إلى الساعة</label>
                                        <input id="closes-at" type="time" step="60" required value={data.closes_at} disabled={!automatic || processing}
                                            onInput={(event) => setData('closes_at', event.currentTarget.value)} aria-invalid={!!errors.closes_at || hoursError} aria-describedby="closes-at-error" />
                                        <span id="closes-at-error"><InputError message={errors.closes_at || (hoursError ? 'يجب أن تكون ساعة النهاية بعد البداية في اليوم نفسه.' : null)} /></span>
                                    </div>
                                </div>
                                <span className="rs-timezone">بتوقيت الشركة · {businessTimezone}</span>
                            </div>
                        </section>
                        <section className="rs-panel">
                            <PanelTitle icon="calendar">إتاحة مهام القرّاء</PanelTitle>
                            <p>يُجهّز التوزيع الثابت تلقائيًا قبل موعد القراءة، لتتاح المهام للمكلّفين في الموعد المحدد.</p>
                            <label htmlFor="assignment-lead-days" className="mb-2 block text-sm font-semibold text-gray-700">إتاحة المهام قبل يوم القراءة</label>
                            <select id="assignment-lead-days" value={data.assignment_lead_days} onChange={(event) => setData('assignment_lead_days', Number(event.target.value))} disabled={processing} className="w-full text-sm">
                                <option value={0}>في يوم القراءة</option>
                                <option value={1}>قبل يوم واحد</option>
                                {[2, 3, 4, 5, 6].map((days) => <option key={days} value={days}>قبل {days} أيام</option>)}
                            </select>
                            <InputError message={errors.assignment_lead_days} />
                            <p>إتاحة المهام للتحضير لا تفتح إدخال القراءات قبل موعده.</p>
                        </section>
                    </div>

                    <div className="rs-column">
                        <section className="rs-panel">
                            <PanelTitle icon="eye">معاينة الأسبوع الحالي</PanelTitle>
                            <p>من {weekDayName(currentWeek.start)} {shortDate(currentWeek.start)} إلى {weekDayName(currentWeek.end)} {shortDate(currentWeek.end)}{days.length !== 7 ? ` · أسبوع انتقالي (${dayCount(days.length)})` : ''}{isDirty ? ' · حسب الإعدادات الجديدة' : ''}</p>
                            <div className="rs-week" style={{ '--week-days': days.length }}>
                                {days.map((day) => {
                                    const open = data.mode === 'open' || (automatic && validEntryHours(data) && data.open_days.includes(day.weekday));
                                    const today = day.date === clock.date;
                                    const reading = day.date === currentWeek.end;
                                    return (
                                        <div key={day.date} className={`rs-week-day ${open ? 'is-open' : ''} ${today ? 'is-today' : ''}`}
                                            title={`${formatWeekDay(day.date)} · ${open ? (automatic ? `${data.opens_at} – ${data.closes_at}` : 'مفتوح طوال اليوم') : 'مغلق'}`}>
                                            <b>{dayLabel(day.weekday)}</b><span className="rs-date">{shortDate(day.date)}</span>
                                            {reading && <span className="rs-tag rs-reading-tag">القراءة</span>}
                                            {today && <span className="rs-tag rs-today-tag">اليوم</span>}
                                            <span className={`rs-tag ${open ? 'rs-open-tag' : 'rs-closed-tag'}`}>{open ? 'مفتوح' : 'مغلق'}</span>
                                        </div>
                                    );
                                })}
                            </div>
                            <div className="rs-legend">
                                <span><i className="rs-open-key" />الإدخال مفتوح</span><span><i className="rs-reading-key" />يوم القراءة (نهاية الأسبوع)</span><span><i className="rs-today-key" />اليوم</span>
                            </div>
                            {automatic && <p className="rs-preview-hours">أيام الفتح: من <b dir="ltr">{data.opens_at || '—'}</b> إلى <b dir="ltr">{data.closes_at || '—'}</b></p>}
                        </section>

                        <section className="rs-panel">
                            <PanelTitle icon="menu">الأسابيع القادمة</PanelTitle>
                            <ol className="rs-weeks">
                                {weeks.map((week, index) => {
                                    const length = weekDates(week).length;
                                    const current = clock.date >= week.start && clock.date <= week.end;
                                    return (
                                        <li key={week.start} className={`${current ? 'is-current' : ''} ${length !== 7 ? 'is-transition' : ''}`}>
                                            <span className="rs-week-number">{index + 1}</span>
                                            <span><b>{shortDate(week.start)}</b> ← <b>{shortDate(week.end)}</b></span>
                                            <span className="rs-week-length">{length !== 7 ? `انتقالي · ${dayCount(length)}` : current ? 'الأسبوع الحالي' : `ينتهي ${weekDayName(week.end)}`}</span>
                                        </li>
                                    );
                                })}
                            </ol>
                        </section>

                        <section className="rs-panel">
                            <PanelTitle icon="history">سجل التغييرات</PanelTitle>
                            {history.length ? (
                                <ul className="rs-history">
                                    {history.map((change) => <li key={change.last_week_end}><b>من {dayLabel(change.reading_day)} إلى {dayLabel(change.to)}</b><small>آخر أسبوع على {dayLabel(change.reading_day)} انتهى {formatWeekDay(change.last_week_end)}</small></li>)}
                                </ul>
                            ) : <p className="rs-empty-history">لم يتغير يوم القراءة الأسبوعي بعد.</p>}
                            {setting.updatedByName && <div className="rs-who"><span className="rs-avatar">{setting.updatedByName.split(' ').slice(0, 2).map((name) => name[0]).join('')}</span><span>آخر تعديل للإعدادات: {setting.updatedByName} · <bdi>{setting.updatedAt}</bdi></span></div>}
                        </section>
                    </div>
                </div>

                {(isDirty || canApplyCompany) && <div className="rs-savebar"><span role="status">{isDirty ? 'لديك تغييرات غير محفوظة' : 'يمكنك إعادة تعميم إعدادات الشركة على جميع الفروع'}</span>{isDirty && <button type="button" className="rs-revert" disabled={processing} onClick={() => resetAndClearErrors()}>تراجع</button>}<button type="submit" className="rs-save" disabled={!canSave}>{processing ? 'جارٍ الحفظ...' : 'حفظ'}</button></div>}
            </form>
            <ConfirmDialog show={confirmingSave} onConfirm={save} onCancel={() => setConfirmingSave(false)} title="حفظ مواعيد القراءات؟"
                message={confirmation} confirmLabel="نعم، احفظ" cancelLabel="مراجعة الإعدادات" icon="calendar" />
        </SettingsLayout>
    );
}
