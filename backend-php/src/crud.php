<?php
/**
 * Mesin CRUD generik SISTRA.
 *
 * Setiap entitas didefinisikan sekali (tabel, kolom, tipe, hak akses per peran, penjaga aturan bisnis, kaskade).
 * Rute: GET /api/crud/meta · GET|POST /api/crud/{e} · GET|PUT|DELETE /api/crud/{e}/{id} · GET /api/crud/{e}/export
 *       GET /api/crud/{e}/options/{field} (pilihan referensi untuk formulir).
 * Alur kerja khusus (transisi persetujuan, penayangan, tanda tangan) tetap lewat endpoint modulnya; CRUD tidak dapat melompatinya.
 */
declare(strict_types=1);

const CRUD_ANY = ['*'];
const CRUD_EDITORS = ['redaktur', 'pimpinan'];
const CRUD_RISK = ['rendah' => 'Rendah', 'sedang' => 'Sedang', 'tinggi' => 'Tinggi'];

function crud_now(): string { return now_iso(); }
function crud_opts(array $list): array { return array_is_list($list) ? array_combine($list, array_map(fn($x) => ucfirst(str_replace('_', ' ', (string)$x)), $list)) : $list; }
function crud_draft_ok(int $id): array { $d = one('SELECT * FROM drafts WHERE id = ?', [$id]); if (!$d) fail(422, 'Naskah tidak ditemukan'); return $d; }
function crud_require_approved(?int $draftId): void
{
    if (!$draftId) return;
    $d = crud_draft_ok($draftId);
    if (!in_array($d['status'], ['disetujui', 'tayang'], true)) fail(409, 'Naskah harus sudah disetujui atau tayang');
}
function crud_referenced(string $sql, array $p, string $msg): ?string { return (int)val($sql, $p) > 0 ? $msg : null; }

