import { useMemo, useState } from 'react';
import Modal from '@/Components/Modal';
import Icon from '@/Components/Icon';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import PermissionPanel from './PermissionPanel';
import { auditScopeLabel } from './AuditAccessCard';
import { ICONS, permissionGuideEntries, permissionGuideHtml } from '@/lib/permissionGuide';

export function PermissionExplanation({ entry, onClose }) {
    return <Modal show={Boolean(entry)} onClose={onClose} maxWidth="2xl" centered><div className="permission-help-dialog" dir="rtl"><header className="permission-help-header flex items-start justify-between gap-4 border-b border-gray-100 p-6"><div><p className="text-sm opacity-70">{entry?.sectionLabel}</p><h3 className="mt-1 text-xl font-bold">{entry?.label}</h3></div><button type="button" aria-label="إغلاق شرح الصلاحية" onClick={onClose} className="rounded-control p-1"><Icon name="close" className="h-5 w-5" /></button></header><div className="space-y-5 p-6"><div><h4 className="font-bold text-gray-900">ماذا تسمح؟</h4><p className="mt-2 text-base leading-8 text-gray-600">{entry?.description}</p></div><div><h4 className="font-bold text-gray-900">حدود الصلاحية</h4><p className="mt-2 text-base leading-8 text-gray-600">{entry?.limit}</p></div><p className="rounded-control border border-gray-100 bg-gray-50 p-4 text-sm leading-7 text-gray-600">{entry?.delegation}</p></div><footer className="flex justify-end border-t border-gray-100 p-4"><SecondaryButton onClick={onClose}>فهمت</SecondaryButton></footer></div></Modal>;
}

