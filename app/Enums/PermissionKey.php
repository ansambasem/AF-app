<?php

namespace App\Enums;

enum PermissionKey: string
{
    case ViewBranches = 'branches.view';
    case ManageBranchDomains = 'branches.manage_domains';
    case CreateBranches = 'branches.create';
    case UpdateBranches = 'branches.update';
    case DeleteBranches = 'branches.delete';

    case ViewUsers = 'users.view';
    case CreateUsers = 'users.create';
    case UpdateUsers = 'users.update';
    case DeleteUsers = 'users.delete';
    case ManagePermissions = 'users.manage_permissions';
    case ManageUserPasswords = 'users.manage_passwords';
    case ManageBranchSchedules = 'users.manage_branch_schedules';
    case ManageBranchClosings = 'collections.manage_branch_closings';

    case ViewUserTypes = 'user_types.view';
    case CreateUserTypes = 'user_types.create';
    case UpdateUserTypes = 'user_types.update';
    case DeleteUserTypes = 'user_types.delete';

    case ViewSubscriptions = 'subscriptions.view';
    case CreateSubscriptions = 'subscriptions.create';
    case UpdateSubscriptions = 'subscriptions.update';
    case DeleteSubscriptions = 'subscriptions.delete';
    case ArchiveSubscriptions = 'subscriptions.archive';
    case ViewArchivedSubscriptions = 'subscriptions.view_archived';
    case RestoreArchivedSubscriptions = 'subscriptions.restore_archived';
    case UpdateSubscriptionMinimumCharge = 'subscriptions.update_minimum_charge';
    case UpdateSubscriptionKilowattPrice = 'subscriptions.update_kilowatt_price';
    case BulkUpdateSubscriptions = 'subscriptions.bulk_update';

    case ViewTariffs = 'tariffs.view';
    case CreateTariffs = 'tariffs.create';
    case UpdateTariffs = 'tariffs.update';
    case DeleteTariffs = 'tariffs.delete';

    case ViewCircuitBreakers = 'circuit_breakers.view';
    case CreateCircuitBreakers = 'circuit_breakers.create';
    case UpdateCircuitBreakers = 'circuit_breakers.update';
    case DeleteCircuitBreakers = 'circuit_breakers.delete';

    case ViewMeterBoxes = 'meter_boxes.view';
    case CreateMeterBoxes = 'meter_boxes.create';
    case UpdateMeterBoxes = 'meter_boxes.update';
    case DeleteMeterBoxes = 'meter_boxes.delete';

    case ViewAreas = 'areas.view';
    case CreateAreas = 'areas.create';
    case UpdateAreas = 'areas.update';
    case DeleteAreas = 'areas.delete';

    case ViewSubAreas = 'sub_areas.view';
    case CreateSubAreas = 'sub_areas.create';
    case UpdateSubAreas = 'sub_areas.update';
    case DeleteSubAreas = 'sub_areas.delete';

    case ViewGovernorates = 'governorates.view';
    case CreateGovernorates = 'governorates.create';
    case UpdateGovernorates = 'governorates.update';
    case DeleteGovernorates = 'governorates.delete';

    case ViewMeterReadings = 'meter_readings.view';
    case RecordMeterReadings = 'meter_readings.record';
    case CorrectMeterReadings = 'meter_readings.correct';
    case CorrectLastMeterReadings = 'meter_readings.correct_last';
    case BrowseMeterReadingWeeks = 'meter_readings.browse_weeks';
    case ApproveMeterReadings = 'meter_readings.approve';

    case ViewReadingAssignments = 'reading_assignments.view';
    case ManageReadingAssignments = 'reading_assignments.manage';
    case ApproveReadingAssignments = 'reading_assignments.approve';
    case PrintReadingSheets = 'reading_assignments.print_sheets';
    case ReviewReadingSubmissions = 'reading_assignments.review';