/** Definisi seluruh entitas. */
function crud_entities(): array
{
    static $E = null;
    if ($E !== null) return $E;
    $prov = ['type' => 'province', 'label' => 'Provinsi', 'filter' => true];
    $user = fn(string $label, bool $req = false) => ['type' => 'ref', 'ref' => 'users', 'label' => $label, 'required' => $req];
    $E = [
        // ------------------------------------------------------------- master
        'issues' => ['label' => 'Isu', 'module' => 'Master', 'table' => 'issues', 'title' => 'name', 'order' => 'name ASC',
            'read' => CRUD_ANY, 'create' => ['analis', 'redaktur', 'pimpinan', 'admin'], 'update' => ['analis', 'redaktur', 'pimpinan', 'admin'], 'delete' => ['pimpinan', 'admin'],
            'fields' => [
                'code' => ['type' => 'text', 'label' => 'Kode', 'required' => true, 'min' => 3, 'max' => 20, 'unique' => true, 'upper' => true, 'list' => true, 'search' => true, 'immutable' => true],
                'name' => ['type' => 'text', 'label' => 'Nama isu', 'required' => true, 'min' => 3, 'max' => 120, 'list' => true, 'search' => true],
                'risk_level' => ['type' => 'enum', 'label' => 'Tingkat risiko', 'options' => CRUD_RISK, 'required' => true, 'list' => true, 'filter' => true],
                'taxonomy_path' => ['type' => 'text', 'label' => 'Taksonomi', 'max' => 200, 'list' => true],
                'description' => ['type' => 'textarea', 'label' => 'Deskripsi', 'max' => 2000],
                'keywords' => ['type' => 'lines', 'label' => 'Kata kunci (satu per baris)'],
                'active' => ['type' => 'bool', 'label' => 'Aktif', 'default' => true, 'list' => true, 'filter' => true],
            ],
            'defaults' => ['created_at' => '@now'],
            'can_delete' => fn($r) => crud_referenced('SELECT (SELECT COUNT(*) FROM drafts WHERE issue_id = ?) + (SELECT COUNT(*) FROM briefs WHERE issue_id = ?) + (SELECT COUNT(*) FROM campaigns WHERE issue_id = ?)',
                [$r['id'], $r['id'], $r['id']], 'Isu dipakai naskah/brief/kampanye; nonaktifkan saja agar jejak tetap utuh'),
        ],
        // ------------------------------------------------------------- M4
        'briefs' => ['label' => 'Brief konten', 'module' => 'M4', 'table' => 'briefs', 'title' => 'title', 'link' => '/produksi?brief={id}',
            'read' => CRUD_ANY, 'create' => AUTHOR_ROLES, 'update' => AUTHOR_ROLES, 'delete' => ['redaktur', 'pimpinan', 'produser', 'analis'],
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Judul/topik', 'required' => true, 'min' => 5, 'max' => 200, 'list' => true, 'search' => true],
                'issue_id' => ['type' => 'ref', 'ref' => 'issues', 'label' => 'Isu', 'required' => true, 'list' => true, 'filter' => true],
                'format' => ['type' => 'enum', 'label' => 'Format', 'options' => FORMAT_LABEL, 'required' => true, 'list' => true, 'filter' => true],
                'tone' => ['type' => 'enum', 'label' => 'Nada', 'options' => crud_opts(TONES), 'default' => 'informatif'],
                'region_code' => ['label' => 'Wilayah'] + $prov,
                'objective' => ['type' => 'text', 'label' => 'Tujuan', 'max' => 600],
                'audience' => ['type' => 'text', 'label' => 'Khalayak', 'max' => 300],
                'key_messages' => ['type' => 'textarea', 'label' => 'Pesan kunci', 'required' => true, 'min' => 5, 'max' => 2000, 'search' => true],
                'facts' => ['type' => 'facts', 'label' => 'Fakta bersumber (satu per baris: fakta | sumber | url | keandalan A–F)'],
                'status' => ['type' => 'enum', 'label' => 'Status', 'options' => ['baru' => 'Baru', 'dihasilkan' => 'Variasi siap', 'dipakai' => 'Dijadikan naskah'], 'default' => 'baru', 'list' => true, 'filter' => true],
                'created_by' => $user('Penyusun') + ['readonly' => true, 'list' => true],
                'created_at' => ['type' => 'datetime', 'label' => 'Dibuat', 'readonly' => true, 'list' => true],
            ],
            'defaults' => ['created_by' => '@user', 'created_at' => '@now', 'updated_at' => '@now'], 'touch' => true,
            'nullify' => [['drafts', 'brief_id'], ['coverage', 'brief_id']], 'cascade' => [['brief_variants', 'brief_id']],
        ],
        'brief_variants' => ['label' => 'Variasi konten AI', 'module' => 'M4', 'table' => 'brief_variants', 'title' => 'title',
            'read' => CRUD_ANY, 'create' => AUTHOR_ROLES, 'update' => AUTHOR_ROLES, 'delete' => AUTHOR_ROLES,
            'fields' => [
                'brief_id' => ['type' => 'ref', 'ref' => 'briefs', 'label' => 'Brief', 'required' => true, 'list' => true, 'filter' => true],
                'angle' => ['type' => 'text', 'label' => 'Sudut pandang', 'required' => true, 'max' => 120, 'list' => true, 'filter' => true],
                'title' => ['type' => 'text', 'label' => 'Judul', 'required' => true, 'min' => 5, 'max' => 300, 'list' => true, 'search' => true],
                'body' => ['type' => 'textarea', 'label' => 'Isi', 'required' => true, 'min' => 20, 'max' => 20000, 'search' => true],
                'hashtags' => ['type' => 'lines', 'label' => 'Tagar (satu per baris)'],
                'ai_mode' => ['type' => 'enum', 'label' => 'Mode AI', 'options' => ['claude' => 'Claude', 'simulasi' => 'Simulasi', 'manual' => 'Manual'], 'default' => 'manual', 'list' => true, 'filter' => true],
                'draft_id' => ['type' => 'ref', 'ref' => 'drafts', 'label' => 'Naskah', 'readonly' => true, 'list' => true],
            ],
            'defaults' => ['created_at' => '@now'],
            'can_update' => fn($r) => $r['draft_id'] ? 'Variasi sudah dijadikan naskah; sunting naskahnya' : null,
            'can_delete' => fn($r) => $r['draft_id'] ? 'Variasi sudah dijadikan naskah' : null,
        ],
        'drafts' => ['label' => 'Naskah', 'module' => 'M4', 'table' => 'drafts', 'title' => 'title', 'order' => 'updated_at DESC', 'link' => '/editorial?id={id}',
            'read' => CRUD_ANY, 'create' => AUTHOR_ROLES, 'update' => array_merge(AUTHOR_ROLES, ['pimpinan']), 'delete' => array_merge(AUTHOR_ROLES, ['pimpinan']),
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Judul', 'required' => true, 'min' => 5, 'max' => 300, 'list' => true, 'search' => true],
                'format' => ['type' => 'enum', 'label' => 'Format', 'options' => FORMAT_LABEL, 'required' => true, 'list' => true, 'filter' => true],
                'body' => ['type' => 'textarea', 'label' => 'Isi', 'required' => true, 'min' => 20, 'max' => 50000, 'search' => true],
                'issue_id' => ['type' => 'ref', 'ref' => 'issues', 'label' => 'Isu', 'list' => true, 'filter' => true],
                'province_code' => $prov + ['list' => true],
                'status' => ['type' => 'enum', 'label' => 'Status', 'options' => ['draf' => 'Draf', 'ditinjau' => 'Ditinjau', 'disetujui' => 'Disetujui', 'tayang' => 'Tayang', 'ditarik' => 'Ditarik'],
                    'readonly' => true, 'list' => true, 'filter' => true, 'hint' => 'Diubah lewat alur persetujuan di Editorial'],
                'priority' => ['type' => 'enum', 'label' => 'Prioritas', 'options' => ['tinggi' => 'Tinggi', 'normal' => 'Normal', 'rendah' => 'Rendah'], 'default' => 'normal', 'list' => true, 'filter' => true],
                'assignee_id' => $user('Penanggung jawab') + ['list' => true],
                'due_at' => ['type' => 'datetime', 'label' => 'Tenggat'],
                'hashtags' => ['type' => 'lines', 'label' => 'Tagar (satu per baris)'],
                'ai_generated' => ['type' => 'bool', 'label' => 'Dibantu AI', 'default' => false, 'filter' => true],
                'version' => ['type' => 'int', 'label' => 'Versi', 'readonly' => true, 'list' => true],
                'updated_at' => ['type' => 'datetime', 'label' => 'Diperbarui', 'readonly' => true, 'list' => true],
            ],
            'defaults' => ['status' => 'draf', 'version' => 1, 'created_by' => '@user', 'created_at' => '@now', 'updated_at' => '@now'], 'touch' => true,
            'before_save' => function (array $data, ?array $old, array $u): array {
                $data['kind'] = $data['format'] ?? ($old['kind'] ?? 'artikel');
                $t = $data['title'] ?? $old['title']; $b = $data['body'] ?? $old['body'];
                $h = draft_hash((string)$t, (string)$b);
                if (!$old) { $data['content_hash'] = $h; return $data; }
                if ($h !== $old['content_hash']) { $data['content_hash'] = $h; $data['version'] = (int)$old['version'] + 1; }
                return $data;
            },
            'after_save' => function (int $id, array $data, ?array $old, array $u): void {
                $d = one('SELECT * FROM drafts WHERE id = ?', [$id]);
                if (!$old || (int)$d['version'] !== (int)$old['version'])
                    insert('draft_revisions', ['draft_id' => $id, 'version' => (int)$d['version'], 'title' => $d['title'], 'body' => $d['body'], 'edited_by' => $u['id'], 'at' => now_iso()]);
                if (!empty($data['assignee_id']) && (!$old || (int)$old['assignee_id'] !== (int)$data['assignee_id']))
                    notify([(int)$data['assignee_id']], 'penugasan', "Anda ditugaskan: {$d['title']}", '/editorial?id=' . $id, (int)$u['id']);
            },
            'can_update' => function (array $r, array $u, array $data): ?string {
                $content = array_intersect(array_keys($data), ['title', 'body', 'format', 'issue_id', 'province_code', 'hashtags', 'ai_generated']);
                if ($content && $r['status'] !== 'draf') return 'Isi naskah hanya dapat diubah pada status Draf (versi yang sudah diajukan/disetujui terkunci tanda tangan)';
                if ($content && (int)$r['created_by'] !== (int)$u['id'] && !has_role($u, 'redaktur')) return 'Hanya penulis atau redaktur yang dapat menyunting isi naskah';
                if (array_intersect(array_keys($data), ['priority', 'assignee_id', 'due_at']) && !has_role($u, 'redaktur', 'pimpinan') && (int)$r['created_by'] !== (int)$u['id'])
                    return 'Penugasan diatur redaktur/pimpinan';
                return null;
            },
            'can_delete' => function (array $r, array $u): ?string {
                if ($r['status'] !== 'draf') return 'Hanya naskah berstatus Draf yang dapat dihapus; naskah lain ditarik lewat alur persetujuan';
                if ((int)val('SELECT COUNT(*) FROM approval_steps WHERE draft_id = ?', [$r['id']])) return 'Naskah pernah diajukan; riwayat persetujuan wajib disimpan — gunakan Tarik';
                if ((int)$r['created_by'] !== (int)$u['id'] && !has_role($u, 'redaktur', 'pimpinan')) return 'Hanya penulis, redaktur, atau pimpinan yang dapat menghapus';
                return null;
            },
            'cascade' => [['draft_revisions', 'draft_id'], ['draft_comments', 'draft_id'], ['draft_assets', 'draft_id'], ['editorial_feedback', 'draft_id'], ['media_outputs', 'draft_id'],
                ['calendar_entries', 'draft_id'], ['renditions', 'draft_id'], ['campaign_slots', 'draft_id']],
            'nullify' => [['brief_variants', 'draft_id'], ['partner_collabs', 'draft_id']],
            'block_children' => [['post_tasks', 'draft_id', 'tugas unggah'], ['distributions', 'draft_id', 'distribusi'], ['press_packs', 'draft_id', 'paket media']],
        ],
        'local_context' => ['label' => 'Konteks lokal provinsi', 'module' => 'M4', 'table' => 'local_context', 'title' => 'content', 'order' => 'province_code ASC, id ASC',
            'read' => CRUD_ANY, 'create' => AUTHOR_ROLES, 'update' => array_merge(AUTHOR_ROLES, ['pimpinan']), 'delete' => ['redaktur', 'pimpinan'],
            'fields' => [
                'province_code' => ['label' => 'Provinsi', 'required' => true, 'list' => true] + $prov,
                'category' => ['type' => 'enum', 'label' => 'Kategori', 'options' => LC_CATEGORIES, 'required' => true, 'list' => true, 'filter' => true],
                'content' => ['type' => 'textarea', 'label' => 'Isi', 'required' => true, 'min' => 3, 'max' => 600, 'list' => true, 'search' => true],
                'source' => ['type' => 'text', 'label' => 'Sumber', 'required' => true, 'min' => 3, 'max' => 300, 'list' => true, 'search' => true],
                'verified' => ['type' => 'bool', 'label' => 'Terverifikasi', 'default' => false, 'list' => true, 'filter' => true, 'hint' => 'Hanya redaktur/pimpinan'],
            ],
            'defaults' => ['created_by' => '@user', 'created_at' => '@now'],
            'before_save' => function (array $data, ?array $old, array $u): array {
                if (array_key_exists('verified', $data)) {
                    if ((bool)$data['verified'] !== (bool)($old['verified'] ?? false) && !has_role($u, 'redaktur', 'pimpinan')) fail(403, 'Verifikasi konteks lokal hanya oleh redaktur/pimpinan');
                    $data['verified_by'] = $data['verified'] ? $u['id'] : null; $data['verified_at'] = $data['verified'] ? now_iso() : null;
                }
                if ($old && $old['verified'] && array_intersect(array_keys($data), ['content', 'source', 'province_code', 'category']) && empty($data['verified'])) { $data['verified'] = 0; $data['verified_by'] = null; $data['verified_at'] = null; }
                return $data;
            },
        ],
        'media_outputs' => ['label' => 'Keluaran multimedia', 'module' => 'M4', 'table' => 'media_outputs', 'title' => 'title', 'link' => '/studio?draft={draft_id}',
            'read' => CRUD_ANY, 'create' => [], 'update' => AUTHOR_ROLES, 'delete' => array_merge(AUTHOR_ROLES, ['pimpinan']),
            'create_hint' => 'Keluaran multimedia dibuat dari Studio Multimedia (AI menyusun spesifikasinya).',
            'fields' => [
                'draft_id' => ['type' => 'ref', 'ref' => 'drafts', 'label' => 'Naskah', 'readonly' => true, 'list' => true],
                'kind' => ['type' => 'enum', 'label' => 'Jenis', 'options' => ['infografis' => 'Infografis', 'video' => 'Video pendek', 'podcast' => 'Naskah audio'], 'readonly' => true, 'list' => true, 'filter' => true],
                'title' => ['type' => 'text', 'label' => 'Judul', 'required' => true, 'min' => 3, 'max' => 300, 'list' => true, 'search' => true],
                'template_code' => ['type' => 'enum', 'label' => 'Templat', 'options' => ['INF-STD' => 'Infografis standar', 'INF-DARURAT' => 'Infografis siaga', 'INF-CERAH' => 'Infografis layanan', 'VID-STD' => 'Video standar'], 'list' => true],
                'ai_mode' => ['type' => 'enum', 'label' => 'Mode AI', 'options' => ['claude' => 'Claude', 'simulasi' => 'Simulasi'], 'readonly' => true, 'list' => true, 'filter' => true],
                'created_at' => ['type' => 'datetime', 'label' => 'Dibuat', 'readonly' => true, 'list' => true],
            ],
            'touch' => true,
        ],
        'editorial_feedback' => ['label' => 'Koreksi redaktur (agen editorial)', 'module' => 'M4', 'table' => 'editorial_feedback', 'title' => 'excerpt', 'order' => 'at DESC',
            'read' => CRUD_ANY, 'create' => ['redaktur', 'pimpinan', 'produser', 'analis'], 'update' => ['redaktur', 'pimpinan'], 'delete' => ['redaktur', 'pimpinan'],
            'fields' => [
                'draft_id' => ['type' => 'ref', 'ref' => 'drafts', 'label' => 'Naskah', 'required' => true, 'list' => true],
                'category' => ['type' => 'enum', 'label' => 'Kategori', 'options' => ['ejaan' => 'Ejaan', 'sara' => 'SARA', 'nada' => 'Nada', 'fakta' => 'Fakta', 'hukum' => 'Hukum', 'kejelasan' => 'Kejelasan'], 'required' => true, 'list' => true, 'filter' => true],
                'severity' => ['type' => 'enum', 'label' => 'Tingkat', 'options' => ['info' => 'Info', 'peringatan' => 'Peringatan', 'kritis' => 'Kritis'], 'default' => 'info', 'list' => true],
                'excerpt' => ['type' => 'text', 'label' => 'Kutipan', 'max' => 300, 'list' => true, 'search' => true],
                'suggestion' => ['type' => 'textarea', 'label' => 'Saran AI', 'max' => 500],
                'decision' => ['type' => 'enum', 'label' => 'Keputusan', 'options' => ['diterima' => 'Diterima', 'ditolak' => 'Ditolak'], 'required' => true, 'list' => true, 'filter' => true],
                'note' => ['type' => 'text', 'label' => 'Catatan redaktur', 'max' => 500],
                'user_id' => $user('Redaktur') + ['readonly' => true, 'list' => true],
            ],
            'defaults' => ['user_id' => '@user', 'at' => '@now'],
        ],
        // ------------------------------------------------------------- M5
        'draft_comments' => ['label' => 'Diskusi naskah', 'module' => 'M5', 'table' => 'draft_comments', 'title' => 'body', 'order' => 'at DESC',
            'read' => CRUD_ANY, 'create' => CRUD_ANY, 'update' => CRUD_ANY, 'delete' => CRUD_ANY,
            'fields' => [
                'draft_id' => ['type' => 'ref', 'ref' => 'drafts', 'label' => 'Naskah', 'required' => true, 'list' => true, 'immutable' => true],
                'body' => ['type' => 'textarea', 'label' => 'Catatan', 'required' => true, 'min' => 1, 'max' => 4000, 'list' => true, 'search' => true],
                'user_id' => $user('Penulis catatan') + ['readonly' => true, 'list' => true],
                'at' => ['type' => 'datetime', 'label' => 'Waktu', 'readonly' => true, 'list' => true],
            ],
            'defaults' => ['user_id' => '@user', 'at' => '@now'],
            'can_update' => fn($r, $u) => (int)$r['user_id'] === (int)$u['id'] ? null : 'Hanya penulis catatan yang dapat mengubahnya',
            'can_delete' => fn($r, $u) => (int)$r['user_id'] === (int)$u['id'] || has_role($u, 'redaktur', 'pimpinan') ? null : 'Hanya penulis catatan, redaktur, atau pimpinan',
        ],
        'calendar_entries' => ['label' => 'Jadwal tayang kanal', 'module' => 'M5', 'table' => 'calendar_entries', 'title' => 'planned_at', 'order' => 'planned_at DESC', 'link' => '/kalender',
            'read' => CRUD_ANY, 'create' => ['redaktur', 'operator', 'pimpinan'], 'update' => ['redaktur', 'operator', 'pimpinan'], 'delete' => ['redaktur', 'operator', 'pimpinan'],
            'fields' => [
                'draft_id' => ['type' => 'ref', 'ref' => 'drafts', 'label' => 'Naskah', 'required' => true, 'list' => true, 'ref_where' => "status NOT IN ('tayang','ditarik')"],
                'channel_id' => ['type' => 'ref', 'ref' => 'channels', 'label' => 'Kanal', 'required' => true, 'list' => true, 'filter' => true],
                'planned_at' => ['type' => 'datetime', 'label' => 'Rencana tayang', 'required' => true, 'list' => true],
                'status' => ['type' => 'enum', 'label' => 'Status', 'options' => ['rencana' => 'Rencana', 'tayang' => 'Tayang', 'batal' => 'Batal'], 'default' => 'rencana', 'list' => true, 'filter' => true],
            ],
            'defaults' => ['created_by' => '@user', 'created_at' => '@now'],
            'before_save' => function (array $data, ?array $old): array {
                if (!$old && isset($data['draft_id'])) { $d = crud_draft_ok((int)$data['draft_id']); if (in_array($d['status'], ['tayang', 'ditarik'], true)) fail(409, 'Naskah sudah tayang/ditarik'); }
                return $data;
            },
        ],
        'assets' => ['label' => 'Bank aset (DAM)', 'module' => 'M5', 'table' => 'assets', 'title' => 'title', 'link' => '/aset', 'upload' => true,
            'read' => CRUD_ANY, 'create' => ['redaktur', 'produser', 'analis', 'operator', 'pimpinan'], 'update' => ['redaktur', 'produser', 'analis', 'pimpinan'], 'delete' => ['redaktur', 'produser', 'pimpinan'],
            'fields' => [
                'file' => ['type' => 'file', 'label' => 'Berkas', 'required' => true, 'virtual' => true, 'create_only' => true],
                'title' => ['type' => 'text', 'label' => 'Judul', 'required' => true, 'min' => 2, 'max' => 200, 'list' => true, 'search' => true],
                'kind' => ['type' => 'enum', 'label' => 'Jenis', 'options' => ['gambar' => 'Gambar', 'video' => 'Video', 'audio' => 'Audio', 'dokumen' => 'Dokumen'], 'readonly' => true, 'list' => true, 'filter' => true],
                'license' => ['type' => 'enum', 'label' => 'Lisensi', 'options' => ASSET_LICENSES, 'required' => true, 'list' => true, 'filter' => true],
                'license_expires_at' => ['type' => 'datetime', 'label' => 'Lisensi berlaku s.d.', 'list' => true],
                'credit' => ['type' => 'text', 'label' => 'Kredit/pemilik', 'max' => 200, 'list' => true, 'search' => true],
                'tags' => ['type' => 'lines', 'label' => 'Tag (satu per baris)', 'search' => true],
                'description' => ['type' => 'textarea', 'label' => 'Deskripsi', 'max' => 1000, 'search' => true],
                'issue_id' => ['type' => 'ref', 'ref' => 'issues', 'label' => 'Isu', 'filter' => true],
                'province_code' => $prov,
                'size' => ['type' => 'int', 'label' => 'Ukuran (bita)', 'readonly' => true, 'list' => true],
            ],
            'defaults' => ['created_by' => '@user', 'created_at' => '@now'],
            'before_save' => function (array $data, ?array $old): array {
                $lic = $data['license'] ?? $old['license'] ?? '';
                $exp = array_key_exists('license_expires_at', $data) ? $data['license_expires_at'] : ($old['license_expires_at'] ?? null);
                if ($lic === 'berlisensi' && !$exp) fail(422, 'Aset berlisensi wajib memiliki tanggal kedaluwarsa lisensi');
                return $data;
            },
            'cascade' => [['draft_assets', 'asset_id']],
            'can_delete' => fn($r) => in_array((int)$r['id'], array_merge(...array_map(fn($x) => array_map('intval', jdec($x['asset_ids'])), all('SELECT asset_ids FROM press_packs') ?: [['asset_ids' => '[]']])), true)
                ? 'Aset dilampirkan pada paket media; lepaskan dari paket dulu' : null,
        ],
        'visual_templates' => ['label' => 'Templat visual', 'module' => 'M5', 'table' => 'visual_templates', 'title' => 'name', 'where' => 'is_current = 1', 'order' => 'kind ASC, name ASC', 'link' => '/aset?tab=templat',
            'read' => CRUD_ANY, 'create' => ['produser', 'redaktur'], 'update' => ['produser', 'redaktur'], 'delete' => ['redaktur', 'pimpinan'],
            'fields' => [
                'code' => ['type' => 'text', 'label' => 'Kode', 'required' => true, 'min' => 3, 'max' => 30, 'upper' => true, 'list' => true, 'search' => true, 'immutable' => true, 'pattern' => '/^[A-Z0-9-]+$/', 'pattern_msg' => 'Kode hanya huruf kapital, angka, dan tanda hubung'],
                'name' => ['type' => 'text', 'label' => 'Nama', 'required' => true, 'min' => 3, 'max' => 120, 'list' => true, 'search' => true],
                'kind' => ['type' => 'enum', 'label' => 'Jenis', 'options' => ['infografis' => 'Infografis', 'video' => 'Video', 'kartu' => 'Kartu kutipan'], 'required' => true, 'list' => true, 'filter' => true, 'immutable' => true],
                'spec_bg' => ['type' => 'color', 'label' => 'Warna latar', 'json' => ['spec', 'bg'], 'default' => '#10243F'],
                'spec_fg' => ['type' => 'color', 'label' => 'Warna teks', 'json' => ['spec', 'fg'], 'default' => '#FFFFFF'],
                'spec_accent' => ['type' => 'color', 'label' => 'Warna aksen', 'json' => ['spec', 'accent'], 'default' => '#F2B705'],
                'spec_logo_text' => ['type' => 'text', 'label' => 'Teks logo', 'json' => ['spec', 'logo_text'], 'max' => 80, 'default' => 'KANAL INFORMASI RESMI (contoh)'],
                'spec_footer' => ['type' => 'text', 'label' => 'Kaki', 'json' => ['spec', 'footer'], 'max' => 120, 'default' => 'Sumber resmi'],
                'version' => ['type' => 'int', 'label' => 'Versi', 'readonly' => true, 'list' => true],
                'locked' => ['type' => 'bool', 'label' => 'Terkunci', 'default' => false, 'list' => true, 'filter' => true, 'hint' => 'Kunci hanya oleh redaktur'],
                'note' => ['type' => 'text', 'label' => 'Catatan versi', 'max' => 300],
            ],
            'defaults' => ['version' => 1, 'is_current' => 1, 'created_by' => '@user', 'created_at' => '@now', 'spec' => '{"font":"Inter, Arial, sans-serif"}'],
            'before_save' => function (array $data, ?array $old, array $u): array {
                if (!$old && one('SELECT id FROM visual_templates WHERE code = ?', [$data['code']])) fail(409, 'Kode templat sudah dipakai');
                if (array_key_exists('locked', $data) && (bool)$data['locked'] !== (bool)($old['locked'] ?? false) && !has_role($u, 'redaktur')) fail(403, 'Mengunci/membuka templat hanya oleh redaktur');
                return $data;
            },
            // perubahan isi = versi baru (riwayat versi tetap tersimpan)
            'versioned' => true,
            'can_update' => fn($r, $u, $data) => $r['locked'] && array_diff(array_keys($data), ['locked']) ? 'Templat terkunci; buka kunci dulu' : null,
            'delete_all_by' => 'code',
        ],
        'coverage' => ['label' => 'Liputan lapangan', 'module' => 'M5', 'table' => 'coverage', 'title' => 'title', 'order' => 'event_at DESC', 'link' => '/aset?tab=liputan',
            'read' => CRUD_ANY, 'create' => CRUD_EDITORS, 'update' => ['redaktur', 'pimpinan', 'produser', 'analis'], 'delete' => CRUD_EDITORS,
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Kegiatan', 'required' => true, 'min' => 5, 'max' => 200, 'list' => true, 'search' => true],
                'event_at' => ['type' => 'datetime', 'label' => 'Waktu', 'required' => true, 'list' => true],
                'location' => ['type' => 'text', 'label' => 'Lokasi', 'required' => true, 'min' => 3, 'max' => 200, 'list' => true, 'search' => true],
                'province_code' => $prov + ['list' => true],
                'reporter_id' => $user('Petugas') + ['list' => true],
                'status' => ['type' => 'enum', 'label' => 'Status', 'options' => ['direncanakan' => 'Direncanakan', 'berlangsung' => 'Berlangsung', 'selesai' => 'Selesai', 'batal' => 'Batal'], 'default' => 'direncanakan', 'list' => true, 'filter' => true],
                'brief' => ['type' => 'textarea', 'label' => 'Arahan liputan', 'max' => 2000],
            ],
            'defaults' => ['created_by' => '@user', 'created_at' => '@now', 'updated_at' => '@now'], 'touch' => true,
            'after_save' => function (int $id, array $data, ?array $old, array $u): void {
                if (!empty($data['reporter_id']) && (!$old || (int)$old['reporter_id'] !== (int)$data['reporter_id']))
                    notify([(int)$data['reporter_id']], 'liputan', 'Anda ditugaskan meliput: ' . val('SELECT title FROM coverage WHERE id = ?', [$id]), '/aset?tab=liputan', (int)$u['id']);
            },
            'nullify' => [['assets', 'coverage_id']],
        ],
        'notifications' => ['label' => 'Notifikasi', 'module' => 'M5', 'table' => 'notifications', 'title' => 'text', 'order' => 'id DESC',
            'read' => CRUD_ANY, 'create' => ['pimpinan', 'redaktur', 'admin'], 'update' => CRUD_ANY, 'delete' => CRUD_ANY,
            'scope' => fn(array $u) => has_role($u, 'admin') ? ['1=1', []] : ['user_id = ?', [$u['id']]],
            'fields' => [
                'user_id' => $user('Penerima', true) + ['list' => true, 'immutable' => true],
                'kind' => ['type' => 'enum', 'label' => 'Jenis', 'options' => ['pengumuman' => 'Pengumuman', 'persetujuan' => 'Persetujuan', 'penugasan' => 'Penugasan', 'diskusi' => 'Diskusi', 'tugas_unggah' => 'Tugas unggah',
                    'paket_respons' => 'Paket respons', 'kampanye' => 'Kampanye', 'distribusi' => 'Distribusi', 'liputan' => 'Liputan'], 'default' => 'pengumuman', 'list' => true, 'filter' => true],
                'text' => ['type' => 'text', 'label' => 'Pesan', 'required' => true, 'min' => 3, 'max' => 300, 'list' => true, 'search' => true],
                'link' => ['type' => 'text', 'label' => 'Tautan di aplikasi', 'max' => 200, 'pattern' => '#^(/[A-Za-z0-9/_?=&.-]*)?$#', 'pattern_msg' => 'Tautan harus berupa jalur internal, mis. /editorial'],
                'read_at' => ['type' => 'datetime', 'label' => 'Dibaca', 'list' => true],
                'at' => ['type' => 'datetime', 'label' => 'Waktu', 'readonly' => true, 'list' => true],
            ],
            'defaults' => ['at' => '@now'],
            'can_update' => fn($r, $u, $data) => (int)$r['user_id'] === (int)$u['id'] || has_role($u, 'admin') ? null : 'Hanya penerima yang dapat mengubah',
            'can_delete' => fn($r, $u) => (int)$r['user_id'] === (int)$u['id'] || has_role($u, 'admin') ? null : 'Hanya penerima yang dapat menghapus',
        ],
        // ------------------------------------------------------------- M6
        'social_accounts' => ['label' => 'Akun media sosial resmi', 'module' => 'M6', 'table' => 'social_accounts', 'title' => 'handle', 'order' => 'followers DESC', 'link' => '/kanal-resmi?tab=akun',
            'read' => CRUD_ANY, 'create' => ['admin', 'operator'], 'update' => ['admin', 'operator'], 'delete' => ['admin'],
            'fields' => [
                'platform' => ['type' => 'enum', 'label' => 'Platform', 'options' => PLATFORMS, 'required' => true, 'list' => true, 'filter' => true],
                'handle' => ['type' => 'text', 'label' => 'Akun', 'required' => true, 'min' => 2, 'max' => 80, 'list' => true, 'search' => true],
                'name' => ['type' => 'text', 'label' => 'Nama', 'required' => true, 'min' => 2, 'max' => 120, 'list' => true, 'search' => true],
                'scope' => ['type' => 'enum', 'label' => 'Cakupan', 'options' => ['nasional' => 'Nasional', 'provinsi' => 'Provinsi', 'kabkota' => 'Kabupaten/kota'], 'default' => 'nasional', 'list' => true, 'filter' => true],
                'province_code' => $prov + ['list' => true],
                'pic_user_id' => $user('Penanggung jawab'),
                'followers' => ['type' => 'int', 'label' => 'Pengikut', 'min' => 0, 'default' => 0, 'list' => true],
                'mfa' => ['type' => 'bool', 'label' => 'MFA aktif', 'default' => true, 'list' => true, 'filter' => true],
                'token_status' => ['type' => 'enum', 'label' => 'Status token', 'options' => ['aman' => 'Aman', 'kedaluwarsa' => 'Kedaluwarsa', 'dicabut' => 'Dicabut'], 'default' => 'aman', 'filter' => true],
                'quota_daily' => ['type' => 'int', 'label' => 'Kuota harian', 'min' => 1, 'max' => 50, 'default' => 4],
                'quota_weekly' => ['type' => 'int', 'label' => 'Kuota mingguan', 'min' => 1, 'max' => 300, 'default' => 20],
                'active' => ['type' => 'bool', 'label' => 'Aktif', 'default' => true, 'list' => true, 'filter' => true],
                'url' => ['type' => 'url', 'label' => 'Tautan profil', 'max' => 300],
            ],
            'defaults' => ['created_at' => '@now'],
            'before_save' => function (array $data, ?array $old): array {
                $d = (int)($data['quota_daily'] ?? $old['quota_daily'] ?? 4); $w = (int)($data['quota_weekly'] ?? $old['quota_weekly'] ?? 20);
                if ($w < $d) fail(422, 'Kuota mingguan tidak boleh lebih kecil dari kuota harian');
                return $data;
            },
            'can_delete' => fn($r) => crud_referenced('SELECT (SELECT COUNT(*) FROM post_tasks WHERE account_id = ?) + (SELECT COUNT(*) FROM social_comments WHERE account_id = ?)', [$r['id'], $r['id']],
                'Akun memiliki riwayat unggahan/komentar; nonaktifkan saja agar jejak tetap utuh'),
        ],
        'post_tasks' => ['label' => 'Tugas unggah', 'module' => 'M6', 'table' => 'post_tasks', 'title' => 'caption', 'order' => 'planned_at DESC', 'link' => '/kanal-resmi?tab=tugas',
            'read' => CRUD_ANY, 'create' => ['operator', 'redaktur', 'pimpinan'], 'update' => ['operator', 'redaktur', 'pimpinan'], 'delete' => ['operator', 'redaktur', 'pimpinan'],
            'fields' => [
                'draft_id' => ['type' => 'ref', 'ref' => 'drafts', 'label' => 'Naskah', 'required' => true, 'list' => true, 'ref_where' => "status IN ('disetujui','tayang')", 'immutable' => true],
                'account_id' => ['type' => 'ref', 'ref' => 'social_accounts', 'label' => 'Akun resmi', 'required' => true, 'list' => true, 'filter' => true, 'ref_where' => 'active = 1'],
                'planned_at' => ['type' => 'datetime', 'label' => 'Jadwal unggah', 'required' => true, 'list' => true],
                'caption' => ['type' => 'textarea', 'label' => 'Caption', 'required' => true, 'min' => 3, 'max' => 2200, 'search' => true],
                'status' => ['type' => 'enum', 'label' => 'Status', 'options' => ['menunggu' => 'Menunggu', 'diunggah' => 'Diunggah', 'batal' => 'Batal'], 'default' => 'menunggu', 'list' => true, 'filter' => true],
                'assignee_id' => $user('Petugas') + ['list' => true],
                'posted_url' => ['type' => 'url', 'label' => 'Tautan unggahan', 'max' => 500],
                'reach' => ['type' => 'int', 'label' => 'Jangkauan', 'min' => 0, 'default' => 0, 'list' => true],
                'impressions' => ['type' => 'int', 'label' => 'Impresi', 'min' => 0, 'default' => 0],
                'engagement' => ['type' => 'int', 'label' => 'Interaksi', 'min' => 0, 'default' => 0],
                'campaign_id' => ['type' => 'ref', 'ref' => 'campaigns', 'label' => 'Kampanye', 'filter' => true],
            ],
            'defaults' => ['created_by' => '@user', 'created_at' => '@now'],
            'before_save' => function (array $data, ?array $old, array $u): array {
                if (!$old) { crud_require_approved((int)$data['draft_id']); check_quota((int)$data['account_id'], (string)$data['planned_at'], $u, false); }
                $st = $data['status'] ?? $old['status'] ?? 'menunggu';
                if ($st === 'diunggah') {
                    $url = $data['posted_url'] ?? $old['posted_url'] ?? '';
                    if (!$url) fail(422, 'Status Diunggah wajib menyertakan tautan unggahan');
                    if (!$old || $old['status'] !== 'diunggah') { $data['posted_at'] = now_iso(); $data['posted_by'] = $u['id']; }
                }
                return $data;
            },
            'can_delete' => fn($r) => $r['status'] === 'diunggah' ? 'Unggahan yang sudah tayang adalah jejak publikasi; tidak dapat dihapus' : null,
            'nullify' => [['campaign_slots', 'post_task_id']],
        ],
        'social_comments' => ['label' => 'Komentar warga', 'module' => 'M6', 'table' => 'social_comments', 'title' => 'text', 'order' => 'at DESC', 'link' => '/kanal-resmi',
            'read' => CRUD_ANY, 'create' => ['operator', 'analis'], 'update' => ['operator', 'analis'], 'delete' => ['operator', 'analis', 'admin'],
            'fields' => [
                'account_id' => ['type' => 'ref', 'ref' => 'social_accounts', 'label' => 'Akun resmi', 'required' => true, 'list' => true, 'filter' => true, 'immutable' => true],
                'author' => ['type' => 'text', 'label' => 'Penulis', 'required' => true, 'max' => 80, 'list' => true, 'search' => true, 'immutable' => true],
                'text' => ['type' => 'textarea', 'label' => 'Komentar', 'required' => true, 'max' => 2000, 'list' => true, 'search' => true, 'immutable' => true, 'pii' => true],
                'at' => ['type' => 'datetime', 'label' => 'Waktu', 'required' => true, 'list' => true, 'immutable' => true],
                'issue_id' => ['type' => 'ref', 'ref' => 'issues', 'label' => 'Isu', 'filter' => true],
                'category' => ['type' => 'enum', 'label' => 'Kategori', 'options' => CATEGORY_LABELS, 'list' => true, 'filter' => true],
                'sentiment' => ['type' => 'enum', 'label' => 'Sentimen', 'options' => ['positif' => 'Positif', 'netral' => 'Netral', 'negatif' => 'Negatif'], 'filter' => true],
                'priority' => ['type' => 'enum', 'label' => 'Prioritas', 'options' => ['tinggi' => 'Tinggi', 'sedang' => 'Sedang', 'rendah' => 'Rendah'], 'list' => true, 'filter' => true],
                'status' => ['type' => 'enum', 'label' => 'Status', 'options' => ['baru' => 'Baru', 'ditriase' => 'Ditriase', 'dibalas' => 'Dibalas', 'diabaikan' => 'Diabaikan', 'dieskalasi' => 'Dieskalasi'], 'default' => 'baru', 'list' => true, 'filter' => true],
                'reply_text' => ['type' => 'textarea', 'label' => 'Balasan resmi', 'max' => 1000],
                'parent_id' => ['type' => 'ref', 'ref' => 'social_comments', 'label' => 'Balasan atas komentar', 'immutable' => true],
                'bot_flag' => ['type' => 'bool', 'label' => 'Indikasi bot', 'readonly' => true, 'list' => true, 'filter' => true],
            ],
            'before_save' => function (array $data, ?array $old, array $u): array {
                if (($data['status'] ?? null) === 'dibalas' && ($old['status'] ?? null) !== 'dibalas') {
                    if (trim((string)($data['reply_text'] ?? $old['reply_text'] ?? '')) === '') fail(422, 'Status Dibalas wajib berisi teks balasan resmi');
                    if (!has_role($u, 'operator')) fail(403, 'Hanya operator yang mencatat balasan resmi');
                    $data['replied_by'] = $u['id']; $data['replied_at'] = now_iso();
                }
                return $data;
            },
            'nullify' => [['social_comments', 'parent_id']],
        ],
        'kb_responses' => ['label' => 'Bahan respons', 'module' => 'M6', 'table' => 'kb_responses', 'title' => 'title', 'order' => 'updated_at DESC', 'link' => '/kanal-resmi?tab=bahan',
            'read' => CRUD_ANY, 'create' => ['redaktur', 'pimpinan', 'analis'], 'update' => ['redaktur', 'pimpinan', 'analis'], 'delete' => CRUD_EDITORS,
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Judul', 'required' => true, 'min' => 3, 'max' => 200, 'list' => true, 'search' => true],
                'issue_id' => ['type' => 'ref', 'ref' => 'issues', 'label' => 'Isu', 'list' => true, 'filter' => true],
                'question' => ['type' => 'textarea', 'label' => 'Pertanyaan warga', 'required' => true, 'min' => 3, 'max' => 1000, 'search' => true],
                'answer' => ['type' => 'textarea', 'label' => 'Jawaban resmi', 'required' => true, 'min' => 10, 'max' => 3000, 'search' => true],
                'sources' => ['type' => 'text', 'label' => 'Sumber', 'max' => 300],
                'status' => ['type' => 'enum', 'label' => 'Status', 'options' => ['draf' => 'Draf', 'disetujui' => 'Disetujui'], 'default' => 'draf', 'list' => true, 'filter' => true, 'hint' => 'Persetujuan oleh redaktur/pimpinan'],
                'updated_at' => ['type' => 'datetime', 'label' => 'Diperbarui', 'readonly' => true, 'list' => true],
            ],
            'defaults' => ['created_by' => '@user', 'updated_at' => '@now'], 'touch' => true,
            'before_save' => function (array $data, ?array $old, array $u): array {
                if (($data['status'] ?? null) === 'disetujui' && ($old['status'] ?? null) !== 'disetujui' && !has_role($u, 'redaktur', 'pimpinan')) fail(403, 'Persetujuan bahan respons oleh redaktur/pimpinan');
                return $data;
            },
        ],
        'response_packs' => ['label' => 'Paket respons cepat', 'module' => 'M6', 'table' => 'response_packs', 'title' => 'title', 'order' => 'created_at DESC', 'link' => '/kanal-resmi?tab=paket',
            'read' => CRUD_ANY, 'create' => ['redaktur', 'pimpinan', 'analis'], 'update' => ['redaktur', 'pimpinan', 'analis'], 'delete' => CRUD_EDITORS,
            'scope' => fn(array $u) => has_role($u, 'redaktur', 'pimpinan', 'analis', 'produser', 'admin') ? ['1=1', []] : ["status = 'terbit'", []],
            'fields' => [
                'title' => ['type' => 'text', 'label' => 'Judul', 'required' => true, 'min' => 5, 'max' => 200, 'list' => true, 'search' => true],
                'issue_id' => ['type' => 'ref', 'ref' => 'issues', 'label' => 'Isu', 'required' => true, 'list' => true, 'filter' => true],
                'level' => ['type' => 'enum', 'label' => 'Tingkat peringatan', 'options' => ['kuning' => 'Kuning', 'oranye' => 'Oranye', 'merah' => 'Merah'], 'default' => 'oranye', 'list' => true, 'filter' => true],
                'trigger_text' => ['type' => 'text', 'label' => 'Pemicu', 'max' => 300],
                'holding_statement' => ['type' => 'textarea', 'label' => 'Pernyataan sementara', 'max' => 4000, 'search' => true],
                'key_messages' => ['type' => 'lines', 'label' => 'Pesan kunci (satu per baris)'],
                'spokesperson' => ['type' => 'textarea', 'label' => 'Petunjuk juru bicara', 'max' => 4000],
                'donts' => ['type' => 'lines', 'label' => 'Hal yang dihindari (satu per baris)'],
                'status' => ['type' => 'enum', 'label' => 'Status', 'options' => ['draf' => 'Draf', 'terbit' => 'Terbit'], 'readonly' => true, 'list' => true, 'filter' => true, 'hint' => 'Diterbitkan lewat tombol Terbitkan'],
                'deadline_at' => ['type' => 'datetime', 'label' => 'Tenggat (SLA 2 jam)', 'readonly' => true, 'list' => true],
            ],
            'defaults' => ['status' => 'draf', 'deadline_at' => '@now+7200', 'created_by' => '@user', 'created_at' => '@now', 'updated_at' => '@now'], 'touch' => true,
            'can_update' => fn($r) => $r['status'] === 'terbit' ? 'Paket yang sudah terbit tidak dapat diubah; buat paket pembaruan' : null,
            'can_delete' => fn($r) => $r['status'] === 'terbit' ? 'Paket terbit adalah arsip resmi; tidak dapat dihapus' : null,
        ],
        'partners' => ['label' => 'Mitra KOL terbuka', 'module' => 'M6', 'table' => 'partners', 'title' => 'name', 'order' => 'followers DESC', 'link' => '/kanal-resmi?tab=mitra',
            'read' => CRUD_ANY, 'create' => CRUD_EDITORS, 'update' => CRUD_EDITORS, 'delete' => CRUD_EDITORS,
            'fields' => [
                'name' => ['type' => 'text', 'label' => 'Nama', 'required' => true, 'min' => 2, 'max' => 120, 'list' => true, 'search' => true],
                'handle' => ['type' => 'text', 'label' => 'Akun', 'required' => true, 'min' => 2, 'max' => 80, 'list' => true, 'search' => true],
                'platform' => ['type' => 'enum', 'label' => 'Platform', 'options' => PLATFORMS, 'required' => true, 'list' => true, 'filter' => true],
                'category' => ['type' => 'text', 'label' => 'Bidang', 'max' => 80, 'list' => true, 'filter_text' => true],
                'followers' => ['type' => 'int', 'label' => 'Pengikut', 'min' => 0, 'default' => 0, 'list' => true],
                'province_code' => $prov,
                'contract_no' => ['type' => 'text', 'label' => 'No. kontrak/PKS', 'required' => true, 'min' => 3, 'max' => 80, 'list' => true, 'search' => true],
                'contract_start' => ['type' => 'date', 'label' => 'Kontrak mulai', 'required' => true],
                'contract_end' => ['type' => 'date', 'label' => 'Kontrak berakhir', 'required' => true, 'list' => true],
                'disclosure' => ['type' => 'text', 'label' => 'Label kemitraan', 'required' => true, 'min' => 5, 'max' => 120],
                'status' => ['type' => 'enum', 'label' => 'Status', 'options' => ['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif'], 'default' => 'aktif', 'list' => true, 'filter' => true],
                'notes' => ['type' => 'textarea', 'label' => 'Catatan', 'max' => 1000],
            ],
            'defaults' => ['created_by' => '@user', 'created_at' => '@now'],
            'before_save' => function (array $data, ?array $old): array {
                $s = $data['contract_start'] ?? $old['contract_start'] ?? ''; $e = $data['contract_end'] ?? $old['contract_end'] ?? '';
                if ($s && $e && $e < $s) fail(422, 'Kontrak berakhir sebelum mulai');
                return $data;
            },
            'can_delete' => fn($r) => crud_referenced('SELECT COUNT(*) FROM partner_collabs WHERE partner_id = ?', [$r['id']], 'Mitra memiliki rekam jejak kolaborasi; nonaktifkan saja'),
        ],
        'partner_collabs' => ['label' => 'Kolaborasi mitra', 'module' => 'M6', 'table' => 'partner_collabs', 'title' => 'url', 'order' => 'posted_at DESC',
            'read' => CRUD_ANY, 'create' => ['redaktur', 'pimpinan', 'operator'], 'update' => ['redaktur', 'pimpinan', 'operator'], 'delete' => CRUD_EDITORS,
            'fields' => [
                'partner_id' => ['type' => 'ref', 'ref' => 'partners', 'label' => 'Mitra', 'required' => true, 'list' => true, 'filter' => true, 'ref_where' => "status = 'aktif'"],
                'campaign_id' => ['type' => 'ref', 'ref' => 'campaigns', 'label' => 'Kampanye', 'list' => true, 'filter' => true],
                'draft_id' => ['type' => 'ref', 'ref' => 'drafts', 'label' => 'Naskah', 'ref_where' => "status IN ('disetujui','tayang')"],
                'posted_at' => ['type' => 'datetime', 'label' => 'Tanggal unggah', 'required' => true, 'list' => true],
                'url' => ['type' => 'url', 'label' => 'Tautan', 'max' => 500, 'search' => true],
                'disclosure_ok' => ['type' => 'bool', 'label' => 'Berlabel kemitraan', 'default' => true, 'list' => true, 'filter' => true],
                'reach' => ['type' => 'int', 'label' => 'Jangkauan', 'min' => 0, 'default' => 0, 'list' => true],
                'engagement' => ['type' => 'int', 'label' => 'Interaksi', 'min' => 0, 'default' => 0, 'list' => true],
                'notes' => ['type' => 'text', 'label' => 'Catatan', 'max' => 500],
            ],
            'defaults' => ['created_by' => '@user', 'created_at' => '@now'],
            'before_save' => function (array $data, ?array $old): array {
                $pid = (int)($data['partner_id'] ?? $old['partner_id']);
                $p = one('SELECT * FROM partners WHERE id = ?', [$pid]);
                $day = substr((string)($data['posted_at'] ?? $old['posted_at']), 0, 10);
                if ($p && $p['contract_end'] < $day) fail(409, 'Tanggal unggah di luar masa kontrak mitra');
                return $data;
            },
        ],
        'campaigns' => ['label' => 'Kampanye resmi', 'module' => 'M6', 'table' => 'campaigns', 'title' => 'name', 'order' => 'start_date DESC', 'link' => '/kanal-resmi?tab=kampanye',
            'read' => CRUD_ANY, 'create' => ['redaktur', 'pimpinan', 'analis'], 'update' => ['redaktur', 'pimpinan', 'analis'], 'delete' => CRUD_EDITORS,
            'fields' => [
                'name' => ['type' => 'text', 'label' => 'Nama kampanye', 'required' => true, 'min' => 5, 'max' => 160, 'list' => true, 'search' => true],
                'issue_id' => ['type' => 'ref', 'ref' => 'issues', 'label' => 'Isu', 'required' => true, 'list' => true, 'filter' => true],
                'objective' => ['type' => 'textarea', 'label' => 'Tujuan', 'required' => true, 'min' => 5, 'max' => 600],
                'audience' => ['type' => 'text', 'label' => 'Sasaran audiens', 'max' => 300],
                'regions' => ['type' => 'provinces', 'label' => 'Wilayah'],
                'key_messages' => ['type' => 'textarea', 'label' => 'Pesan kunci', 'required' => true, 'min' => 5, 'max' => 2000, 'search' => true],
                'start_date' => ['type' => 'date', 'label' => 'Mulai', 'required' => true, 'list' => true],
                'end_date' => ['type' => 'date', 'label' => 'Selesai', 'required' => true, 'list' => true],
                'kpi_reach' => ['type' => 'int', 'label' => 'Target jangkauan', 'min' => 0, 'json' => ['kpi', 'reach'], 'default' => 0],
                'status' => ['type' => 'enum', 'label' => 'Status', 'options' => ['draf' => 'Draf', 'diajukan' => 'Diajukan', 'disetujui' => 'Disetujui', 'berjalan' => 'Berjalan', 'selesai' => 'Selesai', 'dibatalkan' => 'Dibatalkan'],
                    'readonly' => true, 'list' => true, 'filter' => true, 'hint' => 'Diubah lewat alur persetujuan kampanye'],
            ],
            'defaults' => ['status' => 'draf', 'kpi' => '{}', 'created_by' => '@user', 'created_at' => '@now', 'updated_at' => '@now'], 'touch' => true,
            'before_save' => function (array $data, ?array $old): array {
                $s = $data['start_date'] ?? $old['start_date'] ?? ''; $e = $data['end_date'] ?? $old['end_date'] ?? '';
                if ($s && $e && $e < $s) fail(422, 'Tanggal selesai sebelum tanggal mulai');
                if (!$old && empty($data['regions'])) $data['regions'] = jenc(['NAS']);
                return $data;
            },
            'can_update' => fn($r) => in_array($r['status'], ['draf', 'diajukan'], true) ? null : 'Rencana yang sudah disetujui terkunci tanda tangan; batalkan & buat rencana baru bila perlu perubahan',
            'can_delete' => fn($r) => in_array($r['status'], ['draf', 'dibatalkan'], true) ? null : 'Hanya rencana berstatus Draf/Dibatalkan yang dapat dihapus',
            'cascade' => [['campaign_slots', 'campaign_id']], 'nullify' => [['post_tasks', 'campaign_id'], ['partner_collabs', 'campaign_id']],
        ],
        'campaign_slots' => ['label' => 'Slot jadwal kampanye', 'module' => 'M6', 'table' => 'campaign_slots', 'title' => 'date', 'order' => 'date DESC',
            'read' => CRUD_ANY, 'create' => ['redaktur', 'pimpinan', 'operator'], 'update' => ['redaktur', 'pimpinan', 'operator'], 'delete' => ['redaktur', 'pimpinan', 'operator'],
            'fields' => [
                'campaign_id' => ['type' => 'ref', 'ref' => 'campaigns', 'label' => 'Kampanye', 'required' => true, 'list' => true, 'filter' => true, 'ref_where' => "status IN ('disetujui','berjalan')", 'immutable' => true],
                'date' => ['type' => 'date', 'label' => 'Tanggal', 'required' => true, 'list' => true],
                'account_id' => ['type' => 'ref', 'ref' => 'social_accounts', 'label' => 'Akun resmi', 'list' => true],
                'partner_id' => ['type' => 'ref', 'ref' => 'partners', 'label' => 'Mitra', 'list' => true],
                'draft_id' => ['type' => 'ref', 'ref' => 'drafts', 'label' => 'Naskah', 'list' => true, 'ref_where' => "status IN ('disetujui','tayang')"],
                'note' => ['type' => 'text', 'label' => 'Catatan', 'max' => 300],
            ],
            'defaults' => ['created_at' => '@now'],
            'before_save' => function (array $data, ?array $old): array {
                $cid = (int)($data['campaign_id'] ?? $old['campaign_id']);
                $c = one('SELECT * FROM campaigns WHERE id = ?', [$cid]);
                if (!$old && !in_array($c['status'] ?? '', ['disetujui', 'berjalan'], true)) fail(409, 'Slot hanya untuk kampanye yang sudah disetujui');
                $d = $data['date'] ?? $old['date'];
                if ($c && ($d < $c['start_date'] || $d > $c['end_date'])) fail(422, 'Tanggal di luar periode kampanye');
                if (empty($data['account_id'] ?? $old['account_id'] ?? null) && empty($data['partner_id'] ?? $old['partner_id'] ?? null)) fail(422, 'Pilih akun resmi atau mitra');
                if (!empty($data['draft_id'])) crud_require_approved((int)$data['draft_id']);
                return $data;
            },
        ],
        // ------------------------------------------------------------- M7
        'channels' => ['label' => 'Kanal distribusi', 'module' => 'M7', 'table' => 'channels', 'title' => 'name', 'where' => "type IN ('nasional','provinsi')", 'order' => "CASE type WHEN 'nasional' THEN 0 ELSE 1 END, name",
            'link' => '/distribusi?tab=cms', 'read' => CRUD_ANY, 'create' => ['admin'], 'update' => ['admin', 'operator'], 'delete' => ['admin'],
            'fields' => [
                'code' => ['type' => 'text', 'label' => 'Kode', 'required' => true, 'min' => 3, 'max' => 20, 'upper' => true, 'unique' => true, 'list' => true, 'immutable' => true],
                'name' => ['type' => 'text', 'label' => 'Nama', 'required' => true, 'min' => 3, 'max' => 120, 'list' => true, 'search' => true],
                'type' => ['type' => 'enum', 'label' => 'Jenis', 'options' => ['nasional' => 'Nasional', 'provinsi' => 'Provinsi'], 'required' => true, 'list' => true, 'filter' => true],
                'province_code' => $prov + ['list' => true],
                'url' => ['type' => 'url', 'label' => 'Alamat situs', 'max' => 300],
                'audience' => ['type' => 'int', 'label' => 'Khalayak', 'min' => 0, 'default' => 0, 'list' => true],
                'health' => ['type' => 'enum', 'label' => 'Kesehatan', 'options' => ['normal' => 'Normal', 'lambat' => 'Lambat', 'gangguan' => 'Gangguan'], 'default' => 'normal', 'list' => true, 'filter' => true],
                'active' => ['type' => 'bool', 'label' => 'Aktif', 'default' => true, 'list' => true, 'filter' => true],
            ],
            'can_delete' => fn($r) => crud_referenced('SELECT (SELECT COUNT(*) FROM distributions WHERE channel_id = ?) + (SELECT COUNT(*) FROM calendar_entries WHERE channel_id = ?)', [$r['id'], $r['id']],
                'Kanal memiliki riwayat distribusi; nonaktifkan saja (KAK: 39 kanal)'),
        ],
        'distributions' => ['label' => 'Distribusi kanal', 'module' => 'M7', 'table' => 'distributions', 'title' => 'url', 'order' => 'id DESC', 'link' => '/distribusi',
            'read' => CRUD_ANY, 'create' => ['operator'], 'update' => ['operator'], 'delete' => ['operator'],
            'fields' => [
                'draft_id' => ['type' => 'ref', 'ref' => 'drafts', 'label' => 'Naskah', 'required' => true, 'list' => true, 'ref_where' => "status IN ('disetujui','tayang')", 'immutable' => true],
                'channel_id' => ['type' => 'ref', 'ref' => 'channels', 'label' => 'Kanal', 'required' => true, 'list' => true, 'filter' => true, 'ref_where' => "type IN ('nasional','provinsi')", 'immutable' => true],
                'status' => ['type' => 'enum', 'label' => 'Status', 'options' => ['terjadwal' => 'Terjadwal', 'terkirim' => 'Terkirim', 'gagal' => 'Gagal', 'ditarik' => 'Ditarik', 'batal' => 'Batal'], 'default' => 'terjadwal', 'list' => true, 'filter' => true],
                'scheduled_at' => ['type' => 'datetime', 'label' => 'Jadwal', 'list' => true],
                'sent_at' => ['type' => 'datetime', 'label' => 'Terkirim', 'readonly' => true, 'list' => true],
                'views' => ['type' => 'int', 'label' => 'Tayangan', 'readonly' => true, 'list' => true],
                'takedown_reason' => ['type' => 'text', 'label' => 'Alasan penarikan', 'max' => 300],
            ],
            'defaults' => ['created_by' => '@user', 'created_at' => '@now'],
            'before_save' => function (array $data, ?array $old): array {
                if (!$old) {
                    crud_require_approved((int)$data['draft_id']);
                    if (one('SELECT id FROM distributions WHERE draft_id = ? AND channel_id = ?', [$data['draft_id'], $data['channel_id']])) fail(409, 'Naskah sudah punya distribusi ke kanal ini');
                    if (($data['status'] ?? 'terjadwal') !== 'terjadwal') fail(422, 'Distribusi baru dibuat berstatus Terjadwal; pengiriman dilakukan sistem');
                    if (empty($data['scheduled_at']) || $data['scheduled_at'] < now_iso()) fail(422, 'Jadwal wajib diisi dan belum lewat');
                    return $data;
                }
                $to = $data['status'] ?? $old['status'];
                $allowed = ['terjadwal' => ['terjadwal', 'batal'], 'terkirim' => ['terkirim', 'ditarik'], 'gagal' => ['gagal', 'batal'], 'ditarik' => ['ditarik'], 'batal' => ['batal']];
                if (!in_array($to, $allowed[$old['status']] ?? [], true)) fail(409, "Perubahan status {$old['status']} → {$to} tidak diizinkan (pengiriman ulang lewat tombol Coba ulang)");
                if ($to === 'ditarik' && $old['status'] !== 'ditarik') {
                    if (trim((string)($data['takedown_reason'] ?? '')) === '') fail(422, 'Penarikan wajib disertai alasan');
                    $data['taken_down_at'] = now_iso();
                }
                return $data;
            },
            'can_delete' => fn($r) => in_array($r['status'], ['terjadwal', 'batal', 'gagal'], true) ? null : 'Distribusi terkirim/ditarik adalah jejak publikasi; tidak dapat dihapus',
        ],
        'media_partners' => ['label' => 'Media mitra (cetak/TV/radio)', 'module' => 'M7', 'table' => 'media_partners', 'title' => 'name', 'order' => 'type, name', 'link' => '/distribusi?tab=pers',
            'read' => CRUD_ANY, 'create' => ['operator', 'redaktur'], 'update' => ['operator', 'redaktur'], 'delete' => ['operator', 'redaktur'],
            'fields' => [
                'name' => ['type' => 'text', 'label' => 'Nama media', 'required' => true, 'min' => 3, 'max' => 160, 'list' => true, 'search' => true],
                'type' => ['type' => 'enum', 'label' => 'Jenis', 'options' => ['cetak' => 'Cetak', 'tv' => 'Televisi', 'radio' => 'Radio', 'daring' => 'Daring'], 'required' => true, 'list' => true, 'filter' => true],
                'province_code' => $prov + ['list' => true],
                'contact' => ['type' => 'text', 'label' => 'Kontak redaksi', 'max' => 160, 'list' => true],
                'active' => ['type' => 'bool', 'label' => 'Aktif', 'default' => true, 'list' => true, 'filter' => true],
            ],
            'defaults' => ['created_at' => '@now'],
            'can_delete' => fn($r) => crud_referenced('SELECT COUNT(*) FROM press_links WHERE partner_id = ?', [$r['id']], 'Media pernah menerima paket; nonaktifkan saja agar log unduhan tetap utuh'),
        ],
        'press_packs' => ['label' => 'Paket media', 'module' => 'M7', 'table' => 'press_packs', 'title' => 'title', 'order' => 'id DESC', 'link' => '/distribusi?tab=pers',
            'read' => CRUD_ANY, 'create' => ['operator', 'redaktur'], 'update' => ['operator', 'redaktur'], 'delete' => ['operator', 'redaktur'],
            'fields' => [
                'draft_id' => ['type' => 'ref', 'ref' => 'drafts', 'label' => 'Naskah', 'required' => true, 'list' => true, 'ref_where' => "status IN ('disetujui','tayang')", 'immutable' => true],
                'title' => ['type' => 'text', 'label' => 'Judul paket', 'required' => true, 'min' => 3, 'max' => 200, 'list' => true, 'search' => true],
                'note' => ['type' => 'textarea', 'label' => 'Catatan untuk media', 'max' => 1000],
                'expires_at' => ['type' => 'datetime', 'label' => 'Berlaku s.d.', 'required' => true, 'list' => true],
            ],
            'defaults' => ['asset_ids' => '[]', 'created_by' => '@user', 'created_at' => '@now'],
            'before_save' => function (array $data, ?array $old): array { if (!$old) crud_require_approved((int)$data['draft_id']); return $data; },
            'after_save' => function (int $id, array $data): void { if (isset($data['expires_at'])) q('UPDATE press_links SET expires_at = ? WHERE pack_id = ? AND revoked_at IS NULL', [$data['expires_at'], $id]); },
            'can_delete' => fn($r) => crud_referenced('SELECT COUNT(*) FROM press_downloads WHERE pack_id = ?', [$r['id']], 'Paket sudah diunduh media; log unduhan wajib disimpan'),
            'cascade' => [['press_links', 'pack_id']],
        ],
        'api_clients' => ['label' => 'Klien API mitra', 'module' => 'M7', 'table' => 'api_clients', 'title' => 'name', 'order' => 'id DESC', 'link' => '/distribusi?tab=api',
            'read' => ['admin'], 'create' => ['admin'], 'update' => ['admin'], 'delete' => ['admin'],
            'fields' => [
                'name' => ['type' => 'text', 'label' => 'Nama aplikasi', 'required' => true, 'min' => 3, 'max' => 120, 'list' => true, 'search' => true],
                'org' => ['type' => 'text', 'label' => 'Instansi', 'required' => true, 'min' => 2, 'max' => 160, 'list' => true, 'search' => true],
                'rate_per_min' => ['type' => 'int', 'label' => 'Batas laju / menit', 'min' => 1, 'max' => 600, 'default' => 60, 'list' => true],
                'expires_at' => ['type' => 'datetime', 'label' => 'Berlaku s.d.', 'required' => true, 'list' => true],
                'active' => ['type' => 'bool', 'label' => 'Aktif (hapus centang = cabut)', 'default' => true, 'list' => true, 'filter' => true],
                'token_prefix' => ['type' => 'text', 'label' => 'Awalan token', 'readonly' => true, 'list' => true],
            ],
            'defaults' => ['scopes' => '["publikasi:baca"]', 'created_by' => '@user', 'created_at' => '@now'],
            'before_save' => function (array $data, ?array $old): array {
                if (!$old) { $tok = 'sst_' . b64url(random_bytes(32)); $data['token_prefix'] = substr($tok, 0, 10); $data['token_hash'] = hash('sha256', $tok); $GLOBALS['CRUD_EXTRA'] = ['token' => $tok, 'note' => 'Token hanya ditampilkan sekali.']; }
                return $data;
            },
        ],
        // ------------------------------------------------------------- M8
        'reports' => ['label' => 'Laporan berkala', 'module' => 'M8', 'table' => 'reports', 'title' => 'title', 'order' => 'created_at DESC', 'link' => '/laporan',
            'read' => CRUD_ANY, 'create' => ['pimpinan', 'analis', 'redaktur'], 'update' => ['pimpinan', 'analis', 'redaktur'], 'delete' => ['pimpinan', 'analis'],
            'fields' => [
                'period' => ['type' => 'enum', 'label' => 'Periode', 'options' => ['harian' => 'Harian', 'mingguan' => 'Mingguan', 'bulanan' => 'Bulanan'], 'required' => true, 'list' => true, 'filter' => true, 'immutable' => true],
                'title' => ['type' => 'text', 'label' => 'Judul', 'min' => 5, 'max' => 200, 'list' => true, 'search' => true, 'hint' => 'Kosongkan saat membuat: judul & isi disusun otomatis'],
                'summary' => ['type' => 'textarea', 'label' => 'Ringkasan eksekutif', 'max' => 10000, 'search' => true],
                'auto' => ['type' => 'bool', 'label' => 'Terbit otomatis', 'readonly' => true, 'list' => true, 'filter' => true],
                'created_at' => ['type' => 'datetime', 'label' => 'Disusun', 'readonly' => true, 'list' => true],
            ],
            // pembuatan memakai penyusun laporan (data + ringkasan AI)
            'create_with' => function (array $data, array $u): int {
                $id = build_report($u, (string)$data['period'], false, microtime(true) + 86400);
                if (!empty($data['title'])) q('UPDATE reports SET title = ? WHERE id = ?', [$data['title'], $id]);
                if (!empty($data['summary'])) q('UPDATE reports SET summary = ? WHERE id = ?', [$data['summary'], $id]);
                return $id;
            },
        ],
    ];
    return $E;
}

