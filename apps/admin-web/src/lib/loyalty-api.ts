/**
 * The loyalty screen's data layer.
 *
 * A member is identified by phone, not an id: there is no registered-account
 * module yet, so this is the same identity the admin customer directory uses
 * (CLAUDE.md section 5). `tier` is computed by the server from lifetime
 * points earned, never from the current balance - redeeming a reward does
 * not make a member less loyal, and deriving it here would risk disagreeing
 * with the server the moment a threshold changes.
 *
 * Earning has no endpoint here on purpose: a scheduled job credits every PAID
 * payment, so nothing a screen does can invent points.
 */

import { api } from './api-client';

export const LOYALTY_TIERS = ['MEMBER', 'LOYAL'] as const;

export type LoyaltyTier = (typeof LOYALTY_TIERS)[number];

export const LOYALTY_ENTRY_TYPES = ['EARN', 'REDEEM'] as const;

export type LoyaltyEntryType = (typeof LOYALTY_ENTRY_TYPES)[number];

export interface LoyaltySummary {
  readonly members: number;
  readonly pointsIssued: number;
  readonly redemptionsThisMonth: number;
}

export interface LoyaltyMember {
  readonly phone: string;
  readonly name: string;
  readonly lifetimeEarnedPoints: number;
  readonly balance: number;
  readonly tier: LoyaltyTier;
  readonly tierLabel: string;
}

export interface LoyaltyLedgerEntry {
  readonly id: string;
  readonly type: LoyaltyEntryType;
  /** Signed: positive for EARN, negative for REDEEM. */
  readonly points: number;
  readonly rewardName: string | null;
  readonly note: string | null;
  readonly actorName: string | null;
  /** ISO instant. The server stores UTC; the browser renders local time. */
  readonly createdAt: string;
}

export interface LoyaltyMemberDetail {
  readonly phone: string;
  readonly lifetimeEarnedPoints: number;
  readonly balance: number;
  /** Most recent first. */
  readonly history: readonly LoyaltyLedgerEntry[];
}

export interface LoyaltyReward {
  readonly id: string;
  readonly name: string;
  readonly pointsCost: number;
  readonly icon: string | null;
  readonly isActive: boolean;
  readonly redeemedCount: number;
}

/** What the reward form produced, before the server has judged it. */
export interface LoyaltyRewardDraft {
  readonly name: string;
  readonly pointsCost: number;
  readonly icon: string | null;
  readonly isActive: boolean;
}

export interface LoyaltySettings {
  readonly pointsPerCurrency: number;
  readonly loyalTierThresholdPoints: number;
}

export interface LoyaltyMemberQuery {
  readonly search: string;
  readonly cursor?: string;
}

export const LOYALTY_MEMBER_QUERY_DEFAULT: LoyaltyMemberQuery = { search: '' };

interface ApiLoyaltyMember {
  readonly phone: string;
  readonly name: string;
  readonly lifetime_earned_points: number;
  readonly balance: number;
  readonly tier: string;
  readonly tier_label: string;
}

interface ApiLoyaltyMemberPage {
  readonly items: readonly ApiLoyaltyMember[];
  readonly next_cursor: string | null;
  readonly has_more: boolean;
}

interface ApiLoyaltyLedgerEntry {
  readonly id: string;
  readonly type: string;
  readonly points: number;
  readonly reward_name: string | null;
  readonly note: string | null;
  readonly actor_name: string | null;
  readonly created_at: string;
}

interface ApiLoyaltyMemberDetail {
  readonly phone: string;
  readonly lifetime_earned_points: number;
  readonly balance: number;
  readonly history: readonly ApiLoyaltyLedgerEntry[];
}

interface ApiLoyaltyReward {
  readonly id: string;
  readonly name: string;
  readonly points_cost: number;
  readonly icon: string | null;
  readonly is_active: boolean;
  readonly redeemed_count: number;
}

interface ApiLoyaltyRewardPage {
  readonly items: readonly ApiLoyaltyReward[];
}

interface ApiLoyaltySettings {
  readonly points_per_currency: number;
  readonly loyal_tier_threshold_points: number;
}

/** Narrowed through the known list rather than cast. */
function toTier(value: string): LoyaltyTier {
  return LOYALTY_TIERS.find((tier) => tier === value) ?? 'MEMBER';
}

function toEntryType(value: string): LoyaltyEntryType {
  return LOYALTY_ENTRY_TYPES.find((type) => type === value) ?? 'EARN';
}

