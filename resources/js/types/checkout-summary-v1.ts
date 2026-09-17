/** Internal PHP CheckoutSummary serialization only (2026-09-04).
 * Not the integrated-checkout DRAFT, an HTTP schema, editable form, or authority.
 * Runtime validation, monetary bounds, canonical test ordering and access decisions
 * remain server-owned. TypeScript types do not validate received JSON.
 */
export type CheckoutSummaryV1ProfileKey =
    | 'fullName'
    | 'birthDate'
    | 'gender'
    | 'educationLevel'
    | 'intendedField'
    | 'email'
    | 'phone';

export type CheckoutSummaryV1ProfileField<
    Key extends CheckoutSummaryV1ProfileKey = CheckoutSummaryV1ProfileKey,
> = Key extends CheckoutSummaryV1ProfileKey
    ? Readonly<{
          key: Key;
          label: string;
          required: Key extends 'email' ? false : true;
      }> &
          (
              | Readonly<{ state: 'missing'; displayValue?: never }>
              | Readonly<{ state: 'locked'; displayValue: string }>
          )
    : never;

/** PHP emits all seven keys in this order, including optional email. */
export type CheckoutSummaryV1Profile = readonly [
    CheckoutSummaryV1ProfileField<'fullName'>,
    CheckoutSummaryV1ProfileField<'birthDate'>,
    CheckoutSummaryV1ProfileField<'gender'>,
    CheckoutSummaryV1ProfileField<'educationLevel'>,
    CheckoutSummaryV1ProfileField<'intendedField'>,
    CheckoutSummaryV1ProfileField<'email'>,
    CheckoutSummaryV1ProfileField<'phone'>,
];

export type CheckoutSummaryV1PaymentState =
    | 'unselected'
    | 'unpaid'
    | 'unbilled'
    | 'preparing'
    | 'pending'
    | 'recovery_required'
    | 'expired'
    | 'rejected'
    | 'paid'
    | 'free';

type Payer =
    | Readonly<{ payer: 'unselected' | 'self'; organizationName?: never }>
    | Readonly<{ payer: 'organization'; organizationName: string }>;

type PaymentBase = Payer &
    Readonly<{
        state: CheckoutSummaryV1PaymentState;
        actionAvailable: false;
    }>;

type UnavailableAmount = Readonly<{
    amountSource: 'unavailable';
    amountIdr: null;
    consultationRequested: null;
}>;

type SnapshotAmount = Readonly<{
    amountSource: 'charge_snapshot';
    /** Safe nonnegative integer validated by PHP; zero does not imply free. */
    amountIdr: number;
    consultationRequested: boolean;
}>;

export type CheckoutSummaryV1Payment = PaymentBase &
    (UnavailableAmount | SnapshotAmount);

export type CheckoutSummaryV1TestType =
    'dass21' | 'ist' | 'kraepelin' | 'papi' | 'rmib';

export type CheckoutSummaryV1Test = Readonly<{
    testType: CheckoutSummaryV1TestType;
    state: 'locked' | 'ready';
}>;

export type CheckoutSummaryV1Consent =
    | Readonly<{ state: 'accepted'; version: string; document?: never }>
    | Readonly<{
          state: 'required';
          document: Readonly<{ version: string; title: string; text: string }>;
          version?: never;
      }>;

export type CheckoutSummaryV1 = Readonly<{
    contractVersion: 'checkout-summary-v1';
    sourceName: string;
    branchName: string;
    packageName: string;
    attemptLabel: string;
    profile: CheckoutSummaryV1Profile;
    identityMessage: string;
    access: Readonly<{
        state: 'locked' | 'partial' | 'ready';
        tests: readonly [CheckoutSummaryV1Test, ...CheckoutSummaryV1Test[]];
        startAvailable: false;
        message: string;
    }>;
    consents: Readonly<{
        psychotest: CheckoutSummaryV1Consent;
        dass:
            | CheckoutSummaryV1Consent
            | Readonly<{
                  state: 'not_applicable';
                  document?: never;
                  version?: never;
              }>;
        legalReviewPending: boolean;
    }>;
}> &
    (
        | Readonly<{
              packageSource: 'catalog';
              payment: PaymentBase & UnavailableAmount;
          }>
        | Readonly<{
              packageSource: 'charge_snapshot';
              payment: PaymentBase & SnapshotAmount;
          }>
    );