    case RecordCollections = 'collections.record';
    case RecordLatePayments = 'collections.record_late';
    case RecordCashPayments = 'collections.record_cash';
    case RecordTransferPayments = 'collections.record_transfer';
    case CloseCashBoxes = 'collections.close_boxes';
    case ManageBoxPeriods = 'collections.manage_box_periods';
    case SendCashBoxesToAudit = 'collections.send_boxes';
    case ViewCollections = 'collections.view';
    case AddCharges = 'collections.charge';
    case AddDiscounts = 'collections.discount';
    case AddClearings = 'collections.clearing';
    case CorrectTransactions = 'collections.correct';
    case AmendTransactionDetails = 'collections.amend';
    case RefundPayments = 'collections.refund';
    case DeleteTransactions = 'collections.delete';
    case ForceDeleteTransactions = 'collections.force_delete';
    case PrepareClosings = 'closings.prepare';
    case ViewOwnClosings = 'closings.view';
    case AuditClosings = 'closings.audit';
    case ViewAllClosings = 'closings.view_all';

    case ViewFinancialReports = 'reports.view';
    case ViewAllFinancialReports = 'reports.view_all';
    case ViewBranchPerformance = 'reports.branch_performance';
    case ViewDebtAging = 'reports.debt_aging';
    case ViewTransactionAudit = 'reports.transaction_audit';
    case ExportFinancialReports = 'reports.export';

    case ViewDisconnections = 'disconnections.view';
    case ManageDisconnections = 'disconnections.manage';
    case WarnDisconnections = 'disconnections.warn';
    case ExecuteDisconnections = 'disconnections.execute';
    case ChangeDisconnectionFee = 'disconnections.change_fee';

    case ViewMessages = 'messages.view';
    case SendMessages = 'messages.send';

    case ManagePrintTemplates = 'print_templates.manage';
    case PrintCompanyForms = 'company_forms.print';

