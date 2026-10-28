import { useMemo } from 'react';
import { formatNumber } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { ReportChart } from '@/types/reports';
import { formatHalalas, formatThousandths, makeFormatter } from './format';

/** Cell/metric formatter bound to the active locale and the `reports.money_unit` label. */
export function useCellFormatter() {
    const t = useT();
    const { locale } = useLocale();
    const unit = t('reports.money_unit');

    return useMemo(() => makeFormatter(locale, unit), [locale, unit]);
}

/** Value formatter for a chart's unit (money shows the unit label only in the data table / aria text). */
export function useChartValue(unit: ReportChart['unit']) {
    const t = useT();
    const money = t('reports.money_unit');

    return useMemo(
        () =>
            (value: number, withUnit = false): string => {
                if (unit === 'money') {
                    const text = formatHalalas(value);

                    return withUnit ? `${text} ${money}` : text;
                }

                return unit === 'quantity'
                    ? formatThousandths(value)
                    : formatNumber(value);
            },
        [unit, money],
    );
}
