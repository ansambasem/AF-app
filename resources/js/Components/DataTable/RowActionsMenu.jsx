import { Children, cloneElement, isValidElement } from 'react';
import Icon from '@/Components/Icon';
import { useRowActionsMenu } from '@/hooks/useRowActionsMenu';
import RowMenu from './RowMenu';

/** Each action word gets its icon automatically. */
const ICONS = {
    عرض: 'eye',
    تعديل: 'pencil',
    حذف: 'trash',
    'حذف نهائي': 'close',
    'إدارة الصلاحيات': 'shield',
    'كشف الحساب': 'ledger',
    طباعة: 'printer',
    'طباعة السند': 'printer',
    Excel: 'excel',
    'مراجعة وإغلاق': 'lock',
};

/** Actions with a color of their own: blue to edit, burgundy to delete, violet to print, Excel green for Excel. */
const TONES = {
    تعديل: 'row-action-edit',
    حذف: 'row-action-delete',
    'حذف نهائي': 'row-action-delete',
    طباعة: 'row-action-print',
    'طباعة السند': 'row-action-print',
    Excel: 'row-action-excel',
};

/**
 * A row's actions, at the end of the row, always showing:
 *
 * - `onView`: the graphite "عرض" button, the row's main action. With a
 *   `menu`, it is split and its arrow opens the menu. `viewLabel` and
 *   `viewIcon` name another main action the same way, e.g. "الرد".
 * - `onEdit`: a blue pencil button ("تعديل").
 * - `onDelete`: a burgundy trash button ("حذف").
 * - `menu`: more actions in groups (see RowMenu); opened from "عرض"'s
 *   arrow, or from a ⋯ button when there is no "عرض". `coloredMenu` makes
 *   that ⋯ burgundy, for a row whose actions are its main business (a
 *   financial ledger).
 * - `menuController`: shares a useRowActionsMenu controller with the row's
 *   context-click and keyboard handlers, so every trigger opens one menu.
 * - children, e.g. <RowActionsMenu><button onClick={…}>تعديل</button></RowActionsMenu>:
 *   each becomes a same-size icon button with its word as the tooltip
 *   (and its color, for تعديل, حذف, طباعة and Excel).
 */
export default function RowActionsMenu({ onView, viewLabel = 'عرض', viewIcon = 'eye', onEdit, onDelete, menu, coloredMenu = false, menuController = null, children }) {
    const ownMenu = useRowActionsMenu();
    const { opener, toggleMenu, closeMenu } = menuController ?? ownMenu;

    const menuProps = (label) => ({
        'aria-label': label,
        title: label,
        'aria-haspopup': 'menu',
        'aria-expanded': Boolean(opener),
        onClick: toggleMenu,
    });

    return (
        <div className="row-actions">
            <div className="row-actions-buttons">
                {Children.map(children, (child) => {
                    if (!isValidElement(child)) {
                        return child;
                    }

                    const label = child.props.children;
                    const word = typeof label === 'string' ? label.trim() : null;
                    const icon = word ? ICONS[word] : null;

                    return cloneElement(child, {
                        type: child.props.type ?? 'button',
                        className: `row-action ${TONES[word] ?? ''}`.trim(),
                        title: label,
                        'aria-label': label,
                        children: <Icon name={icon ?? 'dots'} className="h-[18px] w-[18px]" />,
                    });
                })}

                {menu && !onView && (
                    <button type="button" className={`row-action ${coloredMenu ? 'row-action-menu' : ''}`.trim()} {...menuProps('إجراءات أخرى')}>
                        <Icon name="dots" className="h-[18px] w-[18px]" strokeWidth={2} />
                    </button>
                )}

                {onDelete && (
                    <button type="button" className="row-action row-action-delete" title="حذف" aria-label="حذف" onClick={onDelete}>
                        <Icon name="trash" className="h-[18px] w-[18px]" />
                    </button>
                )}

                {onEdit && (
                    <button type="button" className="row-action row-action-edit" title="تعديل" aria-label="تعديل" onClick={onEdit}>
                        <Icon name="pencil" className="h-[18px] w-[18px]" />
                    </button>
                )}

                {onView && (
                    <span className="row-action-view">
                        <button type="button" onClick={onView}>
                            <Icon name={viewIcon} className="h-4 w-4" />
                            {viewLabel}
                        </button>
                        {menu && (
                            <button type="button" {...menuProps('إجراءات أخرى')}>
                                <Icon name="chevron-down" className="h-4 w-4" strokeWidth={2} />
                            </button>
                        )}
                    </span>
                )}
            </div>

            {opener && menu && <RowMenu anchor={opener.anchor} point={opener.point} contextual={opener.contextual} menu={menu} onClose={closeMenu} />}
        </div>
    );
}
