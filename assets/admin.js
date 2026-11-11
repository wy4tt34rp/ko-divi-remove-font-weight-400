/* global koFw400 */
(() => {
    'use strict';
    const preview = document.getElementById('ko-preview');
    const clean = document.getElementById('ko-clean');
    const restore = document.getElementById('ko-restore');
    const selection = document.getElementById('ko-run');
    const status = document.getElementById('ko-status');
    const results = document.getElementById('ko-results');

    async function run(mode) {
        preview.disabled = clean.disabled = restore.disabled = true;
        results.replaceChildren();
        let cursor = 0;
        let runId = mode === 'restore' ? selection.value : '';
        selection.disabled = true;
        let changed = 0;
        let skipped = 0;
        let scanned = 0;
        let success = false;
        try {
            let done = false;
            while (!done) {
                status.textContent = `${mode}: ${scanned} candidates checked, ${changed} affected, ${skipped} skipped. Keep this tab open.`;
                const body = new URLSearchParams({ action: 'ko_fw400', nonce: koFw400.nonce, mode, cursor, run: runId });
                const response = await fetch(koFw400.url, { method: 'POST', credentials: 'same-origin', body });
                const payload = await response.json();
                if (!response.ok || !payload.success) throw new Error(payload.data || 'Request failed.');
                const data = payload.data;
                cursor = data.cursor;
                runId = data.run;
                if (mode === 'clean' && !Array.from(selection.options).some(option => option.value === runId)) {
                    selection.add(new Option('Current manual cleanup', runId));
                    selection.value = runId;
                }
                changed += data.changed;
                skipped += data.skipped;
                scanned += data.scanned;
                done = data.done;
                for (const title of data.items) {
                    const item = document.createElement('li');
                    item.textContent = title;
                    results.appendChild(item);
                }
            }
            success = true;
            const verb = mode === 'preview' ? 'would change' : mode === 'clean' ? 'cleaned permanently' : 'restored';
            status.textContent = `${changed} records ${verb}. ${skipped} skipped (permissions, changed content, or already restored). ` +
                (mode === 'preview' ? 'Review the list, then choose cleanup.' : 'Clear Divi and page/CDN caches, then check the affected pages.');
        } catch (error) {
            status.textContent = `Stopped: ${error.message} Some earlier batches may have completed. Reload to access the latest backup. Preview again to find any remaining styles.`;
        } finally {
            preview.disabled = false;
            clean.disabled = !(success && mode === 'preview' && changed > 0);
            selection.disabled = false;
            restore.disabled = !selection.value;
        }
    }
    preview.addEventListener('click', () => run('preview'));
    clean.addEventListener('click', () => run('clean'));
    restore.addEventListener('click', () => run('restore'));
    selection.addEventListener('change', () => { restore.disabled = !selection.value; });
})();
