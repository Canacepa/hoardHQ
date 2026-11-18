import { Controller } from '@hotwired/stimulus';

// Back button: go to the previous page; the link's href is the fallback when there is no history (page opened directly).
export default class extends Controller {
    go(e) {
        if (history.length > 1) {
            e.preventDefault();
            history.back();
        }
    }
}
