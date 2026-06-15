(function () {
    let tooltip = null;
    let selectedPotentialRecordRow = null;

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            let textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.setAttribute("readonly", "");
            textArea.style.position = "fixed";
            textArea.style.opacity = "0";

            document.body.appendChild(textArea);
            textArea.select();

            try {
                document.execCommand("copy");
                resolve();
            } catch (error) {
                reject(error);
            } finally {
                textArea.remove();
            }
        });
    }

    document.addEventListener("click", function (e) {
        let btn = e.target.closest(".act-score-btn");
        let potentialRecordRow = e.target.closest(".potential-record-row");

        if (btn) {
            e.stopPropagation();

            if (tooltip) {
                tooltip.remove();
                tooltip = null;
                return;
            }

            tooltip = document.createElement("div");
            tooltip.className = "act-tooltip";
            tooltip.textContent = btn.dataset.tip;

            document.body.appendChild(tooltip);

            let r = btn.getBoundingClientRect();
            let tr = tooltip.getBoundingClientRect();

            let left = r.left;
            let top = r.bottom + 6;

            if (left + tr.width > window.innerWidth - 8) {
                left = window.innerWidth - tr.width - 8;
            }

            if (left < 8) {
                left = 8;
            }

            if (top + tr.height > window.innerHeight - 8) {
                top = r.top - tr.height - 6;
            }

            if (top < 8) {
                top = 8;
            }

            tooltip.style.left = left + "px";
            tooltip.style.top = top + "px";

            return;
        }

        if (potentialRecordRow) {
            e.stopPropagation();

            if (selectedPotentialRecordRow) {
                selectedPotentialRecordRow.classList.remove("is-selected");
            }

            selectedPotentialRecordRow = potentialRecordRow;
            selectedPotentialRecordRow.classList.add("is-selected");

            copyText(potentialRecordRow.dataset.copy);

            return;
        }

        if (tooltip) {
            tooltip.remove();
            tooltip = null;
        }
    });
})();
