const TEAM = [
    { name: 'محمد وديع حمدان', phone: '+970595252308', phoneLabel: '+970 59 525 2308' },
    { name: 'يوسف مروان حمدان', phone: '+972592737615', phoneLabel: '+972 59 273 7615' },
    { name: 'مصطفى سامح فرحات', phone: '+972599245796', phoneLabel: '+972 59 924 5796' },
    { name: 'فرح هاني الناعوق' },
    { name: 'انسام باسم ابو مسلم' },
];

export default function DevelopmentCredits() {
    return <div className="af-team">
        <p className="af-team__label">فريق التطوير</p>
        <ul className="af-team__names">
            {TEAM.map((member) => <li key={member.name}>
                {member.phone ? <a className="af-team__member" href={`tel:${member.phone}`} aria-label={`اتصال بـ${member.name}: ${member.phoneLabel}`}>
                    <span>{member.name}</span>
                    <span className="af-team__phone" dir="ltr">{member.phoneLabel}</span>
                </a> : <span className="af-team__member">{member.name}</span>}
            </li>)}
        </ul>
    </div>;
}