    public function label(): string
    {
        return match ($this) {
            self::ViewBranches => 'View Branches',
            self::ManageBranchDomains => 'إدارة روابط الفروع',
            self::CreateBranches => 'Add Branches',
            self::UpdateBranches => 'Edit Branches',
            self::DeleteBranches => 'Delete Branches',
            self::ViewUsers => 'View Users',
            self::CreateUsers => 'Add Users',
            self::UpdateUsers => 'Edit Users',
            self::DeleteUsers => 'Delete Users',
            self::ManagePermissions => 'إدارة صلاحيات موظفي الفرع',
            self::ManageUserPasswords => 'تغيير كلمات مرور موظفي الفرع',
            self::ManageBranchSchedules => 'إدارة مواعيد الفرع',
            self::ManageBranchClosings => 'إدارة واعتماد صناديق الفرع',
            self::ViewUserTypes => 'View User Types',
            self::CreateUserTypes => 'Add User Types',
            self::UpdateUserTypes => 'Edit User Types',
            self::DeleteUserTypes => 'Delete User Types',
            self::ViewSubscriptions => 'View Subscriptions',
            self::CreateSubscriptions => 'Add Subscriptions',
            self::UpdateSubscriptions => 'Edit Subscriptions',
            self::DeleteSubscriptions => 'Delete Subscriptions',
            self::ArchiveSubscriptions => 'أرشفة الاشتراكات المنتهية',
            self::ViewArchivedSubscriptions => 'عرض الاشتراكات المؤرشفة',
            self::RestoreArchivedSubscriptions => 'استعادة الاشتراكات المؤرشفة',
            self::UpdateSubscriptionMinimumCharge => 'Edit Subscription Minimum Charge',
            self::UpdateSubscriptionKilowattPrice => 'Edit Subscription Kilo Price',
            self::BulkUpdateSubscriptions => 'Bulk Edit Subscriptions',
            self::ViewTariffs => 'View Tariffs',
            self::CreateTariffs => 'Add Tariffs',
            self::UpdateTariffs => 'Edit Tariffs',
            self::DeleteTariffs => 'Delete Tariffs',
            self::ViewCircuitBreakers => 'View Circuit Breakers',
            self::CreateCircuitBreakers => 'Add Circuit Breakers',
            self::UpdateCircuitBreakers => 'Edit Circuit Breakers',
            self::DeleteCircuitBreakers => 'Delete Circuit Breakers',
            self::ViewMeterBoxes => 'View Meter Boxes',
            self::CreateMeterBoxes => 'Add Meter Boxes',
            self::UpdateMeterBoxes => 'Edit Meter Boxes',
            self::DeleteMeterBoxes => 'Delete Meter Boxes',
            self::ViewAreas => 'View Areas',
            self::CreateAreas => 'Add Areas',
            self::UpdateAreas => 'Edit Areas',
            self::DeleteAreas => 'Delete Areas',
            self::ViewSubAreas => 'View Sub Areas',
            self::CreateSubAreas => 'Add Sub Areas',
            self::UpdateSubAreas => 'Edit Sub Areas',
            self::DeleteSubAreas => 'Delete Sub Areas',
            self::ViewGovernorates => 'View Governorates',
            self::CreateGovernorates => 'Add Governorates',
            self::UpdateGovernorates => 'Edit Governorates',
            self::DeleteGovernorates => 'Delete Governorates',
            self::ViewMeterReadings => 'View Meter Readings',
            self::RecordMeterReadings => 'Record Meter Readings',
            self::CorrectMeterReadings => 'Correct Meter Readings',
            self::CorrectLastMeterReadings => 'Edit Last Readings',
            self::BrowseMeterReadingWeeks => 'Browse Earlier Reading Weeks',
            self::ApproveMeterReadings => 'Approve Meter Readings',
            self::ViewReadingAssignments => 'View Reading Assignments',
            self::ManageReadingAssignments => 'Assign Meter Boxes to Readers',
            self::ApproveReadingAssignments => 'Approve Reading Assignments',
            self::PrintReadingSheets => 'Print Paper Reading Sheets',
            self::ReviewReadingSubmissions => 'Review Field Readings',
            self::RecordCollections => 'Add Payments',
            self::RecordLatePayments => 'تسجيل دفعات بتاريخ سابق',
            self::RecordCashPayments => 'Take Cash Payments',
            self::RecordTransferPayments => 'Take Bank Transfer Payments',
            self::CloseCashBoxes => 'Close Cash Boxes',
            self::ManageBoxPeriods => 'Edit Box Period Start Day',
            self::SendCashBoxesToAudit => 'Send Cash Boxes to Audit',
            self::ViewCollections => 'View Collections',
            self::AddCharges => 'Add Charges',
            self::AddDiscounts => 'Add Discounts',
            self::AddClearings => 'Add Clearings',
            self::CorrectTransactions => 'Edit Transactions',
            self::AmendTransactionDetails => 'Edit Payment Details',
            self::RefundPayments => 'Refund Payments',
            self::DeleteTransactions => 'Cancel Transactions',
            self::ForceDeleteTransactions => 'Permanently Delete Transactions',
            self::PrepareClosings => 'Prepare Closings',
            self::ViewOwnClosings => 'View Branch Closings',
            self::AuditClosings => 'Financial Audit Across All Branches',
            self::ViewAllClosings => 'عرض الصناديق المالية لجميع الفروع',
            self::ViewFinancialReports => 'View Financial Reports',
            self::ViewAllFinancialReports => 'View All Financial Reports',
            self::ViewBranchPerformance => 'View Branch Performance',
            self::ViewDebtAging => 'View Debt Aging',
            self::ViewTransactionAudit => 'View Audit Log',
            self::ExportFinancialReports => 'Export Financial Reports',
            self::ViewDisconnections => 'View Disconnection Lists',
            self::ManageDisconnections => 'Manage Disconnection Lists',
            self::WarnDisconnections => 'Send Disconnection Warnings',
            self::ExecuteDisconnections => 'Confirm Service Disconnections',
            self::ChangeDisconnectionFee => 'Change Disconnection Fees',
            self::ViewMessages => 'View Messages',
            self::SendMessages => 'Send Messages',
            self::ManagePrintTemplates => 'Manage Print Templates',
            self::PrintCompanyForms => 'طباعة وأرشفة نماذج الشركة',
        };
    }

    public function isRetired(): bool
    {
        return $this === self::SendCashBoxesToAudit
            || (! in_array($this, [self::AuditClosings, self::ViewAllClosings], true) && str_starts_with($this->value, 'closings.'));
    }

