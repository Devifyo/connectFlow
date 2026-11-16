import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// While an admin has the site down, every API call for a member answers 503 {maintenance: true}.
// Send the member to the maintenance page instead of leaving a half-working dashboard.
window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error?.response?.status === 503 && error.response.data?.maintenance && !window.location.pathname.startsWith('/maintenance')) {
            window.location.href = '/maintenance';
        }
        return Promise.reject(error);
    },
);

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: window.location.hostname,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 6001,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 6001,
    forceTLS: false,
    enabledTransports: ['ws'],
    wsPath: '/ws',
});