const CATEGORY_LABELS = ['pertanyaan' => 'Pertanyaan', 'keluhan' => 'Keluhan', 'hoaks' => 'Hoaks/disinformasi', 'apresiasi' => 'Apresiasi', 'ujaran_kebencian' => 'Ujaran kebencian', 'spam' => 'Spam/promosi'];
const CRUD_REF_TABLE = ['users' => ['users', 'full_name'], 'issues' => ['issues', 'name'], 'briefs' => ['briefs', 'title'], 'drafts' => ['drafts', 'title'], 'channels' => ['channels', 'name'],
    'social_accounts' => ['social_accounts', "platform || ' ' || handle"], 'social_comments' => ['social_comments', "author || ': ' || substr(text, 1, 60)"], 'campaigns' => ['campaigns', 'name'],
    'partners' => ['partners', "name || ' (' || handle || ')'"], 'media_partners' => ['media_partners', 'name']];

// ======================= mesin =======================
function crud_def(string $e): array { $E = crud_entities(); if (!isset($E[$e])) fail(404, 'Entitas tidak dikenal'); return $E[$e] + ['key' => $e]; }
function crud_guard(array $def, string $op): array
{
    $u = current_user();
    $roles = $def[$op] ?? [];
    $ok = $roles === CRUD_ANY || ($roles !== [] && has_role($u, ...$roles)) || ($op === 'read' && has_role($u, 'admin', 'auditor'));
    if (!$ok) fail(403, $roles === [] ? ($def['create_hint'] ?? 'Operasi ini tidak tersedia') : 'Memerlukan peran: ' . implode(', ', $roles));
    return $u;
}
function crud_scope(array $def, array $u): array
{
    $w = [$def['where'] ?? '1=1']; $p = [];
    if (isset($def['scope'])) { [$sw, $sp] = $def['scope']($u); $w[] = $sw; $p = array_merge($p, $sp); }
    return [implode(' AND ', array_map(fn($x) => "($x)", $w)), $p];
}
function crud_ref_label_sql(string $ref): array { return CRUD_REF_TABLE[$ref] ?? fail(500, "Referensi $ref tidak terdaftar"); }

