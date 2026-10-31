<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum UserRole: string
{
    use HasOptions;

    case SuperAdmin = 'super_admin';
    case BranchAdmin = 'branch_admin';
    case Collector = 'collector';
    case DataEntry = 'data_entry';
    case Accountant = 'accountant';
    case FinancialAuditor = 'financial_auditor';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::BranchAdmin => 'Branch Admin',
            self::Collector => 'Collector',
            self::DataEntry => 'Data Entry',
            self::Accountant => 'Accountant',
            self::FinancialAuditor => 'Financial Auditor',
        };
    }

    /**
     * The branch-level staff roles a Branch Admin may assign. Excludes
     * BranchAdmin and FinancialAuditor, which only a Super Admin may assign.
     *
     * @return array<int, self>
     */
    public static function staffRoles(): array
    {
        return [self::Collector, self::DataEntry, self::Accountant];
    }

    /**
     * The roles a Super Admin may assign: every role except Super Admin
     * itself, which is never handed out from the app.
     *
     * @return array<int, self>
     */
    public static function assignableBySuperAdmin(): array
    {
        return [self::BranchAdmin, ...self::staffRoles(), self::FinancialAuditor];
    }

    /**
     * Explicit fixture and sample-seeder presets. Account creation and
     * job-title edits never apply these automatically. Super Admin access
     * remains implicit and independent of permission rows.
     *
     * @return array<int, PermissionKey>
     */
    public function starterPermissions(): array
    {
        return match ($this) {
            self::BranchAdmin => [
                PermissionKey::ManagePermissions, PermissionKey::ManageUserPasswords,
                PermissionKey::ManageBranchSchedules, PermissionKey::ManageBranchClosings,
                PermissionKey::ViewUsers, PermissionKey::CreateUsers, PermissionKey::UpdateUsers,
                PermissionKey::ViewSubscriptions, PermissionKey::CreateSubscriptions, PermissionKey::UpdateSubscriptions,
                PermissionKey::UpdateSubscriptionMinimumCharge,
                PermissionKey::UpdateSubscriptionKilowattPrice,
                PermissionKey::BulkUpdateSubscriptions,
                PermissionKey::ViewMeterBoxes, PermissionKey::CreateMeterBoxes, PermissionKey::UpdateMeterBoxes,
                PermissionKey::ViewSubAreas, PermissionKey::CreateSubAreas, PermissionKey::UpdateSubAreas,
                PermissionKey::ViewMeterReadings, PermissionKey::RecordMeterReadings, PermissionKey::ApproveMeterReadings,
                PermissionKey::CorrectMeterReadings, PermissionKey::CorrectLastMeterReadings, PermissionKey::BrowseMeterReadingWeeks,
                PermissionKey::ViewCollections, PermissionKey::RecordCollections, PermissionKey::CloseCashBoxes,
                PermissionKey::RecordCashPayments, PermissionKey::RecordTransferPayments,
                PermissionKey::ViewBranchPerformance, PermissionKey::ViewDebtAging, PermissionKey::ViewTransactionAudit,
                PermissionKey::AddCharges, PermissionKey::AddDiscounts, PermissionKey::AddClearings, PermissionKey::CorrectTransactions, PermissionKey::DeleteTransactions,
                PermissionKey::AmendTransactionDetails, PermissionKey::RefundPayments, PermissionKey::ExportFinancialReports,
                PermissionKey::ViewTariffs,
                PermissionKey::ViewCircuitBreakers,
                PermissionKey::ViewFinancialReports,
                PermissionKey::ViewMessages, PermissionKey::SendMessages,
                PermissionKey::ViewDisconnections, PermissionKey::ManageDisconnections,
                PermissionKey::WarnDisconnections, PermissionKey::ExecuteDisconnections, PermissionKey::ChangeDisconnectionFee,
                PermissionKey::ViewReadingAssignments, PermissionKey::ManageReadingAssignments,
                PermissionKey::ApproveReadingAssignments, PermissionKey::PrintReadingSheets,
                PermissionKey::ReviewReadingSubmissions,
                PermissionKey::CustomizePrintouts,
            ],
            self::DataEntry => [
                PermissionKey::ViewSubscriptions, PermissionKey::CreateSubscriptions, PermissionKey::UpdateSubscriptions,
                PermissionKey::ViewMeterReadings, PermissionKey::RecordMeterReadings,
                PermissionKey::CorrectMeterReadings, PermissionKey::CorrectLastMeterReadings, PermissionKey::BrowseMeterReadingWeeks,
                PermissionKey::BulkUpdateSubscriptions,
                PermissionKey::ViewReadingAssignments, PermissionKey::PrintReadingSheets,
                PermissionKey::CustomizePrintouts,
            ],
            self::Accountant => [
                PermissionKey::ViewSubscriptions,
                PermissionKey::ViewMeterReadings, PermissionKey::ApproveMeterReadings, PermissionKey::BrowseMeterReadingWeeks,
                PermissionKey::AddCharges, PermissionKey::AddDiscounts, PermissionKey::AddClearings,
                PermissionKey::ViewFinancialReports,
                PermissionKey::ExportFinancialReports,
                PermissionKey::CustomizePrintouts,
            ],
            // Takes payments in the field app, and nothing else: the financial log stays with the branch's staff.
            self::Collector => [PermissionKey::RecordCollections, PermissionKey::RecordCashPayments, PermissionKey::RecordTransferPayments],
            self::FinancialAuditor => [PermissionKey::AuditClosings, PermissionKey::ViewAllClosings],
            self::SuperAdmin => [],
        };
    }
}
