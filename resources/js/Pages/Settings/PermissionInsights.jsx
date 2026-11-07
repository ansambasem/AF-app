import { useState } from 'react';
import Icon from '@/Components/Icon';
import { EmployeeAvatar } from './PermissionPeople';
import { RiskBadge, SECTION_DETAILS, sectionIcon } from './PermissionMatrix';
import {
    comparePermissions,
    individualChanges,
    matrixExtras,
    permissionEntries,
    permissionsWord,
    riskLevel,
    sectionIds,
    sectionSentence,
} from '@/lib/permissions';

/** How much the permissions can move money or the service, as a half dial from 0 to 100. */
export function RiskGauge({ sections, selected, large = false, onDark = false }) {
    const risk = riskLevel(sections, selected);
    const length = Math.PI * 34;

    return (
        <div
            className={`pm-gauge is-${risk.level} ${large ? 'is-large' : ''} ${onDark ? 'on-dark' : ''}`}
            title={`درجة الخطورة ${risk.score} من 100`}
        >
            <svg viewBox="0 0 84 48" aria-hidden="true">
                <path d="M8 44 A34 34 0 0 1 76 44" className="pm-gauge-track" />
                <path
                    d="M8 44 A34 34 0 0 1 76 44"
                    className="pm-gauge-value"
                    style={{ strokeDasharray: `${(length * risk.score) / 100} ${length}` }}
                />
            </svg>
            <p>
                <b className="font-display">{risk.score}</b>
                <span>خطورة {risk.label}</span>
            </p>
        </div>
    );
}

/** The ways to be paid the selected permissions allow, for the summary. */
function paymentWays(sections, selected) {
    const payments = sections.find((section) => section.key === 'collections');
    const record = payments?.ladder?.actions.indexOf('record') ?? -1;

    if (!payments || record === -1 || !selected.includes(payments.ladder.ids[record])) {
        return null;
    }

    return payments.switches
        .filter((item) => ['record_cash', 'record_transfer'].includes(item.action) && selected.includes(item.id))
        .map((item) => item.label);
}

/**
 * What the employee can do, in plain words, as it stands in the editor:
 * how many permissions (and how many of them sensitive or dangerous), a
 * line per section they hold something in with its special permissions,
 * the pages they'll see, what they can't reach, how far they are from their
 * role's template, and the permissions outside what the actor may manage,
 * which stay as they are.
 */
