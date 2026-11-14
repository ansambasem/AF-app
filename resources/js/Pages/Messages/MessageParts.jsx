import Icon from '@/Components/Icon';
import StatusPill from '@/Components/DataTable/StatusPill';

/** Each kind of message and each way of sending has its own icon, the same wherever it shows. */
export const KIND_ICONS = { weekly_reading: 'gauge', balance_reminder: 'wallet', custom: 'megaphone', disconnection_warning: 'power' };

export const CHANNEL_ICONS = { sms: 'phone', whatsapp: 'chat' };

/** Who each kind of message reaches and what it says, shown under the kinds. */
export const KIND_HINTS = {
    weekly_reading: 'يصل لكل مشترك قراءته في الأسبوع المختار: السابقة والحالية والاستهلاك والمبلغ.',
    balance_reminder: 'يصل لمن عليه رصيد أكبر من الحد الذي تحدده، مع رصيده.',
    custom: 'أي إعلان أو تحديث: انقطاع، مواعيد جديدة، تغيير تعرفة...',
};

/** Select options with their icon added, for ChoiceChips. */
export function withIcons(options, icons) {
    return options.map((option) => ({ ...option, icon: icons[option.value] }));
}

const KIND_TONES = { weekly_reading: 'blue', balance_reminder: 'amber', custom: 'gray', disconnection_warning: 'red' };

const STATUS_TONES = { pending: 'amber', sent: 'green', failed: 'red' };

/** What a send is about: its icon and name, tinted by kind. */
export function KindPill({ kind, label }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <Icon name={KIND_ICONS[kind] ?? 'note'} className="h-4 w-4 shrink-0 text-gray-500" />
            <StatusPill tone={KIND_TONES[kind] ?? 'gray'} label={label} />
        </span>
    );
}

/** How a send went out: SMS or WhatsApp, with its icon. */
export function ChannelLabel({ channel, label }) {
    return (
        <span className="inline-flex items-center gap-1.5 whitespace-nowrap text-gray-700">
            <Icon name={CHANNEL_ICONS[channel] ?? 'send'} className="h-4 w-4 shrink-0 text-gray-500" />
            {label}
        </span>
    );
}

/** How one message went: waiting, sent or failed. */
export function MessageStatusPill({ status, label }) {
    return <StatusPill tone={STATUS_TONES[status] ?? 'gray'} label={label} />;
}

/** "12 أُرسلت · 3 بانتظار الإرسال · 1 فشلت" for a send, leaving out the empty ones. */
export function DeliveryCounts({ batch }) {
    const parts = [
        { count: batch.sent, label: 'أُرسلت', className: 'text-emerald-700 dark:text-emerald-400' },
        { count: batch.pending, label: 'بانتظار الإرسال', className: 'text-amber-700 dark:text-amber-400' },
        { count: batch.failed, label: 'فشلت', className: 'text-brand-600' },
    ].filter((part) => part.count > 0);

    return (
        <span className="flex flex-wrap gap-x-2 text-xs font-semibold">
            <span className="text-gray-500">{batch.total.toLocaleString('en')} رسالة:</span>
            {parts.map((part) => (
                <span key={part.label} className={part.className}>
                    {part.count.toLocaleString('en')} {part.label}
                </span>
            ))}
        </span>
    );
}

/**
 * A message's text with each `{placeholder}` still in it shown as a
 * highlighted tag, so it reads as "filled in for each subscription" rather
 * than as a typo.
 */
export function MessageText({ text }) {
    return (text ?? '').split(/(\{[^{}]+\})/u).map((part, index) =>
        /^\{[^{}]+\}$/u.test(part) ? (
            <mark key={index} className="rounded-md bg-sky-500/15 px-1 font-semibold text-sky-800 dark:text-sky-300">
                {part.slice(1, -1).replaceAll('_', ' ')}
            </mark>
        ) : (
            part
        ),
    );
}

/**
 * The text a subscription gets: every `{placeholder}` replaced by their own
 * value, the same way the server fills it in (MessageComposer::render()).
 */
export function renderMessage(body, variables) {
    return (body ?? '').replace(/\{([^{}]+)\}/gu, (match, name) => variables?.[name.trim()] ?? match);
}
