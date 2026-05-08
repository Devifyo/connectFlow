import { watch, onUnmounted } from 'vue';

const FAVICON_SVG = '/favicon.svg';
let originalTitle = '';
let faviconCanvas = null;
let faviconImg = null;
let faviconLoaded = false;

function getFaviconCanvas() {
    if (faviconCanvas) return faviconCanvas;
    faviconCanvas = document.createElement('canvas');
    faviconCanvas.width = 32;
    faviconCanvas.height = 32;
    return faviconCanvas;
}

function loadFavicon() {
    return new Promise((resolve) => {
        if (faviconLoaded && faviconImg) { resolve(faviconImg); return; }
        faviconImg = new Image();
        faviconImg.onload = () => { faviconLoaded = true; resolve(faviconImg); };
        faviconImg.onerror = () => resolve(null);
        faviconImg.src = FAVICON_SVG;
    });
}

function setFavicon(href) {
    let link = document.querySelector('link[rel="icon"][type="image/svg+xml"]')
        || document.querySelector('link[rel="icon"]');
    if (link) {
        link.type = 'image/png';
        link.href = href;
    }
}

async function drawBadgeFavicon(count) {
    const img = await loadFavicon();
    if (!img) return;

    const canvas = getFaviconCanvas();
    const ctx = canvas.getContext('2d');

    ctx.clearRect(0, 0, 32, 32);
    ctx.drawImage(img, 0, 0, 32, 32);

    if (count > 0) {
        const label = count > 99 ? '99' : String(count);
        const isWide = label.length > 1;
        const bw = isWide ? 22 : 18;
        const bh = 18;
        const bx = 32 - bw;
        const by = 0;

        ctx.fillStyle = '#0a0a0a';
        drawRoundRect(ctx, bx - 2, by - 2, bw + 4, bh + 4, 6);
        ctx.fill();

        ctx.fillStyle = '#ef4444';
        drawRoundRect(ctx, bx, by, bw, bh, 5);
        ctx.fill();

        ctx.fillStyle = '#ffffff';
        ctx.font = `bold ${isWide ? 11 : 13}px Arial, sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(label, bx + bw / 2, by + bh / 2 + 1);
    }

    setFavicon(canvas.toDataURL('image/png'));
}

function drawRoundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.lineTo(x + w - r, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h - r);
    ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    ctx.lineTo(x, y + r);
    ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath();
}

function resetFavicon() {
    let link = document.querySelector('link[rel="icon"][type="image/svg+xml"]')
        || document.querySelector('link[rel="icon"]');
    if (link) {
        link.type = 'image/svg+xml';
        link.href = FAVICON_SVG;
    }
}

export function useTabBadge(unreadRef, baseTitle) {
    originalTitle = baseTitle || document.title;

    const stop = watch(unreadRef, (count) => {
        if (count > 0) {
            document.title = `(${count > 99 ? '99+' : count}) ${originalTitle}`;
            drawBadgeFavicon(count);
        } else {
            document.title = originalTitle;
            resetFavicon();
        }
    }, { immediate: true });

    onUnmounted(() => {
        stop();
        document.title = originalTitle;
        resetFavicon();
    });
}
