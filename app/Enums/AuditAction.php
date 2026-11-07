<?php

namespace App\Enums;

enum AuditAction: string
{
    case ViewAccounts = 'view_accounts';
    case ViewLedger = 'view_ledger';
    case ViewHistory = 'view_history';
    case ReviewTransfers = 'review_transfers';
    case ApproveBoxes = 'approve_boxes';
    case ApprovePeriods = 'approve_periods';
    case PrintReports = 'print_reports';

    public function label(): string
    {
        return match ($this) {
            self::ViewAccounts => 'عرض المشتركين وكشوف حساباتهم',
            self::ViewLedger => 'عرض السجل المالي',
            self::ViewHistory => 'عرض سجل تعديل الحركات',
            self::ReviewTransfers => 'مراجعة الحوالات وتسجيل مبلغ البنك',
            self::ApproveBoxes => 'اعتماد تدقيق الصندوق',
            self::ApprovePeriods => 'اعتماد تدقيق الفترة كاملة',
            self::PrintReports => 'طباعة الصناديق والكشوف والسندات',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ViewAccounts => 'يقرأ بيانات المشترك وكشفه دون تعديلها.',
            self::ViewLedger => 'يتابع الدفعات والحركات المالية للفروع المحددة.',
            self::ViewHistory => 'يرى من عدّل الحركة ووقت التعديل وسببه.',
            self::ReviewTransfers => 'يسجّل ما وصل إلى البنك وسبب الفرق في الصناديق المغلقة.',
            self::ApproveBoxes => 'يعتمد الصندوق ويرحّل فروق التدقيق إلى حسابات المشتركين.',
            self::ApprovePeriods => 'يعتمد صناديق الفترة معًا؛ يتطلب اعتماد الصناديق.',
            self::PrintReports => 'يفتح تقارير الطباعة للبيانات التي يملك الاطلاع عليها.',
        };
    }
}