export default function PermissionGuide({ show, onClose, sections, employee, selected, scopedToOwnBranch, auditOptions, auditValue, initialAudience = 'all' }) {
    const [query, setQuery] = useState('');
    const [audience, setAudience] = useState(initialAudience);
    const [error, setError] = useState('');
    const entries = useMemo(() => permissionGuideEntries(sections), [sections]);
    const included = audience === 'selected' ? entries.filter(entry => selected.includes(entry.id)) : entries;
    const normalize = value => value.replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي');
    const matching = included.filter(entry => normalize(`${entry.label} ${entry.description} ${entry.limit} ${entry.sectionLabel}`).includes(normalize(query.trim())));
    const auditActions = auditOptions && (audience === 'all' || selected.includes(auditOptions.permissionId))
        ? auditOptions.actions.filter(action => audience === 'all' || auditValue?.actions.includes(action.value)) : [];
    const auditScope = auditOptions && auditValue ? auditScopeLabel(auditValue, auditOptions.branches) : '';
    function html() {
        return permissionGuideHtml({ title: audience === 'selected' ? `دليل صلاحيات ${employee.name}` : 'دليل الصلاحيات المتاحة', entries: included, auditActions,
            scopeNote: audience === 'selected' ? `${employee.roleLabel} · ${employee.branchName ?? 'بلا فرع'}${auditOptions && selected.includes(auditOptions.permissionId) ? ` · فروع التدقيق: ${auditScope}` : ''} · بحسب الاختيارات الحالية؛ التعديلات لا تُعتمد إلا بعد حفظها.` : scopedToOwnBranch ? 'الصلاحيات التي يحق لك إدارتها لموظفي فرعك.' : 'جميع الصلاحيات المتاحة للإدارة في النظام.',
            lockedPermissions: audience === 'selected' ? employee.lockedPermissions ?? [] : [],
        });
    }
    function download() {
        const url = URL.createObjectURL(new Blob([html()], { type: 'text/html;charset=utf-8' }));
        const link = document.createElement('a'); link.href = url; link.download = 'دليل الصلاحيات.html'; document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    function print() {
        const target = window.open('', '_blank');
        if (!target) { setError('تعذّر فتح الطباعة. اسمح بالنوافذ المنبثقة لهذا الموقع ثم حاول مجددًا.'); return; }
        target.opener = null;
        target.document.open(); target.document.write(html()); target.document.close();
        target.document.fonts.ready.then(() => { target.focus(); target.print(); });
    }
    return <Modal show={show} onClose={onClose} maxWidth="6xl" centered><div className="flex max-h-[90dvh] min-h-0 flex-col" role="dialog" aria-modal="true" aria-labelledby="permission-guide-title" dir="rtl"><header className="flex shrink-0 items-start justify-between gap-4 border-b border-gray-100 p-5 sm:px-7"><div><h3 id="permission-guide-title" className="text-xl font-bold text-gray-900">دليل الصلاحيات</h3><p className="mt-1 text-sm text-gray-500">ماذا تسمح كل صلاحية، وما حدودها ومن يستطيع تفويضها؟</p></div><button type="button" aria-label="إغلاق دليل الصلاحيات" onClick={onClose} className="rounded-control p-2 text-gray-500"><Icon name="close" className="h-5 w-5" /></button></header><div className="permission-guide-scroll space-y-4 bg-gray-50 p-5 sm:px-7"><div className="permission-design-tabs" role="group" aria-label="محتوى دليل الصلاحيات">{[['all', scopedToOwnBranch ? 'كل الصلاحيات المتاحة لك' : 'كل الصلاحيات'], ['selected', `صلاحيات ${employee.name} المختارة`]].map(([key, label]) => <button type="button" key={key} aria-pressed={audience === key} onClick={() => setAudience(key)} className={`permission-design-tab ${audience === key ? 'active' : ''}`}>{label}</button>)}</div><p className="text-sm leading-7 text-gray-600">الطباعة والتنزيل يشملان الدليل المختار كاملًا، حتى لو كانت البطاقات مغلقة أو نتائج البحث محدودة. دليل الموظف يعكس الاختيارات الحالية.</p><div className="relative"><Icon name="search" className="pointer-events-none absolute start-3 top-3 h-5 w-5 text-gray-400" /><input type="search" aria-label="البحث في دليل الصلاحيات" value={query} onChange={event => setQuery(event.target.value)} placeholder="ابحث باسم الصلاحية أو وظيفتها…" className="w-full ps-10 text-sm" /></div><p className="text-xs text-gray-500">عدد الصلاحيات المطابقة: {matching.length}</p>{sections.filter(section => matching.some(entry => entry.section === section.key)).map(section => <PermissionPanel key={`${audience}:${section.key}`} title={section.label} icon={ICONS[section.key]} count={matching.filter(entry => entry.section === section.key).length} summary="الوظيفة والحدود ونطاق التفويض">{matching.filter(entry => entry.section === section.key).map(entry => <article key={entry.id} className="permission-guide-entry"><h4>{entry.label}</h4><p>{entry.description}</p><small><b>حدودها:</b> {entry.limit}</small><small>{entry.delegation}</small></article>)}</PermissionPanel>)}{matching.length === 0 && <p className="py-6 text-center text-gray-500">لا توجد صلاحيات مطابقة.</p>}{auditActions.length > 0 && <PermissionPanel key={`audit-${audience}`} title="إجراءات التدقيق المالي" icon="shield" count={auditActions.length} summary="مرتبطة بتفعيل التدقيق وفروعه المسموحة">{auditActions.map(action => <article key={action.value} className="permission-guide-entry"><h4>{action.label}</h4><p>{action.hint}</p></article>)}</PermissionPanel>}{audience === 'selected' && employee.lockedPermissions?.length > 0 && <p className="rounded-control border border-gray-200 bg-surface p-4 text-sm leading-7 text-gray-600">صلاحيات محفوظة خارج نطاق إدارتك: {employee.lockedPermissions.join('، ')}.</p>}{error && <p role="alert" className="text-sm text-red-600">{error}</p>}</div><footer className="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-gray-100 bg-surface p-4 sm:px-7"><SecondaryButton onClick={onClose}>إغلاق</SecondaryButton><SecondaryButton onClick={download}><Icon name="document" className="h-4 w-4" />تنزيل HTML</SecondaryButton><PrimaryButton onClick={print}><Icon name="printer" className="h-4 w-4" />طباعة / حفظ PDF</PrimaryButton></footer></div></Modal>;
}
