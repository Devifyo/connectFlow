let activeLoads = 0;
const MAX_CONCURRENT = 3;
const queue = [];

function processQueue() {
    while (activeLoads < MAX_CONCURRENT && queue.length) {
        const next = queue.shift();
        if (next.el.isConnected) next.load();
    }
}

function createLoader(el, src) {
    return {
        el,
        load() {
            activeLoads++;
            el.onload = () => {
                activeLoads--;
                el.classList.remove('opacity-0');
                el.classList.add('opacity-100');
                const parent = el.parentElement;
                if (parent) parent.classList.remove('pf-shimmer');
                processQueue();
            };
            el.onerror = () => {
                activeLoads--;
                processQueue();
            };
            el.src = src;
        },
    };
}

const observer = new IntersectionObserver(
    (entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            const el = entry.target;
            const src = el.dataset.lazySrc;
            if (!src || el.src === src) continue;
            observer.unobserve(el);
            const loader = createLoader(el, src);
            if (activeLoads < MAX_CONCURRENT) {
                loader.load();
            } else {
                queue.push(loader);
            }
        }
    },
    { rootMargin: '600px 0px' }
);

export const vLazySrc = {
    mounted(el, binding) {
        if (!binding.value) return;
        el.dataset.lazySrc = binding.value;
        el.classList.add('opacity-0');
        const parent = el.parentElement;
        if (parent) parent.classList.add('pf-shimmer');
        observer.observe(el);
    },
    updated(el, binding) {
        if (!binding.value || binding.value === binding.oldValue) return;
        el.dataset.lazySrc = binding.value;
        observer.observe(el);
    },
    unmounted(el) {
        observer.unobserve(el);
    },
};
