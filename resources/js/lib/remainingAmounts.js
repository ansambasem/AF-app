/**
 * The remaining amounts report's columns and figures, shared by the report
 * and the dashboard. Every figure is computed on the server (PeriodStatement);
 * these only name and order what it sends.
 */

/** The money columns of the subscribers table, in the order of the accounts formula. */
export const MONEY_COLUMNS = [
    ['invoice', 'الفاتورة'],
    ['accumulated', 'المتراكم'],
    ['advance', 'المقدم'],
    ['net', 'صافي المتراكم'],
    ['discounts', 'الخصومات'],
    ['charges', 'المبالغ المحملة'],
    ['beforePayments', 'الرصيد قبل الدفعات'],
    ['payments', 'إجمالي الدفعات'],
    ['remaining', 'الرصيد المتبقي'],
];

/** The subscribers table's sixteen columns, exactly as they stand: never add, drop, rename or reorder one. */
export const SUBSCRIBER_COLUMNS = [
    'رقم المشترك',
    'اسم المشترك',
    'الفرع',
    'القراءة السابقة',
    'القراءة الحالية',
    'فرق القراءة',
    'سعر الوحدة',
    ...MONEY_COLUMNS.map(([, label]) => label),
];

/**
 * The four collection figures: money actually paid in the period, what
 * settled the invoice (payments, discounts and the advance brought forward),
 * what was collected from the accumulated debt, and the new advance.
 */
export const COLLECTION_INDICATORS = [
    ['payments', 'إجمالي الدفعات', 'كل ما دُفع فعليًا في الفترة: نقدي وبنكي ومقاصة.'],
    ['invoiceSettled', 'إجمالي دفعات الفاتورة', 'ما سُوّي من الفاتورة والمبالغ المحملة: دفعات وخصومات ومقدم سابق.'],
    ['paidToAccumulated', 'إجمالي دفعات المتراكم', 'ما حُصّل فعليًا من ديون الفترات السابقة.'],
    ['newAdvance', 'إجمالي دفعات المقدم', 'الزائد من الدفعات، رصيد دائن يُرحّل للفترة القادمة.'],
];

/** The three rates, each a whole percentage never above 100 (null when there was nothing to collect). */
export const COLLECTION_RATES = [
    ['totalRate', 'نسبة إجمالي التحصيل', 'ما دُفع لتسوية الفاتورة والمتراكم ÷ ما كان يجب دفعه (الرصيد قبل الدفعات).'],
    ['invoiceRate', 'نسبة تحصيل الفاتورة الحالية', 'تسوية الفاتورة ÷ (الفاتورة + المبالغ المحملة).'],
    ['accumulatedRate', 'نسبة تحصيل المتراكم', 'المحصّل من المتراكم ÷ المتراكم في بداية الفترة.'],
];

/** Each subscriber's settlement, shown in its own table under the subscribers one, never as columns of it. */
export const SETTLEMENT_COLUMNS = [
    ['invoice', 'الفاتورة الحالية'],
    ['charges', 'المبالغ المحملة'],
    ['invoiceTarget', 'مستهدف الفاتورة'],
    ['discounts', 'الخصومات'],
    ['advance', 'المقدم المرحّل'],
    ['advanceUsed', 'المقدم المستخدم'],
    ['payments', 'الدفعات الجديدة'],
    ['paidToInvoice', 'المخصص للفاتورة'],
    ['paidToAccumulated', 'المخصص للمتراكم'],
    ['invoiceSettled', 'تسوية الفاتورة'],
    ['invoiceRate', 'نسبة تحصيل الفاتورة', 'rate'],
    ['accumulated', 'المتراكم في البداية'],
    ['paidToAccumulated', 'المحصّل من المتراكم'],
    ['accumulatedRemaining', 'المتبقي من المتراكم'],
    ['newAdvance', 'المقدم الجديد'],
    ['remaining', 'الرصيد النهائي'],
];

/** A rate as the pages show it: a dash when there was nothing to collect. */
export const formatRate = (rate) => (rate === null || rate === undefined ? '—' : `${rate}%`);
