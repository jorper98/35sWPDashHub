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

    function setIndeterminate(on) {
        if (on) {
            barEl.classList.add("hub-progress-bar--indeterminate");
            barEl.style.width = "100%";
        } else {
            barEl.classList.remove("hub-progress-bar--indeterminate");
        }
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

    var sitesJson = document.getElementById("hub-sites-json");
    var sites = [];
    if (sitesJson && sitesJson.textContent) {
        try {
            sites = JSON.parse(sitesJson.textContent);
        } catch (e) {
            sites = [];
        }
    }

    function siteNeedsHubUpdate(s) {
        return s.last_status === "online" && (Number(s.pending_total) || 0) > 0;
    }

    var sitesNeedingUpdate = sites.filter(siteNeedsHubUpdate);

    var syncAllBtn = document.getElementById("hub-sync-all-btn");
    if (syncAllBtn && sites.length) {
        syncAllBtn.addEventListener("click", function () {
            if (!window.confirm("Refresh all " + sites.length + " site(s) now?")) {
                return;
            }
            clearLog();
            showOverlay();
            titleEl.textContent = "Syncing all sites";
            setStatus("Starting…");
            setProgress(0, sites.length);

            var i = 0;
            var errors = [];

            function next() {
                if (i >= sites.length) {
                    setProgress(sites.length, sites.length);
                    setStatus(
                        "Done. " +
                            (errors.length ? errors.length + " error(s)." : "All sync requests finished.")
                    );
                    closeBtn.hidden = false;
                    if (errors.length) {
                        errors.forEach(function (e) {
                            appendLog(e);
                        });
                    }
                    return;
                }

                var s = sites[i];
                var label = s.label || "Site " + s.id;
                setStatus("Syncing " + (i + 1) + " of " + sites.length + ": " + label);

                postAction("sync_one", { id: String(s.id) })
                    .then(function (data) {
                        if (data.ok) {
                            appendLog(
                                "✓ " +
                                    label +
                                    " — " +
                                    (data.last_status || "?") +
                                    (data.message ? " — " + data.message : "")
                            );
                        } else {
                            errors.push("✗ " + label + " — " + (data.error || "Failed"));
                            appendLog(errors[errors.length - 1]);
                        }
                    })
                    .catch(function (err) {
                        errors.push("✗ " + label + " — " + err.message);
                        appendLog(errors[errors.length - 1]);
                    })
                    .finally(function () {
                        i += 1;
                        setProgress(i, sites.length);
                        next();
                    });
            }

            next();
        });
    }

    var testAllBtn = document.getElementById("hub-test-all-btn");
    if (testAllBtn && sites.length) {
        testAllBtn.addEventListener("click", function () {
            if (!window.confirm("Test WordPress connection for all " + sites.length + " site(s) now?")) {
                return;
            }
            clearLog();
            showOverlay();
            titleEl.textContent = "Testing connections";
            setStatus("Starting…");
            setProgress(0, sites.length);

            var ti = 0;
            var testErrors = [];

            function nextTest() {
                if (ti >= sites.length) {
                    setProgress(sites.length, sites.length);
                    setStatus(
                        "Done. " +
                            (testErrors.length
                                ? testErrors.length + " site(s) need attention."
                                : "All tests finished.")
                    );
                    closeBtn.hidden = false;
                    return;
                }

                var st = sites[ti];
                var lab = st.label || "Site " + st.id;
                setStatus("Testing " + (ti + 1) + " of " + sites.length + ": " + lab);

                postAction("test_connection", { id: String(st.id) })
                    .then(function (data) {
                        var okRest = data.rest_authenticated;
                        var okSum = data.companion_summary_ok;
                        var line;
                        if (!okRest) {
                            line = "✗ " + lab + " — " + (data.message || "REST auth failed");
                            testErrors.push(line);
                        } else if (!okSum) {
                            line = "△ " + lab + " — " + (data.message || "Companion summary failed");
                            testErrors.push(line);
                        } else {
                            line = "✓ " + lab + " — " + (data.message || "OK");
                        }
                        appendLog(line);
                        if (data.log_summary) {
                            appendLog("  " + data.log_summary);
                        }
                    })
                    .catch(function (err) {
                        var line = "✗ " + lab + " — " + err.message;
                        testErrors.push(line);
                        appendLog(line);
                    })
                    .finally(function () {
                        ti += 1;
                        setProgress(ti, sites.length);
                        nextTest();
                    });
            }

            nextTest();
        });
    }

    var updateAllBtn = document.getElementById("hub-update-all-btn");
    if (updateAllBtn && sitesNeedingUpdate.length) {
        updateAllBtn.addEventListener("click", function () {
            if (
                !window.confirm(
                    "Run pending updates on " +
                        sitesNeedingUpdate.length +
                        " site(s) with pending work? This changes live WordPress sites (plugins, themes, core)."
                )
            ) {
                return;
            }
            clearLog();
            showOverlay();
            titleEl.textContent = "Updating all sites";
            setStatus("Capturing pre-update snapshot…");
            setProgress(0, sitesNeedingUpdate.length);

            postAction("capture_update_snapshot", {})
                .then(function (data) {
                    if (data.ok) {
                        appendLog("✓ Pre-update snapshot captured.");
                    } else {
                        appendLog("△ Snapshot capture skipped: " + (data.message || "Unknown error"));
                    }
                })
                .catch(function (err) {
                    appendLog("△ Snapshot capture skipped: " + err.message);
                })
                .then(function () {
                    runUpdateLoop();
                });

            function runUpdateLoop() {
                var j = 0;
                var updateErrors = [];

                function nextUpdate() {
                    if (j >= sitesNeedingUpdate.length) {
                        setProgress(sitesNeedingUpdate.length, sitesNeedingUpdate.length);
                        setStatus(
                            "Done. " +
                                (updateErrors.length
                                    ? updateErrors.length + " site(s) reported failure."
                                    : "All update requests finished.")
                        );
                        closeBtn.hidden = false;
                        return;
                    }

                    var st = sitesNeedingUpdate[j];
                    var lab = st.label || "Site " + st.id;
                    setStatus("Updating " + (j + 1) + " of " + sitesNeedingUpdate.length + ": " + lab);

                    postAction("run_updates", {
                        id: String(st.id),
                        confirm: "1",
                        scope: "all",
                    })
                        .then(function (data) {
                            if (data.ok) {
                                appendLog("✓ " + lab + " — " + (data.message || "OK"));
                                if (data.after_sync_summary) {
                                    appendLog("  " + data.after_sync_summary);
                                }
                            } else {
                                var line = "✗ " + lab + " — " + (data.message || "Failed");
                                updateErrors.push(line);
                                appendLog(line);
                            }
                        })
                        .catch(function (err) {
                            var line = "✗ " + lab + " — " + err.message;
                            updateErrors.push(line);
                            appendLog(line);
                        })
                        .finally(function () {
                            j += 1;
                            setProgress(j, sitesNeedingUpdate.length);
                            nextUpdate();
                        });
                }

                nextUpdate();
            }
        });
    }

    document.querySelectorAll("form.hub-sync-one-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
            ev.preventDefault();
            var id = form.querySelector('input[name="id"]');
            if (!id) {
                return;
            }
            var row = form.closest("tr");
            var label = row ? row.querySelector(".strong") : null;
            var name = label ? label.textContent.trim() : "Site " + id.value;

            clearLog();
            showOverlay();
            titleEl.textContent = "Syncing site";
            setIndeterminate(true);
            setStatus(name);
            closeBtn.hidden = true;

            postAction("sync_one", { id: id.value })
                .then(function (data) {
                    setIndeterminate(false);
                    setProgress(1, 1);
                    if (data.ok) {
                        setStatus(data.message || "Done.");
                        appendLog(data.log_summary || data.message || "OK");
                        hideOverlay();
                        window.location.reload();
                        return;
                    } else {
                        setStatus(data.error || "Failed");
                        appendLog(data.error || "Failed");
                    }
                    closeBtn.hidden = false;
                })
                .catch(function (err) {
                    setIndeterminate(false);
                    setStatus(err.message);
                    appendLog(err.message);
                    closeBtn.hidden = false;
                });
        });
    });

    document.querySelectorAll("form.hub-update-form").forEach(function (form) {
        form.addEventListener("submit", function (ev) {
            ev.preventDefault();
            if (!window.confirm("Run remote updates on this site? This changes the live site.")) {
                return;
            }
            var id = form.querySelector('input[name="id"]');
            if (!id) {
                return;
            }
            var row = form.closest("tr");
            var label = row ? row.querySelector(".strong") : null;
            var name = label ? label.textContent.trim() : "Site " + id.value;

            clearLog();
            showOverlay();
            titleEl.textContent = "Running updates";
            setIndeterminate(true);
            setStatus(name + " — contacting WordPress (this may take a while)…");
            closeBtn.hidden = true;

            var fd = new FormData(form);
            fd.append("ajax", "1");
            fd.set("csrf", body.dataset.csrf);

            fetch("index.php", {
                method: "POST",
                body: fd,
                credentials: "same-origin",
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            })
                .then(function (r) {
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
                        return { r: r, data: data };
                    });
                })
                .then(function (o) {
                    setIndeterminate(false);
                    setProgress(1, 1);
                    var data = o.data;
                    if (!o.r.ok && data && data.error) {
                        throw new Error(data.error);
                    }
                    if (data === null || typeof data !== "object") {
                        throw new Error("Empty or invalid JSON response.");
                    }
                    if (data.ok) {
                        setStatus(data.message || "Update finished.");
                        appendLog(data.message || "");
                        if (data.after_sync_summary) {
                            appendLog("After refresh: " + data.after_sync_summary);
                        }
                    } else {
                        setStatus(data.message || "Update failed.");
                        appendLog(data.message || "Failed");
                    }
                    closeBtn.hidden = false;
                })
                .catch(function (err) {
                    setIndeterminate(false);
                    setStatus(err.message);
                    appendLog(err.message);
                    closeBtn.hidden = false;
                });
        });
    });
})();
