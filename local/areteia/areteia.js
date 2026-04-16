/**
 * AreteIA — Client-side AJAX navigation and UI reactivity.
 *
 * Responsibilities:
 * 1. Intercept link clicks for AJAX partial-page updates (no full reload)
 * 2. Auto-capture textarea state (d2) before navigation
 * 3. Step 3 reactivity: enable/disable the "next" button based on dimension completion
 * 4. Loading state management for AI-generation buttons
 */
document.addEventListener("click", e => {
    const link = e.target.closest("a.opt, a.s0-card, a.sug-card, a.areteia-btn, a.fb-btn, a.areteia-dot");
    if (!link || link.classList.contains("external")) return;

    // Confirmation dialog (before fetch)
    if (link.dataset.confirm && !confirm(link.dataset.confirm)) {
        e.preventDefault();
        return;
    }

    const url = new URL(link.href);
    url.searchParams.set("ajax", "1");

    // Auto-capture the objective textarea if present (Step 3)
    const d2Area = document.querySelector('textarea[name="d2"]');
    if (d2Area) {
        url.searchParams.set("d2", d2Area.value);
    }

    // Capture material selection in Step 1 if starting ingestion
    const options = { method: 'GET' };
    if (url.searchParams.get("action") === "ingest") {
        const checkedFiles = Array.from(document.querySelectorAll('.tree-cb[data-type="file"]:checked'))
            .map(cb => cb.value);

        if (checkedFiles.length === 0) {
            alert("Por favor, selecciona al menos un material para continuar.");
            e.preventDefault();
            const primaryBtn = document.querySelector('.areteia-btn-primary.is-loading');
            if (primaryBtn) primaryBtn.classList.remove('is-loading'); // Reset loading
            return;
        }

        // We use POST to avoid URL length limits with many file paths
        options.method = 'POST';
        options.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };

        // Construct form data
        const body = new URLSearchParams();
        body.append('selected_files', JSON.stringify(checkedFiles));
        // Also keep other params
        url.searchParams.forEach((val, key) => body.append(key, val));
        options.body = body.toString();
    }

    fetch(url, options).then(r => {
        if (!r.ok) throw new Error("Server error " + r.status);
        // Clean URL for browser history (remove ajax param)
        const finalUrl = new URL(r.url);
        finalUrl.searchParams.delete("ajax");
        // Don't push selected_files to history
        window.history.pushState({}, "", finalUrl.toString());
        return r.text();
    }).then(html => {
        document.getElementById("areteia-main").innerHTML = html;
        // Scroll to top on step change
        if (url.searchParams.has("step") && url.searchParams.get("step") !== new URL(location.href).searchParams.get("step")) {
            window.scrollTo({ top: 0, behavior: "smooth" });
        }
        initStep3Reactivity();
        initGenerativeLoading();
        initTreeCheckboxes();
    }).catch(err => {
        console.error(err);
        alert("Error en la comunicación con el servidor. Por favor, reintenta.");
        // Reset loading button if any
        const loadingBtn = document.querySelector('.areteia-btn-primary.is-loading');
        if (loadingBtn) {
            loadingBtn.classList.remove('is-loading');
            loadingBtn.innerHTML = loadingBtn.dataset.oldHtml || "Error - Reintentar";
            loadingBtn.style.opacity = '1';
        }
    });
});

/**
 * Hierarchical Tree Selection Logic (Step 1)
 */
function initTreeCheckboxes() {
    const tree = document.getElementById('materials-tree');
    if (!tree) return;

    // Toggle collapse/expand
    tree.addEventListener('click', e => {
        if (e.target.classList.contains('tree-toggle')) {
            const node = e.target.closest('.tree-node');
            if (node) {
                node.classList.toggle('collapsed');
            }
        }
    });

    // Initial count
    updateSelectionCount();

    tree.addEventListener('change', e => {
        if (!e.target.classList.contains('tree-cb')) return;

        const cb = e.target;
        const node = cb.closest('.tree-node');
        const isChecked = cb.checked;

        // Recursive Down: Update all children
        const children = node.querySelectorAll('.tree-cb');
        children.forEach(child => {
            child.checked = isChecked;
            child.indeterminate = false;
        });

        // Bubble Up: Optional - we could update parent state here
        updateParentStates(cb);

        // Update selection counter
        updateSelectionCount();
    });
}

