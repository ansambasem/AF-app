import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import AddButton from '@/Components/AddButton';
import ClassificationChips from '@/Components/ClassificationChips';
import Icon from '@/Components/Icon';
import { useDeleteRecord } from '@/hooks/useDeleteRecord';
import { formatMoney, formatNumber, normalizeDecimalInput } from '@/lib/format';
import { rateChange, stepRate } from '@/lib/tariffs';
import TariffModal from './TariffModal';
import PageHeader from '@/Components/PageHeader';

const CATEGORY_ICONS = { residential: 'home', commercial: 'shop' };
const RATE_STEPS = [-0.25, -0.1, 0.1, 0.25];

/** The price with two decimals: 3 → "3.00". */
function price(amount) {
    return Number(amount ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** One figure in a card's row of three. */
function Stat({ dark, label, value, unit }) {
    return (
        <div className={`rounded-2xl border px-3.5 py-2.5 ${dark ? 'border-white/10 bg-white/5' : 'border-gray-100 bg-gray-50'}`}>
            <small className={`block text-[12.5px] ${dark ? 'text-white/60' : 'text-gray-500'}`}>{label}</small>
            <b className="font-display text-xl font-extrabold">{value}</b>
            {unit && <em className={`ms-1 text-xs not-italic ${dark ? 'text-white/60' : 'text-gray-500'}`}>{unit}</em>}
        </div>
    );
}

/**
 * The inline price editor under a card: the new price with quick steps,
 * what it changes, and save. Enter saves and Esc cancels.
 */
function RateEditor({ tariff, dark, onDone }) {
    const [text, setText] = useState(price(tariff.rate));
    const [saving, setSaving] = useState(false);
    const change = rateChange(tariff.rate, text);
    const average = Number(tariff.averageConsumption ?? 0);
    const tone =
        change.difference > 0
            ? dark
                ? 'text-red-300'
                : 'text-brand-600'
            : change.difference < 0
              ? dark
                  ? 'text-emerald-300'
                  : 'text-emerald-700'
              : '';
    const box = dark ? 'border-white/10 bg-white/5' : 'border-gray-100 bg-surface';
    const muted = dark ? 'text-white/60' : 'text-gray-500';

    function save() {
        if (!change.valid || !change.changed || saving) {
            return;
        }

        router.put(
            `/tariffs/${tariff.id}`,
            { category: tariff.category, rate: change.rate },
            { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false), onSuccess: onDone },
        );
    }

    function onKeyDown(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            save();
        } else if (event.key === 'Escape') {
            event.stopPropagation();
            onDone();
        }
    }

    return (
        <div
            className={`relative grid min-w-0 gap-3 rounded-[20px] border-[1.5px] p-4 ${dark ? 'border-white/15 bg-white/[0.04]' : 'border-gray-200 bg-gray-50'}`}
        >
            <div className="flex flex-wrap items-center gap-3">
                <label
                    className={`flex min-w-[210px] flex-1 items-center gap-2 rounded-2xl border-[1.5px] px-3.5 py-1.5 focus-within:ring-4 ${
                        dark
                            ? 'border-white/20 bg-gray-950/60 focus-within:border-white focus-within:ring-white/10'
                            : 'border-gray-200 bg-surface focus-within:border-brand-500 focus-within:ring-brand-500/15'
                    }`}
                >
                    <input
                        autoFocus
                        value={text}
                        inputMode="decimal"
                        dir="ltr"
                        aria-label={`السعر الجديد لتعرفة ${tariff.categoryLabel}`}
                        onFocus={(event) => event.target.select()}
                        onChange={(event) => setText(normalizeDecimalInput(event.target.value))}
                        onKeyDown={onKeyDown}
                        className="w-0 min-w-0 flex-1 border-0 bg-transparent p-0 text-end font-display text-[32px] font-extrabold text-inherit focus:ring-0"
                    />
                    <span className={`text-[15px] font-bold ${muted}`}>₪ للكيلو</span>
                </label>
                <div className="flex gap-1.5">
                    {RATE_STEPS.map((step) => (
                        <button
                            key={step}
                            type="button"
                            onClick={() => setText(stepRate(text, step))}
                            className={`h-9 rounded-full border px-3 text-[13.5px] font-semibold transition ${
                                dark
                                    ? 'border-white/15 bg-white/5 text-white/90 hover:bg-white/10'
                                    : 'border-gray-200 bg-surface text-gray-800 hover:border-gray-300'
                            }`}
                        >
                            <span dir="ltr">
                                {step > 0 ? '+' : '−'}
                                {Math.abs(step)}
                            </span>
                        </button>
                    ))}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <div className={`rounded-xl border px-3 py-2 ${box}`}>
                    <small className={`block text-xs ${muted}`}>التغيير</small>
                    <b className={`font-display ${tone}`} dir="ltr">
                        {change.valid
                            ? `${change.difference > 0 ? '+' : change.difference < 0 ? '−' : ''}${price(Math.abs(change.difference))} ₪`
                            : '—'}
                    </b>
                </div>
                <div className={`rounded-xl border px-3 py-2 ${box}`}>
                    <small className={`block text-xs ${muted}`}>بالنسبة</small>
                    <b className={`font-display ${tone}`} dir="ltr">
                        {change.valid ? `${change.percent > 0 ? '+' : ''}${change.percent.toFixed(1)}%` : '—'}
                    </b>
                </div>
                <div className={`rounded-xl border px-3 py-2 ${box}`}>
                    <small className={`block text-xs ${muted}`}>متوسط القراءة الجديد</small>
                    <b className="font-display">{average > 0 && change.valid ? `${price(average * change.rate)} ₪` : '—'}</b>
                </div>
            </div>

            {change.large && (
                <p className={`flex items-center gap-1.5 text-[13px] ${dark ? 'text-amber-300' : 'text-amber-700'}`}>
                    <Icon name="warning" className="h-4 w-4" />
                    تغيير كبير ({Math.abs(change.percent).toFixed(0)}%). تأكد منه قبل الحفظ.
                </p>
            )}
            <p className={`flex items-start gap-2 text-[13px] leading-relaxed ${muted}`}>
                <Icon name="info" className="mt-0.5 h-4 w-4 shrink-0" />
                يُطبَّق السعر الجديد على القراءات التي تُسجَّل من الآن، لـ {formatNumber(tariff.subscriptionsCount)} مشترك. القراءات السابقة تبقى على
                سعرها.
            </p>

            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onClick={onDone}
                    className={`h-[42px] rounded-[13px] border px-4 text-[15px] font-bold transition ${
                        dark ? 'border-white/15 bg-white/5 text-white hover:bg-white/10' : 'border-gray-200 bg-surface text-gray-800 hover:bg-gray-50'
                    }`}
                >
                    إلغاء
                </button>
                <button
                    type="button"
                    onClick={save}
                    disabled={!change.valid || !change.changed || saving}
                    className="ms-auto h-[42px] rounded-[13px] bg-brand-gradient px-5 text-[15px] font-bold text-white shadow-glow transition hover:brightness-110 disabled:opacity-45 disabled:shadow-none"
                >
                    {saving ? 'جارٍ الحفظ...' : change.valid ? `حفظ ${price(change.rate)} ₪` : 'حفظ السعر'}
                </button>
            </div>
        </div>
    );
}

