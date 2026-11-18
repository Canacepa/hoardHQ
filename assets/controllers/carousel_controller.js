import { Controller } from '@hotwired/stimulus';

// Native scroll-snap carousel with prev/next buttons (wrapping) and mouse-wheel scrolling.
export default class extends Controller {
    static targets = ['track'];

    connect() {
        this.locked = false;
        this.onWheel = (e) => this.wheel(e);
        // Non-passive so we can stop the page from scrolling while the carousel moves.
        this.trackTarget.addEventListener('wheel', this.onWheel, { passive: false });
    }

    disconnect() {
        this.trackTarget.removeEventListener('wheel', this.onWheel);
    }

    // One slide per wheel gesture; at either end the wheel scrolls the page again.
    wheel(e) {
        if (Math.abs(e.deltaX) > Math.abs(e.deltaY)) return; // horizontal swipes already scroll natively
        const direction = Math.sign(e.deltaY);
        if (!direction || (direction > 0 ? this.atEnd() : this.atStart())) return;
        e.preventDefault();
        if (this.locked) return;
        this.locked = true;
        this.scroll(direction);
        setTimeout(() => (this.locked = false), 450);
    }

    atStart() {
        return this.trackTarget.scrollLeft <= 1;
    }

    atEnd() {
        const t = this.trackTarget;
        return t.scrollLeft + t.clientWidth >= t.scrollWidth - 1;
    }

    prev() {
        this.atStart() ? this.trackTarget.scrollTo({ left: this.trackTarget.scrollWidth, behavior: 'smooth' }) : this.scroll(-1);
    }

    next() {
        this.atEnd() ? this.trackTarget.scrollTo({ left: 0, behavior: 'smooth' }) : this.scroll(1);
    }

    scroll(direction) {
        const t = this.trackTarget;
        const gap = parseFloat(getComputedStyle(t).columnGap) || 0;
        t.scrollBy({ left: direction * (t.firstElementChild.offsetWidth + gap), behavior: 'smooth' });
    }
}
