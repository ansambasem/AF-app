import { useState } from 'react';
import Icon from '@/Components/Icon';
import InlineNameEditor from './InlineNameEditor';

/** Lists longer than this get their own search box. */
const SEARCHABLE_FROM = 6;

/**
 * One column of the governorates → areas → sub-areas browser: a header
 * with the count and "إضافة", an optional search box, then the rows. A
 * row is picked by click or Enter (`onPick`); the pencil renames it in
 * place and the bin asks to delete it. Adding also happens in place, at
 * the top of the list.
 *
 * `items` is null while the column above has nothing picked; `waiting`
 * ({ title, text }) then says what to do. `search`/`onSearchChange` hand the search box to
 * the caller (a server-side search); without them the rows are filtered
 * here. On narrow screens only the `active` column shows, with `onBack`
 * leading to the one before it.
 */
export default function HierarchyColumn({
    title,
    noun,
    items,
    total,
    waiting,
    selectedId = null,
    onPick,
    subtitle,
    canAdd = false,
    createRequest,
    updateRequest,
    onMore,
    onDelete,
    search,
    onSearchChange,
    emptyText,
    active,
    onBack,
    footer,
}) {
    const [localSearch, setLocalSearch] = useState('');
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState(null);

    const searchesOnServer = onSearchChange !== undefined;
    const query = (searchesOnServer ? search : localSearch).trim();
    const visible = items && !searchesOnServer && query ? items.filter((item) => item.name.includes(query)) : items;
    const count = total ?? items?.length ?? 0;

    return (
        <section className={`min-w-0 flex-col border-gray-100 lg:flex lg:border-s lg:first:border-s-0 ${active ? 'flex' : 'hidden'}`}>
            <div className="flex items-center gap-2.5 px-4 pb-2.5 pt-3.5">
                {onBack && (
                    <button
                        type="button"
                        onClick={onBack}
                        aria-label="رجوع"
                        className="grid h-9 w-9 place-items-center rounded-lg border border-gray-200 bg-surface text-gray-700 lg:hidden"
                    >
                        <Icon name="chevron-right" className="h-4 w-4" strokeWidth={2} />
                    </button>
                )}
                <h3 className="font-luxe text-lg font-bold text-gray-900">{title}</h3>
                {items && <small className="font-display text-xs font-semibold text-gray-500">{count}</small>}
                {items && canAdd && (
                    <button
                        type="button"
                        onClick={() => {
                            setAdding(true);
                            setEditingId(null);
                        }}
                        className="ms-auto inline-flex h-9 items-center gap-1.5 rounded-lg border border-gray-200 bg-surface px-2.5 text-xs font-bold text-gray-700 transition hover:border-brand-500/40 hover:bg-brand-500/10 hover:text-brand-600"
                    >
                        <Icon name="plus" className="h-4 w-4" strokeWidth={2} />
                        إضافة
                    </button>
                )}
            </div>

            {!items ? (
                <Empty title={waiting.title} text={waiting.text} />
            ) : (
                <>
                    {(count > SEARCHABLE_FROM || query) && (
                        <div className="px-3 pb-2">
                            <input
                                type="search"
                                value={searchesOnServer ? search : localSearch}
                                onChange={(event) => (searchesOnServer ? onSearchChange : setLocalSearch)(event.target.value)}
                                placeholder="بحث"
                                aria-label={`بحث في ${title}`}
                                className="h-10 w-full rounded-control border-gray-200 bg-gray-50 px-3 text-sm focus:border-brand-500 focus:bg-surface focus:ring-0"
                            />
                        </div>
                    )}

                    <div className="flex-1 overflow-y-auto px-2 pb-2.5 pt-1 lg:max-h-[34rem]">
                        {adding && <InlineNameEditor placeholder={`اسم ${noun}`} request={createRequest} onDone={() => setAdding(false)} />}

                        {visible.map((item) =>
                            editingId === item.id ? (
                                <InlineNameEditor
                                    key={item.id}
                                    initialName={item.name}
                                    placeholder={`اسم ${noun}`}
                                    request={updateRequest(item)}
                                    onDone={() => setEditingId(null)}
                                    onMore={
                                        onMore &&
                                        (() => {
                                            setEditingId(null);
                                            onMore(item);
                                        })
                                    }
                                />
                            ) : (
                                <Row
                                    key={item.id}
                                    item={item}
                                    selected={selectedId === item.id}
                                    onPick={onPick}
                                    subtitle={subtitle?.(item)}
                                    onEdit={() => {
                                        setEditingId(item.id);
                                        setAdding(false);
                                    }}
                                    onDelete={onDelete}
                                />
                            ),
                        )}

                        {visible.length === 0 && !adding && (
                            <Empty title={query ? 'لا توجد نتائج' : emptyText} text={query ? null : canAdd ? 'اضغط «إضافة» في الأعلى.' : null} />
                        )}
                    </div>

                    {footer}
                </>
            )}
        </section>
    );
}

function Row({ item, selected, onPick, subtitle, onEdit, onDelete }) {
    const pickable = Boolean(onPick);
    const pick = () => onPick?.(item);

    return (
        <div
            role={pickable ? 'button' : undefined}
            tabIndex={pickable ? 0 : undefined}
            onClick={pick}
            onKeyDown={(event) => {
                if (pickable && event.key === 'Enter' && event.target === event.currentTarget) {
                    pick();
                }
            }}
            className={`group flex w-full items-center gap-2.5 rounded-control border px-2.5 py-2 text-start transition ${
                selected
                    ? 'border-brand-500/40 bg-brand-500/10 shadow-[inset_-3px_0_0_rgb(var(--brand-500))]'
                    : `border-transparent ${pickable ? 'cursor-pointer hover:border-brand-500/20 hover:bg-brand-500/5' : ''}`
            }`}
        >
            <span className="min-w-0">
                <b
                    className={`block truncate text-sm font-bold ${selected ? 'text-brand-600' : 'text-gray-900'} ${pickable ? 'group-hover:text-brand-600' : ''}`}
                >
                    {item.name}
                </b>
                {subtitle && <small className="block text-xs text-gray-500">{subtitle}</small>}
            </span>
            <span className="ms-auto flex items-center gap-1">
                {item.canUpdate && <RowIconButton label={`تعديل ${item.name}`} icon="pencil" visible={selected} onClick={onEdit} />}
                {item.canDelete && onDelete && (
                    <RowIconButton label={`حذف ${item.name}`} icon="trash" visible={selected} onClick={() => onDelete(item)} />
                )}
                {pickable && (
                    <Icon
                        name="chevron-left"
                        className={`h-4 w-4 ${selected ? 'text-brand-600' : 'text-gray-400 group-hover:text-brand-600'}`}
                        strokeWidth={2}
                    />
                )}
            </span>
        </div>
    );
}

/** The pencil / bin on a row: shown on hover, focus and on the picked row — always on touch screens. */
function RowIconButton({ label, icon, visible, onClick }) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            onClick={(event) => {
                event.stopPropagation();
                onClick();
            }}
            className={`grid h-8 w-8 place-items-center rounded-lg text-gray-500 transition hover:bg-surface hover:text-brand-600 focus:opacity-100 group-hover:opacity-100 max-lg:opacity-100 ${
                visible ? 'opacity-100' : 'opacity-0'
            }`}
        >
            <Icon name={icon} className="h-4 w-4" />
        </button>
    );
}

function Empty({ title, text }) {
    return (
        <div className="px-4 py-9 text-center text-sm text-gray-500">
            <b className="mb-1 block text-gray-700">{title}</b>
            {text}
        </div>
    );
}
