import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

// ─── State modul-singleton (bertahan lintas navigasi SPA) ──────────────────
const items = ref([]); // daftar notifikasi (seed dari shared prop)
const hasUnread = ref(false); // status titik merah

let es = null; // EventSource (dimiliki di scope modul, SURVIVE navigasi)
let pollTimer = null; // id interval fallback
let initialized = false; // guard: init() sekali per sesi SPA
let safetyTimer = null; // id interval safety poll (jaring pengaman walau SSE tampak OPEN)
let connectGuard = null; // id timeout guard: SSE macet di CONNECTING
let reconnectTimer = null; // id timeout reopen manual saat EventSource CLOSED
let signalSeq = 0; // naik tiap sinyal SSE baru; dipakai pollOnce mendeteksi data lebih baru

const POLL_MS = 60000;
const SAFETY_POLL_MS = 5 * 60 * 1000; // safety poll 5 menit
const CONNECT_GUARD_MS = 10000; // SSE belum OPEN setelah 10 dtk → nyalakan polling
const RECONNECT_MS = 15000; // jeda reopen manual saat EventSource CLOSED

// Finding 4: cookie XSRF-TOKEN URL-encoded → wajib decodeURIComponent untuk raw fetch.
function csrfHeader() {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? { 'X-XSRF-TOKEN': decodeURIComponent(m[1]) } : {};
}

// ─── SSE ────────────────────────────────────────────────────────────────────
function openSse() {
    if (es) return; // cegah koneksi ganda
    es = new EventSource(route('notifications.stream'));
    // Guard "stuck CONNECTING": stream tak kunjung terbuka → polling menyala.
    clearTimeout(connectGuard);
    connectGuard = setTimeout(() => {
        connectGuard = null;
        if (!es || es.readyState !== EventSource.OPEN) startPolling();
    }, CONNECT_GUARD_MS);
    es.onopen = () => {
        clearTimeout(connectGuard); // SSE tersambung → matikan guard + fallback
        connectGuard = null;
        stopPolling();
        pollOnce(); // catch-up: ambil yang terlewat selama stream terputus
    };
    es.onmessage = (e) => {
        try {
            handleSignal(JSON.parse(e.data));
        } catch {
            /* abaikan pesan non-JSON (mis. heartbeat tak sampai ke onmessage) */
        }
    };
    es.onerror = () => {
        // Nyalakan polling sementara. Selama readyState CONNECTING, EventSource reconnect sendiri.
        startPolling();
        // CLOSED (mis. respons 502/non-event-stream saat deploy): browser TIDAK akan
        // reconnect → tutup dan buka ulang manual setelah jeda.
        if (es && es.readyState === EventSource.CLOSED) {
            es.close();
            es = null;
            if (!reconnectTimer) {
                reconnectTimer = setTimeout(() => {
                    reconnectTimer = null;
                    openSse();
                }, RECONNECT_MS);
            }
        }
    };
}

function handleSignal(sig) {
    // 1) dedup berdasar id: sinyal yang sudah ada tidak memicu efek samping ganda
    if (items.value.find((i) => i.id === sig.id)) return;
    signalSeq++;
    // 2) titik merah + prepend ke panel
    items.value.unshift({ ...sig, read_at: null });
    hasUnread.value = true;
    // 3) idle-update konservatif bila halaman relevan
    reloadIfRelevant(sig);
}

// Idle-update: reload hanya prop yang terdampak berdasar halaman + sinyal.
// Guard list-vs-detail (§9.1): LIST tidak menyentuh props.tugas.id (array);
// hanya DETAIL yang membandingkan sig.task_id === props.tugas.id.
function reloadIfRelevant(sig) {
    const page = usePage();
    const component = page.component;
    const props = page.props;

    switch (component) {
        case 'Dashboard': {
            const only = ['katimker', 'kabid', 'admin'].filter((k) => k in props);
            if (only.length) router.reload({ only });
            break;
        }
        case 'Kabid/ReviewList':
            if (sig.type === 'task.submitted') router.reload({ only: ['tugas'] });
            break;
        case 'Katimker/TaskList':
            if (['task.approved', 'task.revisi', 'task.rejected', 'task.assigned'].includes(sig.type)) {
                router.reload({ only: ['tugas'] });
            }
            break;
        case 'Kabid/ReviewDetail':
            if (sig.task_id === props?.tugas?.id) router.reload({ only: ['tugas'] });
            break;
        case 'Katimker/TaskDetail':
            if (sig.task_id === props?.tugas?.id) router.reload({ only: ['tugas'] });
            break;
        default:
            // Halaman lain: cukup update lonceng, jangan reload data.
            break;
    }
}

