/** The sample number the request preview and the hints use: a Jawwal mobile. */
export const SAMPLE_LOCAL_PHONE = '0599123456';

/** A phone as the provider receives it: international (970599…) or local (0599…). */
export function phoneAs(format, countryCode, localPhone = SAMPLE_LOCAL_PHONE) {
    const digits = String(localPhone).replace(/\D/g, '');

    return format === 'local' ? digits : `${String(countryCode ?? '').replace(/\D/g, '')}${digits.replace(/^0+/, '')}`;
}

/** The fixed fields written as a query string ("type=unicode&lang=ar"), as pairs. */
export function extraFields(text) {
    return String(text ?? '')
        .split('&')
        .map((pair) => pair.trim())
        .filter(Boolean)
        .map((pair) => {
            const [name, ...value] = pair.split('=');

            return { name: safeDecode(name), value: safeDecode(value.join('=')) };
        })
        .filter((field) => field.name);
}

function safeDecode(text) {
    try {
        return decodeURIComponent(text.replace(/\+/g, ' '));
    } catch {
        return text;
    }
}

/**
 * The request a message would make with the settings on the page, as
 * HttpSmsGateway builds it: the fixed fields, the number and the text, the
 * sender, and the user name and password under the provider's field names,
 * plus the token as a header. Secrets show as dots: `password` / `token`
 * when typed, else `hasPassword` / `hasToken` when one is saved.
 *
 * @returns {{ method: string, url: string, fields: Array<{ name: string, value: string, secret?: boolean, role?: string }>, headers: Array<{ name: string, value: string, secret?: boolean }> }}
 */
export function requestPreview(data, { hasPassword = false, hasToken = false, message = 'نص الرسالة' } = {}) {
    const fields = [...extraFields(data.extra_params).map((field) => ({ ...field, role: 'extra' }))];
    const put = (name, value, extra = {}) => {
        if (!name) {
            return;
        }

        const index = fields.findIndex((field) => field.name === name);
        const field = { name, value, ...extra };

        index === -1 ? fields.push(field) : (fields[index] = field);
    };

    put(data.to_param, phoneAs(data.phone_format, data.country_code), { role: 'to' });
    put(data.message_param, message, { role: 'message' });

    if (data.sender) {
        put(data.sender_param, data.sender, { role: 'sender' });
    }

    if (data.username) {
        put(data.username_param, data.username, { role: 'username' });
    }

    if (data.password || (hasPassword && !data.clear_password)) {
        put(data.password_param, '••••••••', { role: 'password', secret: true });
    }

    const headers = data.token || (hasToken && !data.clear_token) ? [{ name: 'Authorization', value: 'Bearer ••••••••', secret: true }] : [];

    return { method: data.method === 'get' ? 'GET' : 'POST', url: data.url || 'https://…', fields, headers };
}

/** What still stops the provider from being turned on, in the order to fix it. */
export function missingForSending(data) {
    return [
        !data.url && 'رابط الإرسال',
        !data.to_param && 'اسم حقل الرقم',
        !data.message_param && 'اسم حقل الرسالة',
    ].filter(Boolean);
}
