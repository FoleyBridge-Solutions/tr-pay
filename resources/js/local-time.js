/**
 * <local-time> Web Component
 *
 * Converts UTC datetime values to the viewer's browser timezone.
 * Works seamlessly with Livewire 4's DOM morphing.
 *
 * Usage:
 *   <local-time datetime="{{ $model->created_at->toIso8601String() }}"></local-time>
 *   <local-time datetime="{{ $model->created_at->toIso8601String() }}" format="date"></local-time>
 *   <local-time datetime="{{ $model->created_at->toIso8601String() }}" format="short"></local-time>
 *   <local-time datetime="{{ $model->created_at->toIso8601String() }}" format="time"></local-time>
 *
 * Formats:
 *   - "datetime" (default): "Feb 11, 2026, 10:15 AM CST"
 *   - "date":               "Feb 11, 2026"
 *   - "short":              "Feb 11, 10:15 AM"
 *   - "time":               "10:15 AM CST"
 *   - "relative":           "2 hours ago"
 */
class LocalTimeElement extends HTMLElement {
    static get observedAttributes() {
        return ['datetime', 'format'];
    }

    connectedCallback() {
        this.update();
    }

    attributeChangedCallback() {
        this.update();
    }

    update() {
        const attr = this.attributes.getNamedItem('datetime');
        if (!attr || !attr.value) return;

        const date = new Date(attr.value);
        if (isNaN(date.getTime())) return;

        const language = document.querySelector('html')?.getAttribute('lang') || 'en-US';
        const formatType = this.getAttribute('format') || 'datetime';

        if (formatType === 'relative') {
            this.innerText = this.relativeTime(date);
            return;
        }

        const options = this.getFormatOptions(formatType);
        this.innerText = date.toLocaleString(language, options);
    }

    getFormatOptions(formatType) {
        switch (formatType) {
            case 'date':
                return { month: 'short', day: 'numeric', year: 'numeric' };
            case 'time':
                return { hour: 'numeric', minute: '2-digit', hour12: true, timeZoneName: 'short' };
            case 'short':
                return { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true };
            default: // 'datetime'
                return { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true, timeZoneName: 'short' };
        }
    }

    relativeTime(date) {
        const now = new Date();
        const diffMs = now - date;
        const diffSec = Math.floor(diffMs / 1000);
        const diffMin = Math.floor(diffSec / 60);
        const diffHour = Math.floor(diffMin / 60);
        const diffDay = Math.floor(diffHour / 24);

        if (diffSec < 60) return 'just now';
        if (diffMin < 60) return diffMin === 1 ? '1 minute ago' : `${diffMin} minutes ago`;
        if (diffHour < 24) return diffHour === 1 ? '1 hour ago' : `${diffHour} hours ago`;
        if (diffDay < 7) return diffDay === 1 ? '1 day ago' : `${diffDay} days ago`;

        // Fall back to date format for older dates
        const language = document.querySelector('html')?.getAttribute('lang') || 'en-US';
        return date.toLocaleString(language, { month: 'short', day: 'numeric', year: 'numeric' });
    }
}

customElements.define('local-time', LocalTimeElement);

/**
 * Livewire 4 morph hook for web component compatibility.
 *
 * When Livewire morphs the DOM, custom elements need their attributes
 * updated manually so that attributeChangedCallback fires correctly.
 */
document.addEventListener('livewire:init', () => {
    Livewire.hook('morph.updating', ({ el, toEl, skip }) => {
        if (!el.tagName || !el.tagName.includes('-')) return;

        for (const attr of toEl.attributes) {
            el.setAttribute(attr.nodeName, attr.nodeValue);
        }
        skip();
    });
});
