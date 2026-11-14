export default function InputError({ message, className = '' }) {
    if (!message) {
        return null;
    }

    return (
        <p data-input-error className={`text-sm leading-7 text-brand-600 ${className}`}>
            {message}
        </p>
    );
}
