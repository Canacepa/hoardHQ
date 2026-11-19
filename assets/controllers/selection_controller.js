import { Controller } from '@hotwired/stimulus';

// Bulk editing of a list (the items of a collection, the collections list). "Edit" (start) switches the list into selection
// mode: [data-selecting] on the element makes the CSS swap the Edit button for the action dropdown + Validate + Cancel, show
// the checkboxes, and a click on a card ticks it instead of opening it. Cards can be ticked before an action is chosen. Validate submits the bulk
// form with the chosen action (or opens the dropdown if none is chosen yet); Cancel leaves the mode.
export default class extends Controller {
    static targets = ['menu', 'label', 'apply', 'field', 'all'];

    connect() {
        // The wording lives in the template; remember it so the script only adds the count / the chosen action to it.
        this.applyText = this.applyTarget.textContent.trim();
        this.defaultLabel = this.labelTarget.textContent.trim();
        this.selectAllText = this.allTarget.textContent.trim();
    }

    // Ticks every card you can see (a search narrows the list, and a row that folds the rest into "N more…" hides some), or
    // clears them all when they already are.
    toggleAll() {
        const boxes = this.#shown();
        const tickAll = !boxes.every((box) => box.checked);
        boxes.forEach((box) => (box.checked = tickAll));
        this.count();
    }

    start() {
        this.element.setAttribute('data-selecting', '');
        this.#openers(true);
        this.count();
    }

    choose(event) {
        // The dropdown only closes on an outside click, so close it here.
        this.menuTarget.removeAttribute('open');
        this.fieldTarget.value = event.params.kind;
        this.labelTarget.textContent = event.params.label;
    }

    validate(event) {
        if (this.fieldTarget.value) return; // let the form submit
        // No action yet: stop the submit and show the choices. stopPropagation keeps the dropdown's own
        // "click outside closes it" listener from closing it again straight away.
        event.preventDefault();
        event.stopPropagation();
        this.menuTarget.setAttribute('open', '');
    }

    cancel() {
        this.element.removeAttribute('data-selecting');
        this.menuTarget.removeAttribute('open');
        this.#openers(false);
        // Clear the ticks and the action so nothing hidden is submitted later.
        this.#boxes().forEach((box) => (box.checked = false));
        this.fieldTarget.value = '';
        this.labelTarget.textContent = this.defaultLabel;
        this.count();
    }

    count() {
        const selected = this.#boxes().filter((box) => box.checked).length;
        const shown = this.#shown();
        this.applyTarget.disabled = selected === 0;
        this.applyTarget.textContent = selected ? `${this.applyText} (${selected})` : this.applyText;
        this.allTarget.disabled = shown.length === 0;
        this.allTarget.textContent = shown.length && shown.every((box) => box.checked) ? this.allTarget.dataset.selectionClearText : this.selectAllText;
    }

    // What opens a card is switched off while selecting (the mouse is covered by a label over each card; this covers the
    // keyboard): the buttons that open item modals are disabled, and anything marked [data-selection-inert] (the link of a
    // collection card) is made inert, which also takes it out of the tab order.
    #openers(disabled) {
        this.element.querySelectorAll('[data-action~="dialog#open"]').forEach((opener) => (opener.disabled = disabled));
        this.element.querySelectorAll('[data-selection-inert]').forEach((el) => (el.inert = disabled));
    }

    #boxes() {
        return [...this.element.querySelectorAll('input[type="checkbox"][name="entry[]"]')];
    }

    // The ones on screen: a checkbox inside a hidden card has no box.
    #shown() {
        return this.#boxes().filter((box) => box.getClientRects().length > 0);
    }
}
