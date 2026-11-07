<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ReadingPrintData
{
    /**
     * Group headings on a centred line of their own, written once where they
     * change: the region, then each box under it (see resources/js/lib/printLayout.js).
     */
    private const CENTRED_HEADINGS = ['headings' => 'stacked', 'align' => 'center', 'labels' => false, 'repeatHeader' => true];

    /**
     * Subscribers from أ to ي inside each box.
     */
    private const BY_NAME = [['key' => 'المشترك', 'direction' => 'asc']];

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function sheet(LengthAwarePaginator $rows, array $context, bool $audit): array
    {
        $labels = ['المشترك', 'القراءة السابقة', 'القراءة الحالية', 'الفرق (كيلو)', 'سعر الكيلو', 'قيمة القراءة', 'الحد الأدنى', 'المطلوب دفعه', 'الحالة'];
        $fields = ['fullName' => 'اسم المشترك', 'phone' => 'رقم الجوال', 'accountNumber' => 'رقم الاشتراك', 'meterBoxName' => 'اسم الطبلون', 'meterBoxNumber' => 'رقم الطبلون', 'meterBoxLabel' => 'الطبلون', 'subAreaName' => 'منطقة 2', 'branchName' => 'الفرع', 'tariffName' => 'نوع الاشتراك', 'breakerAmpere' => 'القاطع', 'weekStart' => 'بداية أسبوع القراءة', 'weekEnd' => 'نهاية أسبوع القراءة', 'notes' => 'ملاحظات القراءة', 'discountAmount' => 'خصم القراءة', 'originalAmountDue' => 'المستحق قبل إلغاء الفاتورة'];
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
                'meterBoxLabel' => implode(' — ', array_filter([$row['meterBoxName'] ?? null, $row['meterBoxNumber'] ?? null], 'filled')),
                'discountAmount' => $entered ? $reading['discountAmount'] : '', 'originalAmountDue' => $billCancelled ? $reading['amountDue'] : '',
                ...($row['printDetails'] ?? [])];
            if ($audit) {
                $cells['سجّلها'] = $reading['recordedByName'] ?? '';
                $values += ['recordedBy' => $reading['recordedByName'] ?? '', 'recordedAt' => $reading['recordedAt'] ?? '', 'recordedSource' => isset($reading['recordedSource']) ? ($reading['recordedSource'] === 'app' ? 'التطبيق' : 'الموقع') : ''];
            }
            $data[] = self::row($cells, $fields, $values);
        }

        return [
            // Subscribers under their region, then their box, ready to print as read in the field.
            ...self::report($labels, $fields, $data, $context, $rows->total(), ['group' => ['key' => 'field:subAreaName', 'then' => ['field:meterBoxLabel'], 'unit' => 'مشترك', ...self::CENTRED_HEADINGS], 'sort' => self::BY_NAME]),
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

        return self::report($labels, $fields, $data, $context, $total, ['group' => ['key' => 'field:subAreaName', 'unit' => 'طبلون', ...self::CENTRED_HEADINGS]]);
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
                'group' => ['key' => 'field:subAreaName', 'then' => ['الطبلون'], 'unit' => 'مشترك', ...self::CENTRED_HEADINGS],
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
            $value = $values[$key] ?? '';
            // Dates print day first (29/10/2026), as everywhere else.
            $cells['field:'.$key] = in_array($key, ['weekStart', 'weekEnd', 'readingDate'], true) && is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $date)
                ? $date[3].'/'.$date[2].'/'.$date[1]
                : $value;
        }

        return ['type' => 'row', 'cells' => array_map(fn (mixed $value): string => (string) ($value ?? ''), $cells)];
    }
}
