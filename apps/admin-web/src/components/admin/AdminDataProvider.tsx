'use client';

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';

import { ApiError, api } from '@/lib/api-client';
import { loadBankLogos as loadBankLogosRequest } from '@/lib/bank-logos';
import {
  createCoupon as createCouponRequest,
  loadCoupons as loadCouponList,
  updateCoupon as updateCouponRequest,
  type Coupon,
  type CouponDraft,
} from '@/lib/coupons-api';
import { loadCustomers as loadCustomersRequest, type AdminCustomer } from '@/lib/customers-api';
import {
  createLoyaltyReward as createLoyaltyRewardRequest,
  loadLoyaltyMember as loadLoyaltyMemberRequest,
  loadLoyaltyMembers as loadLoyaltyMemberList,
  loadLoyaltyRewards as loadLoyaltyRewardList,
  loadLoyaltySettings as loadLoyaltySettingsRequest,
  loadLoyaltySummary as loadLoyaltySummaryRequest,
  loyaltyMemberQueryKey,
  redeemLoyaltyReward as redeemLoyaltyRewardRequest,
  updateLoyaltyReward as updateLoyaltyRewardRequest,
  updateLoyaltySettings as updateLoyaltySettingsRequest,
  LOYALTY_MEMBER_QUERY_DEFAULT,
  type LoyaltyMember,
  type LoyaltyMemberDetail,
  type LoyaltyMemberQuery,
  type LoyaltyReward,
  type LoyaltyRewardDraft,
  type LoyaltySettings,
  type LoyaltySummary,
} from '@/lib/loyalty-api';
import {
  archiveMenuProduct,
  createMenuProduct,
  loadMenu,
  saveDailyPortions,
  saveMenuProductStock,
  updateMenuProduct,
  type MenuProduct,
  type MenuSnapshot,
  type PreparedPortions,
} from '@/lib/menu-api';
import {
  DEFAULT_ORDER_QUERY,
  ORDER_STATUS_LABELS,
  loadOrders,
  orderQueryKey,
  transitionOrder,
  type AdminOrder,
  type OrderQuery,
  type OrderStatus,
  type OrderStatusCounts,
} from '@/lib/orders-api';
import type { ProductDraft } from '@/lib/product-form';
import {
  confirmPayment as confirmPaymentRequest,
  flagPayment as flagPaymentRequest,
  loadBankAccount as loadBankAccountRequest,
  loadKnownBanks as loadKnownBanksRequest,
  loadPaymentMethodOptions as loadPaymentMethodOptionsRequest,
  loadPayments as loadPaymentsRequest,
  setPaymentMethodEnabled as setPaymentMethodEnabledRequest,
  updateBankAccount as updateBankAccountRequest,
  type AdminPayment,
  type BankAccountDraft,
  type ConfirmPaymentInput,
  type FlagPaymentInput,
  type KnownBankOption,
  type PaymentListQuery,
  type PaymentMethod,
  type PaymentMethodOption,
  type StoreBankAccount,
} from '@/lib/payments-api';
import { loadStoreContext } from '@/lib/store-api';

export type AdminModal =
  | { readonly kind: 'order'; readonly orderId: string }
  | { readonly kind: 'product'; readonly productId: string }
  | { readonly kind: 'stock'; readonly productId: string }
  | { readonly kind: 'daily-portions' }
  /** Create when `couponId` is absent, edit when it is there. */
  | { readonly kind: 'coupon-form'; readonly couponId?: string }
  /** Create when `rewardId` is absent, edit when it is there. */
  | { readonly kind: 'reward-form'; readonly rewardId?: string }
  | { readonly kind: 'loyalty-settings' }
  /** Balance, history and the redeem action for one member. */
  | { readonly kind: 'loyalty-member'; readonly phone: string; readonly name: string }
  | { readonly kind: 'add-product' }
  | { readonly kind: 'delete-product'; readonly productId: string }
  | { readonly kind: 'payment'; readonly paymentId: string }
  | { readonly kind: 'bank-account-form' }
  | { readonly kind: 'payment-methods' };

/** Whether the dashboard has a session yet. */
export type SessionState = 'checking' | 'signed-in' | 'signed-out';

