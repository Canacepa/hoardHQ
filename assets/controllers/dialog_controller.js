import { Controller } from '@hotwired/stimulus';

// Opens/closes a native <dialog> as a modal; clicking the backdrop closes it.
export default class extends Controller {
    static targets = ['dialog'];

    open() {
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    backdrop(e) {
        if (e.target === this.dialogTarget) this.close();
    }
}
