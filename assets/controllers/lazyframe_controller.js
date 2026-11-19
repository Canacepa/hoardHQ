import { Controller } from '@hotwired/stimulus';

// Loads the lazy Turbo frame inside this element, once, when asked (the button that opens the modal holding it calls load).
// Turbo's own lazy loading waits for the browser to report the frame as visible, which is the same thing in practice; this
// makes it explicit and immediate, and doesn't depend on the browser's visibility observer.
export default class extends Controller {
    load() {
        this.element.querySelector('turbo-frame[loading="lazy"]')?.setAttribute('loading', 'eager');
    }
}