/**
 * Updates the "X files selected" badge in real-time.
 */
function updateSelectionCount() {
    const badge = document.getElementById('selection-count-badge');
    if (!badge) return;

    const count = document.querySelectorAll('.tree-cb[data-type="file"]:checked').length;
    badge.textContent = `${count} ${count === 1 ? 'recurso seleccionado' : 'recursos seleccionados'}`;

    // Aesthetic: Change color if 0
    if (count > 0) {
        badge.style.background = '#28a745'; // OK green
        badge.style.color = '#fff';
    } else {
        badge.style.background = '#ffc107'; // Warn yellow
        badge.style.color = '#000';
    }
}

/**
 * Optional: Update parent indeterminate/checked state based on children.
 */
function updateParentStates(startCb) {
    let current = startCb.closest('.tree-node').parentElement.closest('.tree-node');

    while (current) {
        const parentCb = current.querySelector('.tree-cb');
        const siblingNodes = current.querySelectorAll(':scope > .tree-node > .tree-row .tree-cb');

        if (siblingNodes.length > 0) {
            const checkedCount = Array.from(siblingNodes).filter(c => c.checked).length;
            const isIndeterminate = Array.from(siblingNodes).some(c => c.indeterminate);

            if (checkedCount === 0) {
                parentCb.checked = false;
                parentCb.indeterminate = isIndeterminate;
            } else if (checkedCount === siblingNodes.length) {
                parentCb.checked = true;
                parentCb.indeterminate = false;
            } else {
                parentCb.checked = false;
                parentCb.indeterminate = true;
            }
        }
        current = current.parentElement.closest('.tree-node');
    }
}

/**
 * Step 3 reactivity: enable the "next" button only when
 * all 3 pill dimensions are selected AND the objective textarea is filled.
 */
function initStep3Reactivity() {
    const btn = document.getElementById("next-step-btn");
    const d2 = document.querySelector('textarea[name="d2"]');

    if (!btn || !d2) return;

    const updateBtn = () => {
        const hasD2 = d2.value.trim().length > 0;
        const activeOpts = document.querySelectorAll(".opt.main").length;
        const expectedOpts = 3;

        if (hasD2 && activeOpts >= expectedOpts) {
            btn.classList.remove("disabled");
            btn.style.opacity = "1";
            btn.style.cursor = "pointer";
            btn.innerHTML = "Ver Sugerencias →";
        } else {
            btn.classList.add("disabled");
            btn.style.opacity = "0.5";
            btn.style.cursor = "not-allowed";
            btn.innerHTML = "Completa todas las dimensiones";
        }
    };

    d2.addEventListener("input", updateBtn);
    updateBtn();
}

/**
 * Loading state for AI-generation buttons.
 * Shows a spinner and "Generando con IA..." label on click.
 */
function initGenerativeLoading() {
    document.querySelectorAll('.areteia-btn-primary:not(.external)').forEach(btn => {
        if (btn.dataset.bound) return;
        btn.dataset.bound = "1";
        btn.addEventListener('click', function (e) {
            if (this.classList.contains('is-loading')) {
                e.preventDefault();
                return;
            }

            let isIA = this.innerText.includes('✨') || this.dataset.ia === "1";
            let label = isIA ? 'Generando con IA...' : 'Cargando...';

            this.classList.add('is-loading');
            this.dataset.oldHtml = this.innerHTML;
            this.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span> ' + label;
            this.style.opacity = '0.7';
        });
    });
}

/**
 * Search Test UI: Semantic query against Python RAG.
 */