    /**
     * Whether this permission shapes the company itself — its branches and
     * the governorate/area map they sit in — rather than work inside one
     * branch. That is the general manager's job, so only a Super Admin may
     * grant these. (Sub-areas stay grantable: staff add them only inside
     * their own branch's area.) The company also controls delegation of
     * each branch's period-start setting.
     */
    /**
     * Whether a branch manager may hand this permission on, given the
     * right to by the Super Admin: never a company-wide one, nor managing
     * permissions itself, so no one but the Super Admin makes a manager.
     */
    public function isDelegable(): bool
    {
        return ! $this->isCompanyWide() && $this !== self::ManagePermissions;
    }

    public function isCompanyWide(): bool
    {
        return match ($this) {
            self::ViewBranches, self::CreateBranches, self::UpdateBranches, self::DeleteBranches, self::ManageBranchDomains,
            self::ViewGovernorates, self::CreateGovernorates, self::UpdateGovernorates, self::DeleteGovernorates,
            self::ViewAreas, self::CreateAreas, self::UpdateAreas, self::DeleteAreas,
            self::ViewUserTypes, self::CreateUserTypes, self::UpdateUserTypes, self::DeleteUserTypes,
            // Reviewing closings, or seeing every branch's, reaches past one branch, so the company grants it.
            self::AuditClosings, self::ViewAllClosings, self::ViewAllFinancialReports => true,
            // Print templates are shared by every branch.
            self::ManagePrintTemplates => true,
            self::ManageBoxPeriods => true,
            self::ManagePermissions, self::ManageUserPasswords, self::ManageBranchSchedules, self::ManageBranchClosings => true,
            default => false,
        };
    }

