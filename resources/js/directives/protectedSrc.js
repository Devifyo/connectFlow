const dataCache = new Map();

function cacheKey(url) {
    return url.split('?')[0];
}

function addShimmer(el) {
    el.style.opacity = '0';
    const parent = el.parentElement;
    if (parent) parent.classList.add('pf-shimmer');
}

function removeShimmer(el) {
    el.style.opacity = '1';
    const parent = el.parentElement;
    if (parent) parent.classList.remove('pf-shimmer');
}

async function loadImage(el, url) {
    if (!url) return;
    const key = cacheKey(url);
    if (dataCache.has(key)) {
        applyBlob(el, dataCache.get(key));
        return;
    }
    addShimmer(el);
    try {
        const res = await fetch(url, {
            headers: { 'X-PF-Token': document.head.querySelector('meta[name="csrf-token"]')?.content || '1' },
            credentials: 'same-origin',
        });
        if (!res.ok) { removeShimmer(el); return; }
        const buf = await res.arrayBuffer();
        dataCache.set(key, { buf, type: res.headers.get('content-type') || 'image/jpeg' });
        applyBlob(el, dataCache.get(key));
    } catch { removeShimmer(el); }
}

function applyBlob(el, { buf, type }) {
    const blob = new Blob([buf], { type });
    const blobUrl = URL.createObjectURL(blob);
    el.onload = () => {
        URL.revokeObjectURL(blobUrl);
        removeShimmer(el);
    };
    el.src = blobUrl;
}

export const vProtectedSrc = {
    mounted(el, binding) {
        loadImage(el, binding.value);
    },
    updated(el, binding) {
        if (cacheKey(binding.value || '') === cacheKey(binding.oldValue || '')) return;
        loadImage(el, binding.value);
    },
};