export function PermissionSummary({ employee, sections, selected, template, scopedToOwnBranch }) {
    const entries = permissionEntries(sections);
    const enabled = entries.filter((item) => selected.includes(item.id));
    const deviations = template ? individualChanges(sections, template.permissionIds, selected).length : null;
    const locked = employee.lockedPermissions ?? [];
    const held = sections.filter((section) => sectionIds(section).some((id) => selected.includes(id)));
    const notHeld = sections.filter((section) => !held.includes(section));
    const ways = paymentWays(sections, selected);
    const dangerous = enabled.filter((item) => item.risk === 'danger');
    const firstName = employee.name.split(' ')[0];

    return (
        <aside className="pm-card space-y-4 p-4 sm:p-5" aria-label={`ملخص صلاحيات ${employee.name}`}>
            <div className="flex items-start gap-3">
                <span className="pm-card-icon">
                    <Icon name="sparkles" className="h-5 w-5" />
                </span>
                <div>
                    <h3 className="text-lg font-bold text-gray-900">ماذا يستطيع {firstName}؟</h3>
                    <p className="mt-0.5 text-[13.5px] text-gray-500">
                        {scopedToOwnBranch ? 'المعروض هو ما يمكنك تعديله لموظفي فرعك.' : 'يتحدّث مع كل تغيير، قبل الحفظ.'}
                    </p>
                </div>
            </div>

            <div className="grid grid-cols-3 gap-2">
                {[
                    { count: enabled.length, label: 'صلاحية', tone: 'text-gray-900' },
                    { count: enabled.filter((item) => item.risk === 'sensitive').length, label: 'حساسة', tone: 'text-amber-700 dark:text-amber-400' },
                    { count: dangerous.length, label: 'خطيرة', tone: 'text-red-700 dark:text-red-400' },
                ].map((stat) => (
                    <div key={stat.label} className="rounded-control border border-gray-100 bg-gray-50 px-3 py-2">
                        <b className={`block font-display text-xl leading-tight ${stat.tone}`}>{stat.count}</b>
                        <span className="text-xs text-gray-500">{stat.label}</span>
                    </div>
                ))}
            </div>

            {template && (
                <p className="text-xs text-gray-500">
                    {deviations === 0 ? `مطابق لقالب ${template.label}` : `${deviations} اختلاف عن قالب الدور (${template.label})`}
                </p>
            )}

            <ul className="pm-summary-list">
                {held.length === 0 && <li className="text-gray-500">لا يملك أي صلاحية بعد.</li>}
                {held.map((section) => {
                    const special = matrixExtras(section)
                        .flatMap((group) => group.items)
                        .filter((item) => item.risk && selected.includes(item.id));

                    return (
                        <li key={section.key}>
                            <span className="pm-section-icon is-small">
                                <Icon name={sectionIcon(section.key)} className="h-4 w-4" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <b className="font-semibold text-gray-900">{section.label}:</b>{' '}
                                <span className="text-gray-700">{sectionSentence(section, selected)}</span>
                                {special.length > 0 && (
                                    <div className="mt-1.5 flex flex-wrap gap-1">
                                        {special.map((item) => (
                                            <span key={item.id} className={`pm-pill is-${item.risk}`}>
                                                {item.label}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </li>
                    );
                })}
                {ways && (
                    <li>
                        <span className="pm-section-icon is-small">
                            <Icon name="wallet" className="h-4 w-4" />
                        </span>
                        <span className="text-gray-700">
                            {ways.length ? `يأخذ الدفعات: ${ways.join(' و')}` : 'لا يأخذ أي دفعة: لم تُختر طريقة دفع'}
                        </span>
                    </li>
                )}
                {dangerous.length > 0 && (
                    <li className="text-red-700 dark:text-red-400">
                        <span className="pm-section-icon is-small is-danger">
                            <Icon name="alert" className="h-4 w-4" />
                        </span>
                        <span>صلاحيات خطيرة: {dangerous.map((item) => item.label).join('، ')}</span>
                    </li>
                )}
            </ul>

            <div className="border-t border-gray-100 pt-3">
                <h4 className="text-sm font-bold text-gray-900">الصفحات التي سيراها</h4>
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {['الرئيسية', ...held.map((section) => SECTION_DETAILS[section.key]?.page ?? section.label)].map((page) => (
                        <span key={page} className="pm-pill">
                            {page}
                        </span>
                    ))}
                </div>
            </div>

            {notHeld.length > 0 && (
                <div className="border-t border-gray-100 pt-3">
                    <h4 className="text-sm font-bold text-gray-900">لا يصل إلى</h4>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {notHeld.map((section) => (
                            <span key={section.key} className="pm-pill is-ghost">
                                {section.label}
                            </span>
                        ))}
                    </div>
                </div>
            )}

            {locked.length > 0 && (
                <p className="rounded-control bg-gray-50 px-3 py-2 text-xs leading-5 text-gray-600">
                    <b className="text-gray-900">{locked.length} صلاحية خارج نطاق إدارتك، تبقى كما هي:</b> {locked.join('، ')}
                </p>
            )}
        </aside>
    );
}

/** A list of permissions grouped by section, for one side of a comparison. */
function CompareList({ title, items, tone, empty }) {
    const bySection = items.reduce((groups, item) => {
        (groups[item.section] ??= []).push(item);
        return groups;
    }, {});

    return (
        <section className={`pm-compare-side is-${tone}`}>
            <h4>
                {title}
                <b className="font-display">{items.length}</b>
            </h4>
            {items.length === 0 ? (
                <p className="px-1 py-3 text-sm text-gray-500">{empty}</p>
            ) : (
                Object.entries(bySection).map(([section, list]) => (
                    <div key={section} className="pm-compare-group">
                        <p>{section}</p>
                        <ul>
                            {list.map((item) => (
                                <li key={item.id}>
                                    <span>{item.label}</span>
                                    <RiskBadge risk={item.risk} />
                                </li>
                            ))}
                        </ul>
                    </div>
                ))
            )}
        </section>
    );
}

/**
 * The employee beside a colleague: what only each of them holds and what
 * they share, with a way to add what's missing or match the colleague
 * exactly. Either only changes the editor; nothing is saved until reviewed.
 */
export function PermissionCompare({ employee, sections, selected, colleagues, colorOf, onAddMissing, onMatch, disabled }) {
    const [otherId, setOtherId] = useState(null);
    const other = colleagues.find((user) => user.id === otherId) ?? null;
    const result = other ? comparePermissions(sections, selected, other.permissionIds) : null;
    const firstName = employee.name.split(' ')[0];

    return (
        <div className="pm-card p-4 sm:p-5">
            <div className="flex items-start gap-3">
                <span className="pm-card-icon">
                    <Icon name="repeat" className="h-5 w-5" />
                </span>
                <div>
                    <h3 className="text-lg font-bold text-gray-900">مقارنة {firstName} بزميل</h3>
                    <p className="mt-0.5 text-[13.5px] text-gray-500">
                        اختر موظفًا لترى الفرق بينهما، ثم أضف ما ينقص أو طابِق صلاحياته. لا يُحفظ شيء قبل المراجعة.
                    </p>
                </div>
            </div>

            {colleagues.length === 0 ? (
                <p className="mt-4 rounded-control bg-gray-50 p-4 text-center text-sm text-gray-500">لا يوجد موظفون آخرون في هذه الصفحة.</p>
            ) : (
                <div className="mt-4 flex flex-wrap gap-2" role="group" aria-label="الموظف الذي تقارن به">
                    {colleagues.map((user) => (
                        <button
                            key={user.id}
                            type="button"
                            aria-pressed={user.id === otherId}
                            onClick={() => setOtherId(user.id === otherId ? null : user.id)}
                            className="pm-colleague"
                            style={{ '--role': colorOf(user.roleLabel) }}
                        >
                            <EmployeeAvatar name={user.name} color={colorOf(user.roleLabel)} size="sm" />
                            <span className="min-w-0 text-start">
                                <b className="block truncate text-sm">{user.name}</b>
                                <small className="block truncate text-[12px] text-gray-500">{user.roleLabel}</small>
                            </span>
                        </button>
                    ))}
                </div>
            )}

            {result && (
                <div className="mt-5 space-y-4">
                    <p className="text-sm text-gray-600">
                        يشتركان في <b className="font-display text-gray-900">{result.shared.length}</b> {permissionsWord(result.shared.length)}.
                    </p>
                    <div className="grid gap-3 md:grid-cols-2">
                        <CompareList
                            title={`عند ${firstName} فقط`}
                            items={result.onlyMine}
                            tone="mine"
                            empty={`لا يملك ${firstName} شيئًا زائدًا.`}
                        />
                        <CompareList
                            title={`عند ${other.name.split(' ')[0]} فقط`}
                            items={result.onlyTheirs}
                            tone="theirs"
                            empty="لا ينقصه شيء مما عند زميله."
                        />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="pm-soft-button"
                            disabled={disabled || result.onlyTheirs.length === 0}
                            onClick={() => onAddMissing(result.onlyTheirs.map((item) => item.id))}
                        >
                            <Icon name="plus" className="h-4 w-4" />
                            أضف ما ينقصه ({result.onlyTheirs.length})
                        </button>
                        <button
                            type="button"
                            className="pm-soft-button"
                            disabled={disabled || (result.onlyTheirs.length === 0 && result.onlyMine.length === 0)}
                            onClick={() => onMatch(other)}
                        >
                            <Icon name="repeat" className="h-4 w-4" />
                            طابق صلاحيات العمل
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
