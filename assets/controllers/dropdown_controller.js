import { Controller } from '@hotwired/stimulus';

// Closes a <details> dropdown when clicking outside of it or pressing Escape.
export default class extends Controller {
    connect() {
        this.onClick = (e) => {
            if (!this.element.contains(e.target)) this.element.open = false;
        };
        this.onKey = (e) => {
            if (e.key === 'Escape') this.element.open = false;
        };
        document.addEventListener('click', this.onClick);
        document.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        document.removeEventListener('click', this.onClick);
        document.removeEventListener('keydown', this.onKey);
    }
}