    /**
     * Every permission key, grouped by the table/resource it governs, for
     * rendering the Settings → Permissions matrix, in the order it reads:
     * everyday work, then money, reports and messages, then administration. Each group lists its
     * columns in display order as [action => PermissionKey]; a resource
     * that has no grantable key for a given action (e.g. Collections has no
     * "create" — it's recorded/confirmed instead) simply omits that action.
     *
     * @return array<string, array{label: string, actions: array<string, self>}>
     */
    public static function resourceGroups(): array
    {
        return [
            'subscriptions' => [
                'label' => 'Subscriptions',
                'actions' => [
                    'view' => self::ViewSubscriptions,
                    'create' => self::CreateSubscriptions,
                    'update' => self::UpdateSubscriptions,
                    'delete' => self::DeleteSubscriptions,
                    'archive' => self::ArchiveSubscriptions,
                    'view_archived' => self::ViewArchivedSubscriptions,
                    'restore_archived' => self::RestoreArchivedSubscriptions,
                    'minimum_charge' => self::UpdateSubscriptionMinimumCharge,
                    'kilowatt_price' => self::UpdateSubscriptionKilowattPrice,
                    'bulk_update' => self::BulkUpdateSubscriptions,
                ],
            ],
            'meter_boxes' => [
                'label' => 'Meter Boxes',
                'actions' => ['view' => self::ViewMeterBoxes, 'create' => self::CreateMeterBoxes, 'update' => self::UpdateMeterBoxes, 'delete' => self::DeleteMeterBoxes],
            ],
            'circuit_breakers' => [
                'label' => 'Circuit Breakers',
                'actions' => ['view' => self::ViewCircuitBreakers, 'create' => self::CreateCircuitBreakers, 'update' => self::UpdateCircuitBreakers, 'delete' => self::DeleteCircuitBreakers],
            ],
            'tariffs' => [
                'label' => 'Tariffs',
                'actions' => ['view' => self::ViewTariffs, 'create' => self::CreateTariffs, 'update' => self::UpdateTariffs, 'delete' => self::DeleteTariffs],
            ],
            'meter_readings' => [
                'label' => 'Meter Readings',
                'actions' => [
                    'view' => self::ViewMeterReadings,
                    'record' => self::RecordMeterReadings,
                    'browse_weeks' => self::BrowseMeterReadingWeeks,
                    'correct' => self::CorrectMeterReadings,
                    'correct_last' => self::CorrectLastMeterReadings,
                    'approve' => self::ApproveMeterReadings,
                ],
            ],
            'reading_assignments' => [
                'label' => 'Reading Assignments',
                'actions' => [
                    'view' => self::ViewReadingAssignments,
                    'manage' => self::ManageReadingAssignments,
                    'print_sheets' => self::PrintReadingSheets,
                    'approve' => self::ApproveReadingAssignments,
                    'review' => self::ReviewReadingSubmissions,
                ],
            ],
            'collections' => [
                'label' => 'Payments and Transactions',
                'actions' => [
                    'view' => self::ViewCollections,
                    'record' => self::RecordCollections,
                    'record_late' => self::RecordLatePayments,
                    'record_cash' => self::RecordCashPayments,
                    'record_transfer' => self::RecordTransferPayments,
                    'close_boxes' => self::CloseCashBoxes,
                    'manage_box_periods' => self::ManageBoxPeriods,
                    'manage_branch_closings' => self::ManageBranchClosings,
                    'charge' => self::AddCharges,
                    'discount' => self::AddDiscounts,
                    'clearing' => self::AddClearings,
                    'correct' => self::CorrectTransactions,
                    'amend' => self::AmendTransactionDetails,
                    'refund' => self::RefundPayments,
                    'delete' => self::DeleteTransactions,
                    'force_delete' => self::ForceDeleteTransactions,
                ],
            ],
            'financial_audit' => [
                'label' => 'التدقيق المالي',
                'actions' => ['audit' => self::AuditClosings, 'view_all_boxes' => self::ViewAllClosings],
            ],
            'reports' => [
                'label' => 'Reports',
                'actions' => [
                    'view' => self::ViewFinancialReports,
                    'view_all' => self::ViewAllFinancialReports,
                    'branch_performance' => self::ViewBranchPerformance,
                    'debt_aging' => self::ViewDebtAging,
                    'transaction_audit' => self::ViewTransactionAudit,
                    'export' => self::ExportFinancialReports,
                ],
            ],
            'disconnections' => [
                'label' => 'Disconnection Lists',
                'actions' => [
                    'view' => self::ViewDisconnections,
                    'manage' => self::ManageDisconnections,
                    'warn' => self::WarnDisconnections,
                    'execute' => self::ExecuteDisconnections,
                    'change_fee' => self::ChangeDisconnectionFee,
                ],
            ],
            'messages' => [
                'label' => 'Messages',
                'actions' => ['view' => self::ViewMessages, 'send' => self::SendMessages],
            ],
            'print_templates' => [
                'label' => 'Print Templates',
                'actions' => ['manage' => self::ManagePrintTemplates, 'print_company_forms' => self::PrintCompanyForms],
            ],
            'users' => [
                'label' => 'Users',
                'actions' => ['view' => self::ViewUsers, 'create' => self::CreateUsers, 'update' => self::UpdateUsers, 'delete' => self::DeleteUsers, 'manage_permissions' => self::ManagePermissions, 'manage_passwords' => self::ManageUserPasswords, 'manage_branch_schedules' => self::ManageBranchSchedules],
            ],
            'user_types' => [
                'label' => 'User Types',
                'actions' => ['view' => self::ViewUserTypes, 'create' => self::CreateUserTypes, 'update' => self::UpdateUserTypes, 'delete' => self::DeleteUserTypes],
            ],
            'branches' => [
                'label' => 'Branches',
                'actions' => ['view' => self::ViewBranches, 'create' => self::CreateBranches, 'update' => self::UpdateBranches, 'delete' => self::DeleteBranches, 'manage_domains' => self::ManageBranchDomains],
            ],
            'governorates' => [
                'label' => 'Governorates',
                'actions' => ['view' => self::ViewGovernorates, 'create' => self::CreateGovernorates, 'update' => self::UpdateGovernorates, 'delete' => self::DeleteGovernorates],
            ],
            'areas' => [
                'label' => 'Areas',
                'actions' => ['view' => self::ViewAreas, 'create' => self::CreateAreas, 'update' => self::UpdateAreas, 'delete' => self::DeleteAreas],
            ],
            'sub_areas' => [
                'label' => 'Sub Areas',
                'actions' => ['view' => self::ViewSubAreas, 'create' => self::CreateSubAreas, 'update' => self::UpdateSubAreas, 'delete' => self::DeleteSubAreas],
            ],
        ];
    }
}