interface AdminContextValue {
  readonly orders: readonly AdminOrder[];
  /** Open orders per status, counted by the server across the whole store. */
  readonly orderCounts: OrderStatusCounts;
  readonly products: readonly MenuProduct[];
  readonly coupons: readonly Coupon[];
  readonly loyaltySummary: LoyaltySummary | null;
  readonly loyaltyMembers: readonly LoyaltyMember[];
  readonly loyaltyMembersBusy: boolean;
  readonly loyaltyMembersError: string;
  readonly hasMoreLoyaltyMembers: boolean;
  readonly loyaltyMemberDetail: LoyaltyMemberDetail | null;
  readonly loyaltyMemberDetailBusy: boolean;
  readonly loyaltyRewards: readonly LoyaltyReward[];
  readonly loyaltyRewardsBusy: boolean;
  readonly loyaltyRewardsError: string;
  readonly loyaltySettings: LoyaltySettings | null;
  readonly customers: readonly AdminCustomer[];
  readonly pendingCount: number;
  readonly modal: AdminModal | null;
  readonly toastMessage: string;
  readonly sidebarOpen: boolean;
  readonly session: SessionState;
  /** True while the menu is being loaded or saved, for disabling a submit. */
  readonly menuBusy: boolean;
  /** Set when the menu could not be loaded at all, so a screen can say so. */
  readonly menuError: string;
  readonly ordersBusy: boolean;
  readonly ordersError: string;
  readonly hasMoreOrders: boolean;
  /** Orders to call out as new; see markArrivals in this file. */
  readonly newOrderIds: ReadonlySet<string>;
  readonly couponsBusy: boolean;
  readonly couponsError: string;
  readonly payments: readonly AdminPayment[];
  readonly paymentsBusy: boolean;
  readonly paymentsError: string;
  readonly hasMorePayments: boolean;
  readonly bankAccount: StoreBankAccount | null;
  readonly bankAccountBusy: boolean;
  readonly bankAccountError: string;
  readonly bankAccountLoaded: boolean;
  readonly knownBanks: readonly KnownBankOption[];
  /** bin -> logo URL, purely decorative; see loadBankLogos in bank-logos.ts. */
  readonly bankLogos: Readonly<Record<string, string>>;
  readonly paymentMethodOptions: readonly PaymentMethodOption[];
  readonly paymentMethodsBusy: boolean;
  readonly customersBusy: boolean;
  readonly customersError: string;
  readonly hasMoreCustomers: boolean;
  readonly openModal: (modal: AdminModal) => void;
  readonly closeModal: () => void;
  readonly setSidebarOpen: (open: boolean) => void;
  readonly notify: (message: string) => void;
  /** The design's catch-all for controls that are not wired to an API yet. */
  readonly notifyAction: (action: string) => void;
  readonly signIn: (email: string, password: string) => Promise<boolean>;
  readonly signOut: () => void;
  /** Asks for the list a screen shows. Identical repeats are not re-fetched. */
  readonly loadOrders: (query: OrderQuery) => void;
  readonly loadMoreOrders: () => void;
  readonly moveOrder: (orderId: string, toStatus: OrderStatus, reason?: string) => void;
  /** Stops the highlight: one order, or all of them when no id is given. */
  readonly markOrdersSeen: (orderId?: string) => void;
  readonly createProduct: (draft: ProductDraft) => void;
  readonly updateProduct: (productId: string, draft: ProductDraft) => void;
  readonly deleteProduct: (productId: string) => void;
  readonly saveStock: (productId: string, stock: number) => void;
  /** The morning prep sheet: what was made today, for several dishes at once. */
  readonly savePortions: (lines: readonly PreparedPortions[]) => void;
  /** Reads the store's discount codes. The promotions screen asks on mount. */
  readonly loadCoupons: () => void;
  readonly createCoupon: (draft: CouponDraft) => void;
  readonly updateCoupon: (couponId: string, draft: CouponDraft) => void;
  /** Asks for the reconciliation queue. The payments screen asks on mount. */
  readonly loadPayments: (query: PaymentListQuery) => void;
  readonly loadMorePayments: () => void;
  readonly confirmPayment: (paymentId: string, input: ConfirmPaymentInput) => void;
  readonly flagPayment: (paymentId: string, input: FlagPaymentInput) => void;
  readonly loadBankAccount: () => void;
  readonly loadKnownBanks: () => void;
  readonly loadBankLogos: () => void;
  readonly saveBankAccount: (draft: BankAccountDraft) => void;
  readonly loadPaymentMethodOptions: () => void;
  readonly setPaymentMethodEnabled: (method: PaymentMethod, enabled: boolean) => void;
  /** Dashboard tiles. The loyalty screen asks on mount. */
  readonly loadLoyaltySummary: () => void;
  /** Resets to the first page under this search. Pass '' to clear it. */
  readonly loadLoyaltyMembers: (search: string) => void;
  readonly loadMoreLoyaltyMembers: () => void;
  readonly loadLoyaltyMemberDetail: (phone: string) => void;
  readonly redeemLoyaltyReward: (phone: string, rewardId: string, note: string | null) => void;
  readonly loadLoyaltyRewards: () => void;
  readonly createLoyaltyReward: (draft: LoyaltyRewardDraft) => void;
  readonly updateLoyaltyReward: (rewardId: string, draft: LoyaltyRewardDraft) => void;
  readonly loadLoyaltySettings: () => void;
  readonly updateLoyaltySettings: (settings: LoyaltySettings) => void;
  /** Reads the guest-customer directory. The customers screen asks on mount. */
  readonly loadCustomers: (search?: string) => void;
  readonly loadMoreCustomers: () => void;
}

const AdminContext = createContext<AdminContextValue | null>(null);

/**
 * How often the order list re-reads itself while the tab is in front.
 *
 * Half a minute: long enough that a shop's morning costs a couple of hundred
 * cheap reads, short enough that nobody is standing at the counter wondering
 * whether the screen is stuck.
 */
const ORDER_POLL_MS = 30_000;

/**
 * Holds the dashboard's state for the whole admin shell.
 *
 * The menu, the orders, the discount codes and loyalty are real: all four
 * come from the API and survive a reload. Loyalty's members list is read
 * fresh from the points ledger rather than stored anywhere of its own - there
 * is no registered-account module yet, so a member is just a phone number
 * with a balance (CLAUDE.md section 5).
 *
 * Orders are listed per screen rather than once globally: each screen says
 * what it wants to see through `loadOrders`, and an identical request is not
 * repeated. One default page is loaded on mount anyway, because the sidebar
 * badge counts waiting orders on every screen, not just on the orders one.
 */