function toMember(member: ApiLoyaltyMember): LoyaltyMember {
  return {
    phone: member.phone,
    name: member.name,
    lifetimeEarnedPoints: member.lifetime_earned_points,
    balance: member.balance,
    tier: toTier(member.tier),
    tierLabel: member.tier_label,
  };
}

function toLedgerEntry(entry: ApiLoyaltyLedgerEntry): LoyaltyLedgerEntry {
  return {
    id: entry.id,
    type: toEntryType(entry.type),
    points: entry.points,
    rewardName: entry.reward_name,
    note: entry.note,
    actorName: entry.actor_name,
    createdAt: entry.created_at,
  };
}

function toReward(reward: ApiLoyaltyReward): LoyaltyReward {
  return {
    id: reward.id,
    name: reward.name,
    pointsCost: reward.points_cost,
    icon: reward.icon,
    isActive: reward.is_active,
    redeemedCount: reward.redeemed_count,
  };
}

function toSettings(settings: ApiLoyaltySettings): LoyaltySettings {
  return {
    pointsPerCurrency: settings.points_per_currency,
    loyalTierThresholdPoints: settings.loyal_tier_threshold_points,
  };
}

function toRewardPayload(draft: LoyaltyRewardDraft) {
  return {
    name: draft.name,
    points_cost: draft.pointsCost,
    icon: draft.icon,
    is_active: draft.isActive,
  };
}

/** A key two queries agree on only when they would ask the server the same thing. */
export function loyaltyMemberQueryKey(query: LoyaltyMemberQuery): string {
  return query.search.trim();
}

export async function loadLoyaltySummary(): Promise<LoyaltySummary> {
  return api.get<LoyaltySummary>('/admin/loyalty/summary');
}

export interface LoyaltyMemberPage {
  readonly items: readonly LoyaltyMember[];
  readonly nextCursor: string | null;
}

export async function loadLoyaltyMembers(query: LoyaltyMemberQuery): Promise<LoyaltyMemberPage> {
  const params = new URLSearchParams();
  if (query.search.trim() !== '') {
    params.set('q', query.search.trim());
  }
  if (query.cursor !== undefined) {
    params.set('cursor', query.cursor);
  }

  const suffix = params.size === 0 ? '' : `?${params.toString()}`;
  const page = await api.get<ApiLoyaltyMemberPage>(`/admin/loyalty/members${suffix}`);

  return { items: page.items.map(toMember), nextCursor: page.next_cursor };
}

export async function loadLoyaltyMember(phone: string): Promise<LoyaltyMemberDetail> {
  const detail = await api.get<ApiLoyaltyMemberDetail>(
    `/admin/loyalty/members/${encodeURIComponent(phone)}`,
  );

  return {
    phone: detail.phone,
    lifetimeEarnedPoints: detail.lifetime_earned_points,
    balance: detail.balance,
    history: detail.history.map(toLedgerEntry),
  };
}

export async function redeemLoyaltyReward(
  phone: string,
  rewardId: string,
  note: string | null,
): Promise<LoyaltyLedgerEntry> {
  const entry = await api.post<ApiLoyaltyLedgerEntry>(
    `/admin/loyalty/members/${encodeURIComponent(phone)}/redeem`,
    { reward_id: rewardId, note },
  );

  return toLedgerEntry(entry);
}

/** Active and inactive, most recently created first - this is the catalogue to manage, not to browse. */
export async function loadLoyaltyRewards(): Promise<readonly LoyaltyReward[]> {
  const page = await api.get<ApiLoyaltyRewardPage>('/admin/loyalty/rewards');

  return page.items.map(toReward);
}

export async function createLoyaltyReward(draft: LoyaltyRewardDraft): Promise<LoyaltyReward> {
  return toReward(
    await api.post<ApiLoyaltyReward>('/admin/loyalty/rewards', toRewardPayload(draft)),
  );
}

export async function updateLoyaltyReward(
  rewardId: string,
  draft: LoyaltyRewardDraft,
): Promise<LoyaltyReward> {
  return toReward(
    await api.put<ApiLoyaltyReward>(`/admin/loyalty/rewards/${rewardId}`, toRewardPayload(draft)),
  );
}

export async function loadLoyaltySettings(): Promise<LoyaltySettings> {
  return toSettings(await api.get<ApiLoyaltySettings>('/admin/loyalty/settings'));
}

export async function updateLoyaltySettings(settings: LoyaltySettings): Promise<LoyaltySettings> {
  return toSettings(
    await api.put<ApiLoyaltySettings>('/admin/loyalty/settings', {
      points_per_currency: settings.pointsPerCurrency,
      loyal_tier_threshold_points: settings.loyalTierThresholdPoints,
    }),
  );
}
