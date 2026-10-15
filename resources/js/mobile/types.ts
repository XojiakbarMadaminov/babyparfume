export interface Session {
    user: { name: string };
    stores: { id: number; name: string }[];
    store_id: number | null;
    permissions: { products: boolean; debtors: boolean; create_debtor: boolean };
}

export interface Debtor {
    id: number;
    full_name: string;
    phone: string | null;
    amount: number;
    currency: string;
    date: string | null;
    note: string | null;
    overdue: boolean;
    can_update: boolean;
}

export interface Product {
    id: number;
    name: string;
    barcode: string | null;
    price: number;
    type: string;
    quantity: number;
    image: string | null;
}

export interface Transaction {
    id: number;
    type: 'debt' | 'payment';
    amount: number;
    date: string;
    note: string | null;
}

export interface Page<T> {
    data: T[];
    counts?: { active: number; closed: number; overdue: number };
    meta?: { current_page: number; last_page: number };
    current_page?: number;
    last_page?: number;
    summary?: { currency: string; amount: number; count: number }[];
}
