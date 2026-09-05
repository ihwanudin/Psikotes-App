/** Strict read-only projection consumed by the integrated checkout UI.
 * Monetary choices are server facts: the browser may select a supplied boolean,
 * but must never derive an amount or invent payment authority.
 */

export type CheckoutSummaryV2ProfileKey =
    | 'fullName'
    | 'birthDate'
    | 'gender'
    | 'educationLevel'
    | 'intendedField'
    | 'email'
    | 'phone';

export type CheckoutSummaryV2ProfileField<
    Key extends CheckoutSummaryV2ProfileKey = CheckoutSummaryV2ProfileKey,
> = Key extends CheckoutSummaryV2ProfileKey
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

export type CheckoutSummaryV2Profile = readonly [
    CheckoutSummaryV2ProfileField<'fullName'>,
    CheckoutSummaryV2ProfileField<'birthDate'>,
    CheckoutSummaryV2ProfileField<'gender'>,
    CheckoutSummaryV2ProfileField<'educationLevel'>,
    CheckoutSummaryV2ProfileField<'intendedField'>,
    CheckoutSummaryV2ProfileField<'email'>,
    CheckoutSummaryV2ProfileField<'phone'>,
];

export type CheckoutSummaryV2PaymentState =
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

type PaymentChoiceBase = Readonly<{
    baseAmountIdr: number;
    amountIdr: number;
}>;

export type CheckoutSummaryV2PaymentChoice = PaymentChoiceBase &
    (
        | Readonly<{
              consultationRequested: false;
              consultationAmountIdr: 0;
          }>
        | Readonly<{
              consultationRequested: true;
              consultationAmountIdr: number;
          }>
    );

type PaymentActionBase = Readonly<{
    path: '/checkout/payment';
    currency: 'IDR';
}>;

type Choice<Consultation extends boolean> = Extract<
    CheckoutSummaryV2PaymentChoice,
    { consultationRequested: Consultation }
>;

export type CheckoutSummaryV2PaymentAction = PaymentActionBase &
    (
        | Readonly<{
              mode: 'select';
              choices:
                  | readonly [Choice<false>]
                  | readonly [Choice<true>]
                  | readonly [Choice<false>, Choice<true>];
          }>
        | Readonly<{
              mode: 'continue';
              choices: readonly [CheckoutSummaryV2PaymentChoice];
          }>
    );

type UnavailableAmount = Readonly<{
    amountSource: 'unavailable';
    amountIdr: null;
    consultationRequested: null;
}>;

type SnapshotAmount = Readonly<{
    amountSource: 'charge_snapshot';
    amountIdr: number;
    consultationRequested: boolean;
}>;

type Amount = UnavailableAmount | SnapshotAmount;

type UnavailableCapability = Readonly<{
    actionAvailable: false;
    action: null;
}>;

type AvailableCapability = Readonly<{
    actionAvailable: true;
    action: CheckoutSummaryV2PaymentAction;
}>;

type OrganizationZeroCapability = Readonly<{
    actionAvailable: true;
    action: PaymentActionBase &
        Readonly<{
            mode: 'select';
            choices: readonly [
                Readonly<{
                    consultationRequested: false;
                    baseAmountIdr: 0;
                    consultationAmountIdr: 0;
                    amountIdr: 0;
                }>,
            ];
        }>;
}>;

type PayerCapability =
    | (Readonly<{
          payer: 'unselected' | 'self';
          organizationName?: never;
      }> &
          (UnavailableCapability | AvailableCapability))
    | (Readonly<{ payer: 'organization'; organizationName: string }> &
          (UnavailableCapability | OrganizationZeroCapability));

export type CheckoutSummaryV2Payment = PayerCapability &
    Amount &
    Readonly<{ state: CheckoutSummaryV2PaymentState }>;

export type CheckoutSummaryV2TestType =
    'dass21' | 'ist' | 'kraepelin' | 'papi' | 'rmib';

export type CheckoutSummaryV2Test = Readonly<{
    testType: CheckoutSummaryV2TestType;
    state: 'locked' | 'ready';
}>;

export type CheckoutSummaryV2Consent =
    | Readonly<{ state: 'accepted'; version: string; document?: never }>
    | Readonly<{
          state: 'required';
          document: Readonly<{ version: string; title: string; text: string }>;
          version?: never;
      }>;

