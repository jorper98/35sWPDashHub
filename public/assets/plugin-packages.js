(function () {
    "use strict";

    var body = document.body;
    if (!body || !body.dataset.csrf) {
        return;
    }

    var overlay = document.getElementById("hub-progress-overlay");
    var titleEl = document.getElementById("hub-progress-title");
    var barEl = document.getElementById("hub-progress-bar");
    var statusEl = document.getElementById("hub-progress-status");
    var closeBtn = document.getElementById("hub-progress-close");
    var logEl = document.getElementById("hub-progress-log");

    if (!overlay || !titleEl || !barEl || !statusEl || !closeBtn) {
        return;
    }

    function showOverlay() {
        overlay.hidden = false;
        overlay.setAttribute("aria-hidden", "false");
        document.documentElement.classList.add("hub-progress-active");
    }

    function hideOverlay() {
        overlay.hidden = true;
        overlay.setAttribute("aria-hidden", "true");
        document.documentElement.classList.remove("hub-progress-active");
        barEl.classList.remove("hub-progress-bar--indeterminate");
        barEl.style.width = "0%";
    }

    function setProgress(current, total) {
        barEl.classList.remove("hub-progress-bar--indeterminate");
        var pct = total > 0 ? Math.round((100 * current) / total) : 100;
        barEl.style.width = pct + "%";
    }

    function setStatus(text) {
        statusEl.textContent = text || "";
    }

    function appendLog(line) {
        if (!logEl) {
            return;
        }
        var p = document.createElement("p");
        p.className = "hub-progress-log-line";
        p.textContent = line;
        logEl.appendChild(p);
        logEl.scrollTop = logEl.scrollHeight;
    }

    function clearLog() {
        if (logEl) {
            logEl.innerHTML = "";
        }
    }

    closeBtn.addEventListener("click", function () {
        hideOverlay();
        window.location.reload();
    });

    function postAction(action, fields) {
        var fd = new FormData();
        fd.append("csrf", body.dataset.csrf);
        fd.append("action", action);
        fd.append("ajax", "1");
        Object.keys(fields).forEach(function (k) {
            fd.append(k, fields[k]);
        });
        return fetch("index.php", {
            method: "POST",
            body: fd,
            credentials: "same-origin",
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        }).then(function (r) {
            return r.text().then(function (text) {
                var data;
                try {
                    data = text ? JSON.parse(text) : null;
                } catch (e) {
                    throw new Error(
                        r.ok
                            ? "Server returned non-JSON (check PHP errors / logs)."
                            : "Request failed (" + r.status + ")."
                    );
                }
                if (!r.ok && data && data.error) {
                    throw new Error(data.error);
                }
                if (!r.ok) {
                    throw new Error("Request failed (" + r.status + ")");
                }
                if (data === null || typeof data !== "object") {
                    throw new Error("Empty or invalid JSON response.");
                }
                return data;
            });
        });
    }

    var checkAll = document.getElementById("hub-deploy-check-all");
    if (checkAll) {
        checkAll.addEventListener("change", function () {
            document.querySelectorAll(".hub-deploy-site-cb").forEach(function (cb) {
                cb.checked = checkAll.checked;
            });
        });
    }

    var deployBtn = document.getElementById("hub-deploy-selected-btn");
    var pkgSelect = document.getElementById("hub-deploy-package");
    if (deployBtn && pkgSelect) {
        deployBtn.addEventListener("click", function () {
            var packageId = pkgSelect.value;
            var ids = [];
            document.querySelectorAll(".hub-deploy-site-cb:checked").forEach(function (cb) {
                ids.push(cb.value);
            });
            if (!packageId) {
                window.alert("Choose a package.");
                return;
            }
            if (!ids.length) {
                window.alert("Select at least one site.");
                return;
            }
            if (
                !window.confirm(
                    "Install or upgrade from this package on " +
                        ids.length +
                        " site(s)? This changes live WordPress sites."
                )
            ) {
                return;
            }

            var sitesJson = document.getElementById("hub-plugin-sites-json");
            var siteLabels = {};
            if (sitesJson && sitesJson.textContent) {
                try {
                    JSON.parse(sitesJson.textContent).forEach(function (s) {
                        siteLabels[String(s.id)] = s.label || "Site " + s.id;
                    });
                } catch (e) {
                    /* ignore */
                }
            }

            clearLog();
            showOverlay();
            titleEl.textContent = "Deploying plugin package";
            setStatus("Starting…");
            setProgress(0, ids.length);
            closeBtn.hidden = true;

            var i = 0;

            function next() {
                if (i >= ids.length) {
                    setProgress(ids.length, ids.length);
                    setStatus("Done.");
                    closeBtn.hidden = false;
                    return;
                }
                var sid = ids[i];
                var lab = siteLabels[sid] || "Site " + sid;
                setStatus("Site " + (i + 1) + " of " + ids.length + ": " + lab);

                postAction("deploy_plugin_package", {
                    package_id: String(packageId),
                    site_id: String(sid),
                })
                    .then(function (data) {
                        if (data.ok) {
                            appendLog("✓ " + lab + " — " + (data.message || "OK"));
                            if (data.after_sync_summary) {
                                appendLog("  " + data.after_sync_summary);
                            }
                        } else {
                            appendLog("✗ " + lab + " — " + (data.message || "Failed"));
                        }
                    })
                    .catch(function (err) {
                        appendLog("✗ " + lab + " — " + err.message);
                    })
                    .finally(function () {
                        i += 1;
                        setProgress(i, ids.length);
                        next();
                    });
            }

            next();
        });
    }
})();
