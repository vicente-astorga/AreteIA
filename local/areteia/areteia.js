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

    e.preventDefault();
    const url = new URL(link.href);
    url.searchParams.set("ajax", "1");

    // Auto-capture the objective textarea if present (Step 3)
    const d2Area = document.querySelector('textarea[name="d2"]');
    if (d2Area) {
        url.searchParams.set("d2", d2Area.value);
    }

    fetch(url).then(r => {
        if (!r.ok) throw new Error("Server error " + r.status);
        // Clean URL for browser history (remove ajax param)
        const finalUrl = new URL(r.url);
        finalUrl.searchParams.delete("ajax");
        window.history.pushState({}, "", finalUrl.toString());
        return r.text();
    }).then(html => {
        document.getElementById("areteia-main").innerHTML = html;
        // Scroll to top on step change
        if (url.searchParams.has("step") && url.searchParams.get("step") !== new URL(location.href).searchParams.get("step")) {
            window.scrollTo({ top: 0, behavior: "smooth" });
        }
        if (typeof initStep3Reactivity === "function") initStep3Reactivity();
        if (typeof initGenerativeLoading === "function") initGenerativeLoading();
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
        btn.addEventListener('click', function(e) {
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

// Reload on browser back/forward so state stays in sync
window.addEventListener("popstate", () => location.reload());

// Initialize on DOM ready
document.addEventListener("DOMContentLoaded", () => {
    initStep3Reactivity();
    initGenerativeLoading();
});