export type CheckoutSummaryV2 = Readonly<{
    contractVersion: 'checkout-summary-v2';
    sourceName: string;
    branchName: string;
    packageName: string;
    attemptLabel: string;
    profile: CheckoutSummaryV2Profile;
    identityMessage: string;
    access: Readonly<{
        state: 'locked' | 'partial' | 'ready';
        tests: readonly [CheckoutSummaryV2Test, ...CheckoutSummaryV2Test[]];
        startAvailable: false;
        message: string;
    }>;
    consents: Readonly<{
        psychotest: CheckoutSummaryV2Consent;
        dass: CheckoutSummaryV2Consent;
        legalReviewPending: boolean;
    }>;
}> &
    (
        | Readonly<{
              packageSource: 'catalog';
              payment: CheckoutSummaryV2Payment & UnavailableAmount;
          }>
        | Readonly<{
              packageSource: 'charge_snapshot';
              payment: CheckoutSummaryV2Payment & SnapshotAmount;
          }>
    );

const PROFILE_KEYS = [
    'fullName',
    'birthDate',
    'gender',
    'educationLevel',
    'intendedField',
    'email',
    'phone',
] as const;
const PAYMENT_STATES = new Set<unknown>([
    'unselected',
    'unpaid',
    'unbilled',
    'preparing',
    'pending',
    'recovery_required',
    'expired',
    'rejected',
    'paid',
    'free',
]);
const TEST_TYPES = new Set<unknown>([
    'dass21',
    'ist',
    'kraepelin',
    'papi',
    'rmib',
]);

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function hasExactKeys(
    value: Record<string, unknown>,
    expected: readonly string[],
): boolean {
    const keys = Reflect.ownKeys(value);

    return (
        keys.length === expected.length &&
        expected.every((key) => Object.hasOwn(value, key))
    );
}

function isString(value: unknown): value is string {
    return typeof value === 'string';
}

function isSafeAmount(value: unknown): value is number {
    return Number.isSafeInteger(value) && (value as number) >= 0;
}

function isProfile(value: unknown): value is CheckoutSummaryV2Profile {
    if (!Array.isArray(value) || value.length !== PROFILE_KEYS.length) {
        return false;
    }

    return value.every((field, index) => {
        if (!isRecord(field) || field.key !== PROFILE_KEYS[index]) {
            return false;
        }

        const required = field.key !== 'email';

        if (!isString(field.label) || field.required !== required) {
            return false;
        }

        return field.state === 'missing'
            ? hasExactKeys(field, ['key', 'label', 'required', 'state'])
            : field.state === 'locked' &&
                  isString(field.displayValue) &&
                  hasExactKeys(field, [
                      'key',
                      'label',
                      'required',
                      'state',
                      'displayValue',
                  ]);
    });
}

function isChoice(value: unknown): value is CheckoutSummaryV2PaymentChoice {
    if (
        !isRecord(value) ||
        !hasExactKeys(value, [
            'consultationRequested',
            'baseAmountIdr',
            'consultationAmountIdr',
            'amountIdr',
        ]) ||
        typeof value.consultationRequested !== 'boolean' ||
        !isSafeAmount(value.baseAmountIdr) ||
        !isSafeAmount(value.consultationAmountIdr) ||
        !isSafeAmount(value.amountIdr) ||
        value.baseAmountIdr >
            Number.MAX_SAFE_INTEGER - value.consultationAmountIdr ||
        value.amountIdr !== value.baseAmountIdr + value.consultationAmountIdr
    ) {
        return false;
    }

    return value.consultationRequested
        ? value.consultationAmountIdr > 0
        : value.consultationAmountIdr === 0;
}

function isAction(value: unknown): value is CheckoutSummaryV2PaymentAction {
    if (
        !isRecord(value) ||
        !hasExactKeys(value, ['path', 'mode', 'currency', 'choices']) ||
        value.path !== '/checkout/payment' ||
        (value.mode !== 'select' && value.mode !== 'continue') ||
        value.currency !== 'IDR' ||
        !Array.isArray(value.choices) ||
        value.choices.length === 0 ||
        value.choices.length > 2 ||
        !value.choices.every(isChoice) ||
        (value.mode === 'continue' && value.choices.length !== 1)
    ) {
        return false;
    }

    const flags = value.choices.map((choice) => choice.consultationRequested);

    return (
        new Set(flags).size === flags.length &&
        !(flags[0] === true && flags[1] === false)
    );
}

function isOrganizationZeroAction(
    value: CheckoutSummaryV2PaymentAction,
): boolean {
    if (value.mode !== 'select' || value.choices.length !== 1) {
        return false;
    }

    const choice = value.choices[0];

    return (
        choice.consultationRequested === false &&
        choice.baseAmountIdr === 0 &&
        choice.consultationAmountIdr === 0 &&
        choice.amountIdr === 0
    );
}