// ─── Polling fallback ─────────────────────────────────────────────────────
function startPolling() {
    if (pollTimer || document.hidden) return;
    pollTimer = setInterval(pollOnce, POLL_MS);
    pollOnce();
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

async function pollOnce() {
    if (document.hidden) return;
    const seq = signalSeq; // tangkap sebelum fetch
    try {
        const res = await fetch(route('notifications.index'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const ct = res.headers.get('content-type') || '';
        // 302→HTML (admin mid-2FA / sesi kedaluwarsa) → bukan JSON → berhenti diam-diam.
        if (!res.ok || !ct.includes('application/json')) return;
        const data = await res.json();
        if (seq !== signalSeq) return; // sinyal SSE lebih baru datang saat fetch → jangan timpa
        items.value = data.notifications;
        hasUnread.value = data.has_unread;
    } catch {
        /* jaringan gagal → coba lagi interval berikutnya */
    }
}

// ─── Visibility ──────────────────────────────────────────────────────────
function onVisibilityChange() {
    if (document.hidden) {
        stopPolling();
        return;
    }
    // Tab kembali terlihat:
    if (!es) openSse();
    // Jaring pengaman: selama SSE belum OPEN, pastikan polling berjalan.
    if (!es || es.readyState !== EventSource.OPEN) startPolling();
    pollOnce(); // catch-up setelah tab tersembunyi
}

// ─── Lifecycle ──────────────────────────────────────────────────────────
function init(page) {
    if (initialized) return; // guard: sekali per sesi SPA
    initialized = true;

    items.value = page?.props?.notifications?.items ?? [];
    hasUnread.value = page?.props?.notifications?.has_unread ?? false;

    openSse();
    if (!safetyTimer) safetyTimer = setInterval(pollOnce, SAFETY_POLL_MS);
    document.addEventListener('visibilitychange', onVisibilityChange);
    window.addEventListener('beforeunload', teardown);
    window.addEventListener('pagehide', teardown);
}

function teardown() {
    // HANYA pada keluar-app sungguhan (beforeunload/pagehide).
    if (es) {
        es.close();
        es = null;
    }
    stopPolling();
    clearInterval(safetyTimer);
    safetyTimer = null;
    clearTimeout(connectGuard);
    connectGuard = null;
    clearTimeout(reconnectTimer);
    reconnectTimer = null;
    document.removeEventListener('visibilitychange', onVisibilityChange);
    window.removeEventListener('beforeunload', teardown);
    window.removeEventListener('pagehide', teardown);
    initialized = false;
}

// ─── Mark-read ──────────────────────────────────────────────────────────
async function markRead(item) {
    await fetch(route('notifications.read', item.id), {
        method: 'POST',
        headers: { Accept: 'application/json', ...csrfHeader() },
        credentials: 'same-origin',
    });
    item.read_at = new Date().toISOString();
    hasUnread.value = items.value.some((i) => !i.read_at);
    router.visit(item.url); // deep-link relatif via Inertia (SPA)
}

async function markAllRead() {
    await fetch(route('notifications.read-all'), {
        method: 'POST',
        headers: { Accept: 'application/json', ...csrfHeader() },
        credentials: 'same-origin',
    });
    items.value.forEach((i) => (i.read_at = i.read_at ?? new Date().toISOString()));
    hasUnread.value = false;
}

// ─── Helper waktu relatif (Indonesia) ───────────────────────────────────
function relativeTime(iso) {
    if (!iso) return '';
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '';
    const detik = Math.max(0, Math.floor((Date.now() - then) / 1000));
    if (detik < 60) return 'baru saja';
    const menit = Math.floor(detik / 60);
    if (menit < 60) return `${menit} menit lalu`;
    const jam = Math.floor(menit / 60);
    if (jam < 24) return `${jam} jam lalu`;
    const hari = Math.floor(jam / 24);
    if (hari < 7) return `${hari} hari lalu`;
    return new Date(iso).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function useNotifications() {
    return { items, hasUnread, init, markRead, markAllRead, relativeTime };
}
