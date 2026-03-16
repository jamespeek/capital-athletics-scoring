(function () {
    let tooltip = null;

    document.addEventListener("click", function (e) {
        let btn = e.target.closest(".act-score-btn");

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

        if (tooltip) {
            tooltip.remove();
            tooltip = null;
        }
    });
})();
