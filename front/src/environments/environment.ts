export const environment = {
  production: false,
  apiUrl: 'http://localhost:8000/api',
  backendUrl: 'http://localhost:8000',
  webUrl: 'http://localhost:4200',
  frontendUrl: 'http://localhost:4200',
  oauthCallbackOrigin: 'http://localhost:8000',
  /** Fallback before GET /api/public-config â€” keep in sync with backend STRIPE_CURRENCY + Currency::symbol(). */
  currencyCode: 'INR',
  currencySymbol: 'Rs',
  currencyLocale: 'en-IN',
  siteKey: '6Ld7eq4rAAAAALKceq2IFCJiYI3GqEQc9E4Y6U5g',
  stripeKey: 'pk_test_51TRRSJAxWPGe3IbpfBgzgcVsmgj0pPaxRFqxOmhIKp2eV5eZ68VJ5LihFPFUvTV3rksYEudWNuOyXE8dblfM4VlW00uejDoZtp',
  reverbKey: 's6vzvaqv64fdh24ybo80',
  reverbHost: 'localhost',
  reverbPort: 8080,
  reverbUseTls: false
};
