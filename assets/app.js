(function () {
    let tooltip = null;
    let selectedPotentialRecordRow = null;
    let viewTogglesForm = document.querySelector(".view-toggles");

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
        let btn = e.target.closest(".act-score-btn, .act-tooltip-trigger");
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

    if (viewTogglesForm) {
        viewTogglesForm.addEventListener("submit", function () {
            let fallbacks = viewTogglesForm.querySelectorAll(".toggle-fallback");
            let emptyMeansUnset = viewTogglesForm.querySelectorAll("[data-empty-means-unset]");
            let clubField = viewTogglesForm.querySelector('select[name="club"]');
            let athletesFallback = viewTogglesForm.querySelector('input.toggle-fallback[data-checkbox-name="athletes"]');
            let athletesCheckbox = viewTogglesForm.querySelector('input[type="checkbox"][name="athletes"]');

            fallbacks.forEach(function (fallback) {
                let checkbox = viewTogglesForm.querySelector('input[type="checkbox"][name="' + fallback.dataset.checkboxName + '"]');
                if (!checkbox) {
                    return;
                }

                if (fallback.dataset.disableWhenUnchecked === "0") {
                    fallback.disabled = checkbox.checked;
                    checkbox.disabled = checkbox.checked;
                    return;
                }

                fallback.disabled = checkbox.checked;
            });

            let verboseCheckbox = viewTogglesForm.querySelector('input[type="checkbox"][name="verbose"]');
            if (verboseCheckbox) {
                verboseCheckbox.disabled = !verboseCheckbox.checked;
            }

            if (clubField && clubField.value !== "") {
                if (athletesFallback) {
                    athletesFallback.disabled = true;
                }

                if (athletesCheckbox) {
                    athletesCheckbox.disabled = true;
                }
            }

            emptyMeansUnset.forEach(function (field) {
                field.disabled = field.value === "";
            });
        });
    }
})();