/** Validasi & normalisasi satu nilai masukan. */
function crud_value(array $f, string $name, $v)
{
    $label = $f['label'] ?? $name;
    $empty = $v === null || $v === '' || (is_array($v) && !$v);
    if ($empty) {
        if (!empty($f['required']) && $f['type'] !== 'bool') fail(422, "$label wajib diisi");
        return match ($f['type']) { 'bool' => 0, 'lines', 'provinces' => '[]', 'facts' => '[]', 'text', 'textarea' => '', default => null };
    }
    switch ($f['type']) {
        case 'text': case 'textarea':
            $s = trim((string)$v);
            if (!empty($f['upper'])) $s = strtoupper($s);
            if (isset($f['min']) && mb_strlen($s) < $f['min']) fail(422, "$label minimal {$f['min']} karakter");
            if (isset($f['max']) && mb_strlen($s) > $f['max']) fail(422, "$label maksimal {$f['max']} karakter");
            if (isset($f['pattern']) && !preg_match($f['pattern'], $s)) fail(422, $f['pattern_msg'] ?? "$label tidak valid");
            return $s;
        case 'url':
            $s = trim((string)$v);
            if (!preg_match('#^https?://[^\s]+$#i', $s)) fail(422, "$label harus diawali http(s)://");
            if (isset($f['max']) && mb_strlen($s) > $f['max']) fail(422, "$label terlalu panjang");
            return $s;
        case 'int':
            if (!is_numeric($v) || (int)$v != $v) fail(422, "$label harus bilangan bulat");
            $i = (int)$v;
            if (isset($f['min']) && $i < $f['min']) fail(422, "$label minimal {$f['min']}");
            if (isset($f['max']) && $i > $f['max']) fail(422, "$label maksimal {$f['max']}");
            return $i;
        case 'bool': return filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        case 'enum':
            if (!isset($f['options'][(string)$v])) fail(422, "$label tidak dikenal: " . mb_substr((string)$v, 0, 40));
            return (string)$v;
        case 'province':
            if (!isset(PROVINCES[(string)$v])) fail(422, "$label tidak dikenal");
            return (string)$v;
        case 'provinces':
            $arr = is_array($v) ? $v : preg_split('/[\s,]+/', (string)$v, -1, PREG_SPLIT_NO_EMPTY);
            $out = [];
            foreach ($arr as $x) { $x = strtoupper(trim((string)$x)); if ($x === 'NAS' || isset(PROVINCES[$x])) $out[] = $x; else fail(422, "Kode wilayah tidak dikenal: $x"); }
            return jenc(array_values(array_unique($out)));
        case 'date':
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) || !strtotime((string)$v)) fail(422, "$label harus berformat TTTT-BB-HH");
            return (string)$v;
        case 'datetime':
            return parse_input_datetime((string)$v) ?? fail(422, "$label: format waktu tidak valid");
        case 'color':
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$v)) fail(422, "$label harus berformat #RRGGBB");
            return strtoupper((string)$v);
        case 'lines':
            $arr = is_array($v) ? $v : preg_split('/\r?\n/', (string)$v);
            return jenc(array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 300), $arr), fn($x) => $x !== '')), 0, 50));
        case 'facts':
            $arr = is_array($v) ? $v : preg_split('/\r?\n/', (string)$v);
            $items = [];
            foreach ($arr as $line) {
                if (is_array($line)) { $items[] = $line; continue; }
                $p = array_map('trim', explode('|', (string)$line));
                if (($p[0] ?? '') === '') continue;
                $items[] = ['text' => $p[0], 'source' => $p[1] ?? '', 'url' => $p[2] ?? '', 'reliability' => $p[3] ?? ''];
            }
            return jenc(fact_items($items));
        case 'ref':
            if (!ctype_digit((string)$v)) fail(422, "$label tidak valid");
            [$t] = crud_ref_label_sql($f['ref']);
            $where = $f['ref_where'] ?? '1=1';
            if (!one("SELECT id FROM $t WHERE id = ? AND ($where)", [(int)$v])) fail(422, "$label tidak ditemukan atau tidak memenuhi syarat");
            return (int)$v;
    }
    return $v;
}

