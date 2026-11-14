import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';

/** The current address without the open statement. */
function urlWithoutStatement() {
    const url = new URL(window.location.href);
    url.searchParams.delete('statement');

    return `${url.pathname}${url.search}`;
}

/**
 * The statement window of a page whose controller sends the `statement`
 * prop (BuildsSubscriptionStatement::requestedStatement): a subscription's
 * financial history shown over the page. It is open while a statement is
 * loading or once one is in the page props; the subscription is kept in the
 * address (?statement=…) so the window stays open after a payment, charge
 * or discount is saved in it, and Back closes it.
 *
 * Returns `subscription` (the header to show, or null when closed), `form`
 * (the form to open at once, if any), `focusLineId` (the line to go to and
 * mark, if any), `open(header, form?, focusLineId?)` and `close()`.
 * `header` is { id, fullName, … } as StatementModal reads it.
 */
export function useStatementWindow(statement) {
    const [loading, setLoading] = useState(null);
    const [form, setForm] = useState(null);
    const [focusLineId, setFocusLineId] = useState(null);
    const request = useRef(null);

    function open(header, initialForm = null, lineId = null) {
        setLoading(header);
        setForm(initialForm);
        setFocusLineId(lineId);
        router.reload({
            data: { statement: header.id },
            only: ['statement'],
            onCancelToken: (token) => (request.current = token),
            onFinish: () => {
                request.current = null;
                setLoading(null);
            },
        });
    }

    function close() {
        request.current?.cancel();
        setLoading(null);
        setForm(null);
        setFocusLineId(null);
        router.replace({
            url: urlWithoutStatement(),
            props: (props) => ({ ...props, statement: null }),
            preserveScroll: true,
            preserveState: true,
        });
    }

    const subscription = loading ?? statement?.subscription ?? null;

    return {
        subscription,
        // The loaded statement, once it is the one asked for.
        statement: subscription && statement?.subscription.id === subscription.id ? statement : null,
        form,
        focusLineId,
        open,
        close,
    };
}