export function AdminDataProvider({ children }: { children: ReactNode }) {
  const [coupons, setCoupons] = useState<readonly Coupon[]>([]);
  const [couponsBusy, setCouponsBusy] = useState(false);
  const [couponsError, setCouponsError] = useState('');
  /** Whether the list has been asked for yet, so a re-mount does not re-ask. */
  const couponsLoaded = useRef(false);

  const [loyaltySummary, setLoyaltySummary] = useState<LoyaltySummary | null>(null);
  const loyaltySummaryLoaded = useRef(false);

  const [loyaltyMembers, setLoyaltyMembers] = useState<readonly LoyaltyMember[]>([]);
  const [loyaltyMembersBusy, setLoyaltyMembersBusy] = useState(false);
  const [loyaltyMembersError, setLoyaltyMembersError] = useState('');
  const [loyaltyMemberCursor, setLoyaltyMemberCursor] = useState<string | null>(null);
  /** The search currently on screen, so loading more pages asks for the same slice. */
  const loyaltyMemberQuery = useRef<LoyaltyMemberQuery>(LOYALTY_MEMBER_QUERY_DEFAULT);
  const shownLoyaltyMemberQuery = useRef<string | null>(null);

  const [loyaltyMemberDetail, setLoyaltyMemberDetail] = useState<LoyaltyMemberDetail | null>(null);
  const [loyaltyMemberDetailBusy, setLoyaltyMemberDetailBusy] = useState(false);

  const [loyaltyRewards, setLoyaltyRewards] = useState<readonly LoyaltyReward[]>([]);
  const [loyaltyRewardsBusy, setLoyaltyRewardsBusy] = useState(false);
  const [loyaltyRewardsError, setLoyaltyRewardsError] = useState('');
  const loyaltyRewardsLoaded = useRef(false);

  const [loyaltySettings, setLoyaltySettings] = useState<LoyaltySettings | null>(null);
  const loyaltySettingsLoaded = useRef(false);

  const [customers, setCustomers] = useState<readonly AdminCustomer[]>([]);
  const [customersBusy, setCustomersBusy] = useState(false);
  const [customersError, setCustomersError] = useState('');
  const [customersCursor, setCustomersCursor] = useState<string | null>(null);
  /** The search term currently on screen, for "load more" of the same list. */
  const customersSearch = useRef('');

  const [payments, setPayments] = useState<readonly AdminPayment[]>([]);
  const [paymentsBusy, setPaymentsBusy] = useState(false);
  const [paymentsError, setPaymentsError] = useState('');
  const [paymentsCursor, setPaymentsCursor] = useState<string | null>(null);
  /** The filters currently on screen, for a refresh after a confirm or flag. */
  const paymentsQuery = useRef<PaymentListQuery>({ needsAttention: true });

  /**
   * This session's store id, resolved once and cached.
   *
   * Every other admin read resolves its store silently on the server; only
   * the bank-account and payment-method endpoints carry `{store}` in the
   * path (CLAUDE.md section 8's own example), so this is the one lookup the
   * screen never has to think about - it is internal to the actions below.
   */
  const storeIdRef = useRef<string | null>(null);
  const storeIdRequest = useRef<Promise<string> | null>(null);

  const [bankAccount, setBankAccount] = useState<StoreBankAccount | null>(null);
  const [bankAccountBusy, setBankAccountBusy] = useState(false);
  const [bankAccountError, setBankAccountError] = useState('');
  const [bankAccountLoaded, setBankAccountLoaded] = useState(false);

  /** Static reference data: fetched once per session, not reloaded per dialog open. */
  const [knownBanks, setKnownBanks] = useState<readonly KnownBankOption[]>([]);
  const knownBanksLoaded = useRef(false);

  const [bankLogos, setBankLogos] = useState<Readonly<Record<string, string>>>({});
  const bankLogosLoaded = useRef(false);

  const [paymentMethodOptions, setPaymentMethodOptions] = useState<readonly PaymentMethodOption[]>(
    [],
  );
  const [paymentMethodsBusy, setPaymentMethodsBusy] = useState(false);

  const [orders, setOrders] = useState<readonly AdminOrder[]>([]);
  const [orderCounts, setOrderCounts] = useState<OrderStatusCounts>({});
  const [orderCursor, setOrderCursor] = useState<string | null>(null);
  const [ordersBusy, setOrdersBusy] = useState(false);
  const [ordersError, setOrdersError] = useState('');
  /** Orders the operator has not acknowledged yet; the table calls them out. */
  const [newOrderIds, setNewOrderIds] = useState<ReadonlySet<string>>(() => new Set());

  const [snapshot, setSnapshot] = useState<MenuSnapshot | null>(null);
  const [menuBusy, setMenuBusy] = useState(false);
  const [menuError, setMenuError] = useState('');
  const [session, setSession] = useState<SessionState>('checking');

  const [modal, setModal] = useState<AdminModal | null>(null);
  const [toastMessage, setToastMessage] = useState('');
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const toastTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  /** The query currently on screen, for a refresh after a write. */
  const orderQuery = useRef<OrderQuery>(DEFAULT_ORDER_QUERY);
  /** What is already shown, so an identical request is not sent twice. */
  const shownQuery = useRef<string | null>(null);
  /**
   * The newest list request. A debounced search box can have two in flight,
   * and the slower one must not overwrite the newer answer.
   */
  const listTicket = useRef(0);
  /**
   * Every order id this session has already shown.
   *
   * In memory on purpose: "new" means "since you opened this screen", and a
   * reload is a new shift's worth of attention - on which everything still
   * waiting is called out again. Nothing is written to the browser, so no
   * stale flag can outlive the data it describes.
   */
  const knownOrderIds = useRef<Set<string>>(new Set());

  useEffect(() => () => clearTimeout(toastTimer.current), []);

  const notify = useCallback((message: string) => {
    setToastMessage(message);
    clearTimeout(toastTimer.current);
    toastTimer.current = setTimeout(() => setToastMessage(''), 3200);
  }, []);

  const notifyAction = useCallback(
    (action: string) => {
      notify(`Chức năng ${action} sẽ được kết nối API trong giai đoạn triển khai.`);
    },
    [notify],
  );

  const closeModal = useCallback(() => setModal(null), []);

  /**
   * Stops calling an order out as new.
   *
   * Without an id it clears every one, which is the "I have looked at the
   * screen" button. The highlight is a prompt, not a record: nothing is
   * stored, and the next arrival starts the prompt again.
   */
  const markOrdersSeen = useCallback((orderId?: string): void => {
    setNewOrderIds((current) => {
      if (orderId === undefined) {
        return current.size === 0 ? current : new Set();
      }

      if (!current.has(orderId)) {
        return current;
      }

      const next = new Set(current);
      next.delete(orderId);

      return next;
    });
  }, []);

  const openModal = useCallback(
    (next: AdminModal) => {
      // Opening an order is looking at it, so it stops shouting.
      if (next.kind === 'order') {
        markOrdersSeen(next.orderId);
      }

      setModal(next);
    },
    [markOrdersSeen],
  );

  /** Loads the menu, and decides from the answer whether there is a session. */
  const refreshMenu = useCallback(async (): Promise<void> => {
    setMenuBusy(true);
    try {
      setSnapshot(await loadMenu());
      setMenuError('');
      setSession('signed-in');
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        setSession('signed-out');
        setSnapshot(null);
        setMenuError('');

        return;
      }
      setSession('signed-in');
      setMenuError(error instanceof Error ? error.message : 'Không tải được thực đơn.');
    } finally {
      setMenuBusy(false);
    }
  }, []);

  /**
   * Which orders the row list should call out, and why.
   *
   * On the first load it is everything nobody has acted on yet - an order
   * waiting to be confirmed is new to whoever just opened the screen. After
   * that it is whatever arrived since, which is the question a counter
   * actually asks: "did something come in while I was making coffee?".
   *
   * Marked seen when the operator opens the order, moves it, or says so.
   */
  const markArrivals = useCallback(
    (page: readonly AdminOrder[], firstLoad: boolean): void => {
      const arrived = page.filter((order) => !knownOrderIds.current.has(order.id));

      for (const order of page) {
        knownOrderIds.current.add(order.id);
      }

      const toFlag = firstLoad
        ? // Nothing has been seen yet, so "new" means "nobody has dealt with
          // it": a confirmed order is somebody's business already.
          arrived.filter((order) => order.status === 'PLACED' || order.status === 'PENDING_PAYMENT')
        : arrived;

      if (toFlag.length === 0) {
        return;
      }

      setNewOrderIds((current) => {
        const next = new Set(current);
        for (const order of toFlag) {
          next.add(order.id);
        }

        return next;
      });

      // Only an arrival deserves an interruption; the first load is just the
      // screen opening, and a toast there would be noise.
      if (!firstLoad) {
        notify(
          toFlag.length === 1
            ? `Đơn mới: ${toFlag[0]?.code ?? ''}`
            : `${toFlag.length} đơn mới vừa vào`,
        );
      }
    },
    [notify],
  );

  /**
   * Runs one list request.
   *
   * With a cursor it appends the next page; without one it replaces the list.
   * The rows already on screen are left alone until the answer arrives, so
   * changing a filter does not blank the table and then fill it again.
   *
   * `background` marks a refresh nobody asked for - the poll, or the re-read
   * after a status change. Three things follow from that:
   *
   *   - arrivals are detected, because the QUERY has not changed. On a query
   *     the operator changed, rows that were filtered out a moment ago are
   *     not new orders; they were simply not being shown.
   *   - the spinner stays quiet, so the note does not blink twice a minute
   *     at someone who is reading the table.
   *   - a failure is swallowed and retried on the next tick, rather than
   *     replacing a working screen with an error panel because one request
   *     out of a morning's worth timed out. A lost session is the exception:
   *     that one has to be said.
   */
  const runOrderLoad = useCallback(
    async (query: OrderQuery, cursor?: string, background = false): Promise<void> => {
      const ticket = listTicket.current + 1;
      listTicket.current = ticket;

      if (!background) {
        setOrdersBusy(true);
      }

      try {
        const page = await loadOrders(query, cursor);

        // A newer request has been sent since; its answer is the current one.
        if (ticket !== listTicket.current) {
          return;
        }

        if (cursor === undefined && (background || knownOrderIds.current.size === 0)) {
          markArrivals(page.orders, knownOrderIds.current.size === 0);
        }

        setOrders((current) => (cursor === undefined ? page.orders : [...current, ...page.orders]));
        setOrderCounts(page.counts);
        setOrderCursor(page.nextCursor);
        setOrdersError('');
        setSession('signed-in');
      } catch (error) {
        if (ticket !== listTicket.current) {
          return;
        }

        if (error instanceof ApiError && error.status === 401) {
          setSession('signed-out');
          setOrders([]);
          setOrderCounts({});
          setOrdersError('');

          return;
        }

        if (!background) {
          setOrdersError(error instanceof Error ? error.message : 'Không tải được danh sách đơn.');
        }
      } finally {
        if (!background && ticket === listTicket.current) {
          setOrdersBusy(false);
        }
      }
    },
    [markArrivals],
  );

  const requestOrders = useCallback(
    (query: OrderQuery): void => {
      const key = orderQueryKey(query);

      // Already showing exactly this list: a screen re-mounting is not a
      // reason to ask the server again.
      if (key === shownQuery.current) {
        return;
      }

      shownQuery.current = key;
      orderQuery.current = query;
      void runOrderLoad(query);
    },
    [runOrderLoad],
  );

  const loadMoreOrders = useCallback((): void => {
    if (orderCursor === null) {
      return;
    }

    void runOrderLoad(orderQuery.current, orderCursor);
  }, [orderCursor, runOrderLoad]);

  useEffect(() => {
    void refreshMenu();
    // The badge counts waiting orders on every screen, so one page is loaded
    // up front; a screen that wants a different list asks for it.
    requestOrders(DEFAULT_ORDER_QUERY);
  }, [refreshMenu, requestOrders]);

  /**
   * Re-reads the list on a timer, so an order that arrives while the screen
   * is open actually appears.
   *
   * Polling, not websockets: realtime is optional in MVP and Reverb brings a
   * server, a channel and an authorization story with it (CLAUDE.md §16).
   * One cheap read every half minute is the honest version of the same
   * promise, and the screen says when it last looked.
   *
   * Nothing runs while the tab is hidden - a shop leaves this open all
   * morning, and polling a background tab is someone else's battery. The tab
   * coming back to the front reads immediately, because that is exactly the
   * moment the list is most likely to be stale.
   */
  useEffect(() => {
    if (session !== 'signed-in') {
      return;
    }

    function refreshIfVisible(): void {
      if (document.visibilityState !== 'visible') {
        return;
      }

      void runOrderLoad(orderQuery.current, undefined, true);
    }

    const timer = setInterval(refreshIfVisible, ORDER_POLL_MS);
    document.addEventListener('visibilitychange', refreshIfVisible);

    return () => {
      clearInterval(timer);
      document.removeEventListener('visibilitychange', refreshIfVisible);
    };
  }, [session, runOrderLoad]);

  /**
   * Runs one menu write.
   *
   * Everything a write can go wrong with is handled in one place: a 422 shows
   * the server's own message, because the server is the authority on what is
   * valid, and a lost session sends the operator back to the sign-in panel
   * rather than silently dropping their edit.
   */
  const runMenuWrite = useCallback(
    async (
      operation: (current: MenuSnapshot) => Promise<MenuSnapshot>,
      success: string,
    ): Promise<void> => {
      if (snapshot === null) {
        notify('Thực đơn chưa tải xong, vui lòng thử lại.');

        return;
      }

      setMenuBusy(true);
      try {
        setSnapshot(await operation(snapshot));
        setModal(null);
        notify(success);
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          setSession('signed-out');
          notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

          return;
        }
        notify(error instanceof Error ? error.message : 'Không lưu được thay đổi.');
      } finally {
        setMenuBusy(false);
      }
    },
    [snapshot, notify],
  );

  const signIn = useCallback(
    async (email: string, password: string): Promise<boolean> => {
      try {
        await api.post('/auth/login', { email, password });
        await refreshMenu();
        // The session is new, so nothing on screen belongs to it yet.
        shownQuery.current = null;
        knownOrderIds.current = new Set();
        setNewOrderIds(new Set());
        couponsLoaded.current = false;
        loyaltySummaryLoaded.current = false;
        shownLoyaltyMemberQuery.current = null;
        loyaltyRewardsLoaded.current = false;
        loyaltySettingsLoaded.current = false;
        requestOrders(DEFAULT_ORDER_QUERY);

        return true;
      } catch (error) {
        // The API answers a wrong password and an unknown address alike, and
        // so does this: the message is the server's.
        notify(error instanceof Error ? error.message : 'Không đăng nhập được.');

        return false;
      }
    },
    [notify, refreshMenu, requestOrders],
  );

  const signOut = useCallback(() => {
    void api
      .post('/auth/logout')
      .catch(() => undefined)
      .finally(() => {
        setSession('signed-out');
        setSnapshot(null);
        setOrders([]);
        setOrderCounts({});
        setCoupons([]);
        shownQuery.current = null;
        knownOrderIds.current = new Set();
        setNewOrderIds(new Set());
        couponsLoaded.current = false;
        setLoyaltySummary(null);
        loyaltySummaryLoaded.current = false;
        setLoyaltyMembers([]);
        setLoyaltyMemberCursor(null);
        shownLoyaltyMemberQuery.current = null;
        setLoyaltyMemberDetail(null);
        setLoyaltyRewards([]);
        loyaltyRewardsLoaded.current = false;
        setLoyaltySettings(null);
        loyaltySettingsLoaded.current = false;
        setModal(null);
      });
  }, []);

  /**
   * Moves one order to the status the operator chose.
   *
   * The target status is sent, not "next": the server validates it against the
   * committed status under a row lock, so two staff members tapping at once
   * get a conflict they can see rather than an order two steps along. A
   * conflict reloads the list, because the screen was out of date and showing
   * it again is the useful response.
   */
  const moveOrder = useCallback(
    (orderId: string, toStatus: OrderStatus, reason?: string): void => {
      setOrdersBusy(true);

      void transitionOrder(orderId, toStatus, reason)
        .then((updated) => {
          setModal(null);
          notify(`Đơn ${updated.code}: ${ORDER_STATUS_LABELS[updated.status]}`);
          // Re-read rather than patch the row: the move can take the order out
          // of the current filter, and it always changes the open counts.
          void runOrderLoad(orderQuery.current, undefined, true);
        })
        .catch((error: unknown) => {
          if (error instanceof ApiError && error.status === 401) {
            setSession('signed-out');
            notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

            return;
          }

          notify(error instanceof Error ? error.message : 'Không cập nhật được đơn hàng.');

          if (error instanceof ApiError && error.status === 409) {
            setModal(null);
            void runOrderLoad(orderQuery.current, undefined, true);
          }
        })
        .finally(() => setOrdersBusy(false));
    },
    [notify, runOrderLoad],
  );

  /**
   * Adds a dish. The draft is already valid - `validateProductForm` owns the
   * client-side rules - and the server revalidates every one of them.
   */
  const createProduct = useCallback(
    (draft: ProductDraft) => {
      void runMenuWrite(
        (current) => createMenuProduct(current, draft),
        `Đã thêm "${draft.name}" vào thực đơn`,
      );
    },
    [runMenuWrite],
  );

  const updateProduct = useCallback(
    (productId: string, draft: ProductDraft) => {
      void runMenuWrite(
        (current) => updateMenuProduct(current, productId, draft),
        `Đã lưu "${draft.name}"`,
      );
    },
    [runMenuWrite],
  );

  const deleteProduct = useCallback(
    (productId: string) => {
      // Read the name before the write, for the toast.
      const removed = snapshot?.products.find((product) => product.id === productId);
      if (removed === undefined) {
        return;
      }

      void runMenuWrite(
        (current) => archiveMenuProduct(current, productId),
        `Đã ngừng bán "${removed.name}"`,
      );
    },
    [snapshot, runMenuWrite],
  );

  const saveStock = useCallback(
    (productId: string, stock: number) => {
      if (!Number.isInteger(stock) || stock < 0) {
        notify('Số suất không hợp lệ');

        return;
      }

      void runMenuWrite(
        (current) => saveMenuProductStock(current, productId, stock),
        'Đã cập nhật số suất',
      );
    },
    [notify, runMenuWrite],
  );

  /**
   * Records the morning prep for several dishes at once.
   *
   * The whole sheet is one request, so it lands or it does not; the server
   * applies it in one transaction for the same reason.
   */
  const savePortions = useCallback(
    (lines: readonly PreparedPortions[]) => {
      if (lines.length === 0) {
        notify('Chưa nhập số suất cho món nào');

        return;
      }

      void runMenuWrite(
        (current) => saveDailyPortions(current, lines),
        `Đã ghi số suất chuẩn bị cho ${lines.length} món`,
      );
    },
    [notify, runMenuWrite],
  );

  /** Re-reads the whole list, which is how a write refreshes the screen. */
  const refreshCoupons = useCallback(async (): Promise<void> => {
    setCouponsBusy(true);

    try {
      setCoupons(await loadCouponList());
      setCouponsError('');
      setSession('signed-in');
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        setSession('signed-out');
        setCoupons([]);
        setCouponsError('');

        return;
      }

      setCouponsError(error instanceof Error ? error.message : 'Không tải được danh sách mã.');
    } finally {
      setCouponsBusy(false);
    }
  }, []);

  const requestCoupons = useCallback((): void => {
    // A screen re-mounting is not a reason to ask again; a write refreshes
    // the list itself.
    if (couponsLoaded.current) {
      return;
    }

    couponsLoaded.current = true;
    void refreshCoupons();
  }, [refreshCoupons]);

  const runCustomersLoad = useCallback(
    async (search: string, cursor?: string, append = false): Promise<void> => {
      setCustomersBusy(true);

      try {
        const page = await loadCustomersRequest(search, cursor);
        setCustomers((current) => (append ? [...current, ...page.items] : page.items));
        setCustomersCursor(page.nextCursor);
        setCustomersError('');
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          setSession('signed-out');
          notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

          return;
        }

        setCustomersError(
          error instanceof Error ? error.message : 'Không tải được danh sách khách hàng.',
        );
      } finally {
        setCustomersBusy(false);
      }
    },
    [notify],
  );

  const loadCustomers = useCallback(
    (search = ''): void => {
      customersSearch.current = search;
      void runCustomersLoad(search);
    },
    [runCustomersLoad],
  );

  const loadMoreCustomers = useCallback((): void => {
    if (customersCursor === null) {
      return;
    }

    void runCustomersLoad(customersSearch.current, customersCursor, true);
  }, [customersCursor, runCustomersLoad]);

  /**
   * Runs one coupon write.
   *
   * Everything a write can go wrong with is handled in one place: a 422 shows
   * the server's own message - it is the authority on what a valid discount
   * is - and a lost session sends the operator back to the sign-in panel
   * rather than silently dropping their edit.
   */
  const runCouponWrite = useCallback(
    async (
      operation: () => Promise<Coupon>,
      success: (coupon: Coupon) => string,
    ): Promise<void> => {
      setCouponsBusy(true);

      try {
        const coupon = await operation();
        setModal(null);
        notify(success(coupon));
        await refreshCoupons();
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          setSession('signed-out');
          notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

          return;
        }

        notify(error instanceof Error ? error.message : 'Không lưu được mã ưu đãi.');
      } finally {
        setCouponsBusy(false);
      }
    },
    [notify, refreshCoupons],
  );

  const createCoupon = useCallback(
    (draft: CouponDraft) => {
      void runCouponWrite(
        () => createCouponRequest(draft),
        (coupon) => `Đã tạo mã ${coupon.code}`,
      );
    },
    [runCouponWrite],
  );

  const updateCoupon = useCallback(
    (couponId: string, draft: CouponDraft) => {
      void runCouponWrite(
        () => updateCouponRequest(couponId, draft),
        (coupon) => `Đã lưu mã ${coupon.code}`,
      );
    },
    [runCouponWrite],
  );

  const loadLoyaltySummary = useCallback((): void => {
    if (loyaltySummaryLoaded.current) {
      return;
    }

    loyaltySummaryLoaded.current = true;

    void (async () => {
      try {
        setLoyaltySummary(await loadLoyaltySummaryRequest());
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          setSession('signed-out');
          loyaltySummaryLoaded.current = false;

          return;
        }
        // The dashboard tiles are a nice-to-have; a failure here should not
        // block the rest of the screen the way a list failure does.
        loyaltySummaryLoaded.current = false;
      }
    })();
  }, []);

  const runLoyaltyMemberLoad = useCallback(
    async (query: LoyaltyMemberQuery, cursor?: string): Promise<void> => {
      setLoyaltyMembersBusy(true);

      try {
        const page = await loadLoyaltyMemberList({ ...query, cursor });
        setLoyaltyMembers((current) =>
          cursor === undefined ? page.items : [...current, ...page.items],
        );
        setLoyaltyMemberCursor(page.nextCursor);
        setLoyaltyMembersError('');
        setSession('signed-in');
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          setSession('signed-out');
          setLoyaltyMembers([]);
          setLoyaltyMemberCursor(null);
          setLoyaltyMembersError('');

          return;
        }
        setLoyaltyMembersError(
          error instanceof Error ? error.message : 'Không tải được danh sách khách tích điểm.',
        );
      } finally {
        setLoyaltyMembersBusy(false);
      }
    },
    [],
  );

  const loadLoyaltyMembers = useCallback(
    (search: string): void => {
      const query: LoyaltyMemberQuery = { search };
      const key = loyaltyMemberQueryKey(query);

      if (key === shownLoyaltyMemberQuery.current) {
        return;
      }

      shownLoyaltyMemberQuery.current = key;
      loyaltyMemberQuery.current = query;
      void runLoyaltyMemberLoad(query);
    },
    [runLoyaltyMemberLoad],
  );

  const loadMoreLoyaltyMembers = useCallback((): void => {
    if (loyaltyMemberCursor === null) {
      return;
    }

    void runLoyaltyMemberLoad(loyaltyMemberQuery.current, loyaltyMemberCursor);
  }, [loyaltyMemberCursor, runLoyaltyMemberLoad]);

  const loadLoyaltyMemberDetail = useCallback(
    (phone: string): void => {
      setLoyaltyMemberDetailBusy(true);

      void (async () => {
        try {
          setLoyaltyMemberDetail(await loadLoyaltyMemberRequest(phone));
        } catch (error) {
          if (error instanceof ApiError && error.status === 401) {
            setSession('signed-out');
            setLoyaltyMemberDetail(null);

            return;
          }
          notify(error instanceof Error ? error.message : 'Không tải được lịch sử điểm.');
        } finally {
          setLoyaltyMemberDetailBusy(false);
        }
      })();
    },
    [notify],
  );

  const redeemLoyaltyReward = useCallback(
    (phone: string, rewardId: string, note: string | null) => {
      void (async () => {
        try {
          await redeemLoyaltyRewardRequest(phone, rewardId, note);
          notify('Đã đổi quà cho khách');
          // Refreshes in place: the dialog stays open, showing the new
          // balance and the entry that was just recorded.
          setLoyaltyMemberDetail(await loadLoyaltyMemberRequest(phone));
          shownLoyaltyMemberQuery.current = null;
          void runLoyaltyMemberLoad(loyaltyMemberQuery.current);
          loyaltySummaryLoaded.current = false;
          loadLoyaltySummary();
        } catch (error) {
          if (error instanceof ApiError && error.status === 401) {
            setSession('signed-out');
            notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

            return;
          }
          notify(error instanceof Error ? error.message : 'Không đổi được quà.');
        }
      })();
    },
    [notify, runLoyaltyMemberLoad, loadLoyaltySummary],
  );

  const refreshLoyaltyRewards = useCallback(async (): Promise<void> => {
    setLoyaltyRewardsBusy(true);

    try {
      setLoyaltyRewards(await loadLoyaltyRewardList());
      setLoyaltyRewardsError('');
      setSession('signed-in');
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        setSession('signed-out');
        setLoyaltyRewards([]);
        setLoyaltyRewardsError('');

        return;
      }
      setLoyaltyRewardsError(
        error instanceof Error ? error.message : 'Không tải được danh sách quà tặng.',
      );
    } finally {
      setLoyaltyRewardsBusy(false);
    }
  }, []);

  const loadLoyaltyRewards = useCallback((): void => {
    if (loyaltyRewardsLoaded.current) {
      return;
    }

    loyaltyRewardsLoaded.current = true;
    void refreshLoyaltyRewards();
  }, [refreshLoyaltyRewards]);

  const runLoyaltyRewardWrite = useCallback(
    async (
      operation: () => Promise<LoyaltyReward>,
      success: (reward: LoyaltyReward) => string,
    ): Promise<void> => {
      setLoyaltyRewardsBusy(true);

      try {
        const reward = await operation();
        setModal(null);
        notify(success(reward));
        await refreshLoyaltyRewards();
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          setSession('signed-out');
          notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

          return;
        }
        notify(error instanceof Error ? error.message : 'Không lưu được quà tặng.');
      } finally {
        setLoyaltyRewardsBusy(false);
      }
    },
    [notify, refreshLoyaltyRewards],
  );

  const createLoyaltyReward = useCallback(
    (draft: LoyaltyRewardDraft) => {
      void runLoyaltyRewardWrite(
        () => createLoyaltyRewardRequest(draft),
        (reward) => `Đã thêm quà ${reward.name}`,
      );
    },
    [runLoyaltyRewardWrite],
  );

  const updateLoyaltyReward = useCallback(
    (rewardId: string, draft: LoyaltyRewardDraft) => {
      void runLoyaltyRewardWrite(
        () => updateLoyaltyRewardRequest(rewardId, draft),
        (reward) => `Đã lưu quà ${reward.name}`,
      );
    },
    [runLoyaltyRewardWrite],
  );

  const loadLoyaltySettings = useCallback((): void => {
    if (loyaltySettingsLoaded.current) {
      return;
    }

    loyaltySettingsLoaded.current = true;

    void (async () => {
      try {
        setLoyaltySettings(await loadLoyaltySettingsRequest());
      } catch (error) {
        loyaltySettingsLoaded.current = false;
        if (error instanceof ApiError && error.status === 401) {
          setSession('signed-out');

          return;
        }
        notify(error instanceof Error ? error.message : 'Không tải được cấu hình tích điểm.');
      }
    })();
  }, [notify]);

  const updateLoyaltySettingsAction = useCallback(
    (settings: LoyaltySettings) => {
      void (async () => {
        try {
          setLoyaltySettings(await updateLoyaltySettingsRequest(settings));
          setModal(null);
          notify('Đã lưu cấu hình tích điểm');
        } catch (error) {
          if (error instanceof ApiError && error.status === 401) {
            setSession('signed-out');
            notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

            return;
          }
          notify(error instanceof Error ? error.message : 'Không lưu được cấu hình.');
        }
      })();
    },
    [notify],
  );

  /** This session's store id, fetched once and shared by every call in flight. */
  const resolveStoreId = useCallback(async (): Promise<string> => {
    if (storeIdRef.current !== null) {
      return storeIdRef.current;
    }

    storeIdRequest.current ??= loadStoreContext()
      .then((store) => {
        storeIdRef.current = store.id;

        return store.id;
      })
      .finally(() => {
        storeIdRequest.current = null;
      });

    return storeIdRequest.current;
  }, []);

  const runPaymentsLoad = useCallback(
    async (query: PaymentListQuery, cursor?: string, append = false): Promise<void> => {
      setPaymentsBusy(true);

      try {
        const page = await loadPaymentsRequest(cursor === undefined ? query : { ...query, cursor });
        setPayments((current) => (append ? [...current, ...page.items] : page.items));
        setPaymentsCursor(page.nextCursor);
        setPaymentsError('');
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          setSession('signed-out');
          notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

          return;
        }

        setPaymentsError(
          error instanceof Error ? error.message : 'Không tải được danh sách thanh toán.',
        );
      } finally {
        setPaymentsBusy(false);
      }
    },
    [notify],
  );

  const loadPayments = useCallback(
    (query: PaymentListQuery) => {
      paymentsQuery.current = query;
      void runPaymentsLoad(query);
    },
    [runPaymentsLoad],
  );

  const loadMorePayments = useCallback(() => {
    if (paymentsCursor === null) {
      return;
    }

    void runPaymentsLoad(paymentsQuery.current, paymentsCursor, true);
  }, [paymentsCursor, runPaymentsLoad]);

  /**
   * Runs one payment write (confirm or flag) and refreshes the queue on
   * success, the same shape as `runCouponWrite` above.
   */
  const runPaymentWrite = useCallback(
    async (operation: () => Promise<AdminPayment>, success: (payment: AdminPayment) => string) => {
      setPaymentsBusy(true);

      try {
        const payment = await operation();
        setModal(null);
        notify(success(payment));
        await runPaymentsLoad(paymentsQuery.current);
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          setSession('signed-out');
          notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

          return;
        }

        notify(error instanceof Error ? error.message : 'Không lưu được thanh toán.');
      } finally {
        setPaymentsBusy(false);
      }
    },
    [notify, runPaymentsLoad],
  );

  const confirmPayment = useCallback(
    (paymentId: string, input: ConfirmPaymentInput) => {
      void runPaymentWrite(
        () => confirmPaymentRequest(paymentId, input),
        (payment) => `Đã xác nhận đơn ${payment.orderCode}`,
      );
    },
    [runPaymentWrite],
  );

  const flagPayment = useCallback(
    (paymentId: string, input: FlagPaymentInput) => {
      void runPaymentWrite(
        () => flagPaymentRequest(paymentId, input),
        (payment) => `Đã ghi nhận ngoại lệ cho đơn ${payment.orderCode}`,
      );
    },
    [runPaymentWrite],
  );

  const loadBankAccount = useCallback(() => {
    setBankAccountBusy(true);

    void resolveStoreId()
      .then((storeId) => loadBankAccountRequest(storeId))
      .then((account) => {
        setBankAccount(account);
        setBankAccountError('');
        setBankAccountLoaded(true);
      })
      .catch((error: unknown) => {
        if (error instanceof ApiError && error.status === 401) {
          setSession('signed-out');
          notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

          return;
        }

        setBankAccountError(
          error instanceof Error ? error.message : 'Không tải được tài khoản ngân hàng.',
        );
        setBankAccountLoaded(true);
      })
      .finally(() => setBankAccountBusy(false));
  }, [notify, resolveStoreId]);

  /** The bank directory is the same for every store, so this asks once. */
  const loadKnownBanks = useCallback(() => {
    if (knownBanksLoaded.current) {
      return;
    }

    knownBanksLoaded.current = true;

    void loadKnownBanksRequest()
      .then((banks) => setKnownBanks(banks))
      .catch((error: unknown) => {
        knownBanksLoaded.current = false;

        if (error instanceof ApiError && error.status === 401) {
          setSession('signed-out');
          notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

          return;
        }

        notify(error instanceof Error ? error.message : 'Không tải được danh sách ngân hàng.');
      });
  }, [notify]);

  /**
   * Logos for the bank picker, fetched straight from VietQR's own public
   * directory in the browser (see bank-logos.ts). Purely decorative, so a
   * failure here is never surfaced - the picker works from `knownBanks`
   * either way, just without a picture next to each name.
   */
  const loadBankLogos = useCallback(() => {
    if (bankLogosLoaded.current) {
      return;
    }

    bankLogosLoaded.current = true;
    void loadBankLogosRequest().then((logos) => setBankLogos(logos));
  }, []);

  const saveBankAccount = useCallback(
    (draft: BankAccountDraft) => {
      setBankAccountBusy(true);

      void resolveStoreId()
        .then((storeId) => updateBankAccountRequest(storeId, draft))
        .then((account) => {
          setBankAccount(account);
          setModal(null);
          notify('Đã lưu tài khoản nhận chuyển khoản');
        })
        .catch((error: unknown) => {
          if (error instanceof ApiError && error.status === 401) {
            setSession('signed-out');
            notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

            return;
          }

          notify(error instanceof Error ? error.message : 'Không lưu được tài khoản ngân hàng.');
        })
        .finally(() => setBankAccountBusy(false));
    },
    [notify, resolveStoreId],
  );

  const loadPaymentMethodOptions = useCallback(() => {
    setPaymentMethodsBusy(true);

    void resolveStoreId()
      .then((storeId) => loadPaymentMethodOptionsRequest(storeId))
      .then((options) => setPaymentMethodOptions(options))
      .catch((error: unknown) => {
        if (error instanceof ApiError && error.status === 401) {
          setSession('signed-out');
          notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

          return;
        }

        notify(
          error instanceof Error
            ? error.message
            : 'Không tải được cấu hình phương thức thanh toán.',
        );
      })
      .finally(() => setPaymentMethodsBusy(false));
  }, [notify, resolveStoreId]);

  const setPaymentMethodEnabled = useCallback(
    (method: PaymentMethod, enabled: boolean) => {
      setPaymentMethodsBusy(true);

      void resolveStoreId()
        .then((storeId) => setPaymentMethodEnabledRequest(storeId, method, enabled))
        .then((options) => {
          setPaymentMethodOptions(options);
          notify(enabled ? 'Đã bật phương thức thanh toán' : 'Đã tắt phương thức thanh toán');
        })
        .catch((error: unknown) => {
          if (error instanceof ApiError && error.status === 401) {
            setSession('signed-out');
            notify('Phiên đăng nhập đã hết, vui lòng đăng nhập lại.');

            return;
          }

          notify(
            error instanceof Error
              ? error.message
              : 'Không lưu được cấu hình phương thức thanh toán.',
          );
        })
        .finally(() => setPaymentMethodsBusy(false));
    },
    [notify, resolveStoreId],
  );

  /**
   * Orders waiting for someone to act: a transfer not yet verified, or an
   * order the store has not acknowledged.
   *
   * Taken from the server's counts, not from the loaded page, so the badge is
   * the real number even when the screen is showing one filtered page.
   */
  const pendingCount = useMemo(
    () => (orderCounts.PENDING_PAYMENT ?? 0) + (orderCounts.PLACED ?? 0),
    [orderCounts],
  );

  const products = useMemo<readonly MenuProduct[]>(() => snapshot?.products ?? [], [snapshot]);

  const value = useMemo<AdminContextValue>(
    () => ({
      orders,
      orderCounts,
      products,
      coupons,
      loyaltySummary,
      loyaltyMembers,
      loyaltyMembersBusy,
      loyaltyMembersError,
      hasMoreLoyaltyMembers: loyaltyMemberCursor !== null,
      loyaltyMemberDetail,
      loyaltyMemberDetailBusy,
      loyaltyRewards,
      loyaltyRewardsBusy,
      loyaltyRewardsError,
      loyaltySettings,
      customers,
      pendingCount,
      modal,
      toastMessage,
      sidebarOpen,
      session,
      menuBusy,
      menuError,
      ordersBusy,
      ordersError,
      hasMoreOrders: orderCursor !== null,
      newOrderIds,
      couponsBusy,
      couponsError,
      payments,
      paymentsBusy,
      paymentsError,
      hasMorePayments: paymentsCursor !== null,
      bankAccount,
      bankAccountBusy,
      bankAccountError,
      bankAccountLoaded,
      knownBanks,
      bankLogos,
      paymentMethodOptions,
      paymentMethodsBusy,
      customersBusy,
      customersError,
      hasMoreCustomers: customersCursor !== null,
      openModal,
      closeModal,
      setSidebarOpen,
      notify,
      notifyAction,
      signIn,
      signOut,
      loadOrders: requestOrders,
      loadMoreOrders,
      moveOrder,
      markOrdersSeen,
      createProduct,
      updateProduct,
      deleteProduct,
      saveStock,
      savePortions,
      loadCoupons: requestCoupons,
      createCoupon,
      updateCoupon,
      loadPayments,
      loadMorePayments,
      confirmPayment,
      flagPayment,
      loadBankAccount,
      loadKnownBanks,
      loadBankLogos,
      saveBankAccount,
      loadPaymentMethodOptions,
      setPaymentMethodEnabled,
      loadLoyaltySummary,
      loadLoyaltyMembers,
      loadMoreLoyaltyMembers,
      loadLoyaltyMemberDetail,
      redeemLoyaltyReward,
      loadLoyaltyRewards,
      createLoyaltyReward,
      updateLoyaltyReward,
      loadLoyaltySettings,
      updateLoyaltySettings: updateLoyaltySettingsAction,
      loadCustomers,
      loadMoreCustomers,
    }),
    [
      orders,
      orderCounts,
      products,
      coupons,
      loyaltySummary,
      loyaltyMembers,
      loyaltyMembersBusy,
      loyaltyMembersError,
      loyaltyMemberCursor,
      loyaltyMemberDetail,
      loyaltyMemberDetailBusy,
      loyaltyRewards,
      loyaltyRewardsBusy,
      loyaltyRewardsError,
      loyaltySettings,
      customers,
      pendingCount,
      modal,
      toastMessage,
      sidebarOpen,
      session,
      menuBusy,
      menuError,
      ordersBusy,
      ordersError,
      orderCursor,
      newOrderIds,
      couponsBusy,
      couponsError,
      payments,
      paymentsBusy,
      paymentsError,
      paymentsCursor,
      bankAccount,
      bankAccountBusy,
      bankAccountError,
      bankAccountLoaded,
      knownBanks,
      bankLogos,
      paymentMethodOptions,
      paymentMethodsBusy,
      customersBusy,
      customersError,
      customersCursor,
      openModal,
      closeModal,
      notify,
      notifyAction,
      signIn,
      signOut,
      requestOrders,
      loadMoreOrders,
      moveOrder,
      markOrdersSeen,
      createProduct,
      updateProduct,
      deleteProduct,
      saveStock,
      savePortions,
      requestCoupons,
      createCoupon,
      updateCoupon,
      loadPayments,
      loadMorePayments,
      confirmPayment,
      flagPayment,
      loadBankAccount,
      loadKnownBanks,
      loadBankLogos,
      saveBankAccount,
      loadPaymentMethodOptions,
      setPaymentMethodEnabled,
      loadLoyaltySummary,
      loadLoyaltyMembers,
      loadMoreLoyaltyMembers,
      loadLoyaltyMemberDetail,
      redeemLoyaltyReward,
      loadLoyaltyRewards,
      createLoyaltyReward,
      updateLoyaltyReward,
      loadLoyaltySettings,
      updateLoyaltySettingsAction,
      loadCustomers,
      loadMoreCustomers,
    ],
  );

  return <AdminContext.Provider value={value}>{children}</AdminContext.Provider>;
}

export function useAdminData(): AdminContextValue {
  const value = useContext(AdminContext);
  if (value === null) {
    throw new Error('useAdminData must be used inside AdminDataProvider');
  }

  return value;
}
