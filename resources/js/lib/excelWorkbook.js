import { excelLook, excelOrientation } from './excelSheet.js';

/**
 * Writes the sheets lib/excelSheet.js builds as a real .xlsx workbook with
 * ExcelJS, loaded only when a file is made so no page carries it: the
 * look the user chose (the colours of the heading row and title, the font
 * size and row height; by default a dark heading row and a red title),
 * money in shekels,
 * right to left, the heading row frozen and filtered, the totals as live
 * formulas, and ready to print from Excel (A4, fitted to the width, the
 * heading row on every page, numbered pages).
 */

const THIN = { style: 'thin', color: { argb: 'FFD9DDE2' } };
const BOX = { top: THIN, bottom: THIN, left: THIN, right: THIN };
const fill = (hex) => ({ type: 'pattern', pattern: 'solid', fgColor: { argb: `FF${hex}` } });
const argb = (hex) => `FF${hex}`;
const MERGED = new Set(['title', 'subtitle', 'meta', 'group']);

/** The Excel number format of a column type. */
export function numberFormat(type, currency) {
    if (type === 'money') {
        return currency ? '#,##0.00 "₪";[Red]-#,##0.00 "₪"' : '#,##0.00;[Red]-#,##0.00';
    }

    if (type === 'decimal') {
        return '#,##0.00;[Red]-#,##0.00';
    }

    if (type === 'int') {
        return '#,##0';
    }

    return type === 'date' ? 'dd/mm/yyyy' : undefined;
}

/** A cell's value as ExcelJS writes it: a formula with its last value, a date, a number or text. */
function excelValue(cell) {
    if (cell.f) {
        return { formula: cell.f, result: typeof cell.v === 'number' ? cell.v : undefined };
    }

    if (cell.t === 'date' && /^\d{4}-\d{2}-\d{2}/.test(String(cell.v))) {
        return new Date(`${String(cell.v).slice(0, 10)}T00:00:00Z`);
    }

    return cell.v === '' || cell.v === undefined ? null : cell.v;
}

/** The workbook of `sheets` (from buildWorkbookModel) with the user's `settings`, as a Blob ready to save. */
export async function workbookBlob(sheets, settings) {
    const { default: ExcelJS } = await import('exceljs');
    const workbook = new ExcelJS.Workbook();
    workbook.creator = 'AF PowerCollect';
    workbook.created = new Date();
    // Excel works the totals out afresh on opening, rather than showing the values written beside them.
    workbook.calcProperties.fullCalcOnLoad = true;

    const { theme, font: sizes, rows: heights } = excelLook(settings);
    const font = (extra = {}) => ({ name: 'Arial', size: sizes.body, ...extra });

    for (const sheet of sheets) {
        const columns = Math.max(sheet.widths.length, 1);
        const frozen = settings.freeze && sheet.key !== 'criteria';
        const worksheet = workbook.addWorksheet(sheet.name.replace(/[\\/?*[\]:]/g, ' ').slice(0, 31), {
            views: [{ rightToLeft: settings.rtl, state: frozen ? 'frozen' : 'normal', ySplit: frozen ? sheet.head : 0 }],
        });
        worksheet.columns = sheet.widths.map((width) => ({ width }));

        for (const row of sheet.rows) {
            const excelRow = worksheet.getRow(row.row);
            row.cells.forEach((cell, index) => {
                const target = excelRow.getCell(index + 1);
                target.value = excelValue(cell);
                target.font = font();
                target.alignment = { vertical: 'middle', readingOrder: settings.rtl ? 'rtl' : 'ltr' };
                const format = numberFormat(cell.t, settings.currency);
                if (format) target.numFmt = format;
            });

            if (MERGED.has(row.kind) && row.cells.length === 1 && columns > 1) {
                worksheet.mergeCells(row.row, 1, row.row, columns);
            }

            const each = (style) => {
                for (let index = 1; index <= columns; index++) style(excelRow.getCell(index));
            };

            if (row.kind === 'title') {
                excelRow.height = sizes.title * 1.75;
                each((cell) => { cell.font = font({ size: sizes.title, bold: true, color: { argb: argb(theme.title) } }); });
            } else if (row.kind === 'subtitle') {
                excelRow.height = sizes.subtitle * 1.65;
                each((cell) => { cell.font = font({ size: sizes.subtitle, bold: true }); });
            } else if (row.kind === 'meta') {
                each((cell) => { cell.font = font({ size: sizes.body - 1, color: { argb: 'FF5B6470' } }); });
            } else if (row.kind === 'head') {
                excelRow.height = heights.data + 6;
                each((cell) => {
                    cell.fill = fill(theme.head);
                    cell.font = font({ bold: true, color: { argb: argb(theme.headText) } });
                    cell.alignment = { vertical: 'middle', horizontal: 'center', wrapText: true, readingOrder: settings.rtl ? 'rtl' : 'ltr' };
                    cell.border = BOX;
                });
            } else if (row.kind === 'data') {
                excelRow.height = heights.data;
                each((cell) => {
                    cell.border = BOX;
                    if (row.zebra) cell.fill = fill('F6F7F9');
                });
            } else if (row.kind === 'group') {
                each((cell) => {
                    cell.fill = fill(theme.group);
                    cell.font = font({ bold: true, color: { argb: argb(theme.groupText) } });
                });
            } else if (row.kind === 'subtotal') {
                each((cell) => {
                    cell.fill = fill('EEF0F3');
                    cell.font = font({ bold: true });
                    cell.border = { top: THIN, bottom: { style: 'thin', color: { argb: 'FF9AA3AE' } } };
                });
            } else if (row.kind === 'total') {
                each((cell) => {
                    cell.fill = fill('E3E6EA');
                    cell.font = font({ bold: true });
                    cell.border = { top: { style: 'double', color: { argb: 'FF353C46' } }, bottom: { style: 'medium', color: { argb: 'FF353C46' } } };
                });
            }
        }

        if (settings.autofilter && sheet.key !== 'criteria' && !sheet.grouped && sheet.lastData >= sheet.firstData) {
            worksheet.autoFilter = { from: { row: sheet.head, column: 1 }, to: { row: sheet.lastData, column: columns } };
        }

        if (settings.pageSetup) {
            worksheet.pageSetup = {
                paperSize: 9,
                orientation: excelOrientation(settings, columns),
                fitToPage: true,
                fitToWidth: 1,
                fitToHeight: 0,
                horizontalCentered: true,
                margins: { left: 0.4, right: 0.4, top: 0.5, bottom: 0.6, header: 0.3, footer: 0.3 },
                printTitlesRow: `${sheet.head}:${sheet.head}`,
            };
            worksheet.headerFooter = { oddFooter: `&L&8${sheet.name}&C&8صفحة &P من &N&R&8AF PowerCollect` };
        }
    }

    const buffer = await workbook.xlsx.writeBuffer();

    return new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
}

/** Saves a Blob under `fileName`, as a download link does. */
export function saveBlob(blob, fileName) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 2000);
}
