/**
 * The guest-customer directory's data layer.
 *
 * There is no registered-account system yet, so a "customer" here is every
 * order placed under one contact phone number - the closest thing to an
 * identity a guest checkout has. Deliberately carries no points, tier or
 * membership field: that is Loyalty's data, and needs a real customer
 * identity and a real checkout to earn from before either number means
 * anything (CLAUDE.md delivery stage 4).
 */

import { api } from './api-client';

export interface AdminCustomer {
  readonly phone: string;
  /** The contact name on this phone's most recent order. */
  readonly name: string;
  readonly email: string | null;
  readonly orderCount: number;
  /** Whole đồng. Sums only orders whose payment is PAID. */
  readonly totalSpent: number;
  readonly lastOrderAt: string | null;
}

export interface CustomerPage {
  readonly items: readonly AdminCustomer[];
  readonly nextCursor: string | null;
  readonly hasMore: boolean;
}

interface ApiMoney {
  readonly amount: string;
  readonly currency: string;
}

interface ApiAdminCustomer {
  readonly phone: string;
  readonly name: string;
  readonly email: string | null;
  readonly order_count: number;
  readonly total_spent: ApiMoney;
  readonly last_order_at: string | null;
}

interface ApiCustomerPage {
  readonly items: readonly ApiAdminCustomer[];
  readonly next_cursor: string | null;
  readonly has_more: boolean;
}

function toCustomer(customer: ApiAdminCustomer): AdminCustomer {
  return {
    phone: customer.phone,
    name: customer.name,
    email: customer.email,
    orderCount: customer.order_count,
    totalSpent: Number.parseInt(customer.total_spent.amount, 10),
    lastOrderAt: customer.last_order_at,
  };
}

/** One page of this store's guest customers, most recent activity first. */
export async function loadCustomers(
  search?: string,
  cursor?: string | null,
): Promise<CustomerPage> {
  const params = new URLSearchParams();

  if (search !== undefined && search.trim() !== '') {
    params.set('q', search.trim());
  }

  if (cursor !== undefined && cursor !== null) {
    params.set('cursor', cursor);
  }

  const query = params.toString();
  const page = await api.get<ApiCustomerPage>(`/admin/customers${query === '' ? '' : `?${query}`}`);

  return {
    items: page.items.map(toCustomer),
    nextCursor: page.next_cursor,
    hasMore: page.has_more,
  };
}
