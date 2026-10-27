import AuthenticatedLayout from './AuthenticatedLayout';

/**
 * Layout for the settings pages (Branches, Tariffs, Circuit Breakers, Meter
 * Boxes, Governorates, Permissions, Reading Schedule, Print Templates).
 * The top bar places these pages in their relevant sections.
 */
export default function SettingsLayout({ header, scope = null, children }) {
    return <AuthenticatedLayout header={header} scope={scope}>{children}</AuthenticatedLayout>;
}
