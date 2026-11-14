/**
 * The Arabic message for a field that fails its own rules (required, min,
 * pattern…), in place of the browser's English pop-up.
 */
function messageFor(field) {
    const { validity } = field;

    if (validity.valueMissing) {
        return field.tagName === 'SELECT' || field.type === 'radio' ? 'اختر قيمة لهذا الحقل.' : 'هذا الحقل مطلوب.';
    }

    if (validity.badInput) {
        return 'أدخل رقمًا صحيحًا.';
    }

    if (validity.typeMismatch) {
        return field.type === 'email' ? 'أدخل بريدًا إلكترونيًا صحيحًا.' : 'القيمة المدخلة غير صحيحة.';
    }

    if (validity.rangeUnderflow) {
        return `يجب ألا تقل القيمة عن ${field.min}.`;
    }

    if (validity.rangeOverflow) {
        return `يجب ألا تزيد القيمة عن ${field.max}.`;
    }

    if (validity.stepMismatch) {
        return 'عدد الخانات بعد الفاصلة العشرية أكثر من المسموح.';
    }

    if (validity.tooShort) {
        return `يجب ألا يقل عن ${field.minLength} خانات.`;
    }

    if (validity.tooLong) {
        return `يجب ألا يزيد عن ${field.maxLength} خانة.`;
    }

    return field.title || 'القيمة المدخلة غير صحيحة.';
}

/**
 * Check a form's fields against their own rules before sending it. Each
 * invalid field gets its message under it, keyed by its name or id like
 * the server's errors, and the first one is focused. Returns whether the
 * form can be sent.
 */
export function validateFormFields(formElement, form) {
    const invalidFields = [...formElement.elements].filter((field) => field.willValidate && !field.checkValidity());

    if (invalidFields.length === 0) {
        return true;
    }

    form.clearErrors();
    form.setError(
        Object.fromEntries(invalidFields.filter((field) => field.name || field.id).map((field) => [field.name || field.id, messageFor(field)])),
    );
    invalidFields[0].focus();

    return false;
}

/**
 * Save a form with Ctrl + Enter (⌘ + Enter on a Mac) from any of its
 * fields, a textarea included, going through its usual checks.
 */
export function submitOnCtrlEnter(event) {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey) && !event.nativeEvent?.isComposing) {
        event.preventDefault();
        event.currentTarget.requestSubmit();
    }
}

/**
 * Clear a field's error as soon as it is edited, so the message doesn't
 * linger once the value is fixed.
 */
export function clearErrorOnInput(event, form) {
    const key = event.target.name || event.target.id;

    if (key && form.errors[key]) {
        form.clearErrors(key);
    }
}

/**
 * A typed number as the field's format expects it: Arabic-Indic digits
 * (٠–٩, ۰–۹) as 0–9, and spaces and dashes dropped — so 056 ٢٢٢-٢٢٢٢ is
 * 0562222222.
 */
export function plainDigits(value) {
    return String(value ?? '')
        .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x06f0))
        .replace(/[\s-]+/g, '');
}
