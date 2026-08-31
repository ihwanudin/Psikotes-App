/** DRAFT internal presentation contract. Not an HTTP response or authorization proof. */
export type CheckoutProfileKey =
    | 'fullName'
    | 'birthDate'
    | 'gender'
    | 'educationLevel'
    | 'intendedField'
    | 'email'
    | 'phone';

export type CheckoutProfileField = {
    key: CheckoutProfileKey;
    label: string;
} & (
    | { state: 'locked'; displayValue: string }
    | {
          state: 'missing';
          required: boolean;
          input: 'text' | 'email' | 'tel' | 'date';
          autoComplete?: string;
          options?: never;
      }
    | {
          state: 'missing';
          required: boolean;
          input: 'select';
          options: ReadonlyArray<{ value: string; label: string }>;
      }
);

export type CheckoutConsentDocument = {
    version: string;
    title: string;
    text: string;
};

export type CheckoutConsent =
    | { state: 'required'; document: CheckoutConsentDocument }
    | { state: 'accepted'; version: string }
    | { state: 'declined'; version: string };

/** Only this participant's amount. Never pass a bill model, batch, or invoice URL.
 * Unselected is DRAFT read-only presentation, not a server funding mode.
 */
export type CheckoutPayment = {
    amountIdr: number | null;
} & (
    | { payer: 'unselected'; state: 'unselected' }
    | {
          payer: 'self';
          state: 'unpaid' | 'pending';
          actionAvailable: boolean;
      }
    | {
          payer: 'self';
          state: 'paid' | 'free' | 'review' | 'expired' | 'rejected';
      }
    | {
          payer: 'organization';
          organizationName: string;
          state:
              | 'unbilled'
              | 'pending'
              | 'paid'
              | 'free'
              | 'review'
              | 'expired'
              | 'rejected';
      }
);

export type CheckoutSummary = {
    /** Remount form when attempt or required document version changes. Not a credential. */
    formKey: string;
    sourceName: string;
    branchName: string;
    packageName: string;
    attemptLabel: string;
    profile: ReadonlyArray<CheckoutProfileField>;
    identityMessage: string;
    payment: CheckoutPayment;
    access: { state: 'locked' | 'ready'; message: string };
    consents: {
        psychotest: Exclude<CheckoutConsent, { state: 'declined' }>;
        dass: CheckoutConsent | { state: 'not_applicable' };
        legalReviewPending: boolean;
    };
};

export type CheckoutConfirmation = {
    missingProfile: Partial<Record<CheckoutProfileKey, string>>;
    psychotest?: { version: string; accepted: true };
    dass?: { version: string; accepted: boolean };
};

export type IntegratedCheckoutProps = {
    screen:
        | { state: 'loading' }
        | { state: 'expired' }
        | { state: 'error'; message: string }
        | { state: 'ready'; summary: CheckoutSummary };
    busy?: boolean;
    feedback?: {
        message: string;
        fieldErrors?: Partial<Record<CheckoutProfileKey, string>>;
    };
    onConfirm?: (confirmation: CheckoutConfirmation) => void;
    onPayment?: () => void;
    onRefresh?: () => void;
    onHelp?: () => void;
};
