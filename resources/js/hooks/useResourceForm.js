import { useForm } from '@inertiajs/react';

/**
 * The create/edit form of a resource, shared by its modal and its
 * standalone Create/Edit pages. `save()` POSTs to `url` for a new record
 * and PUTs to `url/{id}` when `record` is given.
 *
 * Returns everything `useForm()` does, plus `isEdit` and `save`.
 */
export function useResourceForm(url, record, initialData) {
    const form = useForm(initialData);
    const isEdit = Boolean(record);

    function save(options = {}) {
        if (isEdit) {
            form.put(`${url}/${record.id}`, options);
        } else {
            form.post(url, options);
        }
    }

    return { ...form, isEdit, save };
}
