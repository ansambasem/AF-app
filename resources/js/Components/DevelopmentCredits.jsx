import { useState } from 'react';
import DialogFrame from '@/Components/DialogFrame';
import Icon from '@/Components/Icon';

const DEVELOPMENT_TEAM = [
    { name: 'محمد وديع حمدان', phone: '+970595252308', phoneLabel: '+970 59 525 2308' },
    { name: 'يوسف مروان حمدان', phone: '+972592737615', phoneLabel: '+972 59 273 7615' },
    { name: 'مصطفى سامح فرحات', phone: '+972599245796', phoneLabel: '+972 59 924 5796' },
    { name: 'فرح هاني الناعوق' },
    { name: 'انسام باسم ابو مسلم' },
];

export default function DevelopmentCredits() {
    const [open, setOpen] = useState(false);

    return <>
        <button type="button" className="af-footer__credits" aria-haspopup="dialog" onClick={() => setOpen(true)}>
            <Icon name="users" className="h-4 w-4" />
            فريق التطوير
        </button>
        <DialogFrame show={open} onClose={() => setOpen(false)} title="فريق التطوير" icon="users" maxWidth="md">
            <ul className="af-team-dialog__names">
                {DEVELOPMENT_TEAM.map((member) => <li key={member.name} className="af-team-dialog__member">
                    <span className="af-team-dialog__name">{member.name}</span>
                    {member.phone && <a className="af-team__contact" href={`tel:${member.phone}`} aria-label={`اتصال بـ${member.name}: ${member.phoneLabel}`}>
                        <Icon name="phone" className="h-4 w-4" />
                        <span dir="ltr">{member.phoneLabel}</span>
                    </a>}
                </li>)}
            </ul>
        </DialogFrame>
    </>;
}
