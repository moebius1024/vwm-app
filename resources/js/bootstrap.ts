import axios, { AxiosStatic } from 'axios';

declare global {
    interface Window {
        axios: AxiosStatic;
    }
}

if (typeof window !== 'undefined') {
    window.axios = axios;
    window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
}

export default axios;
