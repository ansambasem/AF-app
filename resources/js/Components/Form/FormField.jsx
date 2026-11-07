import { cloneElement } from 'react';
import InfoTip from '@/Components/InfoTip';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';

/**
 * One labelled field of a form: its label (with a star when required), an
 * "!" beside it holding the field's guidance (`hint`), the control (given
 * the field's id) and the error the server found. `span` is a grid class
 * to make it wider.
 */
export default function FormField({ id, label, required, error, hint, span = '', children }) {
    return (
        <div className={span}>
            <div className="flex items-center gap-1">
                <InputLabel htmlFor={id}>
                    {label}
                    {required && <span className="text-brand-500"> *</span>}
                </InputLabel>
                {hint && <InfoTip label={`عن حقل ${typeof label === 'string' ? label : ''}`.trim()}>{hint}</InfoTip>}
            </div>
            <div className="mt-1">{cloneElement(children, { id })}</div>
            <InputError message={error} className="mt-1" />
        </div>
    );
}
