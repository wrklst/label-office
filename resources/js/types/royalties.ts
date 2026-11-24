export type Option = { id: number; name: string };

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type CurrentLabel = {
    id: number;
    name: string;
    currency: string;
    role: 'admin' | 'payee' | null;
    logo_url: string | null;
    signet_url: string | null;
    services: string[];
    tools: { mechanicals: boolean; ppd: boolean; reserves: boolean };
};

export type PayeeTotals = {
    income: string;
    expense: string;
    net: string;
    payee_share: string;
    transfers: string;
    previous_balance: string;
    previous_payable: string;
    total_payable: string;
    total_paid: string;
    balance: string;
    outstanding: string;
};

export type StatementTrack = {
    track_id: number | null;
    track_name: string;
    contract_id: number | null;
    contract_name: string | null;
    split: string;
    income: string;
    expense: string;
    net: string;
    payee_share: string;
    previous_balance: string;
    balance: string;
};

export type StatementRelease = {
    release_id: number | null;
    release_name: string;
    income: string;
    expense: string;
    net: string;
    payee_share: string;
    previous_balance: string;
    balance: string;
    tracks: StatementTrack[];
};

export type StatementLedger = {
    ledger_id: number | null;
    ledger_name: string;
    income: string;
    expense: string;
    net: string;
    payee_share: string;
    transfers: string;
    payouts: string;
    previous_balance: string;
    balance: string;
    unrecouped: boolean;
    releases: StatementRelease[];
};

export type StatementDetailRow = {
    release_id: number | null;
    release_name: string;
    track_name: string | null;
    contract_name: string | null;
    format: string | null;
    category: string | null;
    split: string;
    quantity: string;
    amount: string;
    payee_share: string;
};

export type StatementData = {
    period: { id: number; name: string; starts_on: string; ends_on: string };
    currency: string;
    generated_at?: string;
    payee_id: number;
    payee_name: string;
    totals: PayeeTotals;
    ledgers: StatementLedger[];
    income_rows: StatementDetailRow[];
    expense_rows: StatementDetailRow[];
    transfer_rows: {
        id: number;
        date: string;
        category: string | null;
        memo: string | null;
        amount: string;
        release_name: string | null;
        ledger_name: string;
    }[];
    payout_rows: {
        id: number;
        date: string;
        memo: string | null;
        amount: string;
    }[];
};

export type Breakdown = {
    label: string | null;
    total: string;
    quantity: string;
};