/** Ubah baris DB → keluaran API (nilai terdekode + label referensi). */
function crud_out(array $def, array $row, array $labels = []): array
{
    $o = ['id' => (int)$row['id']];
    foreach ($def['fields'] as $name => $f) {
        if (!empty($f['virtual'])) continue;
        if (isset($f['json'])) { $j = jdec($row[$f['json'][0]] ?? null); $o[$name] = $j[$f['json'][1]] ?? null; continue; }
        $v = $row[$name] ?? null;
        $o[$name] = match ($f['type']) {
            'bool' => (bool)$v, 'int' => $v === null ? null : (int)$v,
            'lines', 'provinces' => jdec($v), 'facts' => array_map(fn($x) => implode(' | ', array_filter([$x['text'], $x['source'], $x['url'], $x['reliability']], fn($y) => $y !== '')), fact_items($v)),
            'ref' => $v === null ? null : (int)$v, default => $v,
        };
        if (!empty($f['pii']) && is_string($o[$name])) $o[$name] = pii_mask($o[$name])[0];
        if ($f['type'] === 'ref' && $v !== null) $o[$name . '__label'] = $labels[$f['ref']][(int)$v] ?? ('#' . $v);
        if ($f['type'] === 'province' && $v !== null) $o[$name . '__label'] = province_name((string)$v);
    }
    if (isset($def['link'])) $o['_link'] = preg_replace_callback('/\{(\w+)\}/', fn($m) => (string)($row[$m[1]] ?? ''), $def['link']);
    return $o;
}
/** Ambil label referensi untuk sekumpulan baris (satu kueri per tabel referensi). */
function crud_labels(array $def, array $rows): array
{
    $ids = [];
    foreach ($def['fields'] as $name => $f) if ($f['type'] === 'ref') foreach ($rows as $r) if (!empty($r[$name])) $ids[$f['ref']][(int)$r[$name]] = true;
    $out = [];
    foreach ($ids as $ref => $set) {
        [$t, $lbl] = crud_ref_label_sql($ref);
        $list = implode(',', array_keys($set));
        foreach (all("SELECT id, $lbl AS l FROM $t WHERE id IN ($list)") as $x) $out[$ref][(int)$x['id']] = mb_substr((string)$x['l'], 0, 120);
    }
    return $out;
}
function crud_meta_public(array $def, array $u): array
{
    $fields = [];
    foreach ($def['fields'] as $name => $f) {
        $o = ['name' => $name, 'label' => $f['label'] ?? $name, 'type' => $f['type']];
        foreach (['required', 'readonly', 'list', 'search', 'filter', 'immutable', 'min', 'max', 'hint', 'create_only', 'default'] as $k) if (isset($f[$k])) $o[$k] = $f[$k];
        if (isset($f['options'])) $o['options'] = array_map(fn($k, $v) => ['value' => (string)$k, 'label' => $v], array_keys($f['options']), $f['options']);
        if ($f['type'] === 'province' || $f['type'] === 'provinces') $o['options'] = array_merge($f['type'] === 'provinces' ? [['value' => 'NAS', 'label' => 'Nasional']] : [], array_map(fn($c) => ['value' => (string)$c, 'label' => PROVINCES[$c][0]], array_keys(PROVINCES)));
        if ($f['type'] === 'ref') $o['ref'] = $f['ref'];
        $fields[] = $o;
    }
    $can = fn(string $op) => ($def[$op] ?? []) === CRUD_ANY || (($def[$op] ?? []) !== [] && has_role($u, ...$def[$op]));
    return ['key' => $def['key'], 'label' => $def['label'], 'module' => $def['module'], 'title' => $def['title'], 'link' => $def['link'] ?? null, 'upload' => !empty($def['upload']),
        'create_hint' => $def['create_hint'] ?? null, 'fields' => $fields,
        'can' => ['read' => $can('read') || has_role($u, 'admin', 'auditor'), 'create' => $can('create'), 'update' => $can('update'), 'delete' => $can('delete')]];
}

