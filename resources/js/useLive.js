import { useEffect, useRef, useState } from 'react';
import axios from 'axios';

/**
 * Keeps a screen up to date by re-fetching its JSON every few seconds.
 * Pauses while the browser tab is hidden and refreshes as soon as it is shown again.
 */
export default function useLive(url, params = {}, { interval = 4000, initial = null } = {}) {
    const [data, setData] = useState(initial);
    const [online, setOnline] = useState(true);
    const [loading, setLoading] = useState(!initial);
    const key = JSON.stringify(params);
    const skipFirst = useRef(!!initial);

    useEffect(() => {
        let stopped = false;
        let timer;
        let controller;

        const load = async () => {
            clearTimeout(timer);
            controller?.abort();
            controller = new AbortController();
            try {
                const { data } = await axios.get(url, { params, signal: controller.signal, headers: { Accept: 'application/json' } });
                if (stopped) return;
                setData(data);
                setOnline(true);
            } catch (err) {
                if (axios.isCancel(err) || stopped) return;
                // Logged out (session expired): send them to the login page
                if (err.response?.status === 401 || err.response?.status === 419) return window.location.reload();
                setOnline(false);
            } finally {
                if (!stopped) {
                    setLoading(false);
                    if (!document.hidden) timer = setTimeout(load, interval);
                }
            }
        };

        const onVisible = () => !document.hidden && load();

        if (skipFirst.current) {
            // The page already came with fresh data from the server
            skipFirst.current = false;
            timer = setTimeout(load, interval);
        } else {
            setLoading(true);
            load();
        }
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            stopped = true;
            clearTimeout(timer);
            controller?.abort();
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [url, key, interval]);

    return { data, online, loading };
}
