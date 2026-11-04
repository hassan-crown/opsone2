import type { TranslateFn, TranslationKey } from '@/lib/i18n';

/** Shared vocabulary of the automation pages (ADR-0042): labels of triggers, fields, operators and actions. */

export type Text = { ar: string | null; en: string | null };

export type Target =
    | { type: 'responsible' }
    | { type: 'member'; id: number }
    | { type: 'role'; role: string };

export type RuleAction =
    | { type: 'send_message'; template: string; channel: 'auto' | 'mail' }
    | {
          type: 'create_task';
          title: Text;
          sla_days: number;
          to: Target;
      }
    | { type: 'notify'; to: Target; message: Text }
    | { type: 'assign'; to: Target }
    | { type: 'add_note'; body: Text };

export type ConditionValue = string | number | (string | number)[] | null;

export type Condition = { field: string; op: string; value: ConditionValue };

export type Conditions = { match: 'all' | 'any'; items: Condition[] };

export function triggerLabel(t: TranslateFn, trigger: string): string {
    return t(`automation.trigger.${trigger}` as TranslationKey);
}

export function actionLabel(t: TranslateFn, type: string): string {
    return t(`automation.action.${type}` as TranslationKey);
}

export function fieldLabel(t: TranslateFn, field: string): string {
    return t(`automation.field.${field}` as TranslationKey);
}

export function opLabel(t: TranslateFn, op: string): string {
    return t(`automation.op.${op}` as TranslationKey);
}

export function reasonLabel(t: TranslateFn, reason: string | null): string {
    return reason === null
        ? ''
        : t(`automation.reason.${reason}` as TranslationKey);
}

/** «قبل الاستحقاق بـ 3 أيام» for a timer trigger, nothing otherwise. */
export function paramLabel(
    t: TranslateFn,
    params: Record<string, number> | [],
): string {
    const entries = Object.entries(params ?? {});

    return entries
        .map(([name, value]) =>
            t(`automation.param.${name}_value` as TranslationKey, { n: value }),
        )
        .join('، ');
}

export const OUTCOME_VARIANT: Record<string, 'success' | 'neutral' | 'danger'> =
    {
        matched: 'success',
        skipped: 'neutral',
        failed: 'danger',
    };
