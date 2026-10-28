<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ReadingPrintData
{
    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function sheet(LengthAwarePaginator $rows, array $context, bool $audit): array
    {
        $labels = ['المشترك', 'القراءة السابقة', 'القراءة الحالية', 'الفرق (كيلو)', 'سعر الكيلو', 'قيمة القراءة', 'الحد الأدنى', 'المطلوب دفعه', 'الحالة'];
        $fields = ['fullName' => 'اسم المشترك', 'phone' => 'رقم الجوال', 'accountNumber' => 'رقم الاشتراك', 'meterBoxName' => 'اسم الطبلون', 'meterBoxNumber' => 'رقم الطبلون', 'subAreaName' => 'منطقة 2', 'branchName' => 'الفرع', 'tariffName' => 'نوع الاشتراك', 'breakerAmpere' => 'القاطع', 'weekStart' => 'بداية أسبوع القراءة', 'weekEnd' => 'نهاية أسبوع القراءة', 'notes' => 'ملاحظات القراءة', 'discountAmount' => 'خصم القراءة', 'originalAmountDue' => 'المستحق قبل إلغاء الفاتورة'];
        if ($audit) {
            $labels[] = 'سجّلها';
            $fields += ['recordedBy' => 'مدخل القراءة', 'recordedAt' => 'وقت إدخال القراءة', 'recordedSource' => 'مصدر القراءة'];
        }
        $data = [];
        foreach ($rows->items() as $row) {
            $reading = $row['reading'];
            $entered = $reading !== null && ! $reading['requiresReentry'];
            $billCancelled = $entered && $reading['status'] === 'approved' && ! $reading['billed'];
            $status = ($reading['requiresReentry'] ?? false) ? 'بانتظار إدخال الحالية' : ($reading['statusLabel'] ?? 'لم تُدخل');
            $cells = [
                'المشترك' => $row['fullName'], 'القراءة السابقة' => $row['previousReading'],
                'القراءة الحالية' => $entered ? $reading['currentReading'] : '', 'الفرق (كيلو)' => $entered ? $reading['consumption'] : '',
                'سعر الكيلو' => $row['unitPrice'], 'قيمة القراءة' => $entered ? $reading['readingFee'] : '',
                'الحد الأدنى' => $row['minimumPayment'], 'المطلوب دفعه' => $entered ? ($billCancelled ? 0 : $reading['amountDue']) : '',
                'الحالة' => $status.($billCancelled ? ' — فاتورة ملغاة، لم تُحمَّل على المشترك' : ''),
            ];
            $values = [...$row, 'weekStart' => $context['weekStart'], 'weekEnd' => $context['weekEnd'],
                'discountAmount' => $entered ? $reading['discountAmount'] : '', 'originalAmountDue' => $billCancelled ? $reading['amountDue'] : '',
                ...($row['printDetails'] ?? [])];
            if ($audit) {
                $cells['سجّلها'] = $reading['recordedByName'] ?? '';
                $values += ['recordedBy' => $reading['recordedByName'] ?? '', 'recordedAt' => $reading['recordedAt'] ?? '', 'recordedSource' => isset($reading['recordedSource']) ? ($reading['recordedSource'] === 'app' ? 'التطبيق' : 'الموقع') : ''];
            }
            $data[] = self::row($cells, $fields, $values);
        }

        return [
            ...self::report($labels, $fields, $data, $context, $rows->total()),
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'total' => $rows->total(),
                'next_page_url' => $rows->nextPageUrl(),
            ],
        ];
    }

    /** @param list<array<string, mixed>> $boxes
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function distribution(array $boxes, array $context, int $total): array
    {
        $labels = ['منطقة 2', 'الطبلون', 'القارئ', 'المشتركون', 'طريقة القراءة', 'حالة التوزيع'];
        $fields = ['subAreaName' => 'منطقة 2', 'boxName' => 'اسم الطبلون', 'boxNumber' => 'رقم الطبلون', 'readerName' => 'القارئ', 'branchName' => 'الفرع', 'approvedBy' => 'اعتمد التوزيع', 'approvedAt' => 'وقت اعتماد التوزيع', 'readingDate' => 'تاريخ القراءة'];
        $data = array_map(fn (array $box): array => self::row([
            'منطقة 2' => $box['subAreaName'], 'الطبلون' => $box['boxLabel'], 'القارئ' => $box['readerName'],
            'المشتركون' => $box['subscribers'], 'طريقة القراءة' => $box['channel'], 'حالة التوزيع' => $box['status'],
        ], $fields, [...$box, 'branchName' => $context['branchName'], 'readingDate' => $context['readingDate']]), $boxes);

        return self::report($labels, $fields, $data, $context, $total, ['group' => ['key' => 'field:subAreaName']]);
    }

    /** @param list<array<string, mixed>> $boxes
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function paper(array $boxes, array $context): array
    {
        $labels = ['#', 'الطبلون', 'المشترك', 'رقم الاشتراك', 'القراءة السابقة', 'القراءة الحالية', 'ملاحظات'];
        $fields = ['subAreaName' => 'منطقة 2', 'boxName' => 'اسم الطبلون', 'boxNumber' => 'رقم الطبلون', 'fullName' => 'اسم المشترك', 'phone' => 'رقم الجوال', 'accountNumber' => 'رقم الاشتراك', 'branchName' => 'الفرع', 'readingDate' => 'تاريخ القراءة'];
        $data = [];
        foreach ($boxes as $box) {
            foreach ($box['subscriptions'] as $subscription) {
                $data[] = self::row([
                    '#' => count($data) + 1, 'الطبلون' => $box['label'],
                    'المشترك' => $subscription['name'].($subscription['phone'] ? "\n".$subscription['phone'] : ''),
                    'رقم الاشتراك' => $subscription['accountNumber'], 'القراءة السابقة' => $subscription['previousReading'],
                    'القراءة الحالية' => '', 'ملاحظات' => '',
                ], $fields, ['subAreaName' => $box['subAreaName'] ?? 'بدون منطقة 2', 'boxName' => $box['name'], 'boxNumber' => $box['number'], 'fullName' => $subscription['name'], 'phone' => $subscription['phone'], 'accountNumber' => $subscription['accountNumber'], 'branchName' => $context['branchName'], 'readingDate' => $context['readingDate']]);
            }
        }

        return [
            ...self::report($labels, $fields, $data, $context, count($data), [
                'density' => 'relaxed',
                'group' => ['key' => 'field:subAreaName'],
                'footer' => ['signatures' => ['القارئ', 'المراجع']],
            ]),
            'warnings' => array_values(array_map(
                fn (array $box): string => 'الطبلون '.$box['label'].': نُقل من الكشوف الورقية بعد إنشاء الكشف. راجع التوزيع قبل الطباعة.',
                array_filter($boxes, fn (array $box): bool => $box['movedOffPaper']),
            )),
        ];
    }

    /** @param list<string> $labels
     * @param  array<string, string>  $fields
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private static function report(array $labels, array $fields, array $rows, array $context, int $total, array $defaults = []): array
    {
        return [
            'columns' => [...array_map(fn (string $label): array => ['key' => $label, 'label' => $label], $labels), ...array_map(fn (string $key, string $label): array => ['key' => 'field:'.$key, 'label' => $label, 'extra' => true], array_keys($fields), array_values($fields))],
            'rows' => $rows, 'total' => $total, 'context' => $context, 'defaults' => $defaults,
        ];
    }

    /** @param array<string, mixed> $cells
     * @param  array<string, string>  $fields
     * @param  array<string, mixed>  $values
     * @return array{type: string, cells: array<string, string>}
     */
    private static function row(array $cells, array $fields, array $values): array
    {
        foreach ($fields as $key => $label) {
            $cells['field:'.$key] = $values[$key] ?? '';
        }

        return ['type' => 'row', 'cells' => array_map(fn (mixed $value): string => (string) ($value ?? ''), $cells)];
    }
}
