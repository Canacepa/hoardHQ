import { Controller } from '@hotwired/stimulus';

// Keeps a grid of cards to a single line: the cards that don't fit are hidden and an "N more…" tile takes the last slot.
export default class extends Controller {
    static targets = ['more'];

    connect() {
        this.observer = new ResizeObserver(() => this.fit());
        this.observer.observe(this.element);
        this.fit();
    }

    disconnect() {
        this.observer.disconnect();
    }

    fit() {
        const cards = [...this.element.children].filter((el) => el !== this.moreTarget);
        const columns = getComputedStyle(this.element).gridTemplateColumns.split(' ').length;
        const overflow = cards.length > columns;
        // Reserve one slot for the "more" tile when something is hidden.
        const keep = overflow ? Math.max(1, columns - 1) : cards.length;
        cards.forEach((card, i) => (card.style.display = i < keep ? '' : 'none'));
        this.moreTarget.style.display = overflow ? '' : 'none';
        this.moreTarget.querySelector('[data-count]').textContent = cards.length - keep;
    }
}
