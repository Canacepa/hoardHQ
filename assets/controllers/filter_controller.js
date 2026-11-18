import { Controller } from '@hotwired/stimulus';

// Live-filters a list: hides the items whose text doesn't contain the typed query.
export default class extends Controller {
    static targets = ['input', 'item', 'empty'];

    filter() {
        const q = this.inputTarget.value.trim().toLowerCase();
        let shown = 0;
        this.itemTargets.forEach((item) => {
            const match = item.textContent.toLowerCase().includes(q);
            item.hidden = !match;
            if (match) shown++;
        });
        if (this.hasEmptyTarget) this.emptyTarget.hidden = shown > 0 || this.itemTargets.length === 0;
    }
}
