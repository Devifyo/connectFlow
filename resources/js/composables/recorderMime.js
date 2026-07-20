// Picks a MediaRecorder mime type the current browser can actually record.
// Chrome/Firefox/desktop => webm (vp9/vp8). iOS Safari (all iOS browsers use WebKit)
// does NOT support webm recording and only produces mp4/H.264 — without this the
// recorder throws and the punch silently records no video.
export function pickRecorderMimeType() {
    if (typeof MediaRecorder === 'undefined' || !MediaRecorder.isTypeSupported) return '';
    const candidates = [
        'video/webm;codecs=vp9,opus',
        'video/webm;codecs=vp8,opus',
        'video/webm',
        'video/mp4;codecs=avc1,mp4a',
        'video/mp4',
    ];
    for (const type of candidates) {
        if (MediaRecorder.isTypeSupported(type)) return type;
    }
    return '';
}

export function isWebmMime(mime) {
    return !!mime && mime.indexOf('webm') !== -1;
}

export function extForMime(mime) {
    return mime && mime.indexOf('mp4') !== -1 ? 'mp4' : 'webm';
}
