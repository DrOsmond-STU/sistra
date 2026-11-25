// Tipe data modul M4–M7, dashboard, dan asisten SISTRA AGENT.

export interface AiStatus {
  mode: "claude" | "simulasi"; configured: boolean; enabled: boolean; library: boolean; model: string; model_label: string;
  models: { id: string; label: string }[];
}

export interface Variant {
  id: number; angle: string; title: string; body: string; hashtags: string[]; ai_mode: string; draft_id: number | null; created_at: string;
}
export interface FactItem { text: string; source: string; url: string; reliability: string }
export interface Brief {
  id: number; title: string; issue_code: string | null; issue_name: string | null; format: string; objective: string; audience: string;
  region_code: string | null; region_name: string; tone: string; key_messages: string; facts: string[]; status: string; ai_mode: string | null;
  created_by: number; author_name: string; created_at: string; variant_count: number; variants?: Variant[]; notes?: string; mode?: string;
  fact_items?: FactItem[]; gen_ms?: number | null;
}
export interface M4Meta {
  formats: { id: string; label: string }[]; tones: string[]; angles: string[]; issues: { code: string; name: string }[]; ai: AiStatus;
}
export interface Finding { category: string; severity: "info" | "peringatan" | "kritis"; excerpt: string; suggestion: string }
export interface EditorialResult { mode: string; score: number; summary: string; findings: Finding[] }

export interface Card {
  id: number; title: string; format: string; status: string; ai_generated: boolean; issue_code: string | null; province_name: string | null;
  author_name: string; assignee_id: number | null; assignee_name: string | null; due_at: string | null; priority: string;
  editorial_score: number | null; comments: number; updated_at: string; overdue: boolean; distributed?: number; failed?: number;
}
export interface Board { columns: { status: string; cards: Card[] }[]; team: { id: number; name: string; roles: string[] }[] }
export interface DraftComment { id: number; user_name: string; body: string; at: string }

export interface Account {
  id: number; platform: string; platform_label: string; handle: string; name: string; scope: string; province_code: string | null;
  province_name: string | null; pic_user_id: number | null; pic_name: string | null; followers: number; token_status: string; mfa: boolean;
  active: boolean; url: string; pending_tasks: number; new_comments: number;
}
export interface PostTask {
  id: number; draft_id: number; draft_title: string; draft_status: string; format: string | null; account_id: number; platform: string; handle: string;
  planned_at: string; caption: string; status: string; overdue: boolean; assignee_name: string | null; posted_url: string | null; posted_at: string | null;
  posted_by_name: string | null; reach: number; engagement: number;
}
export interface SocialComment {
  id: number; account_id: number; platform: string; handle: string; author: string; text: string; at: string; issue_code: string | null;
  category: string | null; sentiment: string | null; priority: string | null; reason: string | null; bot_flag: boolean; status: string;
  suggested_reply: string | null; reply_text: string | null; replied_by_name: string | null; replied_at: string | null; ai_mode: string | null;
  pii_masked?: number; parent_id?: number | null; replies?: number;
}
export interface KbEntry { id: number; issue_code: string | null; title: string; question: string; answer: string; sources: string; status: string; updated_at: string }

export interface ChannelInfo {
  id: number; code: string; name: string; type: string; province_code: string | null; health: "normal" | "lambat" | "gangguan";
  latency_ms: number; uptime: number; checked_at: string | null; audience: number; sent_7d: number; failed_7d: number; views_7d: number; last_sent_at: string | null;
}
export interface Distribution {
  id: number; draft_id: number; channel_code: string; channel_name: string; status: string; sent_at: string | null; url: string | null;
  error: string | null; views: number; clicks: number; shares: number;
}

export interface ExecSummary {
  generated_at: string;
  kpi: Record<string, number>;
  trend: { date: string; produced: number; published: number; views: number }[];
  issues: { code: string; name: string; risk_level: string; drafts: number; published: number; comments: number; negative_comments: number; negative_ratio: number }[];
  decisions: Card[];
  channel_health: Record<"normal" | "lambat" | "gangguan", number>;
  alerts: { level: "tinggi" | "sedang"; text: string }[];
}

export type Pair = { key: string; value: number };
export interface Analytics {
  days: number;
  production: { by_format: Pair[]; by_status: Pair[]; by_issue: Pair[]; by_author: Pair[]; ai_assisted: number; total: number };
  editorial: { stage_hours: { stage: string; hours: number }[]; returned: number; avg_score: number | null; checked: number };
  distribution: {
    channels: { code: string; name: string; province_code: string | null; health: string; sent: number; views: number; clicks: number; failed: number; ctr: number }[];
    sent: number; views: number; failed: number;
  };
  social: {
    categories: Pair[]; sentiment: Pair[]; status: Pair[]; avg_reply_hours: number; replied: number; within_sla: number;
    accounts: { handle: string; platform: string; followers: number; posts: number; reach: number; engagement: number; engagement_rate: number; comments: number; replied: number }[];
  };
  ai: { feature: string; calls: number; live: number; input_tokens: number; output_tokens: number; avg_ms: number; errors: number }[];
}

export const FORMAT_LABEL: Record<string, string> = {
  rilis: "Siaran pers", artikel: "Artikel", opini: "Opini", caption: "Caption medsos", thread: "Utas (thread)", carousel: "Carousel",
  video: "Naskah video pendek", podcast: "Naskah podcast", infografis: "Infografis", faq: "Tanya-jawab (FAQ)",
  editorial: "Editorial", briefing: "Briefing", audio: "Audio",
};
export const CATEGORY_LABEL: Record<string, string> = {
  pertanyaan: "Pertanyaan", keluhan: "Keluhan", hoaks: "Hoaks/disinformasi", apresiasi: "Apresiasi", ujaran_kebencian: "Ujaran kebencian", spam: "Spam/promosi",
};
export const COMMENT_STATUS: Record<string, string> = {
  baru: "Baru", ditriase: "Ditriase", dibalas: "Dibalas", diabaikan: "Diabaikan", dieskalasi: "Dieskalasi",
};
export const HEALTH_LABEL: Record<string, string> = { normal: "Normal", lambat: "Lambat", gangguan: "Gangguan" };
export const AI_FEATURE_LABEL: Record<string, string> = {
  generate: "Produksi konten", pelokalan: "Pelokalan", editorial: "Cek editorial", triase: "Triase komentar",
  balasan: "Saran balasan", asisten: "Tanya SISTRA AGENT", tulis_ulang: "Tulis ulang", uji: "Uji koneksi",
};
