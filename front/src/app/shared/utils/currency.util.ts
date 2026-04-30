import { environment } from '../../../environments/environment';

const DEFAULT_CURRENCY_CODE = 'INR';
const DEFAULT_CURRENCY_SYMBOL = 'Rs';
const DEFAULT_LOCALE = 'en-IN';

export type AppCurrencyConfig = {
  code: string;
  symbol: string;
  locale: string;
};

/** Populated from GET /api/public-config so UI matches Laravel + Stripe. */
let runtimeCurrency: AppCurrencyConfig | null = null;

export function setAppCurrencyConfig(config: Partial<AppCurrencyConfig> | null): void {
  if (!config) {
    runtimeCurrency = null;
    return;
  }
  const code = (config.code || DEFAULT_CURRENCY_CODE).toUpperCase();
  runtimeCurrency = {
    code,
    symbol: (config.symbol ?? DEFAULT_CURRENCY_SYMBOL).trim() || DEFAULT_CURRENCY_SYMBOL,
    locale: (config.locale ?? DEFAULT_LOCALE).trim() || DEFAULT_LOCALE,
  };
}

export function getAppCurrencyConfig(): AppCurrencyConfig {
  if (runtimeCurrency) {
    return runtimeCurrency;
  }
  return {
    code: (environment.currencyCode || DEFAULT_CURRENCY_CODE).toUpperCase(),
    symbol: environment.currencySymbol || DEFAULT_CURRENCY_SYMBOL,
    locale: environment.currencyLocale || DEFAULT_LOCALE,
  };
}

export function appCurrencyCode(): string {
  return getAppCurrencyConfig().code;
}

export function appCurrencySymbol(): string {
  return getAppCurrencyConfig().symbol;
}

export function appCurrencyLocale(): string {
  return getAppCurrencyConfig().locale;
}

/**
 * Same pattern as backend {@see \App\Support\Currency::format}: symbol + grouped number + 2 decimals.
 */
export function formatAppCurrency(value: unknown): string {
  const amount = Number(value ?? 0);
  const safeAmount = Number.isFinite(amount) ? amount : 0;
  const { symbol, locale } = getAppCurrencyConfig();

  return `${symbol} ${new Intl.NumberFormat(locale, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(safeAmount)}`;
}
