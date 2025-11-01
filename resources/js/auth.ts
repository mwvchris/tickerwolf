// resources/js/auth.ts
export async function ensureCsrfCookie() {
    try {
        const response = await fetch('/sanctum/csrf-cookie', {
            method: 'GET',
            credentials: 'include', // Important: allows cookie to be set
        });

        if (!response.ok) {
            console.warn('CSRF cookie fetch returned non-OK status:', response.status);
        } else {
            console.info('CSRF cookie initialized successfully.');
        }
    } catch (error) {
        console.error('Error initializing CSRF cookie:', error);
    }
}

export function getXsrfToken() {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; XSRF-TOKEN=`);
    if (parts.length === 2) return decodeURIComponent(parts.pop().split(';').shift());
    return null;
}