/** Ambil dan validasi data masukan untuk tambah/ubah. */
function crud_input(array $def, array $b, ?array $old): array
{
    $data = []; $json = [];
    foreach ($def['fields'] as $name => $f) {
        if (!empty($f['readonly']) || !empty($f['virtual'])) continue;
        if ($old && !empty($f['immutable'])) { if (array_key_exists($name, $b) && (string)crud_value($f, $name, $b[$name]) !== (string)$old[$name] && !isset($f['json'])) fail(422, ($f['label'] ?? $name) . ' tidak dapat diubah'); continue; }
        if (!array_key_exists($name, $b)) {
            if ($old) continue;
            if (array_key_exists('default', $f)) $b[$name] = $f['default'];
            elseif (in_array($f['type'], ['text', 'textarea'], true) && empty($f['required']) && !isset($f['json'])) $b[$name] = '';
        }
        if (!array_key_exists($name, $b)) { if (!empty($f['required']) && $f['type'] !== 'bool') fail(422, ($f['label'] ?? $name) . ' wajib diisi'); continue; }
        $v = crud_value($f, $name, $b[$name]);
        if (isset($f['json'])) { $json[$f['json'][0]][$f['json'][1]] = $v; continue; }
        if (!empty($f['unique']) && $v !== null && one("SELECT id FROM {$def['table']} WHERE $name = ?" . ($old ? ' AND id != ?' : ''), $old ? [$v, $old['id']] : [$v])) fail(409, ($f['label'] ?? $name) . " \"$v\" sudah dipakai");
        $data[$name] = $v;
    }
    foreach ($json as $col => $kv) { $base = jdec($old[$col] ?? ($def['defaults'][$col] ?? '{}')); $data[$col] = jenc(array_merge(is_array($base) ? $base : [], $kv)); }
    return $data;
}
function crud_defaults(array $def, array $u): array
{
    $o = [];
    foreach ($def['defaults'] ?? [] as $k => $v) $o[$k] = match (true) { $v === '@now' => now_iso(), $v === '@user' => $u['id'], is_string($v) && str_starts_with($v, '@now+') => iso_from_ts(microtime(true) + (int)substr($v, 5)), default => $v };
    return $o;
}
function crud_row(array $def, int $id, array $u): array
{
    [$w, $p] = crud_scope($def, $u);
    $r = one("SELECT * FROM {$def['table']} WHERE id = ? AND $w", array_merge([$id], $p));
    if (!$r) fail(404, "{$def['label']} #$id tidak ditemukan");
    return $r;
}

