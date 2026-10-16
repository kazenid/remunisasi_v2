/**
 * Returns the correct asset URL when the app runs in a subdirectory.
 * Uses VITE_BASE_URL env variable (e.g. "/tukiman").
 *
 * Usage in Vue:  :src="asset('images/logo_rsud_bangil.png')"
 */
const base = (import.meta.env.VITE_BASE_URL || '').replace(/\/$/, '');

export function asset(path) {
    // path should NOT start with "/"
    const cleanPath = path.replace(/^\//, '');
    return base ? `${base}/${cleanPath}` : `/${cleanPath}`;
}
