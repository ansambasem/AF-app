import { useId, useState } from 'react';
import Icon from '@/Components/Icon';

/** Opening a panel changes navigation only; permission controls live in its body. */
export default function PermissionPanel({ title, summary, icon = 'shield', count, changed = false, open: controlledOpen, onToggle, children, className = '' }) {
    const id = useId();
    const [localOpen, setLocalOpen] = useState(false);
    const open = controlledOpen ?? localOpen;
    return <section className={`permission-panel ${open ? 'is-open' : ''} ${changed ? 'is-changed' : ''} ${className}`}>
        <button type="button" className="permission-panel-heading" aria-expanded={open} aria-controls={id} onClick={() => onToggle ? onToggle(!open) : setLocalOpen(!open)}>
            <span className="permission-panel-icon"><Icon name={icon} className="h-5 w-5" /></span>
            <span className="permission-panel-copy"><b>{title}</b>{summary && <small>{summary}</small>}</span>
            {changed && <span className="permission-change-label">تغيّر</span>}
            {count !== undefined && <bdi dir="ltr" className="permission-panel-count">{count}</bdi>}
            <span className="permission-open-label">{open ? 'مفتوح' : 'مغلق'}</span>
            <Icon name="chevron-down" className={`h-4 w-4 shrink-0 transition-transform motion-reduce:transition-none ${open ? 'rotate-180' : ''}`} />
        </button>
        <div id={id} className="permission-panel-body" hidden={!open}>{children}</div>
    </section>;
}
