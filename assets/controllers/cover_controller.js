import { Controller } from '@hotwired/stimulus';

// The cover field of the collection form: a drop zone for a picture of your own, and under it one dropdown holding the
// default icon, the current picture (when there is one) and the collection's items. A picture dropped anywhere on the field
// (or chosen with the file button) becomes "My picture"; picking anything else from the dropdown drops it again.
export default class extends Controller {
    static targets = ['zone', 'file', 'preview', 'message', 'menu', 'current', 'thumb', 'uploadOption', 'uploadThumb'];

    connect() {
        this.storedSrc = this.previewTarget.getAttribute('src') || '';
        this.stored = this.uploadOptionTarget.dataset.stored === '1';
        this.sync();
    }

    // --- the dropdown ---

    choose() {
        if (this.checked()?.value !== 'upload') this.clearFile();
        this.menuTarget.open = false;
        this.sync();
    }

    checked() {
        return this.element.querySelector('input[name$="[cover]"]:checked');
    }

    // Brings the summary line, the preview and the "My picture" entry in line with the current choice and file.
    sync() {
        const radio = this.checked();
        const hasFile = this.fileTarget.files.length > 0;
        // "My picture" only exists once there is a picture: the saved one, or one just picked
        this.uploadOptionTarget.hidden = !(this.stored || hasFile);
        const src = this.previewTarget.getAttribute('src');
        this.previewTarget.hidden = !(radio?.value === 'upload' && src);
        if (radio) {
            const option = radio.closest('label');
            this.currentTarget.textContent = option.dataset.label;
            this.thumbTarget.innerHTML = option.querySelector('[data-thumb]').innerHTML;
        }
    }

    // --- the drop zone ---

    over(event) {
        // only for files: dragging a piece of text over the field is not for us
        if (![...(event.dataTransfer?.types ?? [])].includes('Files')) return;
        event.preventDefault();
        this.zoneTarget.toggleAttribute('data-over', true);
    }

    leave(event) {
        if (!this.element.contains(event.relatedTarget)) this.zoneTarget.removeAttribute('data-over');
    }

    drop(event) {
        if (![...(event.dataTransfer?.types ?? [])].includes('Files')) return;
        event.preventDefault();
        this.zoneTarget.removeAttribute('data-over');

        const file = [...event.dataTransfer.files].find((f) => /^image\/(jpeg|png|webp)$/.test(f.type));
        if (!file) return this.say('Please drop a JPEG, PNG or WebP picture.');
        if (file.size > 5 * 1024 * 1024) return this.say('That picture is over 5 MB.');

        // the dropped file goes into the form's file input, so it is sent with the form like a chosen one
        const files = new DataTransfer();
        files.items.add(file);
        this.fileTarget.files = files.files;
        this.preview();
    }

    // A file was picked (button or drop): show it and make it the choice.
    preview() {
        const file = this.fileTarget.files[0];
        if (!file) return;
        this.say('');
        this.revoke();
        const url = URL.createObjectURL(file);
        this.objectUrl = url;
        this.previewTarget.src = url;
        this.uploadThumbTarget.src = url;
        const upload = this.element.querySelector('input[name$="[cover]"][value="upload"]');
        if (upload) upload.checked = true;
        this.sync();
    }

    clearFile() {
        this.fileTarget.value = '';
        this.revoke();
        this.previewTarget.setAttribute('src', this.storedSrc);
        this.uploadThumbTarget.setAttribute('src', this.storedSrc);
    }

    revoke() {
        if (this.objectUrl) URL.revokeObjectURL(this.objectUrl);
        this.objectUrl = null;
    }

    say(text) {
        this.messageTarget.textContent = text;
        this.messageTarget.hidden = text === '';
    }
}