function isPayment(value: unknown): value is CheckoutSummaryV2Payment {
    if (!isRecord(value)) {
        return false;
    }

    const payerKeys =
        value.payer === 'organization'
            ? [
                  'payer',
                  'state',
                  'amountIdr',
                  'amountSource',
                  'consultationRequested',
                  'actionAvailable',
                  'action',
                  'organizationName',
              ]
            : [
                  'payer',
                  'state',
                  'amountIdr',
                  'amountSource',
                  'consultationRequested',
                  'actionAvailable',
                  'action',
              ];

    if (
        !hasExactKeys(value, payerKeys) ||
        (value.payer !== 'unselected' &&
            value.payer !== 'self' &&
            value.payer !== 'organization') ||
        (value.payer === 'organization' && !isString(value.organizationName)) ||
        !PAYMENT_STATES.has(value.state)
    ) {
        return false;
    }

    const amountValid =
        (value.amountSource === 'unavailable' &&
            value.amountIdr === null &&
            value.consultationRequested === null) ||
        (value.amountSource === 'charge_snapshot' &&
            isSafeAmount(value.amountIdr) &&
            typeof value.consultationRequested === 'boolean');
    const capabilityValid =
        (value.actionAvailable === false && value.action === null) ||
        (value.actionAvailable === true && isAction(value.action));

    return (
        amountValid &&
        capabilityValid &&
        (value.payer !== 'organization' ||
            value.actionAvailable === false ||
            (isAction(value.action) && isOrganizationZeroAction(value.action)))
    );
}

function isConsent(value: unknown): value is CheckoutSummaryV2Consent {
    if (!isRecord(value)) {
        return false;
    }

    if (value.state === 'accepted') {
        return (
            hasExactKeys(value, ['state', 'version']) && isString(value.version)
        );
    }

    if (value.state !== 'required' || !isRecord(value.document)) {
        return false;
    }

    return (
        hasExactKeys(value, ['state', 'document']) &&
        hasExactKeys(value.document, ['version', 'title', 'text']) &&
        isString(value.document.version) &&
        isString(value.document.title) &&
        isString(value.document.text)
    );
}

function isAccess(value: unknown): value is CheckoutSummaryV2['access'] {
    if (
        !isRecord(value) ||
        !hasExactKeys(value, ['state', 'tests', 'startAvailable', 'message']) ||
        (value.state !== 'locked' &&
            value.state !== 'partial' &&
            value.state !== 'ready') ||
        value.startAvailable !== false ||
        !isString(value.message) ||
        !Array.isArray(value.tests) ||
        value.tests.length === 0
    ) {
        return false;
    }

    const types = new Set<unknown>();

    for (const item of value.tests) {
        if (
            !isRecord(item) ||
            !hasExactKeys(item, ['testType', 'state']) ||
            !TEST_TYPES.has(item.testType) ||
            (item.state !== 'locked' && item.state !== 'ready') ||
            types.has(item.testType)
        ) {
            return false;
        }

        types.add(item.testType);
    }

    return types.has('dass21');
}

function isConsents(value: unknown): value is CheckoutSummaryV2['consents'] {
    return (
        isRecord(value) &&
        hasExactKeys(value, ['psychotest', 'dass', 'legalReviewPending']) &&
        isConsent(value.psychotest) &&
        isConsent(value.dass) &&
        typeof value.legalReviewPending === 'boolean'
    );
}

export function isCheckoutSummaryV2(
    value: unknown,
): value is CheckoutSummaryV2 {
    return (
        isRecord(value) &&
        hasExactKeys(value, [
            'contractVersion',
            'sourceName',
            'branchName',
            'packageName',
            'packageSource',
            'attemptLabel',
            'profile',
            'identityMessage',
            'payment',
            'access',
            'consents',
        ]) &&
        value.contractVersion === 'checkout-summary-v2' &&
        isString(value.sourceName) &&
        isString(value.branchName) &&
        isString(value.packageName) &&
        (value.packageSource === 'catalog' ||
            value.packageSource === 'charge_snapshot') &&
        isString(value.attemptLabel) &&
        isProfile(value.profile) &&
        isString(value.identityMessage) &&
        isPayment(value.payment) &&
        ((value.packageSource === 'catalog' &&
            value.payment.amountSource === 'unavailable') ||
            (value.packageSource === 'charge_snapshot' &&
                value.payment.amountSource === 'charge_snapshot')) &&
        isAccess(value.access) &&
        isConsents(value.consents)
    );
}

export function parseCheckoutSummaryV2(value: unknown): CheckoutSummaryV2 {
    if (!isCheckoutSummaryV2(value)) {
        throw new TypeError('Invalid checkout summary v2');
    }

    return value;
}