function crud_list(array $def, array $u, int $limit, int $offset): array
{
    [$w, $p] = crud_scope($def, $u);
    $f = $def['fields'];
    if (($q = trim((string)qparam('q', ''))) !== '') {
        // pencarian selalu di dalam cakupan (scope) — tidak pernah memperluas hak baca
        $cols = array_keys(array_filter($f, fn($x) => !empty($x['search']) && empty($x['json'])));
        $or = array_map(fn($c) => "CAST($c AS TEXT) LIKE ? ESCAPE '\\'", $cols); $op = array_fill(0, count($cols), like_escape($q));
        if (ctype_digit($q)) { $or[] = 'id = ?'; $op[] = (int)$q; }
        if ($or) { $w .= ' AND (' . implode(' OR ', $or) . ')'; array_push($p, ...$op); }
    }
    foreach ($_GET['filter'] ?? [] as $k => $v) {
        if (!isset($f[$k]) || $v === '' || isset($f[$k]['json'])) continue;
        if ($f[$k]['type'] === 'bool') { $w .= " AND COALESCE($k, 0) = ?"; $p[] = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0; }
        elseif ($v === '__null') $w .= " AND $k IS NULL";
        else { $w .= " AND $k = ?"; $p[] = (string)$v; }
    }
    $sort = (string)qparam('sort', '');
    $dir = strtolower((string)qparam('dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $order = ($sort === 'id' || (isset($f[$sort]) && empty($f[$sort]['json']) && empty($f[$sort]['virtual']))) ? "$sort $dir, id DESC" : ($def['order'] ?? 'id DESC');
    $total = (int)val("SELECT COUNT(*) FROM {$def['table']} WHERE $w", $p);
    $rows = all("SELECT * FROM {$def['table']} WHERE $w ORDER BY $order LIMIT $limit OFFSET $offset", $p);
    $labels = crud_labels($def, $rows);
    return [$total, array_map(fn($r) => crud_out($def, $r, $labels), $rows)];
}

function register_routes_crud(Router $r): void
{
    $r->add('GET', '/api/crud/meta', function () {
        $u = current_user();
        $out = [];
        foreach (crud_entities() as $k => $d) { $m = crud_meta_public($d + ['key' => $k], $u); if ($m['can']['read']) { $m['count'] = (int)val("SELECT COUNT(*) FROM {$d['table']} WHERE " . crud_scope($d + ['key' => $k], $u)[0], crud_scope($d + ['key' => $k], $u)[1]); $out[] = $m; } }
        send_json($out);
    });
    $r->add('GET', '/api/crud/([a-z_]+)', function ($e) {
        $def = crud_def($e); $u = crud_guard($def, 'read');
        $per = qint('per_page', 25, 1, 200); $page = qint('page', 1, 1, 100000);
        [$total, $items] = crud_list($def, $u, $per, ($page - 1) * $per);
        send_json(['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int)ceil($total / $per))]);
    });
    $r->add('GET', '/api/crud/([a-z_]+)/export', function ($e) {
        $def = crud_def($e); $u = crud_guard($def, 'read');
        [, $items] = crud_list($def, $u, 5000, 0);
        $cols = array_keys(array_filter($def['fields'], fn($f) => empty($f['virtual'])));
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_merge(['id'], array_map(fn($c) => $def['fields'][$c]['label'] ?? $c, $cols)), ';');
        foreach ($items as $it) fputcsv($fh, array_merge([$it['id']], array_map(function ($c) use ($it) {
            $v = $it[$c . '__label'] ?? $it[$c] ?? '';
            $s = is_array($v) ? implode(', ', $v) : (is_bool($v) ? ($v ? 'ya' : 'tidak') : (string)$v);
            return preg_match('/^[=+\-@]/', $s) ? "'" . $s : $s; // cegah injeksi formula spreadsheet
        }, $cols)), ';');
        rewind($fh); $csv = stream_get_contents($fh); fclose($fh);
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode("sistra-$e-" . gmdate('Ymd') . '.csv'));
        echo $csv;
    });
    $r->add('GET', '/api/crud/([a-z_]+)/options/([a-z_]+)', function ($e, $field) {
        $def = crud_def($e); current_user();
        $f = $def['fields'][$field] ?? fail(404, 'Kolom tidak dikenal');
        if ($f['type'] !== 'ref') fail(422, 'Kolom bukan referensi');
        [$t, $lbl] = crud_ref_label_sql($f['ref']);
        $w = $f['ref_where'] ?? '1=1'; $p = [];
        if (($q = trim((string)qparam('q', ''))) !== '') { $w .= " AND ($lbl LIKE ? ESCAPE '\\'" . (ctype_digit($q) ? ' OR id = ?' : '') . ')'; $p[] = like_escape($q); if (ctype_digit($q)) $p[] = (int)$q; }
        if ($t === 'channels') $w .= " AND type IN ('nasional','provinsi')";
        send_json(array_map(fn($x) => ['id' => (int)$x['id'], 'label' => mb_substr((string)$x['l'], 0, 120)], all("SELECT id, $lbl AS l FROM $t WHERE $w ORDER BY id DESC LIMIT 50", $p)));
    });
    $r->add('GET', '/api/crud/([a-z_]+)/(\d+)', function ($e, $id) {
        $def = crud_def($e); $u = crud_guard($def, 'read');
        $row = crud_row($def, (int)$id, $u);
        send_json(crud_out($def, $row, crud_labels($def, [$row])));
    });
    $r->add('POST', '/api/crud/([a-z_]+)', function ($e) {
        $def = crud_def($e); $u = crud_guard($def, 'create');
        $multipart = !empty($def['upload']) && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/');
        $b = $multipart ? $_POST : json_body();
        if (!empty($def['upload']) && !$multipart) fail(422, 'Unggah berkas wajib (multipart/form-data)');
        $data = crud_input($def, $b, null);
        if (isset($def['create_with'])) {
            $id = $def['create_with']($data, $u);
        } else {
            if ($multipart) {
                [$fn, $mime, $raw] = read_upload();
                $data += ['filename' => $fn, 'mime' => $mime, 'size' => strlen($raw), 'sha256' => blob_put($raw),
                    'kind' => str_starts_with($mime, 'image/') ? 'gambar' : (str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'audio/') ? 'audio' : 'dokumen'))];
                $tag = ai_tag_asset($u, (string)$data['title'], $mime, str_starts_with($mime, 'image/') ? $raw : null);
                $data['ai_tags'] = jenc($tag['tags']);
            }
            $data = $data + crud_defaults($def, $u);
            if (isset($def['before_save'])) $data = $def['before_save']($data, null, $u);
            $id = insert($def['table'], $data);
            if (isset($def['after_save'])) $def['after_save']($id, $data, null, $u);
        }
        audit_record($u, "crud.$e.tambah", $e, $id, ['kolom' => array_keys($data)]);
        $row = one("SELECT * FROM {$def['table']} WHERE id = ?", [$id]);
        send_json(crud_out($def, $row, crud_labels($def, [$row])) + ($GLOBALS['CRUD_EXTRA'] ?? []), 201);
    });
    $r->add('PUT', '/api/crud/([a-z_]+)/(\d+)', function ($e, $id) {
        $def = crud_def($e); $u = crud_guard($def, 'update');
        $old = crud_row($def, (int)$id, $u);
        $b = json_body();
        $data = crud_input($def, $b, $old);
        if (!$data) fail(422, 'Tidak ada perubahan yang dikirim');
        if (isset($def['can_update']) && ($why = $def['can_update']($old, $u, $data))) fail(409, $why);
        if (!empty($def['touch']) && array_key_exists('updated_at', $old)) $data['updated_at'] = now_iso();
        if (isset($def['before_save'])) $data = $def['before_save']($data, $old, $u);
        if (!empty($def['versioned']) && array_diff(array_keys($data), ['locked'])) {
            // versi baru: baris lama dipertahankan sebagai riwayat
            q("UPDATE {$def['table']} SET is_current = 0 WHERE code = ?", [$old['code']]);
            $new = array_merge($old, $data, ['version' => (int)$old['version'] + 1, 'is_current' => 1, 'created_by' => $u['id'], 'created_at' => now_iso()]);
            unset($new['id']);
            $nid = insert($def['table'], $new);
            audit_record($u, "crud.$e.versi_baru", $e, $nid, ['dari' => (int)$old['id'], 'versi' => $new['version']]);
            $row = one("SELECT * FROM {$def['table']} WHERE id = ?", [$nid]);
            send_json(crud_out($def, $row, crud_labels($def, [$row])));
            return;
        }
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
        q("UPDATE {$def['table']} SET $sets WHERE id = ?", array_merge(array_values($data), [$old['id']]));
        if (isset($def['after_save'])) $def['after_save']((int)$old['id'], $data, $old, $u);
        audit_record($u, "crud.$e.ubah", $e, $old['id'], ['kolom' => array_keys($data)]);
        $row = one("SELECT * FROM {$def['table']} WHERE id = ?", [$old['id']]);
        send_json(crud_out($def, $row, crud_labels($def, [$row])));
    });
    $r->add('DELETE', '/api/crud/([a-z_]+)/(\d+)', function ($e, $id) {
        $def = crud_def($e); $u = crud_guard($def, 'delete');
        $old = crud_row($def, (int)$id, $u);
        if (isset($def['can_delete']) && ($why = $def['can_delete']($old, $u))) fail(409, $why);
        foreach ($def['block_children'] ?? [] as [$t, $c, $what]) if ((int)val("SELECT COUNT(*) FROM $t WHERE $c = ?", [$old['id']])) fail(409, "Masih ada $what yang terkait; hapus/batalkan dulu");
        foreach ($def['cascade'] ?? [] as [$t, $c]) q("DELETE FROM $t WHERE $c = ?", [$old['id']]);
        foreach ($def['nullify'] ?? [] as [$t, $c]) q("UPDATE $t SET $c = NULL WHERE $c = ?", [$old['id']]);
        if (isset($def['delete_all_by'])) q("DELETE FROM {$def['table']} WHERE {$def['delete_all_by']} = ?", [$old[$def['delete_all_by']]]);
        else q("DELETE FROM {$def['table']} WHERE id = ?", [$old['id']]);
        audit_record($u, "crud.$e.hapus", $e, $old['id'], ['judul' => mb_substr((string)($old[$def['title']] ?? ''), 0, 120)]);
        send_json(['ok' => true, 'id' => (int)$old['id']]);
    });
}
