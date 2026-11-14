/**
 * A text field. Corners, border and the focus ring come from the shared field styles in app.css.
 */
export default function TextInput({ className = '', ...props }) {
    return <input {...props} className={`block ${className}`} />;
}
