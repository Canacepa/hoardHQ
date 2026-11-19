import { Controller } from '@hotwired/stimulus';

// Changes an item's watch status without reloading, so the open modal stays open. The server stays the single source
// of truth: the POST redirects back to the collection page, and the status block and the card badge are swapped
// in from that fresh HTML. Without JavaScript the forms still work as plain posts.
export default class extends Controller {
    async save(event) {
        event.preventDefault();
        const form = event.currentTarget;
        // The submitter carries which season/episode was clicked (its name/value), so it has to be passed along.
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form, event.submitter) });
        if (!response.ok) return location.reload(); // e.g. expired session: let the normal page show what happened

        const open = [...this.element.querySelectorAll('details[open]')].map((details) => details.id);
        const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
        for (const id of [this.element.id, this.element.dataset.badge]) {
            const next = fresh.getElementById(id);
            if (next) document.getElementById(id)?.replaceWith(next);
        }
        // The swapped-in block is rendered closed: re-open the seasons that were expanded so ticking episodes doesn't collapse them.
        for (const id of open) document.getElementById(id)?.setAttribute('open', '');
    }
}
