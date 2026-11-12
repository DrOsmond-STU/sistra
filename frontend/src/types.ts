export interface Unit { id: number; code: string; name: string; parent_id: number | null }

export interface User {
  id: number; username: string; full_name: string; nip: string; unit: Unit; roles: string[];
  clearance: string; clearance_label: string; active: boolean;
}

export type Level = "hijau" | "kuning" | "oranye" | "merah";
export type Sentiment = "positif" | "netral" | "negatif";

export interface IssueSummary {
  issue_id: number; code: string; name: string; risk_level: string; level: Level; reason: string;
  volume_24h: number; baseline_daily: number; growth: number; negative_ratio: number; province_spread: number;
}

export interface DashboardSummary {
  generated_at: string;
  window_hours: number;
  content: { total_window: number; total_all: number; sentiment: Record<Sentiment, number>; provinces_covered: number };
  timeline: { date: string; positif: number; netral: number; negatif: number; total: number }[];
  provinces: { code: string; name: string; volume: number; negative: number }[];
  issues: IssueSummary[];
  alerts: Record<"merah" | "oranye" | "kuning", number>;
  drafts: Record<string, number>;
  documents_accessible: number;
  sources: number;
  last_ingest_at: string | null;
}

export interface AlertEvent { at: string; action: string; level: Level; note: string; actor_id: number | null }
export interface Alert {
  id: number; issue_id: number; issue_code: string; issue_name: string; level: Level; status: string; reason: string;
  metrics: Record<string, number>; owner_id: number | null; triggered_at: string; updated_at: string;
  resolved_at: string | null; events: AlertEvent[];
}

export interface Issue {
  id: number; code: string; name: string; taxonomy_path: string; description: string; risk_level: string;
  keywords: string[]; active: boolean;
}

export interface ContentIssue { code: string; name: string; confidence: number; assigned_by: string }
export interface ContentItem {
  id: number; source_id: number; source_name: string; source_reliability: string; url: string; title: string;
  excerpt: string; author: string; published_at: string; ingested_at: string; sentiment_label: Sentiment;
  sentiment_score: number; province_code: string | null; province_name: string | null; provinces: string[];
  duplicate_of: number | null; issues: ContentIssue[]; body?: string;
}

export interface Source {
  id: number; type: string; name: string; url: string; platform: string; province_code: string | null;
  reliability: string; access_basis: string; active: boolean; last_fetched_at: string | null; item_count: number;
}

export interface IngestResult {
  received: number; inserted: number; exact_duplicates: number; near_duplicates: number; skipped: number;
  alerts_created: number[]; alerts_escalated: number[];
}

export interface Version {
  version_no: number; filename: string; mime: string; size: number; sha256: string; note: string;
  created_by: number; created_at: string;
}
export interface Grant {
  id: number; user_id: number; username: string; reason: string; valid_from: string; valid_until: string;
  revoked_at: string | null;
}
export interface Doc {
  id: number; title: string; description: string; owner_unit_code: string; owner_unit_name: string;
  classification: string; classification_label: string; period_start: string; period_end: string; origin: string;
  retention_class: string; tags: string[]; status: string; checked_out_by: number | null; current_version: Version;
  created_by: number; created_at: string; updated_at: string; snippet: string;
  permissions: { write: boolean; manage: boolean };
  versions?: Version[]; grants?: Grant[];
}
export interface DownloadLog { id: number; version_no: number; username: string; ip: string; watermark_id: string; at: string }

export interface Channel { id: number; code: string; name: string; type: string; province_code: string | null }
export interface CalendarEntry {
  id: number; draft_id: number; draft_title: string; draft_status: string; channel_code: string; channel_name: string;
  planned_at: string; status: string;
}
export interface Draft {
  id: number; kind: string; title: string; body: string; province_code: string | null; province_name: string | null;
  issue_code: string | null; ai_generated: boolean; ai_label: string | null; status: string; version: number;
  content_hash: string; created_by: number; author_name: string; created_at: string; updated_at: string;
  approved_by: number | null; approved_at: string | null; allowed_actions: string[];
  steps?: { from_status: string; to_status: string; actor_name: string; actor_role: string; comment: string; version: number; signature: string | null; at: string }[];
  revisions?: { version: number; title: string; edited_by: number; at: string }[];
  schedule?: CalendarEntry[];
}

export interface AuditEvent {
  seq: number; at: string; actor_username: string; action: string; object_type: string; object_id: string; ip: string;
  detail: Record<string, unknown>; hash: string;
}
