import Icon from '@/Components/Icon';
import Pagination from '@/Components/DataTable/Pagination';

/** An employee's initial on a tile in their job title's colour. */
export function EmployeeAvatar({ name, color, size = 'md' }) {
    const sizes = { sm: 'h-8 w-8 rounded-[10px] text-xs', md: 'h-10 w-10 rounded-[13px] text-sm', lg: 'h-14 w-14 rounded-[18px] text-xl' };

    return (
        <span className={`pm-avatar font-display font-bold ${sizes[size]}`} style={{ '--role': color }} aria-hidden="true">
            {(name ?? '').trim().substring(0, 1)}
        </span>
    );
}

/** A job title as a small chip with its colour. */
export function RoleChip({ label, color, className = '' }) {
    return (
        <span className={`pm-role-chip ${className}`} style={{ '--role': color }}>
            <i aria-hidden="true" />
            {label}
        </span>
    );
}

/**
 * The side panel: search, the job titles in their colours (and the branch
 * for the Super Admin), then the employees under their job title, each with
 * how many permissions they hold, how many of those are dangerous, and a
 * bar for how far their access reaches.
 */
export default function EmployeeList({
    users,
    selectedId,
    selectedDirty,
    filters,
    filterOptions,
    search,
    onSearchChange,
    filterValues,
    onFilterChange,
    onSelect,
    searchRef,
    colorOf,
    statsOf,
}) {
    const roleGroup = filterOptions.find((group) => group.key === 'user_type_id');
    const branchGroup = filterOptions.find((group) => group.key === 'branch_id');
    const order = (roleGroup?.options ?? []).map((option) => option.label);
    const byRole = users.data.reduce((groups, user) => {
        const role = user.roleLabel ?? 'غير محدد';
        (groups[role] ??= []).push(user);
        return groups;
    }, {});
    const roles = Object.keys(byRole).sort((first, second) => {
        const rank = (label) => (label === 'غير محدد' ? 1000 : order.indexOf(label) === -1 ? 999 : order.indexOf(label));
        return rank(first) - rank(second);
    });
    const showBranches = new Set(users.data.map((user) => user.branchName ?? '')).size > 1;

    return (
        <aside className="pm-people rise-in" aria-label="الموظفون">
            <div className="pm-people-head">
                <h3 className="flex items-center gap-2 text-lg font-bold text-gray-900">
                    الموظفون
                    <span className="ms-auto font-display text-sm font-semibold text-gray-500">{users.total.toLocaleString('en')} موظف</span>
                </h3>
                <div className="relative mt-3">
                    <Icon name="search" className="pointer-events-none absolute inset-y-0 start-3.5 my-auto h-[18px] w-[18px] text-gray-400" />
                    <input
                        ref={searchRef}
                        type="text"
                        value={search}
                        onChange={(event) => onSearchChange(event.target.value)}
                        placeholder="ابحث عن موظف..."
                        aria-label="البحث عن موظف بالاسم أو اسم المستخدم"
                        className="block w-full py-2.5 pe-10 ps-10 text-sm"
                    />
                    <span className="kbd pointer-events-none absolute inset-y-0 end-3 my-auto h-fit">/</span>
                </div>
                {roleGroup && (
                    <div className="mt-3 flex flex-wrap gap-1.5" role="group" aria-label={roleGroup.label}>
                        {[{ value: '', label: 'الكل' }, ...roleGroup.options].map((option) => {
                            const pressed = (filterValues.user_type_id ?? '') === option.value;

                            return (
                                <button
                                    key={option.value}
                                    type="button"
                                    aria-pressed={pressed}
                                    onClick={() => onFilterChange('user_type_id', option.value)}
                                    className="pm-filter-chip"
                                    style={option.value ? { '--role': colorOf(option.label) } : undefined}
                                >
                                    {option.value && <i aria-hidden="true" />}
                                    {option.label}
                                </button>
                            );
                        })}
                    </div>
                )}
                {branchGroup && (
                    <select
                        value={filterValues.branch_id ?? ''}
                        onChange={(event) => onFilterChange('branch_id', event.target.value)}
                        aria-label={branchGroup.label}
                        className="mt-3 block w-full py-2 text-sm"
                    >
                        <option value="">جميع الفروع</option>
                        {branchGroup.options.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                )}
            </div>

            <div className="pm-people-list">
                {users.data.length === 0 ? (
                    <p className="px-4 py-10 text-center text-sm text-gray-500">لا يوجد موظفون مطابقون.</p>
                ) : (
                    roles.map((role) => (
                        <section key={role} aria-label={role} style={{ '--role': colorOf(role) }}>
                            <p className="pm-people-role">
                                <i aria-hidden="true" />
                                <span>{role}</span>
                                <span className="ms-auto font-display">{byRole[role].length}</span>
                            </p>
                            <ul className="space-y-1">
                                {byRole[role].map((user) => {
                                    const isSelected = user.id === selectedId;
                                    const stats = statsOf(user);

                                    return (
                                        <li key={user.id}>
                                            <button
                                                type="button"
                                                data-employee={user.id}
                                                onClick={() => onSelect(user)}
                                                aria-current={isSelected ? 'true' : undefined}
                                                className={`pm-person ${isSelected ? 'is-selected' : ''}`}
                                            >
                                                <EmployeeAvatar name={user.name} color={colorOf(role)} />
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-center gap-1.5">
                                                        <span className="truncate text-[15px] font-bold text-gray-900">{user.name}</span>
                                                        {isSelected && selectedDirty && <span title="تغييرات لم تُحفظ" className="pm-unsaved" />}
                                                        {user.isActive === false && (
                                                            <span className="rounded-md bg-gray-100 px-1.5 text-[12px] font-bold text-gray-500">
                                                                موقوف
                                                            </span>
                                                        )}
                                                    </span>
                                                    <span className="mt-0.5 flex items-center gap-2.5 text-[13px] text-gray-500">
                                                        <span className="inline-flex items-center gap-1" title="عدد الصلاحيات">
                                                            <Icon name="key" className="h-3.5 w-3.5" />
                                                            <b className="font-display font-semibold text-gray-700">{stats.total}</b>
                                                        </span>
                                                        {stats.danger > 0 && (
                                                            <span
                                                                className="inline-flex items-center gap-1 text-red-700 dark:text-red-400"
                                                                title="صلاحيات خطيرة"
                                                            >
                                                                <Icon name="alert" className="h-3.5 w-3.5" />
                                                                <b className="font-display font-semibold">{stats.danger}</b>
                                                            </span>
                                                        )}
                                                        {showBranches && <span className="truncate">{user.branchName ?? 'بلا فرع'}</span>}
                                                    </span>
                                                    <span className={`pm-reach is-${stats.level}`} title={`مدى الوصول: خطورة ${stats.label}`}>
                                                        <i style={{ width: `${Math.max(stats.total ? 4 : 0, stats.share)}%` }} />
                                                    </span>
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    ))
                )}
            </div>

            {users.last_page > 1 && (
                <div className="border-t border-gray-100 p-3">
                    <Pagination meta={users} filters={filters} baseUrl="/settings/permissions" extraParams={{ selected: selectedId }} />
                </div>
            )}
        </aside>
    );
}
