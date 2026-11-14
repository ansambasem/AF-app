import { useState } from 'react';
import Icon from '@/Components/Icon';
import InfoTip from '@/Components/InfoTip';
import SegmentedTabs from '@/Components/SegmentedTabs';
import { permissionEntries } from '@/lib/permissions';
import { sectionIcon, TriToggle } from './PermissionMatrix';

const RISK_WORDS = { danger: 'خطيرة', sensitive: 'حساسة' };

/**
 * The right to hand permissions on, which only the Super Admin gives, and
 * only to someone who manages permissions: each delegable permission the
 * employee holds, by section, with a green box to tick when they may grant
 * it to their branch's staff. `grantsGiven` counts, per permission, the
 * staff who hold it from them, who lose it when they do.
 */
export default function GrantRightPanel({ sections, selected, grantable, delegableIds, grantsGiven = {}, firstName, disabled, onChange }) {
    const [filter, setFilter] = useState('all');
    const held = permissionEntries(sections).filter((entry) => selected.includes(entry.id) && delegableIds.includes(entry.id));
    const keyed = held.filter((entry) => grantable.includes(entry.id)).length;
    const bySection = Object.values(
        held.reduce((groups, entry) => {
            groups[entry.sectionKey] ??= { key: entry.sectionKey, label: entry.section, entries: [] };
            groups[entry.sectionKey].entries.push(entry);

            return groups;
        }, {}),
    );

    function toggle(id) {
        onChange(grantable.includes(id) ? grantable.filter((item) => item !== id) : [...grantable, id]);
    }

    /** Ticks or clears a set of boxes at once. */
    function setAll(entries, on) {
        const ids = entries.map((entry) => entry.id);
        onChange(on ? [...new Set([...grantable, ...ids])] : grantable.filter((id) => !ids.includes(id)));
    }

    return (
        <section className="pm-card p-5 sm:p-6" aria-label="حق المنح">
            <div className="flex flex-wrap items-center gap-3">
                <span className="pm-card-icon">
                    <Icon name="key" className="h-5 w-5" />
                </span>
                <div className="min-w-0">
                    <h4 className="flex items-center gap-1.5 font-bold text-gray-900">
                        ماذا يستطيع {firstName} أن يمنح لموظفي فرعه؟
                        <InfoTip label="عن حق المنح">
                            {`المربع الأخضر يعني أن ${firstName} يستطيع منح هذه الصلاحية لموظفي فرعه فقط، ويعمل منحه فورًا ويصلك إشعار به. إذا سحبت منه صلاحية تُسحب ممن منحهم إياها، وإذا أفرغت المربع وحده يبقى ما منحه.`}
                        </InfoTip>
                    </h4>
                    <p className="text-sm text-gray-500">
                        يمنح <b className="font-display text-gray-900">{keyed}</b> من <b className="font-display text-gray-900">{held.length}</b>{' '}
                        صلاحية يملكها
                    </p>
                </div>
                <div className="ms-auto flex flex-wrap items-center gap-2">
                    <SegmentedTabs
                        label="عرض"
                        value={filter}
                        onChange={setFilter}
                        options={[
                            { value: 'all', label: 'الكل' },
                            { value: 'on', label: 'يمنحها' },
                            { value: 'off', label: 'لنفسه فقط' },
                        ]}
                    />
                    <button type="button" disabled={disabled || keyed === held.length} onClick={() => setAll(held, true)} className="pm-grant-button">
                        منح الكل
                    </button>
                    <button type="button" disabled={disabled || keyed === 0} onClick={() => onChange([])} className="pm-grant-button">
                        لا شيء
                    </button>
                </div>
            </div>

            <div className="pm-grant-legend">
                <span>
                    <i className="pm-grant-dot" aria-hidden="true" />
                    يملكها لنفسه
                </span>
                <span>
                    <i className="pm-check is-on pm-grant-legend-check" aria-hidden="true">
                        <Icon name="check" className="h-3 w-3" strokeWidth={3} />
                    </i>
                    يمنحها لموظفي فرعه
                </span>
                <span>
                    <b className="pm-grant-given">منحها لـ 3</b>
                    موظفون يفقدونها إن سحبتها منه
                </span>
            </div>

            {bySection.length === 0 ? (
                <p className="py-10 text-center text-sm text-gray-500">
                    لا يملك {firstName} صلاحيات يمكن منحها بعد. امنحه صلاحيات من تبويب «الصلاحيات» أولًا.
                </p>
            ) : (
                <div className="divide-y divide-gray-100">
                    {bySection.map((section) => {
                        const shown = section.entries.filter((entry) => filter === 'all' || (filter === 'on') === grantable.includes(entry.id));
                        const sectionKeyed = section.entries.filter((entry) => grantable.includes(entry.id)).length;

                        if (shown.length === 0) {
                            return null;
                        }

                        return (
                            <div key={section.key} className="py-5 last:pb-0">
                                <div className="mb-3 flex items-center gap-2.5">
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-gray-100 bg-gray-50 text-gray-500">
                                        <Icon name={sectionIcon(section.key)} className="h-[18px] w-[18px]" />
                                    </span>
                                    <b className="text-[16px] text-gray-900">{section.label}</b>
                                    <span className="font-display text-xs font-bold text-gray-400">
                                        {sectionKeyed}/{section.entries.length} يمنحها
                                    </span>
                                    <span className="ms-auto inline-flex items-center gap-2 text-sm font-semibold text-gray-600">
                                        الكل
                                        <TriToggle
                                            ids={section.entries.map((entry) => entry.id)}
                                            selected={grantable}
                                            onChange={(on) => setAll(section.entries, on)}
                                            disabled={disabled}
                                            label={`منح كل صلاحيات ${section.label}`}
                                        />
                                    </span>
                                </div>
                                <div className="grid gap-2 md:grid-cols-2">
                                    {shown.map((entry) => {
                                        const on = grantable.includes(entry.id);

                                        return (
                                            <button
                                                key={entry.id}
                                                type="button"
                                                role="checkbox"
                                                aria-checked={on}
                                                aria-label={`يمنح «${entry.label}» لموظفي فرعه`}
                                                disabled={disabled}
                                                onClick={() => toggle(entry.id)}
                                                className={`pm-grant-row ${on ? 'is-on' : ''}`}
                                            >
                                                <span className="min-w-0 flex-1">
                                                    <b className="block truncate text-[15px] font-semibold text-gray-900">{entry.label}</b>
                                                    <small className="flex flex-wrap items-center gap-1.5 text-[12.5px] text-gray-500">
                                                        {entry.risk && (
                                                            <>
                                                                <i className={`pm-grant-risk is-${entry.risk}`} aria-hidden="true" />
                                                                {RISK_WORDS[entry.risk]} ·
                                                            </>
                                                        )}
                                                        يملكها
                                                        {grantsGiven[entry.id] > 0 && (
                                                            <>
                                                                {' · '}
                                                                <b className="pm-grant-given">منحها لـ {grantsGiven[entry.id]}</b>
                                                            </>
                                                        )}
                                                    </small>
                                                </span>
                                                <span className="pm-grant-state">{on ? 'يمنحها' : 'لنفسه فقط'}</span>
                                                <span className={`pm-check ${on ? 'is-on' : ''}`} aria-hidden="true">
                                                    <Icon name="check" className="h-4 w-4" strokeWidth={3} />
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}

/** How many permissions in `next` are not in `previous`, and how many are gone. */
export function grantRightChanges(previous, next) {
    return {
        added: next.filter((id) => !previous.includes(id)).length,
        removed: previous.filter((id) => !next.includes(id)).length,
    };
}