function initRagSearchTest() {
    const btn = document.getElementById('rag-search-btn');
    const input = document.getElementById('rag-search-input');
    const container = document.getElementById('rag-search-results');

    if (!btn || !input || !container) return;

    btn.addEventListener('click', () => {
        const query = input.value.trim();
        const courseid = btn.dataset.courseid;
        if (!query) return;

        btn.disabled = true;
        btn.innerHTML = 'Buscando...';
        container.innerHTML = '<div style="text-align:center; padding:20px; color:#666;">⏳ Consultando IA...</div>';

        fetch(`ajax_search.php?course_id=${courseid}&query=${encodeURIComponent(query)}`)
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                btn.innerHTML = 'Buscar';

                if (res.status === 'success' && res.results) {
                    if (res.results.length === 0) {
                        container.innerHTML = '<div style="color:#666; font-size:12px;">No se encontraron fragmentos relevantes.</div>';
                        return;
                    }
                    let html = '<div style="display:flex; flex-direction:column; gap:10px;">';
                    res.results.forEach(item => {
                        const sim = item.similarity ? `${(item.similarity * 100).toFixed(1)}% coincidencia` : '';
                        html += `
                            <div style="background:#fff; border:1px solid #eee; padding:10px; border-radius:8px; font-size:12px; line-height:1.4;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                                    <strong style="color:#6c63ff;">📄 ${item.filename}</strong>
                                    <span style="color:#28a745; font-size:10px; font-weight:bold;">${sim}</span>
                                </div>
                                <div style="color:#444; font-style:italic; border-left:3px solid #ddd; padding-left:10px;">"${item.text}"</div>
                            </div>
                        `;
                    });
                    html += '</div>';
                    container.innerHTML = html;
                } else {
                    container.innerHTML = `<div style="color:red; font-size:12px;">Error: ${res.message || 'Error desconocido'}</div>`;
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = 'Buscar';
                container.innerHTML = `<div style="color:red; font-size:12px;">Error de red: ${err.message}</div>`;
            });
    });

    // Enter key support
    input.addEventListener('keypress', e => {
        if (e.key === 'Enter') btn.click();
    });
}

// Reload on browser back/forward so state stays in sync
window.addEventListener("popstate", () => location.reload());

// Initialize on DOM ready
document.addEventListener("DOMContentLoaded", () => {
    initStep3Reactivity();
    initGenerativeLoading();
    initTreeCheckboxes();
    initRagSearchTest();
    initIngestionForm();
});

/**
 * Step 1: Ingestion Form Handling
 * Collects all checked file paths from the tree and submits the form.
 */
function initIngestionForm() {
    const btn = document.getElementById('confirm-ingest-btn');
    const form = document.getElementById('areteia-ingest-form');
    const input = document.getElementById('selected-files-input');

    if (!btn || !form || !input) return;

    btn.addEventListener('click', (e) => {
        e.preventDefault();

        // Collect all checked checkboxes of type "file"
        const selectedFiles = [];
        const fileCheckboxes = document.querySelectorAll('.tree-cb[data-type="file"]:checked');

        fileCheckboxes.forEach(cb => {
            if (cb.value) {
                selectedFiles.push(cb.value);
            }
        });

        // Update hidden input with JSON
        input.value = JSON.stringify(selectedFiles);

        // Submit the form
        form.submit();
    });
}

/**
 * Real-time RAG Ingestion Poller
 */
function initIngestionPoller(courseid) {
    const bar = document.getElementById('areteia-ingestion-bar');
    const statusText = document.getElementById('areteia-ingestion-status');
    const percentText = document.getElementById('areteia-ingestion-percent');

    if (!bar || !statusText || !percentText) return;

    const interval = setInterval(() => {
        fetch(`ajax_status.php?course_id=${courseid}`)
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success' && res.data) {
                    const data = res.data;

                    // Update UI
                    const p = data.progress || 0;
                    bar.style.width = p + '%';
                    percentText.innerHTML = p + '%';
                    statusText.innerHTML = data.message || 'Procesando...';

                    // If finished, wait a second and reload to show success view
                    if (p >= 100) {
                        clearInterval(interval);
                        setTimeout(() => {
                            const url = new URL(window.location.href);
                            url.searchParams.set('ingested', '1');
                            window.location.href = url.toString();
                        }, 1500);
                    }
                }
            })
            .catch(err => {
                console.error("Error polling ingestion:", err);
            });
    }, 2000);
}