/** A tariff's card: its price, since when, its figures, the inline editor and its price history. */
function TariffCard({ tariff, dark, editing, onEdit, onDoneEditing, onDelete }) {
    const average = tariff.averageConsumption;
    const muted = dark ? 'text-white/60' : 'text-gray-500';

    return (
        <section
            className={`relative flex flex-col gap-4 overflow-hidden rounded-[26px] p-5 sm:p-6 ${
                dark ? 'bg-graphite-gradient text-white shadow-lift' : 'border border-gray-100 bg-surface text-gray-900 shadow-card'
            }`}
        >
            {dark && (
                <>
                    <span className="absolute inset-x-0 top-0 h-[3px] bg-spectrum" aria-hidden="true" />
                    <span
                        className="pointer-events-none absolute -bottom-36 -start-16 h-[340px] w-[340px] rounded-full bg-[radial-gradient(closest-side,rgb(165_29_38/0.22),transparent)]"
                        aria-hidden="true"
                    />
                </>
            )}

            <div className="relative flex items-center gap-3">
                <span
                    className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-[15px] ${dark ? 'bg-white/10' : 'bg-gray-100 text-gray-700'}`}
                >
                    <Icon name={CATEGORY_ICONS[tariff.category] ?? 'currency'} className="h-6 w-6" />
                </span>
                <div className="min-w-0">
                    <h3 className="font-luxe text-[22px] font-bold">{tariff.categoryLabel}</h3>
                    <small className={`block text-[13.5px] ${muted}`}>{formatNumber(tariff.subscriptionsCount)} مشترك على هذه التعرفة</small>
                </div>
                <div className="ms-auto flex items-center gap-2">
                    {tariff.canDelete && !editing && (
                        <button
                            type="button"
                            onClick={onDelete}
                            title="حذف التعرفة"
                            aria-label={`حذف تعرفة ${tariff.categoryLabel}`}
                            className={`flex h-11 w-11 items-center justify-center rounded-[13px] border transition ${
                                dark
                                    ? 'border-white/15 bg-white/5 text-red-300 hover:bg-white/10'
                                    : 'border-brand-500/25 bg-brand-500/5 text-brand-600 hover:border-transparent hover:bg-brand-600 hover:text-white'
                            }`}
                        >
                            <Icon name="trash" className="h-[18px] w-[18px]" />
                        </button>
                    )}
                    {tariff.canUpdate && !editing && (
                        <button
                            type="button"
                            onClick={onEdit}
                            className={`inline-flex h-11 items-center gap-2 whitespace-nowrap rounded-[13px] border px-4 text-[15px] font-bold transition ${
                                dark
                                    ? 'border-white/15 bg-white/5 text-white hover:bg-white/10'
                                    : 'border-blue-500/30 bg-blue-500/10 text-blue-600 hover:border-transparent hover:bg-blue-600 hover:text-white'
                            }`}
                        >
                            <Icon name="pencil" className="h-[17px] w-[17px]" />
                            تعديل السعر
                        </button>
                    )}
                </div>
            </div>

            <div className="relative flex flex-wrap items-end gap-2.5">
                <span className="font-display text-[44px] font-extrabold leading-none tracking-tight sm:text-[56px]" dir="ltr">
                    {price(tariff.rate)}
                </span>
                <span className={`pb-2 text-base font-semibold ${muted}`}>₪ للكيلو</span>
                {tariff.rateSince && (
                    <span className={`ms-auto pb-2 text-end text-[13px] ${muted}`}>
                        منذ <b className={dark ? 'font-semibold text-white' : 'font-semibold text-gray-700'}>{tariff.rateSince}</b>
                        {tariff.rateChangedBy && (
                            <>
                                <br />
                                حدّده {tariff.rateChangedBy}
                            </>
                        )}
                    </span>
                )}
            </div>

            <div className="relative grid grid-cols-3 gap-2 sm:gap-2.5">
                <Stat dark={dark} label="المشتركون" value={formatNumber(tariff.subscriptionsCount)} />
                <Stat
                    dark={dark}
                    label="متوسط الاستهلاك"
                    value={average === null ? '—' : formatMoney(average)}
                    unit={average === null ? null : 'كيلو/أسبوع'}
                />
                <Stat
                    dark={dark}
                    label="متوسط القراءة"
                    value={average === null ? '—' : price(average * Number(tariff.rate))}
                    unit={average === null ? null : '₪/أسبوع'}
                />
            </div>

            {editing && <RateEditor tariff={tariff} dark={dark} onDone={onDoneEditing} />}

            {tariff.history.length > 0 && (
                <p className={`relative flex flex-wrap items-center gap-1.5 text-[12.5px] ${dark ? 'text-white/50' : 'text-gray-500'}`}>
                    السجل:
                    {tariff.history.map((change, index) => (
                        <span key={change.id} className="inline-flex items-center gap-1.5">
                            {index > 0 && <i className="h-1 w-1 rounded-full bg-current opacity-50" aria-hidden="true" />}
                            {change.date} <b className={`font-display font-bold ${dark ? 'text-white/85' : 'text-gray-700'}`}>{price(change.rate)}</b>
                        </span>
                    ))}
                </p>
            )}
        </section>
    );
}

/** How much a given number of kilos comes to on each tariff. */
function QuickCalculator({ tariffs }) {
    const [kilos, setKilos] = useState('40');
    const amount = Number(kilos) || 0;

    return (
        <section
            aria-label="حاسبة سريعة"
            className="mt-4 flex flex-wrap items-center gap-4 rounded-card border border-gray-100 bg-surface px-5 py-4 shadow-card sm:px-6"
        >
            <div>
                <h3 className="font-luxe text-lg font-bold text-gray-900">حاسبة سريعة</h3>
                <p className="text-[13.5px] text-gray-500">كم يدفع المشترك عن استهلاك معيّن؟</p>
            </div>
            <label className="flex items-center gap-2 rounded-[14px] border-[1.5px] border-gray-200 bg-surface px-3 py-1 focus-within:border-brand-500">
                <input
                    value={kilos}
                    inputMode="decimal"
                    dir="ltr"
                    aria-label="عدد الكيلوات"
                    onChange={(event) => setKilos(normalizeDecimalInput(event.target.value))}
                    className="w-24 border-0 bg-transparent p-0 text-end font-display text-2xl font-extrabold text-gray-900 focus:ring-0"
                />
                <span className="font-bold text-gray-500">كيلو</span>
            </label>
            <div className="flex w-full flex-wrap gap-2.5 sm:ms-auto sm:w-auto">
                {tariffs.map((tariff) => (
                    <div key={tariff.id} className="min-w-[150px] flex-1 rounded-2xl border border-gray-100 bg-gray-50 px-4 py-2.5">
                        <small className="block text-[12.5px] text-gray-500">
                            {tariff.categoryLabel} · {price(tariff.rate)} ₪
                        </small>
                        <b className="font-display text-[22px] font-extrabold text-gray-900">{price(amount * Number(tariff.rate))} ₪</b>
                    </div>
                ))}
            </div>
        </section>
    );
}

/**
 * The tariffs: a card for each with its kilo price, the figures behind it
 * and its price history, the price edited in place; a quick calculator;
 * and each tariff's customer segments.
 */
export default function Index({ tariffs, segments, canCreate, canCreateSegment, categoryOptions }) {
    const [editingId, setEditingId] = useState(null);
    const [creating, setCreating] = useState(false);
    const { requestDelete, deleteDialog } = useDeleteRecord('التعرفة');
    const { requestDelete: requestSegmentDelete, deleteDialog: segmentDeleteDialog } = useDeleteRecord('تصنيف الزبائن');

    return (
        <SettingsLayout
            header={
                <PageHeader
                    title="التعرفات"
                    info="سعر الكيلو لكل فئة. تُحسب كل قراءة على السعر الساري وقتها، فتغيير السعر لا يمسّ القراءات السابقة."
                    actions={canCreate && <AddButton onClick={() => setCreating(true)}>تعرفة جديدة</AddButton>}
                />
            }
        >
            <Head title="التعرفات" />

            {tariffs.length === 0 ? (
                <p className="rounded-card border border-dashed border-gray-200 bg-surface px-6 py-12 text-center text-gray-500">
                    لا توجد تعرفات بعد.
                </p>
            ) : (
                <>
                    <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        {tariffs.map((tariff, index) => (
                            <TariffCard
                                key={tariff.id}
                                tariff={tariff}
                                dark={index === 0}
                                editing={editingId === tariff.id}
                                onEdit={() => setEditingId(tariff.id)}
                                onDoneEditing={() => setEditingId(null)}
                                onDelete={() => requestDelete(`/tariffs/${tariff.id}`, tariff.categoryLabel)}
                            />
                        ))}
                    </div>

                    <QuickCalculator tariffs={tariffs} />

                    <ClassificationChips
                        title="تصنيف الزبائن"
                        description="مثل المساجد والمدارس والمستشفيات — للتجميع والتقارير فقط، ويمكن اختيار أي تصنيف لأي مشترك مهما كانت تعرفته، ويبقى السعر سعر التعرفة."
                        items={segments.map((segment) => ({ ...segment, count: segment.subscriptionsCount }))}
                        baseUrl="/tariff-segments"
                        canCreate={canCreateSegment}
                        countLabel="مشترك"
                        addLabel="تصنيف جديد"
                        inputLabel="اسم التصنيف"
                        emptyLabel="لا يوجد تصنيف بعد."
                        className="mt-4"
                        onDelete={(segment) => requestSegmentDelete(`/tariff-segments/${segment.id}`, segment.name)}
                    />
                </>
            )}

            {canCreate && <TariffModal show={creating} onClose={() => setCreating(false)} tariff={null} categoryOptions={categoryOptions} />}

            {deleteDialog}
            {segmentDeleteDialog}
        </SettingsLayout>
    );
}
