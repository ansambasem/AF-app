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
 * The four collection figures, all new money of the period: what was paid
 * in all, the part of it paid to the invoice, the part collected from the
 * accumulated debt, and the part left over as new advance. The discount and
 * the advance brought forward are never counted as payments.
 */
export const COLLECTION_INDICATORS = [
    ['payments', 'إجمالي الدفعات', 'كل ما دُفع فعليًا في الفترة: نقدي وبنكي ومقاصة.'],
    ['paidToInvoice', 'إجمالي دفعات الفاتورة', 'الدفعات الجديدة المخصصة للفاتورة فقط، دون الخصم أو المقدم المستخدم.'],
    ['paidToAccumulated', 'إجمالي دفعات المتراكم', 'ما حُصّل فعليًا من ديون الفترات السابقة.'],
    ['newAdvance', 'إجمالي دفعات المقدم', 'الزائد من الدفعات الجديدة، رصيد دائن يُرحّل للفترة القادمة.'],
];

/** The three rates, each a whole percentage never above 100; the invoice rate is 100 when nothing was left to pay of it. */
export const COLLECTION_RATES = [
    ['totalRate', 'نسبة إجمالي التحصيل', 'ما دُفع للفاتورة والمتراكم ÷ ما كان يجب دفعه بعد الخصم والمقدم المستخدم.'],
    ['invoiceRate', 'نسبة تحصيل الفاتورة الحالية', 'دفعات الفاتورة ÷ مستهدف الفاتورة (الفاتورة + المحملة − الخصم − المقدم المستخدم).'],
    ['accumulatedRate', 'نسبة تحصيل المتراكم', 'المحصّل من المتراكم ÷ المتراكم في بداية الفترة.'],
];

/**
 * Each subscriber's settlement, shown in its own table under the subscribers
 * one, never as columns of it: the invoice, what came off it first (the
 * discount, then the advance brought forward), its target, and how the new
 * payments were split.
 */
export const SETTLEMENT_COLUMNS = [
    ['invoice', 'الفاتورة الحالية'],
    ['charges', 'المبالغ المحملة'],
    ['discounts', 'الخصم'],
    ['advance', 'المقدم المرحّل'],
    ['advanceUsed', 'المقدم المستخدم'],
    ['invoiceTarget', 'مستهدف الفاتورة'],
    ['payments', 'الدفعات الجديدة'],
    ['paidToInvoice', 'المخصص للفاتورة'],
    ['paidToAccumulated', 'المخصص للمتراكم'],
    ['invoiceRate', 'نسبة تحصيل الفاتورة', 'rate'],
    ['accumulated', 'المتراكم في البداية'],
    ['paidToAccumulated', 'المحصّل من المتراكم'],
    ['accumulatedRemaining', 'المتبقي من المتراكم'],
    ['unusedAdvance', 'المقدم غير المستخدم'],
    ['newAdvance', 'المقدم الجديد من الدفعات'],
    ['carriedAdvance', 'المقدم المرحّل للفترة القادمة'],
    ['remaining', 'الرصيد النهائي'],
];

/** A rate as the pages show it: a dash when there was nothing to collect. */
export const formatRate = (rate) => (rate === null || rate === undefined ? '—' : `${rate}%`);
